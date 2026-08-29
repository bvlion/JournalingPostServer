<?php

declare(strict_types=1);

namespace JournalingPostServer\Installation;

use Closure;
use DateTimeImmutable;
use PDO;

/**
 * 匿名installationの登録と認証。
 *
 * 保持するのはinstallation識別子、API keyのhash、作成日時、最終利用日時だけで、
 * 名前・メールアドレス・profile・timezoneは保持しない。
 */
final class InstallationRepository
{
    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private Closure $connection)
    {
    }

    public function register(DateTimeImmutable $now): IssuedInstallation
    {
        $installation = new IssuedInstallation(
            self::generateId(),
            ApiKey::generate(),
        );

        ($this->connection)()
            ->prepare(
                // PDOのemulate preparesを無効にしているため、同じ名前付き
                // パラメータを1つの文で二度使えない。
                'INSERT INTO installations
                    (id, api_key_hash, created_at, last_used_at)
                 VALUES (:id, :api_key_hash, :created_at, :last_used_at)',
            )
            ->execute([
                'id' => $installation->id,
                'api_key_hash' => ApiKey::hash($installation->apiKey),
                'created_at' => self::formatTimestamp($now),
                'last_used_at' => self::formatTimestamp($now),
            ]);

        return $installation;
    }

    /**
     * API keyに対応するinstallation識別子を返す。該当しない場合はnullを返す。
     */
    public function authenticate(
        string $apiKey,
        DateTimeImmutable $now,
    ): ?string {
        $connection = ($this->connection)();
        $statement = $connection->prepare(
            'SELECT id FROM installations WHERE api_key_hash = :api_key_hash',
        );
        $statement->execute(['api_key_hash' => ApiKey::hash($apiKey)]);
        $installationId = $statement->fetchColumn();

        if (!is_string($installationId)) {
            return null;
        }

        // 使われなくなったinstallationを判別できるようにするための最小情報。
        $connection
            ->prepare(
                'UPDATE installations SET last_used_at = :now WHERE id = :id',
            )
            ->execute([
                'id' => $installationId,
                'now' => self::formatTimestamp($now),
            ]);

        return $installationId;
    }

    private static function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }

    private static function formatTimestamp(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s.u');
    }
}
