<?php

declare(strict_types=1);

namespace JournalingPostServer\Installation;

use Closure;
use DateTimeImmutable;
use PDO;

/**
 * 匿名installationの登録と認証。
 *
 * 保持するのはServer内部のinstallation識別子、API keyのhash、作成日時だけである。
 * 名前・メールアドレス・profile・timezoneは保持しない。
 *
 * installation識別子はクライアントへ返さない。Androidが送るのはAPI keyだけで、
 * 識別子を送る用途が無いためである。
 */
final class InstallationRepository
{
    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private Closure $connection)
    {
    }

    /**
     * installationを登録し、発行したAPI keyの平文を返す。
     *
     * Serverはhashしか保存しないため、平文を返せるのは登録時だけである。
     */
    public function register(DateTimeImmutable $now): string
    {
        $apiKey = ApiKey::generate();

        ($this->connection)()
            ->prepare(
                'INSERT INTO installations (id, api_key_hash, created_at)
                 VALUES (:id, :api_key_hash, :created_at)',
            )
            ->execute([
                'id' => self::generateId(),
                'api_key_hash' => ApiKey::hash($apiKey),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
            ]);

        return $apiKey;
    }

    /**
     * API keyに対応するinstallation識別子を返す。該当しない場合はnullを返す。
     */
    public function authenticate(string $apiKey): ?string
    {
        $statement = ($this->connection)()->prepare(
            'SELECT id FROM installations WHERE api_key_hash = :api_key_hash',
        );
        $statement->execute(['api_key_hash' => ApiKey::hash($apiKey)]);
        $installationId = $statement->fetchColumn();

        return is_string($installationId) ? $installationId : null;
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
}
