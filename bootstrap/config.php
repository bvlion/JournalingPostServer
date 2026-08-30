<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// Hosted Serverはユーザーのtimezoneやrecurrenceを解釈せず、絶対時刻だけを扱う。
// そのため内部の時刻処理はUTCへ固定する。表示用のtimezone変換は端末側の責務で
// あり、Serverでは設定可能にしない。
date_default_timezone_set('UTC');

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$requiredEnvironmentVariables = [
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
    'ANALYSIS_FINGERPRINT_SECRET',
];

$dotenv->required($requiredEnvironmentVariables)->notEmpty();

$fingerprintSecret = $_ENV['ANALYSIS_FINGERPRINT_SECRET']
    ?? $_SERVER['ANALYSIS_FINGERPRINT_SECRET'];

// 短い値では鍵付きhashの意味がなくなるため、最低長を課す。値そのものは
// 例外メッセージへ含めない。
if (strlen($fingerprintSecret) < 32) {
    throw new \RuntimeException(
        'ANALYSIS_FINGERPRINT_SECRET must be at least 32 characters.',
    );
}

return [
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'],
        'port' => $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'],
        'name' => $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'],
        'user' => $_ENV['DB_USER'] ?? $_SERVER['DB_USER'],
        'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'],
    ],
    'analysis' => [
        // 解析requestのfingerprintを鍵付きにするための秘密値。本番deployを
        // 跨いで同じ値を使う。値が変わると、保持期間内の再送が別内容と判定
        // されて`409 idempotency_key_reuse`になる。
        'fingerprintSecret' => $fingerprintSecret,
    ],
];
