<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// HTTPアプリの入口用の完全な設定。
//
// DB接続設定は database-config.php で読み込み・検証する（CLIと共通）。この
// ファイルはそれに加えて、HTTPアプリだけが必要とするanalysis / OpenAI設定を
// 必須検証して analysis キーを足す。DBだけを必要とするCLIはこのファイルを
// 経由せず database-config.php を直接使う。

$configuration = require __DIR__ . '/database-config.php';

// analysis / OpenAI設定はHTTPアプリ専用。ここでだけ必須検証する。
Dotenv::createImmutable(dirname(__DIR__))->required([
    'ANALYSIS_FINGERPRINT_SECRET',
    'OPENAI_API_KEY',
    'OPENAI_TIMEOUT_SECONDS',
])->notEmpty();

$fingerprintSecret = $_ENV['ANALYSIS_FINGERPRINT_SECRET']
    ?? $_SERVER['ANALYSIS_FINGERPRINT_SECRET'];

// 短い値では鍵付きhashの意味がなくなるため、最低長を課す。値そのものは
// 例外メッセージへ含めない。
if (strlen($fingerprintSecret) < 32) {
    throw new \RuntimeException(
        'ANALYSIS_FINGERPRINT_SECRET must be at least 32 characters.',
    );
}

$openAiApiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'];

// OpenAI呼び出しのtimeout秒数は設定可能にする。productionで使う値は実
// provider / XServerでの実測から決定する（`docs/hosted-analysis-api.md`）。
// 正の整数以外は起動を失敗させる。値そのものは秘密ではないが、扱いを他の
// 設定値とそろえる。
$openAiTimeoutSeconds = $_ENV['OPENAI_TIMEOUT_SECONDS']
    ?? $_SERVER['OPENAI_TIMEOUT_SECONDS'];

if (preg_match('/\A[1-9][0-9]*\z/', (string) $openAiTimeoutSeconds) !== 1) {
    throw new \RuntimeException(
        'OPENAI_TIMEOUT_SECONDS must be a positive integer number of seconds.',
    );
}

$configuration['analysis'] = [
    // 解析requestのfingerprintを鍵付きにするための秘密値。本番deployを跨いで
    // 同じ値を使う。値が変わると、保持期間内の再送が別内容と判定されて
    // `409 idempotency_key_reuse`になる。
    'fingerprintSecret' => $fingerprintSecret,
    // OpenAI Responses APIの認証情報。実値をrepository・response・通常ログ・
    // 例外メッセージへ出さない。
    'openAiApiKey' => $openAiApiKey,
    'openAiTimeoutSeconds' => (int) $openAiTimeoutSeconds,
];

return $configuration;
