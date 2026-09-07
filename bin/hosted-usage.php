<?php

declare(strict_types=1);

use JournalingPostServer\Database\ConnectionFactory;

require_once __DIR__ . '/../vendor/autoload.php';

// 運用者専用。HTTPへ公開しない。本文・API key平文を受け取らない。
try {
    $operation = $argv[1] ?? '';
    if (!in_array($operation, ['show', 'monthly', 'unlock'], true)) {
        throw new RuntimeException('Usage: hosted-usage.php show [support-id] | monthly | unlock support-id yyyyMMdd');
    }
    $supportId = $operation === 'monthly' ? null : ($argv[2] ?? null);
    if ($supportId !== null && preg_match('/\A[0-9a-f]{64}\z/', $supportId) !== 1) {
        throw new RuntimeException('Support ID must be a lowercase SHA-256 hexadecimal value.');
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $date = $argv[3] ?? '';
    if ($operation === 'unlock') {
        $parsed = DateTimeImmutable::createFromFormat('!Ymd', $date, new DateTimeZone('Asia/Tokyo'));
        if (
            $supportId === null || $parsed === false || $parsed->format('Ymd') !== $date
            || $date > $now->format('Ymd') || $date < $now->modify('-6 days')->format('Ymd')
        ) {
            throw new RuntimeException('Unlock requires a Support ID and a date within the current seven JST days.');
        }
    }
    $configuration = require __DIR__ . '/../bootstrap/database-config.php';
    $database = $configuration['database'];
    $connection = (new ConnectionFactory(
        $database['host'],
        $database['port'],
        $database['name'],
        $database['user'],
        $database['password'],
    ))->create();
    $connection->beginTransaction();
    $installationId = null;
    if ($supportId !== null) {
        $statement = $connection->prepare('SELECT id FROM installations WHERE api_key_hash = ? FOR UPDATE');
        $statement->execute([$supportId]);
        $installationId = $statement->fetchColumn();
        if ($installationId === false) {
            throw new RuntimeException('Installation not found.');
        }
    }
    if ($operation === 'unlock') {
        $statement = $connection->prepare('SELECT 1 FROM analysis_requests
            WHERE installation_id = ? AND analysis_date = ? AND expires_at > UTC_TIMESTAMP(6)');
        $statement->execute([$installationId, $date]);
        if ($statement->fetchColumn() !== false) {
            throw new RuntimeException('An active request or delivery buffer exists; unlock was not performed.');
        }
        $connection->prepare('DELETE FROM analysis_requests WHERE installation_id = ? AND analysis_date = ?')
            ->execute([$installationId, $date]);
        $statement = $connection->prepare('DELETE FROM analysis_days WHERE installation_id = ? AND analysis_date = ?');
        $statement->execute([$installationId, $date]);
        $count = $statement->rowCount();
        $connection->commit();
        fwrite(STDOUT, sprintf("Unlocked analysis dates: %d\n", $count));
        exit(0);
    }
    $monthEnd = $now->modify('first day of this month')->setTime(0, 0);
    $start = $operation === 'monthly' ? $monthEnd->modify('-1 month') : $now->modify('-35 days');
    $end = $operation === 'monthly' ? $monthEnd : $now;
    $parameters = [
        $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
    ];
    $where = 'called_at >= ? AND called_at < ?';
    if ($installationId !== null) {
        $where .= ' AND installation_id = ?';
        $parameters[] = $installationId;
    }
    if ($operation === 'monthly') {
        // 集計と削除の間に未集計callが入らないよう、前月範囲だけロックする。
        // AUTO_INCREMENTの採番順とcommit順は一致しないためMAX(id)だけでは不十分。
        $statement = $connection->prepare('SELECT id FROM provider_calls WHERE ' . $where . ' FOR UPDATE');
        $statement->execute($parameters);
        $statement->fetchAll(PDO::FETCH_COLUMN);
    }
    $statement = $connection->prepare('SELECT model, COUNT(*) AS provider_calls,
        SUM(input_tokens IS NULL OR output_tokens IS NULL) AS unknown_usage_calls,
        SUM(input_tokens) AS input_tokens, SUM(cached_input_tokens) AS cached_input_tokens,
        SUM(output_tokens) AS output_tokens,
        SUM(cached_input_tokens IS NULL) AS unknown_cache_calls,
        SUM(CASE WHEN cached_input_tokens IS NOT NULL THEN input_tokens ELSE NULL END) AS known_input_tokens,
        SUM(CASE WHEN cached_input_tokens IS NOT NULL THEN output_tokens ELSE NULL END) AS known_output_tokens
        FROM provider_calls WHERE ' . $where . ' GROUP BY model');
    $statement->execute($parameters);
    $groups = $statement->fetchAll();
    // 単価は運用者が公式価格を確認したJSONを使う。単価不明も0円にしない。
    $pricesFile = $_ENV['PROVIDER_PRICES_FILE'] ?? $_SERVER['PROVIDER_PRICES_FILE'] ?? '';
    $prices = $pricesFile === '' ? [] : json_decode(
        (string) @file_get_contents($pricesFile),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (!is_array($prices)) {
        throw new RuntimeException('Provider price configuration is invalid.');
    }
    foreach ($groups as &$group) {
        $group['estimated_usd'] = null;
        $group['known_usage_estimated_usd'] = null;
        $rates = $prices[$group['model'] ?? ''] ?? null;
        if (is_array($rates) && $group['known_input_tokens'] !== null) {
            foreach (['input', 'cached_input', 'output'] as $kind) {
                if (
                    !is_numeric($rates[$kind] ?? null) || !is_finite((float) $rates[$kind])
                    || (float) $rates[$kind] < 0
                ) {
                    throw new RuntimeException('Provider prices must be non-negative USD per million tokens.');
                }
            }
            $group['known_usage_estimated_usd'] = (
                ((int) $group['known_input_tokens'] - (int) $group['cached_input_tokens']) * (float) $rates['input']
                + (int) $group['cached_input_tokens'] * (float) $rates['cached_input']
                + (int) $group['known_output_tokens'] * (float) $rates['output']
            ) / 1000000;
            if ((int) $group['unknown_usage_calls'] === 0 && (int) $group['unknown_cache_calls'] === 0) {
                $group['estimated_usd'] = $group['known_usage_estimated_usd'];
            }
        }
    }
    unset($group);
    $output = json_encode(
        ['start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM), 'models' => $groups],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
    ) . "\n";
    if (@fwrite(STDOUT, $output) !== strlen($output) || !@fflush(STDOUT)) {
        throw new RuntimeException('Summary output failed; metadata was not deleted.');
    }
    if ($operation === 'monthly') {
        $connection->prepare('DELETE FROM provider_calls WHERE ' . $where)->execute($parameters);
    }
    $connection->commit();
} catch (Throwable $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    // DBの例外や設定ファイル本文・パスを出力しない。
    fwrite(STDERR, "Hosted usage operation failed. Check arguments, configuration, database and output destination.\n");
    exit(1);
}
