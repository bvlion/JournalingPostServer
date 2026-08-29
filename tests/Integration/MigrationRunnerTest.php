<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration;

use JournalingPostServer\Database\MigrationRunner;
use JournalingPostServer\Tests\Integration\Support\DatabaseTestCase;
use PDO;
use RuntimeException;

/**
 * マイグレーション機構の検証は、この場限りの一時マイグレーションを生成して
 * 行う。動作確認だけを目的とした永続テーブルを`database/migrations`へ
 * 置かないためであり、生成したテーブルと`schema_migrations`の記録は
 * テスト終了時に必ず削除する。
 */
final class MigrationRunnerTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $createdTableNames = [];

    /** @var list<string> */
    private array $recordedVersions = [];

    /** @var list<string> */
    private array $createdDirectories = [];

    protected function tearDown(): void
    {
        $connection = self::createConnection();

        foreach ($this->createdTableNames as $tableName) {
            $connection->exec(sprintf('DROP TABLE IF EXISTS %s', $tableName));
        }

        $deleteStatement = $connection->prepare(
            'DELETE FROM schema_migrations WHERE version = :version',
        );

        foreach ($this->recordedVersions as $version) {
            $deleteStatement->execute(['version' => $version]);
        }

        foreach ($this->createdDirectories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }

        $this->createdTableNames = [];
        $this->recordedVersions = [];
        $this->createdDirectories = [];

        parent::tearDown();
    }

    public function testPendingMigrationIsAppliedOnceAndRecorded(): void
    {
        $connection = self::createConnection();
        $tableName = $this->reserveTableName();
        $version = $this->reserveVersion('29991231235959_create_' . $tableName);
        $migrationDirectory = $this->createMigrationDirectory([
            $version => $this->createTableSql($tableName),
        ]);
        $runner = $this->createRunner($connection, $migrationDirectory);

        self::assertSame([$version], $runner->run());
        self::assertTrue($this->tableExists($connection, $tableName));
        self::assertSame(1, $this->recordCount($connection, $version));

        // 同じマイグレーションを再実行しても、適用済みとして読み飛ばす。
        self::assertSame([], $runner->run());
        self::assertSame(1, $this->recordCount($connection, $version));
        self::assertTrue($this->tableExists($connection, $tableName));
    }

    public function testPendingMigrationsAreAppliedInFileNameOrder(): void
    {
        $connection = self::createConnection();
        $firstTableName = $this->reserveTableName();
        $secondTableName = $this->reserveTableName();
        $firstVersion = $this->reserveVersion(
            '29991231235957_create_' . $firstTableName,
        );
        $secondVersion = $this->reserveVersion(
            '29991231235958_create_' . $secondTableName,
        );
        // ファイル作成順ではなくファイル名順で適用されることを確認するため、
        // 意図的に後勝ちの版から先に書き出す。
        $migrationDirectory = $this->createMigrationDirectory([
            $secondVersion => $this->createTableSql($secondTableName),
            $firstVersion => $this->createTableSql($firstTableName),
        ]);
        $runner = $this->createRunner($connection, $migrationDirectory);

        self::assertSame(
            [$firstVersion, $secondVersion],
            $runner->run(),
        );
        self::assertTrue($this->tableExists($connection, $firstTableName));
        self::assertTrue($this->tableExists($connection, $secondTableName));
    }

    public function testRepositoryMigrationsAreRerunnable(): void
    {
        $connection = self::createConnection();
        $runner = $this->createRunner(
            $connection,
            self::projectPath('database/migrations'),
        );

        // `composer migrate`済み・未実行のどちらから開始しても、
        // 2回目以降の実行は必ず適用対象なしになる。
        $runner->run();

        self::assertSame([], $runner->run());
        self::assertTrue($this->tableExists($connection, 'schema_migrations'));
    }

    private function createRunner(
        PDO $connection,
        string $migrationDirectory,
    ): MigrationRunner {
        return new MigrationRunner(
            $connection,
            self::projectPath('database/schema_migrations.sql'),
            $migrationDirectory,
        );
    }

    private function reserveTableName(): string
    {
        $tableName = 'migration_runner_test_' . bin2hex(random_bytes(8));
        $this->createdTableNames[] = $tableName;

        return $tableName;
    }

    private function reserveVersion(string $version): string
    {
        $version .= '.sql';
        $this->recordedVersions[] = $version;

        return $version;
    }

    private function createTableSql(string $tableName): string
    {
        return sprintf(
            'CREATE TABLE %s (id TINYINT UNSIGNED NOT NULL PRIMARY KEY)'
            . ' ENGINE=InnoDB;',
            $tableName,
        );
    }

    /**
     * @param array<string, string> $migrations ファイル名 => SQL
     */
    private function createMigrationDirectory(array $migrations): string
    {
        $migrationDirectory = sys_get_temp_dir()
            . '/journaling-post-server-migrations-'
            . bin2hex(random_bytes(8));

        if (!mkdir($migrationDirectory) && !is_dir($migrationDirectory)) {
            throw new RuntimeException(
                'テスト用マイグレーションディレクトリを作成できませんでした。',
            );
        }

        $this->createdDirectories[] = $migrationDirectory;

        foreach ($migrations as $version => $sql) {
            file_put_contents($migrationDirectory . '/' . $version, $sql);
        }

        return $migrationDirectory;
    }

    private function recordCount(PDO $connection, string $version): int
    {
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE version = :version',
        );
        $statement->execute(['version' => $version]);

        return (int) $statement->fetchColumn();
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
