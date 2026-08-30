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
];

$dotenv->required($requiredEnvironmentVariables)->notEmpty();

return [
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'],
        'port' => $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'],
        'name' => $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'],
        'user' => $_ENV['DB_USER'] ?? $_SERVER['DB_USER'],
        'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'],
    ],
];
