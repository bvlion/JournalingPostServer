<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use JournalingPostServer\Analysis\AnalysisClaim;
use JournalingPostServer\Analysis\AnalysisRequest;
use JournalingPostServer\Analysis\AnalysisRequestParser;
use JournalingPostServer\Analysis\AnalysisRequestRepository;
use JournalingPostServer\Analysis\Analyzer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * `POST /v1/analyses`
 *
 * 対象期間のJournalEntryを受け取り、AI解析結果を同じHTTP応答で返す。
 * 契約の詳細は`docs/hosted-analysis-api.md`にまとめている。
 *
 * JournalEntry本文はこのrequestの処理中だけメモリ上に存在し、DBへ保存しない。
 * 解析結果本文は再送へ同じ結果を返すための引き渡しバッファにだけ、保持期間の間
 * 残る（`analysis_deliveries`）。
 */
final class CreateAnalysisAction
{
    /**
     * idempotency metadataと解析結果の引き渡しバッファの保持期間。
     *
     * 解析完了後はこの時間で失効し、以降は同じIdempotency-Keyの再送へ結果を
     * 返さない。network timeoutからの再送はこの範囲で起きる想定である。
     * 長くするほど解析結果本文がServer上に残る時間が延びるため、必要最小限に
     * している。
     *
     * 完了しなかったrequestも、取得時刻からこの時間で失効する。失効するまでは
     * 同じkeyへ新しいAI呼び出し権を与えない。
     */
    public const RETENTION_SECONDS = 1800;

    private const RETRY_AFTER_IN_PROGRESS_SECONDS = 15;

    /** requestのbody上限。実際にはentry数とnote長の上限が先に効く。 */
    private const MAX_BODY_BYTES = 1048576;

    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9_-]{16,64}\z/';

    private const RESPONSE_TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** JSONのrootがobjectか。有効なJSONではこの1文字でroot typeが決まる。 */
    private const JSON_OBJECT_PATTERN = '/\A\s*\{/';

    public function __construct(
        private AnalysisRequestRepository $analysisRequests,
        private Analyzer $analyzer,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $installationId = (string) $request->getAttribute(
            AuthenticationMiddleware::INSTALLATION_ID_ATTRIBUTE,
        );
        $idempotencyKey = self::readIdempotencyKey($request);
        $analysisRequest = self::readAnalysisRequest($request);

        $now = new DateTimeImmutable('now');
        // 失効した本文を返さないよう、判定の前に削除する。requestが来ない
        // 期間の削除はXServer Cron（bin/prune-expired-analyses.php）が行う。
        $this->analysisRequests->purgeExpired($now);

        $claim = $this->analysisRequests->claim(
            $installationId,
            $idempotencyKey,
            $analysisRequest->fingerprint(),
            $now,
            self::expiry($now),
        );

        if ($claim === AnalysisClaim::KeyReuse) {
            throw new ApiException(
                409,
                'idempotency_key_reuse',
                'The idempotency key was already used for a different request.',
            );
        }

        if ($claim === AnalysisClaim::InProgress) {
            throw new ApiException(
                409,
                'analysis_in_progress',
                'An analysis for this idempotency key is still running.',
                headers: [
                    'Retry-After' => (string) self::RETRY_AFTER_IN_PROGRESS_SECONDS,
                ],
            );
        }

        if ($claim === AnalysisClaim::Completed) {
            return JsonResponse::writeBody(
                $response,
                $this->readDelivery($installationId, $idempotencyKey),
            );
        }

        $responseBody = $this->analyze(
            $installationId,
            $idempotencyKey,
            $analysisRequest,
            $now,
        );

        return JsonResponse::writeBody($response, $responseBody);
    }

    /**
     * AI解析を実行し、結果を引き渡しバッファへ入れてからresponse bodyを返す。
     */
    private function analyze(
        string $installationId,
        string $idempotencyKey,
        AnalysisRequest $analysisRequest,
        DateTimeImmutable $claimedAt,
    ): string {
        try {
            $analysis = $this->analyzer->analyze($analysisRequest);
        } catch (Throwable $exception) {
            // 解析できなかったIdempotency-Keyを占有したままにしない。
            $this->analysisRequests->release(
                $installationId,
                $idempotencyKey,
                $claimedAt,
            );

            throw $exception;
        }

        $responseBody = JsonResponse::encode([
            'analysis' => [
                'period' => [
                    'start' => self::formatTimestamp(
                        $analysisRequest->periodStart,
                    ),
                    'end' => self::formatTimestamp($analysisRequest->periodEnd),
                ],
                'analyzedAt' => self::formatTimestamp($analysis->analyzedAt),
                'entryCount' => count($analysisRequest->entries),
                'model' => $analysis->model,
                'text' => $analysis->text,
            ],
        ]);

        // 保持期間の起点は解析完了時に揃える。
        $completedAt = new DateTimeImmutable('now');
        // 取得したclaimが保持期間切れで削除された後だと記録されない（false）。
        // 同じIdempotency-Keyの新しいclaimを完了扱いにしたり、その結果を上書き
        // したりしないためである。その場合でもこのbodyは今処理しているrequest
        // 自身の結果なので、呼び出し元へはそのまま返す。再送に対する引き渡し
        // バッファが無いだけである。
        $this->analysisRequests->complete(
            $installationId,
            $idempotencyKey,
            $responseBody,
            $claimedAt,
            $completedAt,
            self::expiry($completedAt),
        );

        return $responseBody;
    }

    private static function expiry(DateTimeImmutable $from): DateTimeImmutable
    {
        return $from->modify(sprintf('+%d seconds', self::RETENTION_SECONDS));
    }

    private function readDelivery(
        string $installationId,
        string $idempotencyKey,
    ): string {
        $responseBody = $this->analysisRequests->findDelivery(
            $installationId,
            $idempotencyKey,
        );

        if ($responseBody === null) {
            // 完了記録は残っているのに結果が無い状態。保持期間の削除は
            // 両方まとめて行うため通常は起こらないが、起きた場合は結果を
            // 作り直せないことをAndroidへ伝える。
            throw new ApiException(
                409,
                'analysis_result_unavailable',
                'The analysis completed but its result can no longer be returned.',
            );
        }

        return $responseBody;
    }

    private static function readIdempotencyKey(
        ServerRequestInterface $request,
    ): string {
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));

        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new ApiException(
                400,
                'invalid_request',
                'The Idempotency-Key header is required and must be 16 to 64 '
                    . 'characters of [A-Za-z0-9_-].',
            );
        }

        return $idempotencyKey;
    }

    private static function readAnalysisRequest(
        ServerRequestInterface $request,
    ): AnalysisRequest {
        if (!self::isJsonRequest($request)) {
            throw new ApiException(
                415,
                'unsupported_media_type',
                'The request must use Content-Type: application/json.',
            );
        }

        $body = (string) $request->getBody();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new ApiException(
                413,
                'payload_too_large',
                sprintf(
                    'The request body must not exceed %d bytes.',
                    self::MAX_BODY_BYTES,
                ),
            );
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // 解析できなかったbodyは応答へ含めない（JournalEntry本文を含むため）。
            throw new ApiException(
                400,
                'invalid_request',
                'The request body must be valid JSON.',
            );
        }

        // `json_decode(..., true)`では`{}`と`[]`がどちらも空配列になり、
        // rootがobjectだったかを復元できない。契約ではobject以外のrootが
        // `400 invalid_request`、objectの中身の違反が`422 validation_error`で
        // 区別されるため、root typeはdecode前のJSON表記で判定する。
        if (
            !is_array($payload)
            || preg_match(self::JSON_OBJECT_PATTERN, $body) !== 1
        ) {
            throw new ApiException(
                400,
                'invalid_request',
                'The request body must be a JSON object.',
            );
        }

        return AnalysisRequestParser::parse($payload);
    }

    private static function isJsonRequest(
        ServerRequestInterface $request,
    ): bool {
        $mediaType = trim(
            explode(';', $request->getHeaderLine('Content-Type'), 2)[0],
        );

        return strtolower($mediaType) === 'application/json';
    }

    private static function formatTimestamp(DateTimeInterface $moment): string
    {
        return $moment->format(self::RESPONSE_TIMESTAMP_FORMAT);
    }
}
