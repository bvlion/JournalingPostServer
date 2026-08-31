<?php

declare(strict_types=1);

namespace JournalingPostServer\Http;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use JournalingPostServer\Analysis\AnalysisClaim;
use JournalingPostServer\Analysis\AnalysisRequest;
use JournalingPostServer\Analysis\AnalysisRequestParser;
use JournalingPostServer\Analysis\AnalysisRequestRepository;
use JournalingPostServer\Analysis\AnalysisResultUnconfirmedException;
use JournalingPostServer\Analysis\Analyzer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
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
    public const MAX_BODY_BYTES = 1048576;

    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9_-]{16,64}\z/';

    private const RESPONSE_TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @param string $fingerprintSecret 解析requestのfingerprintを鍵付きに
     *        するための秘密値。DBを読める側が本文の候補を照合できないように
     *        するためのもので、本番deployを跨いで同じ値を使う。
     */
    public function __construct(
        private AnalysisRequestRepository $analysisRequests,
        private Analyzer $analyzer,
        private string $fingerprintSecret,
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
            $analysisRequest->fingerprint(
                $installationId,
                $this->fingerprintSecret,
            ),
            $now,
            self::expiry($now),
        );

        if ($claim->status === AnalysisClaim::KeyReuse) {
            throw new ApiException(
                409,
                'idempotency_key_reuse',
                'The idempotency key was already used for a different request.',
            );
        }

        if ($claim->status === AnalysisClaim::InProgress) {
            throw new ApiException(
                409,
                'analysis_in_progress',
                'An analysis for this idempotency key is still running.',
                headers: [
                    'Retry-After' => (string) self::RETRY_AFTER_IN_PROGRESS_SECONDS,
                ],
            );
        }

        if ($claim->status === AnalysisClaim::Completed) {
            return JsonResponse::writeBody(
                $response,
                $this->readDelivery(
                    $installationId,
                    $idempotencyKey,
                    $claim->claimedAt,
                ),
            );
        }

        $responseBody = $this->analyze(
            $installationId,
            $idempotencyKey,
            $analysisRequest,
            $claim->claimedAt,
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
        } catch (AnalysisResultUnconfirmedException $exception) {
            // OpenAIへ送信後、処理・課金済みかServerから確定できない失敗
            // （timeout・応答受信の途絶・provider 5xx・2xxだが生成結果を
            // 利用できない）。
            // claimを解放すると、同じIdempotency-KeyのretryがOpenAIを再実行して
            // 二重課金し得る。解放せず、失効（保持期間）までこのkeyへ新しい
            // 呼び出し権を与えない。保持期間内の再送は409 analysis_in_progress
            // になる。provider固有の状態はidempotency repositoryへ持ち込まない。
            throw $exception->response();
        } catch (Throwable $exception) {
            // AIが成功していないと確定できる失敗。解析できなかった
            // Idempotency-Keyを占有したままにせず、同じkeyでそのまま再試行できる
            // ようにする。provider利用不能は503 analysis_unavailableである。
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
        DateTimeImmutable $claimedAt,
    ): string {
        // claimが完了と判定した世代からだけ読む。期限の判定はクエリの中で
        // 行うため、ここで現在時刻を確定させない。
        $responseBody = $this->analysisRequests->findDelivery(
            $installationId,
            $idempotencyKey,
            $claimedAt,
        );

        if ($responseBody === null) {
            // 完了記録は残っているのに、返せる結果が無い状態。取得の直前に
            // 失効した場合、その世代が削除されて別の世代になった場合、
            // バッファだけが失われた場合がある。いずれも結果を作り直せない
            // ことをAndroidへ伝える。
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

        $body = self::readBody($request);

        try {
            // associativeにしない。JSON objectを`stdClass`のまま扱うことで、
            // `{}`と`[]`、`{"0":…}`とJSON arrayをJSON上の型で区別できる。
            // associativeで復号すると、どちらもPHPの配列になり区別できない。
            $payload = json_decode($body, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // 解析できなかったbodyは応答へ含めない（JournalEntry本文を含むため）。
            throw new ApiException(
                400,
                'invalid_request',
                'The request body must be valid JSON.',
            );
        }

        // rootがobjectでなければ`400 invalid_request`、rootはobjectで中身が
        // 契約に反する場合は`422 validation_error`である。
        if (!$payload instanceof stdClass) {
            throw new ApiException(
                400,
                'invalid_request',
                'The request body must be a JSON object.',
            );
        }

        return AnalysisRequestParser::parse($payload);
    }

    /**
     * request bodyを上限までしか読まずに返す。
     *
     * body全体をstringへ読んでから長さを確認すると、上限はworkerのメモリ消費を
     * 制限できない。`Content-Length`が上限を超えていれば読む前に拒否し、その
     * ヘッダーが無い場合や実際のbodyと一致しない場合に備えて、streamからも
     * 上限＋1 byteまでしか読み取らない。1 byte多く読むのは、上限ちょうどの
     * bodyと上限超過のbodyを区別するためである。
     */
    private static function readBody(ServerRequestInterface $request): string
    {
        $declaredLength = $request->getHeaderLine('Content-Length');

        if (
            preg_match('/\A\d+\z/', $declaredLength) === 1
            && (int) $declaredLength > self::MAX_BODY_BYTES
        ) {
            throw self::payloadTooLarge();
        }

        $stream = $request->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (strlen($body) <= self::MAX_BODY_BYTES && !$stream->eof()) {
            $chunk = $stream->read(
                self::MAX_BODY_BYTES + 1 - strlen($body),
            );

            // eof()を正しく報告しないstreamで読み続けないようにする。
            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw self::payloadTooLarge();
        }

        return $body;
    }

    private static function payloadTooLarge(): ApiException
    {
        return new ApiException(
            413,
            'payload_too_large',
            sprintf(
                'The request body must not exceed %d bytes.',
                self::MAX_BODY_BYTES,
            ),
        );
    }

    private static function isJsonRequest(
        ServerRequestInterface $request,
    ): bool {
        $mediaType = trim(
            explode(';', $request->getHeaderLine('Content-Type'), 2)[0],
        );

        return strtolower($mediaType) === 'application/json';
    }

    /**
     * responseのtimestampはUTC・秒精度である。
     *
     * `format()`はtimezoneを変換せず、`RESPONSE_TIMESTAMP_FORMAT`の`Z`は
     * リテラルである。Analyzer（Issue #4）がUTC以外のtimezoneで`analyzedAt`を
     * 返しても同じ瞬間を表すUTC timestampになるよう、format前に変換する。
     */
    private static function formatTimestamp(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::RESPONSE_TIMESTAMP_FORMAT);
    }
}
