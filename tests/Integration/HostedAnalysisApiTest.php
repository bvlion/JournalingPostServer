<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration;

use DateTimeImmutable;
use JournalingPostServer\Analysis\Analysis;
use JournalingPostServer\Analysis\AnalysisClaim;
use JournalingPostServer\Analysis\AnalysisRequest;
use JournalingPostServer\Analysis\AnalysisRequestParser;
use JournalingPostServer\Analysis\AnalysisRequestRepository;
use JournalingPostServer\Http\ApiException;
use JournalingPostServer\Http\CreateAnalysisAction;
use JournalingPostServer\Tests\Integration\Support\DatabaseTestCase;
use JournalingPostServer\Tests\Integration\Support\FakeAnalyzer;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Hosted解析APIの契約（認証・request / response・idempotency・error・保持期間）。
 *
 * fixtureはすべて架空の値であり、実データを含まない。
 */
final class HostedAnalysisApiTest extends DatabaseTestCase
{
    /** 端末側で生成するIdempotency-Keyの例（架空のUUID v4）。 */
    private const KEY = '2f5b6a1c-7d84-4e0f-9a31-6c0d5e8b41a7';

    private const OTHER_KEY = '9c1e0f47-3ab6-4d52-8e10-7f2b5a6c93d8';

    private const NOTE = 'DBへ保存されてはいけない架空のメモ';

    private PDO $connection;

    private FakeAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->connection = self::createConnection();
        // installationsの削除はanalysis_requests・analysis_deliveriesへ
        // ON DELETE CASCADEで波及する。
        $this->connection->exec('DELETE FROM installations');
        $this->analyzer = new FakeAnalyzer();
    }

    public function testRegistrationIssuesAnApiKeyAndStoresOnlyItsHash(): void
    {
        $response = $this->send('POST', '/v1/installations');
        $installation = self::payload($response)['installation'];

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['apiKey'], array_keys($installation));
        self::assertStringStartsWith('jpk_', $installation['apiKey']);

        $stored = $this->connection
            ->query('SELECT * FROM installations')
            ->fetchAll();

        self::assertCount(1, $stored);
        self::assertSame(
            ['id', 'api_key_hash', 'created_at'],
            array_keys($stored[0]),
        );
        self::assertSame(
            hash('sha256', $installation['apiKey']),
            $stored[0]['api_key_hash'],
        );
        // Server内部の識別子は応答へ出さない。
        self::assertStringNotContainsString(
            $stored[0]['id'],
            (string) $response->getBody(),
        );
    }

    public function testAnalysisRequiresAValidInstallationApiKey(): void
    {
        $this->register();
        $rejectedKeys = [
            null,
            'jpk_00000000000000000000000000000000000000000',
            '3f1c9c4e-2a55-4c1b-9c2a-0b8f6b7d5e41',
            'client-chosen-token-value',
            $this->installationId(),
        ];

        foreach ($rejectedKeys as $rejectedKey) {
            $response = $this->analyse($rejectedKey);

            self::assertSame(401, $response->getStatusCode());
            self::assertSame(
                'unauthorized',
                self::payload($response)['error']['code'],
            );
        }

        self::assertSame(0, $this->analyzer->callCount);
    }

    public function testSuccessfulAnalysisReturnsAStorableAnalysisResult(): void
    {
        $response = $this->analyse($this->register());
        $analysis = self::payload($response)['analysis'];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/json; charset=utf-8',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            [
                'period' => [
                    'start' => '2026-08-29T00:00:00Z',
                    'end' => '2026-08-29T09:00:00Z',
                ],
                'analyzedAt' => '2026-08-29T09:00:05Z',
                'entryCount' => 2,
                'model' => 'example/analysis-model',
                'text' => '架空の振り返り（2件）',
            ],
            $analysis,
        );
    }

    /**
     * responseがnetworkで失われた場合の再送。AIを再度呼ばずに同じ結果を返す。
     */
    public function testRetryWithTheSameKeyReturnsTheSameResultWithoutReanalysing(): void
    {
        $apiKey = $this->register();
        $first = $this->analyse($apiKey);
        $second = $this->analyse($apiKey);

        self::assertSame(200, $second->getStatusCode());
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame(1, $this->analyzer->callCount);
    }

    public function testSameKeyWithADifferentRequestIsRejected(): void
    {
        $apiKey = $this->register();
        $this->analyse($apiKey);

        $response = $this->analyse(
            $apiKey,
            payload: self::requestPayload('別の架空のメモ'),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'idempotency_key_reuse',
            self::payload($response)['error']['code'],
        );
        self::assertSame(1, $this->analyzer->callCount);
    }

    /**
     * ユーザーが意図して再解析する場合は、別のIdempotency-Keyで区別する。
     */
    public function testDeliberateReanalysisUsesADifferentKey(): void
    {
        $apiKey = $this->register();
        $this->analyse($apiKey);
        $response = $this->analyse($apiKey, key: self::OTHER_KEY);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->analyzer->callCount);
    }

    public function testConcurrentRequestWithTheSameKeyIsAskedToRetry(): void
    {
        $apiKey = $this->register();
        $this->beginUnfinishedAnalysis('now');

        $response = $this->analyse($apiKey);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'analysis_in_progress',
            self::payload($response)['error']['code'],
        );
        self::assertSame('15', $response->getHeaderLine('Retry-After'));
        self::assertSame(0, $this->analyzer->callCount);
    }

    /**
     * 経過時間だけを根拠に新しいAI呼び出し権を与えない。前の処理が動き続けて
     * いる可能性がある間にAIを二重に呼ばないための契約である。
     */
    public function testUnfinishedAnalysisIsNotTakenOverBeforeItExpires(): void
    {
        $apiKey = $this->register();
        $this->beginUnfinishedAnalysis(
            sprintf('-%d seconds', CreateAnalysisAction::RETENTION_SECONDS - 60),
        );

        $response = $this->analyse($apiKey);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'analysis_in_progress',
            self::payload($response)['error']['code'],
        );
        self::assertSame(0, $this->analyzer->callCount);
    }

    /**
     * 完了しなかったrequestも保持期間で失効し、その後は新しい解析として扱う。
     */
    public function testExpiredUnfinishedAnalysisBecomesANewRequest(): void
    {
        $apiKey = $this->register();
        $this->beginUnfinishedAnalysis(
            sprintf('-%d seconds', CreateAnalysisAction::RETENTION_SECONDS + 60),
        );

        $response = $this->analyse($apiKey);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->analyzer->callCount);
    }

    /**
     * 解析に失敗したkeyを占有したままにせず、同じkeyで再試行できる。
     */
    public function testFailedAnalysisReleasesTheKeyForARetry(): void
    {
        $apiKey = $this->register();
        $this->analyzer->behaveAs(
            static fn (AnalysisRequest $request): Analysis => throw
                new ApiException(
                    503,
                    'analysis_unavailable',
                    'The analysis provider is not available.',
                ),
        );

        $failed = $this->analyse($apiKey);

        self::assertSame(503, $failed->getStatusCode());
        self::assertSame(
            'analysis_unavailable',
            self::payload($failed)['error']['code'],
        );
        self::assertSame(
            [],
            $this->connection->query('SELECT * FROM analysis_requests')
                ->fetchAll(),
        );

        $this->analyzer->behaveAs(
            static fn (AnalysisRequest $request): Analysis => new Analysis(
                new DateTimeImmutable('2026-08-29T09:00:05Z'),
                'example/analysis-model',
                '架空の振り返り',
            ),
        );

        self::assertSame(200, $this->analyse($apiKey)->getStatusCode());
    }

    /**
     * 別のrequestが取得したAI呼び出し権を、遅れて失敗した処理が解放しない。
     */
    public function testReleaseOnlyAffectsTheClaimItTook(): void
    {
        $this->register();
        $this->beginUnfinishedAnalysis('now');
        $repository = new AnalysisRequestRepository(
            fn (): PDO => $this->connection,
        );

        $repository->release(
            $this->installationId(),
            self::KEY,
            new DateTimeImmutable('2020-01-01T00:00:00Z'),
        );

        self::assertSame(
            1,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM analysis_requests')
                ->fetchColumn(),
        );
    }

    /**
     * 保持期間切れでclaimが削除された後に同じIdempotency-Keyで作られた新しい
     * claimを、遅れて終わった古い処理が完了扱いにしたり、その引き渡しバッファ
     * へ古い結果を書き込んだりしない。
     */
    public function testCompletionOnlyAffectsTheClaimItTook(): void
    {
        $apiKey = $this->register();
        // 古いclaimは失効・削除済みで、同じkeyの新しいclaimが進行中とする。
        $this->beginUnfinishedAnalysis('now');
        $repository = new AnalysisRequestRepository(
            fn (): PDO => $this->connection,
        );

        $recorded = $repository->complete(
            $this->installationId(),
            self::KEY,
            '{"analysis":{"text":"古い処理の架空の結果"}}',
            new DateTimeImmutable('2020-01-01T00:00:00Z'),
            new DateTimeImmutable('now'),
            new DateTimeImmutable('now'),
        );

        self::assertFalse($recorded);
        self::assertSame(0, $this->countRows('analysis_deliveries'));
        self::assertNull(
            $this->connection
                ->query('SELECT completed_at FROM analysis_requests')
                ->fetchColumn(),
        );

        // 新しいclaimは進行中のままで、古い結果を返さない。
        $response = $this->analyse($apiKey);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'analysis_in_progress',
            self::payload($response)['error']['code'],
        );
    }

    /**
     * 完了済みのclaimを二重に完了させない。引き渡しバッファも上書きしない。
     */
    public function testCompletedAnalysisIsNotCompletedAgain(): void
    {
        $apiKey = $this->register();
        $original = (string) $this->analyse($apiKey)->getBody();
        $claim = $this->connection
            ->query('SELECT started_at, completed_at FROM analysis_requests')
            ->fetch();
        $repository = new AnalysisRequestRepository(
            fn (): PDO => $this->connection,
        );

        $recorded = $repository->complete(
            $this->installationId(),
            self::KEY,
            '{"analysis":{"text":"上書きされてはいけない架空の結果"}}',
            new DateTimeImmutable($claim['started_at']),
            new DateTimeImmutable('now'),
            new DateTimeImmutable('now'),
        );

        self::assertFalse($recorded);
        self::assertSame(
            $claim['completed_at'],
            $this->connection
                ->query('SELECT completed_at FROM analysis_requests')
                ->fetchColumn(),
        );
        self::assertSame($original, (string) $this->analyse($apiKey)->getBody());
    }

    /**
     * requestの開始時に取得した`$now`の後で行が失効した場合でも、失効した完了
     * 記録と引き渡しバッファを返さない。失効判定は`$now`ではなく判定時点の
     * 現在時刻で行う。
     *
     * `purgeExpired()`を経由せずrepositoryの`claim()`を直接呼び、`$now`には
     * 失効前の時刻（request開始時に取得した値に相当）を渡している。
     */
    public function testExpiryIsEvaluatedAtDecisionTimeNotAtRequestStart(): void
    {
        $apiKey = $this->register();
        $this->analyse($apiKey);
        // `$now`の取得後に失効した状態にする。
        $this->expireStoredAnalyses();

        $claim = $this->claimDirectly(
            $this->storedExpiry()->modify('-1 second'),
        );

        self::assertSame(AnalysisClaim::Granted, $claim);
        self::assertSame(0, $this->countRows('analysis_deliveries'));
    }

    /**
     * 完了していない失効済みclaimも同様に、判定時点で失効を確認して新しく
     * 取得できる。
     */
    public function testExpiredUnfinishedClaimIsRecheckedWhenClaiming(): void
    {
        $this->register();
        $this->beginUnfinishedAnalysis('now');
        $this->expireStoredAnalyses();

        $claim = $this->claimDirectly(
            $this->storedExpiry()->modify('-1 second'),
        );

        self::assertSame(AnalysisClaim::Granted, $claim);
        self::assertSame(1, $this->countRows('analysis_requests'));
        self::assertNull(
            $this->connection
                ->query('SELECT completed_at FROM analysis_requests')
                ->fetchColumn(),
        );
    }

    /**
     * 失効していない行は、呼び出し元が渡した`$now`が失効時刻を過ぎていても
     * 完了済みとして扱う。判定に使うのは実際の現在時刻だけである。
     */
    public function testUnexpiredCompletedClaimIsStillReplayable(): void
    {
        $apiKey = $this->register();
        $this->analyse($apiKey);

        $claim = $this->claimDirectly($this->storedExpiry());

        self::assertSame(AnalysisClaim::Completed, $claim);
        self::assertSame(1, $this->countRows('analysis_deliveries'));
    }

    /**
     * `Idempotency-Key`は大文字小文字を区別する。契約が許可する文字には大小
     * 両方が含まれるため、DBの照合順序で同一視されてはならない。
     */
    public function testIdempotencyKeysDifferingOnlyInCaseAreDistinct(): void
    {
        $apiKey = $this->register();
        $upperCaseKey = strtoupper(self::KEY);

        self::assertNotSame(self::KEY, $upperCaseKey);

        $first = $this->analyse($apiKey);
        $second = $this->analyse($apiKey, key: $upperCaseKey);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        // 同じ結果の使い回しではなく、別の解析として実行する。
        self::assertSame(2, $this->analyzer->callCount);
        self::assertSame(
            [self::KEY, $upperCaseKey],
            $this->connection
                ->query(
                    'SELECT idempotency_key FROM analysis_requests'
                        . ' ORDER BY started_at',
                )
                ->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame(2, $this->countRows('analysis_deliveries'));
    }

    /**
     * UTC以外のtimezoneで返された`analyzedAt`も、同じ瞬間のUTC timestampとして
     * 返す。`format()`はtimezoneを変換しないため、変換忘れは9時間ずれた解析
     * 日時が端末へ保存される形で表面化する。
     */
    public function testAnalyzedAtIsConvertedToUtc(): void
    {
        $apiKey = $this->register();
        $this->analyzer->behaveAs(
            static fn (AnalysisRequest $request): Analysis => new Analysis(
                new DateTimeImmutable('2026-08-29T18:00:05+09:00'),
                'example/analysis-model',
                '架空の振り返り',
            ),
        );

        $response = $this->analyse($apiKey);

        self::assertSame(
            '2026-08-29T09:00:05Z',
            self::payload($response)['analysis']['analyzedAt'],
        );
    }

    private function storedExpiry(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $this->connection
                ->query('SELECT expires_at FROM analysis_requests')
                ->fetchColumn(),
        );
    }

    /**
     * `purgeExpired()`を経由せずにclaimだけを実行する。
     */
    private function claimDirectly(DateTimeImmutable $now): AnalysisClaim
    {
        return (new AnalysisRequestRepository(fn (): PDO => $this->connection))
            ->claim(
                $this->installationId(),
                self::KEY,
                self::requestFingerprint(),
                $now,
                $now->modify(
                    sprintf(
                        '+%d seconds',
                        CreateAnalysisAction::RETENTION_SECONDS,
                    ),
                ),
            );
    }

    /**
     * AI providerを差し替えるまでは、解析直前で503を返す（既定のAnalyzer）。
     */
    public function testAnalysisProviderIsNotConfiguredYet(): void
    {
        $response = $this->analyse(
            $this->register(),
            useDefaultAnalyzer: true,
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            'analysis_unavailable',
            self::payload($response)['error']['code'],
        );
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
    }

    public function testInvalidRequestsAreRejectedBeforeAnalysis(): void
    {
        $apiKey = $this->register();
        $expectations = [
            ['key' => 'short', 'code' => 'invalid_request', 'status' => 400],
            ['body' => '{', 'code' => 'invalid_request', 'status' => 400],
            [
                'contentType' => 'text/plain',
                'code' => 'unsupported_media_type',
                'status' => 415,
            ],
            [
                'payload' => ['period' => [], 'entries' => []],
                'code' => 'validation_error',
                'status' => 422,
            ],
        ];

        foreach ($expectations as $expectation) {
            $response = $this->analyse(
                $apiKey,
                key: $expectation['key'] ?? self::KEY,
                payload: $expectation['payload'] ?? null,
                body: $expectation['body'] ?? null,
                contentType: $expectation['contentType'] ?? 'application/json',
            );

            self::assertSame($expectation['status'], $response->getStatusCode());
            self::assertSame(
                $expectation['code'],
                self::payload($response)['error']['code'],
            );
        }

        self::assertSame(0, $this->analyzer->callCount);
    }

    /**
     * JSON rootの型で応答を分ける。objectでないrootは`400 invalid_request`、
     * objectの中身の違反は`422 validation_error`である。
     *
     * `json_decode(..., true)`では`{}`と`[]`がどちらも空配列になるため、
     * 空配列を送ったクライアントが422を受け取らないことを確認する。
     */
    public function testJsonRootTypeDecidesBetweenInvalidRequestAndValidation(): void
    {
        $apiKey = $this->register();
        $expectations = [
            ['body' => '[]', 'code' => 'invalid_request', 'status' => 400],
            [
                'body' => '[{"period":{},"entries":[]}]',
                'code' => 'invalid_request',
                'status' => 400,
            ],
            ['body' => '"text"', 'code' => 'invalid_request', 'status' => 400],
            ['body' => '123', 'code' => 'invalid_request', 'status' => 400],
            ['body' => 'null', 'code' => 'invalid_request', 'status' => 400],
            // rootがobjectであれば、空でも契約違反として422で返す。
            ['body' => '{}', 'code' => 'validation_error', 'status' => 422],
            [
                'body' => "\n\t {} ",
                'code' => 'validation_error',
                'status' => 422,
            ],
        ];

        foreach ($expectations as $expectation) {
            $response = $this->analyse($apiKey, body: $expectation['body']);

            self::assertSame(
                $expectation['status'],
                $response->getStatusCode(),
                $expectation['body'],
            );
            self::assertSame(
                $expectation['code'],
                self::payload($response)['error']['code'],
                $expectation['body'],
            );
        }

        self::assertSame(0, $this->analyzer->callCount);
        self::assertSame(0, $this->countRows('analysis_requests'));
    }

    /**
     * `entries`はJSON arrayのときだけ受理する。`{"0":…}`のようなJSON objectは
     * associativeな`json_decode()`では整数キーの配列になり、`array_is_list()`
     * を通過してarrayとして受理されてしまう。
     */
    public function testEntriesGivenAsAJsonObjectIsRejected(): void
    {
        $response = $this->analyse(
            $this->register(),
            body: '{"period":{"start":"2026-08-29T00:00:00Z",'
                . '"end":"2026-08-29T09:00:00Z"},'
                . '"entries":{"0":{"recordedAt":"2026-08-29T01:15:00Z"},'
                . '"1":{"recordedAt":"2026-08-29T05:40:00Z"}}}',
        );
        $error = self::payload($response)['error'];

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $error['code']);
        self::assertSame(['entries: must be an array.'], $error['details']);
        self::assertSame(0, $this->analyzer->callCount);
    }

    /**
     * JournalEntry本文をDBへ保存しない。解析結果本文は引き渡しバッファへ
     * 保持期間の間だけ残り、metadataとは別のテーブルへ分離する。
     */
    public function testJournalEntryContentIsNeverPersisted(): void
    {
        $this->analyse($this->register());

        $requests = $this->connection
            ->query('SELECT * FROM analysis_requests')
            ->fetchAll();

        self::assertCount(1, $requests);
        self::assertSame(
            [
                'installation_id',
                'idempotency_key',
                'request_fingerprint',
                'started_at',
                'completed_at',
                'expires_at',
            ],
            array_keys($requests[0]),
        );

        foreach ($requests[0] as $value) {
            self::assertStringNotContainsString(self::NOTE, (string) $value);
        }

        $deliveries = $this->connection
            ->query('SELECT * FROM analysis_deliveries')
            ->fetchAll();

        self::assertCount(1, $deliveries);
        self::assertStringNotContainsString(
            self::NOTE,
            $deliveries[0]['response_body'],
        );
    }

    /**
     * 解析結果bufferの保持期間の起点は解析完了時である。解析に時間がかかっても
     * 完了からの一定時間で失効する。
     */
    public function testBufferedResultExpiresRelativeToCompletion(): void
    {
        $this->analyse($this->register());

        $request = $this->connection
            ->query(
                'SELECT started_at, completed_at, expires_at'
                    . ' FROM analysis_requests',
            )
            ->fetch();

        self::assertNotSame($request['started_at'], $request['completed_at']);
        self::assertSame(
            (new DateTimeImmutable($request['completed_at']))
                ->modify(
                    sprintf(
                        '+%d seconds',
                        CreateAnalysisAction::RETENTION_SECONDS,
                    ),
                )
                ->format('Y-m-d H:i:s.u'),
            $request['expires_at'],
        );
    }

    /**
     * 後続の解析requestが来なくても、失効した解析結果本文がDBへ残り続けない。
     * XServer Cronが`bin/prune-expired-analyses.php`から呼ぶ経路。
     */
    public function testExpiredResultsArePurgedWithoutAFurtherRequest(): void
    {
        $this->analyse($this->register());
        $this->expireStoredAnalyses();

        $purgedCount = (new AnalysisRequestRepository(
            fn (): PDO => $this->connection,
        ))->purgeExpired(new DateTimeImmutable('now'));

        self::assertSame(1, $purgedCount);
        self::assertSame(0, $this->countRows('analysis_requests'));
        self::assertSame(0, $this->countRows('analysis_deliveries'));
    }

    /**
     * 保持期間を過ぎたrequestは、次の解析requestの処理中にも削除される。
     */
    public function testExpiredResultsArePurgedByTheNextRequest(): void
    {
        $apiKey = $this->register();
        $this->analyse($apiKey);
        $this->expireStoredAnalyses();

        $response = $this->analyse($apiKey);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->analyzer->callCount);
        self::assertSame(1, $this->countRows('analysis_deliveries'));
    }

    /**
     * 完了記録だけが残った場合でも、結果を作り直さずAndroidへ状態を伝える。
     */
    public function testCompletedRequestWithoutABufferedResultIsReported(): void
    {
        $apiKey = $this->register();
        $this->analyse($apiKey);
        $this->connection->exec('DELETE FROM analysis_deliveries');

        $response = $this->analyse($apiKey);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'analysis_result_unavailable',
            self::payload($response)['error']['code'],
        );
        self::assertSame(1, $this->analyzer->callCount);
    }

    /**
     * 完了していない解析requestを、Serverの外から作れない形で再現する。
     */
    private function beginUnfinishedAnalysis(string $startedAtModifier): void
    {
        $startedAt = (new DateTimeImmutable('now'))->modify($startedAtModifier);

        $this->connection
            ->prepare(
                'INSERT INTO analysis_requests
                    (installation_id, idempotency_key, request_fingerprint,
                     started_at, expires_at)
                 VALUES (:installation_id, :idempotency_key, :fingerprint,
                         :started_at, :expires_at)',
            )
            ->execute([
                'installation_id' => $this->installationId(),
                'idempotency_key' => self::KEY,
                'fingerprint' => self::requestFingerprint(),
                'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
                'expires_at' => $startedAt
                    ->modify(
                        sprintf(
                            '+%d seconds',
                            CreateAnalysisAction::RETENTION_SECONDS,
                        ),
                    )
                    ->format('Y-m-d H:i:s.u'),
            ]);
    }

    private function expireStoredAnalyses(): void
    {
        $this->connection->exec(
            sprintf(
                'UPDATE analysis_requests SET expires_at = expires_at'
                    . ' - INTERVAL %d SECOND',
                CreateAnalysisAction::RETENTION_SECONDS + 60,
            ),
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection
            ->query(sprintf('SELECT COUNT(*) FROM %s', $table))
            ->fetchColumn();
    }

    /**
     * Server内部のinstallation識別子。APIからは取得できないため、テストは
     * 直接DBから読む。
     */
    private function installationId(): string
    {
        return (string) $this->connection
            ->query('SELECT id FROM installations')
            ->fetchColumn();
    }

    private function register(): string
    {
        return self::payload(
            $this->send('POST', '/v1/installations'),
        )['installation']['apiKey'];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function analyse(
        ?string $apiKey,
        string $key = self::KEY,
        ?array $payload = null,
        ?string $body = null,
        string $contentType = 'application/json',
        bool $useDefaultAnalyzer = false,
    ): ResponseInterface {
        $headers = ['Idempotency-Key' => $key, 'Content-Type' => $contentType];

        if ($apiKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $this->send(
            'POST',
            '/v1/analyses',
            $headers,
            $body ?? json_encode(
                $payload ?? self::requestPayload(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            $useDefaultAnalyzer ? null : $this->analyzer,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        ?FakeAnalyzer $analyzer = null,
    ): ResponseInterface {
        /** @var callable(?FakeAnalyzer): App<null> $createApplication */
        $createApplication = require self::projectPath('bootstrap/app.php');
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody(
                (new StreamFactory())->createStream($body),
            );
        }

        return $createApplication($analyzer)->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestPayload(string $note = self::NOTE): array
    {
        return [
            'period' => [
                'start' => '2026-08-29T00:00:00Z',
                'end' => '2026-08-29T09:00:00Z',
            ],
            'entries' => [
                [
                    'recordedAt' => '2026-08-29T01:15:00Z',
                    'mood' => ['emoji' => '😐', 'label' => '架空の気分'],
                ],
                [
                    'recordedAt' => '2026-08-29T05:40:00Z',
                    'mood' => ['emoji' => '🙂', 'label' => '架空の気分'],
                    'note' => $note,
                ],
            ],
        ];
    }

    /**
     * parserはassociativeにしない`json_decode()`の結果を受け取る。テストの
     * 配列もJSONを経由してJSON上の型どおりの値へ変換する。
     */
    private static function requestFingerprint(): string
    {
        return AnalysisRequestParser::parse(
            json_decode(
                json_encode(
                    (object) self::requestPayload(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
                false,
                flags: JSON_THROW_ON_ERROR,
            ),
        )->fingerprint();
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(ResponseInterface $response): array
    {
        $payload = json_decode(
            (string) $response->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);

        return $payload;
    }
}
