<?php

declare(strict_types=1);

use JournalingPostServer\Database\ConnectionFactory;
use JournalingPostServer\Database\MigrationRunner;

require_once __DIR__ . '/../vendor/autoload.php';

$configuration = require __DIR__ . '/../bootstrap/config.php';
$databaseConfiguration = $configuration['database'];
$connection = (new ConnectionFactory(
    $databaseConfiguration['host'],
    $databaseConfiguration['port'],
    $databaseConfiguration['name'],
    $databaseConfiguration['user'],
    $databaseConfiguration['password'],
    new DateTimeZone($configuration['app']['timezone']),
))->create();

$appliedMigrations = (new MigrationRunner(
    $connection,
    __DIR__ . '/../database/schema_migrations.sql',
    __DIR__ . '/../database/migrations',
))->run();

foreach ($appliedMigrations as $appliedMigration) {
    fwrite(STDOUT, sprintf("Applied migration: %s\n", $appliedMigration));
}

if ($appliedMigrations === []) {
    fwrite(STDOUT, "No pending migrations.\n");
}
