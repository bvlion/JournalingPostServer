<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration;

use JournalingPostServer\Database\MigrationRunner;
use JournalingPostServer\Tests\Integration\Support\DatabaseTestCase;
use PDO;
use RuntimeException;

final class MigrationRunnerTest extends DatabaseTestCase
{
    public function testPendingMigrationIsAppliedOnceAndRecorded(): void
    {
        $connection = self::createConnection();
        $tableName = 'migration_runner_test_' . bin2hex(random_bytes(8));
        $version = '29991231235959_create_' . $tableName . '.sql';
        $migrationDirectory = $this->createMigrationDirectory(
            $version,
            sprintf(
                'CREATE TABLE %s (id TINYINT UNSIGNED NOT NULL PRIMARY KEY)'
                . ' ENGINE=InnoDB;',
                $tableName,
            ),
        );
        $runner = new MigrationRunner(
            $connection,
            self::projectPath('database/schema_migrations.sql'),
            $migrationDirectory,
        );

        try {
            self::assertSame([$version], $runner->run());
            self::assertTrue($this->tableExists($connection, $tableName));

            // 同じマイグレーションを再実行しても、適用済みとして読み飛ばす。
            self::assertSame([], $runner->run());
            self::assertSame(
                1,
                (int) $connection
                    ->query(
                        'SELECT COUNT(*) FROM schema_migrations'
                        . " WHERE version = '" . $version . "'",
                    )
                    ->fetchColumn(),
            );
        } finally {
            $connection->exec(
                sprintf('DROP TABLE IF EXISTS %s', $tableName),
            );
            $connection
                ->prepare(
                    'DELETE FROM schema_migrations WHERE version = :version',
                )
                ->execute(['version' => $version]);
            unlink($migrationDirectory . '/' . $version);
            rmdir($migrationDirectory);
        }
    }

    public function testRepositoryMigrationsAreAppliedAndRerunnable(): void
    {
        $connection = self::createConnection();
        $runner = new MigrationRunner(
            $connection,
            self::projectPath('database/schema_migrations.sql'),
            self::projectPath('database/migrations'),
        );

        // `composer migrate`済み・未実行のどちらから開始しても、
        // 2回目以降の実行は必ず適用対象なしになる。
        $runner->run();

        self::assertSame([], $runner->run());
        self::assertTrue($this->tableExists($connection, 'schema_migrations'));
        self::assertTrue($this->tableExists($connection, 'migration_check'));
    }

    private function createMigrationDirectory(
        string $version,
        string $sql,
    ): string {
        $migrationDirectory = sys_get_temp_dir()
            . '/journaling-post-server-migrations-'
            . bin2hex(random_bytes(8));

        if (!mkdir($migrationDirectory) && !is_dir($migrationDirectory)) {
            throw new RuntimeException(
                'テスト用マイグレーションディレクトリを作成できませんでした。',
            );
        }

        file_put_contents($migrationDirectory . '/' . $version, $sql);

        return $migrationDirectory;
    }

    private function tableExists(PDO $connection, string $tableName): bool
    {
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = :table_name',
        );
        $statement->execute(['table_name' => $tableName]);

        return (int) $statement->fetchColumn() > 0;
    }
}
