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
     * network timeoutからの再送はこの範囲で起きる想定である。長くするほど
     * 解析結果本文がServer上に残る時間が延びるため、必要最小限にしている。
     */
    public const RETENTION_SECONDS = 1800;

    /**
     * 同期処理1回に許す上限。これを超えて完了しない解析は、処理が中断した
     * ものとみなして同じIdempotency-Keyでの再送が引き継ぐ。
     */
    public const PROCESSING_TIMEOUT_SECONDS = 120;

    private const RETRY_AFTER_IN_PROGRESS_SECONDS = 15;

    /** requestのbody上限。実際にはentry数とnote長の上限が先に効く。 */
    private const MAX_BODY_BYTES = 1048576;

    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9_-]{16,64}\z/';

    private const RESPONSE_TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

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
        $this->analysisRequests->purgeExpired($now);

        $claim = $this->analysisRequests->claim(
            $installationId,
            $idempotencyKey,
            $analysisRequest->fingerprint(),
            $now,
            $now->modify(sprintf('+%d seconds', self::RETENTION_SECONDS)),
            $now->modify(
                sprintf('-%d seconds', self::PROCESSING_TIMEOUT_SECONDS),
            ),
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
    ): string {
        try {
            $analysis = $this->analyzer->analyze($analysisRequest);
        } catch (Throwable $exception) {
            // 解析できなかったIdempotency-Keyを占有したままにしない。
            $this->analysisRequests->release($installationId, $idempotencyKey);

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

        $this->analysisRequests->complete(
            $installationId,
            $idempotencyKey,
            $responseBody,
            new DateTimeImmutable('now'),
        );

        return $responseBody;
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

        // 空のJSON object（`{}`）はPHPで空配列になりlistと区別できない。
        // 契約違反として422で返すため、ここでは通す。
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
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
