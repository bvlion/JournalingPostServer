<?php

declare(strict_types=1);

namespace JournalingPostServer\Database;

use PDO;
use RuntimeException;

/**
 * `database/migrations`のSQLファイルを、ファイル名の昇順で未適用のものだけ
 * 適用する。適用済みファイル名は`schema_migrations`へ記録するため、同じ
 * コマンドを何度実行しても結果は変わらない。
 */
final class MigrationRunner
{
    public function __construct(
        private PDO $connection,
        private string $metadataFile,
        private string $migrationDirectory,
    ) {
    }

    /**
     * @return list<string> 今回適用したマイグレーションのファイル名
     */
    public function run(): array
    {
        $this->connection->exec($this->read($this->metadataFile));

        $migrationFiles = glob($this->migrationDirectory . '/*.sql');

        if ($migrationFiles === false) {
            throw new RuntimeException('Migration files could not be read.');
        }

        sort($migrationFiles);

        $migrationStatusStatement = $this->connection->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE version = :version',
        );
        $migrationRecordStatement = $this->connection->prepare(
            'INSERT INTO schema_migrations (version) VALUES (:version)',
        );
        $appliedMigrations = [];

        foreach ($migrationFiles as $migrationFile) {
            $migrationVersion = basename($migrationFile);
            $migrationStatusStatement->execute(
                ['version' => $migrationVersion],
            );

            if ((int) $migrationStatusStatement->fetchColumn() > 0) {
                continue;
            }

            $this->connection->exec($this->read($migrationFile));
            $migrationRecordStatement->execute(
                ['version' => $migrationVersion],
            );
            $appliedMigrations[] = $migrationVersion;
        }

        return $appliedMigrations;
    }

    private function read(string $file): string
    {
        $sql = file_get_contents($file);

        if ($sql === false) {
            throw new RuntimeException(
                sprintf('SQL file could not be read: %s', basename($file)),
            );
        }

        return $sql;
    }
}
