<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration\Support;

use JournalingPostServer\Database\ConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * ローカル・CIのMySQLコンテナへ実際に接続するテストの共通基底クラス。
 * 接続先は`phpunit.xml`が設定する架空値の開発用データベースだけであり、
 * 本番データベースへは接続しない。
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static function createConnection(): PDO
    {
        $configuration = require self::projectPath('bootstrap/config.php');
        $databaseConfiguration = $configuration['database'];

        return (new ConnectionFactory(
            $databaseConfiguration['host'],
            $databaseConfiguration['port'],
            $databaseConfiguration['name'],
            $databaseConfiguration['user'],
            $databaseConfiguration['password'],
        ))->create();
    }

    protected static function projectPath(string $relativePath): string
    {
        return dirname(__DIR__, 3) . '/' . $relativePath;
    }
}
