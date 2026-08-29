<?php

declare(strict_types=1);

namespace JournalingPostServer\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Pdo\Mysql;
use RuntimeException;

final class ConnectionFactory
{
    public function __construct(
        private string $host,
        private string $port,
        private string $databaseName,
        private string $username,
        private string $password,
        private DateTimeZone $timeZone,
    ) {
    }

    public function create(): PDO
    {
        $dataSourceName = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->databaseName,
        );

        try {
            return new PDO(
                $dataSourceName,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    Mysql::ATTR_INIT_COMMAND => sprintf(
                        "SET time_zone = '%s'",
                        $this->sessionTimeZoneOffset(),
                    ),
                ],
            );
        } catch (PDOException) {
            // 例外メッセージへ接続情報が混ざらないよう、元の例外は連鎖させない。
            throw new RuntimeException('Database connection failed.');
        }
    }

    /**
     * MySQLのセッションタイムゾーンへ渡す`+00:00`形式のオフセットを返す。
     *
     * 名前付きタイムゾーンはMySQLのタイムゾーンテーブル導入が前提となるため、
     * XServerの本番MySQLでも確実に解釈できるオフセット表記を使用する。
     */
    private function sessionTimeZoneOffset(): string
    {
        return (new DateTimeImmutable('now', $this->timeZone))->format('P');
    }
}
