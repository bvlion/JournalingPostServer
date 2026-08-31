<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// DB接続に必要な設定だけを読み込んで検証する。
//
// HTTPアプリの入口（bootstrap/config.php）と、DBだけを必要とするCLI
// （bin/migrate.php・bin/prune-expired-analyses.php）の共通土台である。
// analysis / OpenAI設定はここでは検証しない。API keyの失効対応などで
// OPENAI_API_KEYを空にしても、5分間隔の削除Cronが起動不能になって解析結果
// 本文が保持期間（30分）を越えて残り続けることがないようにするためである。

// Hosted Serverはユーザーのtimezoneやrecurrenceを解釈せず、絶対時刻だけを扱う。
// そのため内部の時刻処理はUTCへ固定する。表示用のtimezone変換は端末側の責務で
// あり、Serverでは設定可能にしない。
date_default_timezone_set('UTC');

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$dotenv->required([
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
])->notEmpty();

return [
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'],
        'port' => $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'],
        'name' => $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'],
        'user' => $_ENV['DB_USER'] ?? $_SERVER['DB_USER'],
        'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'],
    ],
];
