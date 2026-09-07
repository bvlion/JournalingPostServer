<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use JournalingPostServer\Analysis\AnalysisClaim;
use JournalingPostServer\Analysis\AnalysisRequestRepository;
use JournalingPostServer\Installation\InstallationRepository;
use JournalingPostServer\Tests\Integration\Support\DatabaseTestCase;
use PDO;

final class HostedUsageTest extends DatabaseTestCase
{
    private PDO $connection;
    private string $installationId;
    private string $supportId;
    private AnalysisRequestRepository $repository;

    protected function setUp(): void
    {
        $this->connection = self::createConnection();
        $this->connection->exec('DELETE FROM installations');
        $installations = new InstallationRepository(fn (): PDO => $this->connection);
        $key = $installations->register(new DateTimeImmutable('now'));
        $this->installationId = $installations->authenticate($key);
        $this->supportId = hash('sha256', $key);
        $this->repository = new AnalysisRequestRepository(fn (): PDO => $this->connection);
    }

    public function testDifferentKeysForOneDayShareTheClaimButOtherDaysAreIndependent(): void
    {
        $now = new DateTimeImmutable('now');
        $expires = $now->modify('+30 minutes');
        $date = $now->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Ymd');
        $first = $this->repository->claim($this->installationId, 'example-first-key', 'a', $now, $expires, $date);
        $otherConnection = self::createConnection();
        $otherRepository = new AnalysisRequestRepository(static fn (): PDO => $otherConnection);
        $second = $otherRepository->claim($this->installationId, 'example-other-key', 'b', $now, $expires, $date);
        self::assertSame(AnalysisClaim::Granted, $first->status);
        self::assertSame(AnalysisClaim::InProgress, $second->status);
        $this->repository->release($this->installationId, 'example-first-key', $first->claimedAt);
        self::assertSame(
            AnalysisClaim::Granted,
            $otherRepository->claim($this->installationId, 'example-other-key', 'b', $now, $expires, $date)->status
        );
        $this->repository->recordSuccess($this->installationId, $date);
        self::assertSame(
            AnalysisClaim::RateLimited,
            $this->repository->claim($this->installationId, 'example-third-key', 'c', $now, $expires, $date)->status
        );
        self::assertSame(
            AnalysisClaim::Granted,
            $this->repository->claim(
                $this->installationId,
                'example-next-day-key',
                'd',
                $now,
                $expires,
                $now->setTimezone(new DateTimeZone('Asia/Tokyo'))->modify('-1 day')->format('Ymd')
            )->status
        );
    }

    public function testCleanupUsesIndependentRetentionWindows(): void
    {
        $now = new DateTimeImmutable('now');
        $today = $now->setTimezone(new DateTimeZone('Asia/Tokyo'));
        $this->repository->recordSuccess($this->installationId, $today->modify('-6 days')->format('Ymd'));
        $this->repository->recordSuccess($this->installationId, $today->modify('-7 days')->format('Ymd'));
        $insert = $this->connection->prepare('INSERT INTO provider_calls
            (installation_id, recorded_at, model, input_tokens, cached_input_tokens, output_tokens)
            VALUES (?, ?, NULL, NULL, NULL, NULL)');
        foreach (['-36 days', '-34 days'] as $offset) {
            $insert->execute([$this->installationId, $now->modify($offset)->format('Y-m-d H:i:s.u')]);
        }
        $this->repository->purgeExpired($now);
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) FROM analysis_days')->fetchColumn());
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) FROM provider_calls')->fetchColumn());
    }

    public function testMonthlyOutputsPreviousJstMonthAndDeletesOnlyThoseCalls(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $insert = $this->connection->prepare('INSERT INTO provider_calls
            (installation_id, recorded_at, model, input_tokens, cached_input_tokens, output_tokens)
            VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([$now, $now->modify('first day of last month')->setTime(0, 0)] as $time) {
            $insert->execute([$this->installationId,
                $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'example-model', 100, 10, 20]);
        }
        [$status, $output] = $this->command(['monthly']);
        self::assertSame(0, $status);
        $summary = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $summary['models'][0]['provider_calls']);
        self::assertSame('100', $summary['models'][0]['input_tokens']);
        self::assertNull($summary['models'][0]['estimated_usd']);
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) FROM provider_calls')->fetchColumn());
        self::assertSame(0, $this->command(['monthly'])[0]);
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) FROM provider_calls')->fetchColumn());
    }

    public function testUnlockIsLimitedToOneDayWithinTheWindow(): void
    {
        $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $date = $today->format('Ymd');
        $this->repository->recordSuccess($this->installationId, $date);
        $this->repository->recordSuccess($this->installationId, $today->modify('-1 day')->format('Ymd'));
        self::assertSame(1, $this->command(['unlock', $this->supportId, $today->modify('-7 days')->format('Ymd')])[0]);
        self::assertSame(0, $this->command(['unlock', $this->supportId, $date])[0]);
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) FROM analysis_days')->fetchColumn());
    }

    public function testMonthlyOutputFailureDoesNotDeleteCalls(): void
    {
        $time = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))
            ->modify('first day of last month')->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
        $this->connection->prepare('INSERT INTO provider_calls
            (installation_id, recorded_at, model, input_tokens, cached_input_tokens, output_tokens)
            VALUES (?, ?, NULL, NULL, NULL, NULL)')
            ->execute([$this->installationId, $time->format('Y-m-d H:i:s.u')]);
        $process = proc_open(
            [PHP_BINARY, self::projectPath('bin/hosted-usage.php'), 'monthly'],
            [1 => ['file', '/dev/full', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::projectPath('')
        );
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) FROM provider_calls')->fetchColumn());
    }

    public function testUnlockRejectsActiveRequestsAndLeavesCostHistory(): void
    {
        $now = new DateTimeImmutable('now');
        $date = $now->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Ymd');
        $claim = $this->repository->claim(
            $this->installationId,
            'example-first-key',
            'a',
            $now,
            $now->modify('+30 minutes'),
            $date,
        );
        $this->repository->recordSuccess($this->installationId, $date);
        $this->repository->recordCall($this->installationId, [
            'model' => null, 'inputTokens' => null, 'cachedInputTokens' => null, 'outputTokens' => null,
        ]);
        self::assertSame(1, $this->command(['unlock', $this->supportId, $date])[0]);
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) FROM analysis_days')->fetchColumn());
        $this->repository->release($this->installationId, 'example-first-key', $claim->claimedAt);
        self::assertSame(0, $this->command(['unlock', $this->supportId, $date])[0]);
        self::assertSame(0, $this->connection->query('SELECT COUNT(*) FROM analysis_days')->fetchColumn());
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) FROM provider_calls')->fetchColumn());
    }

    public function testCostEstimateUsesOnlyKnownUsageAndKeepsUnknownCostsNull(): void
    {
        foreach ([100, null] as $inputTokens) {
            $this->repository->recordCall($this->installationId, [
                'model' => 'example-model', 'inputTokens' => $inputTokens,
                'cachedInputTokens' => $inputTokens === null ? null : 10,
                'outputTokens' => $inputTokens === null ? null : 20,
            ]);
        }
        $file = tempnam(sys_get_temp_dir(), 'example-prices-');
        file_put_contents($file, '{"example-model":{"input":1,"cached_input":0.1,"output":2}}');
        try {
            $process = proc_open(
                [PHP_BINARY, self::projectPath('bin/hosted-usage.php'), 'show', $this->supportId],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                self::projectPath(''),
                ['PROVIDER_PRICES_FILE' => $file]
            );
            $output = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            $group = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['models'][0];
            self::assertSame(2, $group['provider_calls']);
            self::assertSame('1', $group['unknown_usage_calls']);
            self::assertNull($group['estimated_usd']);
            self::assertEqualsWithDelta(0.000131, $group['known_usage_estimated_usd'], 0.000000001);
        } finally {
            unlink($file);
        }
    }

    /** @param list<string> $arguments @return array{int, string} */
    private function command(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, self::projectPath('bin/hosted-usage.php'), ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::projectPath('')
        );
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
