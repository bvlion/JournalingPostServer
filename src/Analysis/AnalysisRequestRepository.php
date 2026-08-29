<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use Closure;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * 解析requestのidempotency metadataと、結果の引き渡しバッファを扱う。
 *
 * 保持するのはinstallation識別子・Idempotency-Key・requestのhash・時刻だけで、
 * JournalEntry本文は保持しない。解析結果本文は`analysis_deliveries`へ分離し、
 * 保持期間を過ぎた`analysis_requests`の行と一緒に（ON DELETE CASCADE）消える。
 */
final class AnalysisRequestRepository
{
    private const DUPLICATE_KEY_SQL_STATE = '23000';

    private const MAX_CLAIM_ATTEMPTS = 2;

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private Closure $connection)
    {
    }

    /**
     * 保持期間を過ぎたidempotency metadataと引き渡しバッファを削除する。
     *
     * 解析requestの度に実行する。Cronを増やさずに保持期間の上限を守るためで、
     * Issue #3のCronへ依存しない。
     */
    public function purgeExpired(DateTimeImmutable $now): void
    {
        ($this->connection)()
            ->prepare(
                'DELETE FROM analysis_requests WHERE expires_at <= :now',
            )
            ->execute(['now' => self::formatTimestamp($now)]);
    }

    /**
     * AI呼び出し権を取得する。
     *
     * @param DateTimeImmutable $abandonedBefore この時刻より前に開始したまま
     *        完了していないrequestは、処理が中断したものとみなして引き継ぐ。
     */
    public function claim(
        string $installationId,
        string $idempotencyKey,
        string $fingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $abandonedBefore,
    ): AnalysisClaim {
        // 既存行の検出と読み出しの間で、別requestのpurgeによりその行が消えると
        // どちらの判定もできない。その場合だけ取得をやり直す。
        for ($attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; $attempt++) {
            $claim = $this->attemptClaim(
                $installationId,
                $idempotencyKey,
                $fingerprint,
                $now,
                $expiresAt,
                $abandonedBefore,
            );

            if ($claim !== null) {
                return $claim;
            }
        }

        // 取得できたか判定できないまま解析へ進むと重複課金になり得るため、
        // 再送を促す側へ倒す。
        return AnalysisClaim::InProgress;
    }

    private function attemptClaim(
        string $installationId,
        string $idempotencyKey,
        string $fingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $abandonedBefore,
    ): ?AnalysisClaim {
        $connection = ($this->connection)();

        try {
            $connection
                ->prepare(
                    'INSERT INTO analysis_requests
                        (installation_id, idempotency_key, request_fingerprint,
                         started_at, expires_at)
                     VALUES (:installation_id, :idempotency_key, :fingerprint,
                             :now, :expires_at)',
                )
                ->execute([
                    'installation_id' => $installationId,
                    'idempotency_key' => $idempotencyKey,
                    'fingerprint' => $fingerprint,
                    'now' => self::formatTimestamp($now),
                    'expires_at' => self::formatTimestamp($expiresAt),
                ]);

            return AnalysisClaim::Granted;
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::DUPLICATE_KEY_SQL_STATE) {
                throw $exception;
            }
        }

        $statement = $connection->prepare(
            'SELECT request_fingerprint, completed_at
             FROM analysis_requests
             WHERE installation_id = :installation_id
               AND idempotency_key = :idempotency_key',
        );
        $statement->execute([
            'installation_id' => $installationId,
            'idempotency_key' => $idempotencyKey,
        ]);
        $existing = $statement->fetch();

        if ($existing === false) {
            // 直前にpurgeまたはcascade削除された。呼び出し元がやり直す。
            return null;
        }

        if (!hash_equals($existing['request_fingerprint'], $fingerprint)) {
            return AnalysisClaim::KeyReuse;
        }

        if ($existing['completed_at'] !== null) {
            return AnalysisClaim::Completed;
        }

        $takeover = $connection->prepare(
            'UPDATE analysis_requests
             SET started_at = :now
             WHERE installation_id = :installation_id
               AND idempotency_key = :idempotency_key
               AND completed_at IS NULL
               AND started_at <= :abandoned_before',
        );
        $takeover->execute([
            'installation_id' => $installationId,
            'idempotency_key' => $idempotencyKey,
            'now' => self::formatTimestamp($now),
            'abandoned_before' => self::formatTimestamp($abandonedBefore),
        ]);

        return $takeover->rowCount() > 0
            ? AnalysisClaim::Granted
            : AnalysisClaim::InProgress;
    }

    /**
     * 解析の完了を記録し、responseを引き渡しバッファへ入れる。
     *
     * `$responseBody`は返却するJSONそのものである。再送に対して同じbyte列を
     * 返すため、整形し直さずに保存する。
     */
    public function complete(
        string $installationId,
        string $idempotencyKey,
        string $responseBody,
        DateTimeImmutable $now,
    ): void {
        $connection = ($this->connection)();
        $connection->beginTransaction();

        try {
            $connection
                ->prepare(
                    'INSERT INTO analysis_deliveries
                        (installation_id, idempotency_key, response_body)
                     VALUES (:installation_id, :idempotency_key, :response_body)
                     ON DUPLICATE KEY UPDATE response_body = VALUES(response_body)',
                )
                ->execute([
                    'installation_id' => $installationId,
                    'idempotency_key' => $idempotencyKey,
                    'response_body' => $responseBody,
                ]);
            $connection
                ->prepare(
                    'UPDATE analysis_requests
                     SET completed_at = :now
                     WHERE installation_id = :installation_id
                       AND idempotency_key = :idempotency_key',
                )
                ->execute([
                    'installation_id' => $installationId,
                    'idempotency_key' => $idempotencyKey,
                    'now' => self::formatTimestamp($now),
                ]);
            $connection->commit();
        } catch (PDOException $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * 解析が完了しなかったrequestのAI呼び出し権を解放し、同じIdempotency-Keyでの
     * 再送をそのまま再実行できるようにする。
     *
     * #2の時点ではAI providerを呼ばないため、解放しても課金は発生しない。
     * Issue #4でAI呼び出し後に失敗する経路が生まれた場合は、課金済みかどうかで
     * 解放してよいかが変わるため、そこで判断する。
     */
    public function release(
        string $installationId,
        string $idempotencyKey,
    ): void {
        ($this->connection)()
            ->prepare(
                'DELETE FROM analysis_requests
                 WHERE installation_id = :installation_id
                   AND idempotency_key = :idempotency_key
                   AND completed_at IS NULL',
            )
            ->execute([
                'installation_id' => $installationId,
                'idempotency_key' => $idempotencyKey,
            ]);
    }

    public function findDelivery(
        string $installationId,
        string $idempotencyKey,
    ): ?string {
        $statement = ($this->connection)()->prepare(
            'SELECT response_body
             FROM analysis_deliveries
             WHERE installation_id = :installation_id
               AND idempotency_key = :idempotency_key',
        );
        $statement->execute([
            'installation_id' => $installationId,
            'idempotency_key' => $idempotencyKey,
        ]);
        $responseBody = $statement->fetchColumn();

        return is_string($responseBody) ? $responseBody : null;
    }

    private static function formatTimestamp(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s.u');
    }
}
