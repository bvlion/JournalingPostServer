<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use Closure;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * 解析requestのidempotency metadataと、解析結果の引き渡しバッファを扱う。
 *
 * metadataとして保持するのはinstallation識別子・Idempotency-Key・requestのhash・
 * 時刻だけで、JournalEntry本文は保持しない。解析結果本文は
 * `analysis_deliveries`へ分離し、失効した`analysis_requests`の行と一緒に
 * （ON DELETE CASCADE）削除される。
 */
final class AnalysisRequestRepository
{
    private const DUPLICATE_KEY_SQL_STATE = '23000';

    /**
     * 取得をやり直す理由は「行が消えていた」「行が失効していた」の2つある。
     * 両方が1回ずつ起きても判定できる回数にする。
     */
    private const MAX_CLAIM_ATTEMPTS = 3;

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private Closure $connection)
    {
    }

    /**
     * 失効したidempotency metadataと引き渡しバッファを削除し、削除件数を返す。
     *
     * 解析requestの度と、`bin/prune-expired-analyses.php`（XServer Cron）から
     * 呼ぶ。requestが来なくなっても、失効した解析結果本文がDBへ残り続けない
     * ようにするため、両方が必要である。
     */
    public function purgeExpired(DateTimeImmutable $now): int
    {
        $statement = ($this->connection)()->prepare(
            'DELETE FROM analysis_requests WHERE expires_at <= :now',
        );
        $statement->execute(['now' => self::formatTimestamp($now)]);

        return $statement->rowCount();
    }

    /**
     * AI呼び出し権を取得する。
     *
     * 完了していない同じkeyのrequestが残っている間は`InProgress`を返し、
     * 新しいAI呼び出し権を与えない。経過時間だけを根拠に引き継ぐと、前の処理が
     * 動き続けている場合に同じ解析を二重にAIへ投げるためである。
     *
     * 前の処理がresponseを返さずに終わった場合、そのkeyは`expires_at`まで
     * 使えない。AI provider側のtimeout特性を踏まえた復帰の制御は、実providerを
     * 実装するIssue #4で判断する。
     */
    public function claim(
        string $installationId,
        string $idempotencyKey,
        string $fingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): AnalysisClaim {
        // 既存行が消えていた場合と、読み出した行が既に失効していた場合は
        // どちらとも判定できない。その場合だけ取得をやり直す。
        for ($attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; $attempt++) {
            $claim = $this->attemptClaim(
                $installationId,
                $idempotencyKey,
                $fingerprint,
                $now,
                $expiresAt,
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
    ): ?AnalysisClaim {
        $connection = ($this->connection)();

        try {
            $connection
                ->prepare(
                    'INSERT INTO analysis_requests
                        (installation_id, idempotency_key, request_fingerprint,
                         started_at, expires_at)
                     VALUES (:installation_id, :idempotency_key, :fingerprint,
                             :started_at, :expires_at)',
                )
                ->execute([
                    'installation_id' => $installationId,
                    'idempotency_key' => $idempotencyKey,
                    'fingerprint' => $fingerprint,
                    'started_at' => self::formatTimestamp($now),
                    'expires_at' => self::formatTimestamp($expiresAt),
                ]);

            return AnalysisClaim::Granted;
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::DUPLICATE_KEY_SQL_STATE) {
                throw $exception;
            }
        }

        $statement = $connection->prepare(
            'SELECT request_fingerprint, completed_at, expires_at
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

        // `purgeExpired()`とこの読み出しは別の操作であり、その間にこの行が
        // 失効し得る。失効した完了記録やバッファをここで返さないよう、判定の
        // 時点でも確認する。この判定は`purgeExpired()`が動いたかに依存しない。
        if (new DateTimeImmutable($existing['expires_at']) <= $now) {
            $this->discardExpired(
                $connection,
                $installationId,
                $idempotencyKey,
                $now,
            );

            // 失効した行は消えた。呼び出し元がやり直し、新しいrequestとして
            // 取得する。
            return null;
        }

        if (!hash_equals($existing['request_fingerprint'], $fingerprint)) {
            return AnalysisClaim::KeyReuse;
        }

        return $existing['completed_at'] !== null
            ? AnalysisClaim::Completed
            : AnalysisClaim::InProgress;
    }

    /**
     * 失効した1件の行を削除する。`analysis_deliveries`の本文も
     * `ON DELETE CASCADE`で一緒に消える。
     *
     * 削除条件へ`expires_at`を含め、判定に使った時点で失効していた行だけを
     * 対象にする。別requestが同じkeyで新しく取得した行を消さないためである。
     */
    private function discardExpired(
        PDO $connection,
        string $installationId,
        string $idempotencyKey,
        DateTimeImmutable $now,
    ): void {
        $connection
            ->prepare(
                'DELETE FROM analysis_requests
                 WHERE installation_id = :installation_id
                   AND idempotency_key = :idempotency_key
                   AND expires_at <= :now',
            )
            ->execute([
                'installation_id' => $installationId,
                'idempotency_key' => $idempotencyKey,
                'now' => self::formatTimestamp($now),
            ]);
    }

    /**
     * 解析の完了を記録し、responseを引き渡しバッファへ入れる。
     *
     * `$claimedAt`で取得したclaimを自分が保持している場合だけ記録し、記録できた
     * かどうかを返す。claimが保持期間切れで削除され、同じIdempotency-Keyで
     * 新しいclaimが作られていた場合、遅れて終わった古い処理がそれを完了扱いに
     * したり、新しいrequestの結果を古い結果で上書きしたりしてはならないため、
     * 完了記録とバッファ書き込みの両方を同じ条件でfenceする。`release()`と
     * 同じ所有条件である。
     *
     * 保持期間の起点を解析完了時へ揃えるため、`expires_at`もここで引き直す。
     * 解析に時間がかかっても、結果本文の保持期間は完了時からの一定時間になる。
     *
     * `$responseBody`は返却するJSONそのものである。再送に対して同じbyte列を
     * 返すため、整形し直さずに保存する。
     */
    public function complete(
        string $installationId,
        string $idempotencyKey,
        string $responseBody,
        DateTimeImmutable $claimedAt,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): bool {
        $connection = ($this->connection)();
        $connection->beginTransaction();

        try {
            $completion = $connection->prepare(
                'UPDATE analysis_requests
                 SET completed_at = :completed_at, expires_at = :expires_at
                 WHERE installation_id = :installation_id
                   AND idempotency_key = :idempotency_key
                   AND started_at = :started_at
                   AND completed_at IS NULL',
            );
            $completion->execute([
                'installation_id' => $installationId,
                'idempotency_key' => $idempotencyKey,
                'started_at' => self::formatTimestamp($claimedAt),
                'completed_at' => self::formatTimestamp($now),
                'expires_at' => self::formatTimestamp($expiresAt),
            ]);

            if ($completion->rowCount() === 0) {
                // claimを保持していない。書き込みを一切行わずに戻す。
                $connection->rollBack();

                return false;
            }

            // 完了記録がある行にしかバッファは存在しないため、上のUPDATEが
            // 通った時点でこのINSERTが既存行と衝突することはない。
            $connection
                ->prepare(
                    'INSERT INTO analysis_deliveries
                        (installation_id, idempotency_key, response_body)
                     VALUES (:installation_id, :idempotency_key, :response_body)',
                )
                ->execute([
                    'installation_id' => $installationId,
                    'idempotency_key' => $idempotencyKey,
                    'response_body' => $responseBody,
                ]);
            $connection->commit();

            return true;
        } catch (PDOException $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * 解析が完了しなかったrequestのAI呼び出し権を解放し、同じIdempotency-Keyでの
     * 再送をそのまま再実行できるようにする。
     *
     * 自分が取得した行だけを対象にするため、取得時刻も条件に含める。前の処理が
     * 遅れて失敗したときに、別のrequestの取得を消さないためである。
     */
    public function release(
        string $installationId,
        string $idempotencyKey,
        DateTimeImmutable $claimedAt,
    ): void {
        ($this->connection)()
            ->prepare(
                'DELETE FROM analysis_requests
                 WHERE installation_id = :installation_id
                   AND idempotency_key = :idempotency_key
                   AND started_at = :started_at
                   AND completed_at IS NULL',
            )
            ->execute([
                'installation_id' => $installationId,
                'idempotency_key' => $idempotencyKey,
                'started_at' => self::formatTimestamp($claimedAt),
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
