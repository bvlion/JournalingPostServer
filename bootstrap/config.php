<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$requiredEnvironmentVariables = [
    'APP_TIMEZONE',
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
];

$dotenv->required($requiredEnvironmentVariables)->notEmpty();

return [
    'app' => [
        'timezone' => $_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'],
    ],
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'],
        'port' => $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'],
        'name' => $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'],
        'user' => $_ENV['DB_USER'] ?? $_SERVER['DB_USER'],
        'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'],
    ],
];
