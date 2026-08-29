<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration;

use DateTimeImmutable;
use JournalingPostServer\Analysis\Analysis;
use JournalingPostServer\Analysis\AnalysisRequest;
use JournalingPostServer\Analysis\AnalysisRequestParser;
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

    public function testRegistrationIssuesCredentialAndStoresOnlyItsHash(): void
    {
        $response = $this->send('POST', '/v1/installations');
        $installation = self::payload($response)['installation'];

        self::assertSame(201, $response->getStatusCode());
        self::assertStringStartsWith('jpk_', $installation['apiKey']);

        $stored = $this->connection
            ->query('SELECT * FROM installations')
            ->fetchAll();

        self::assertCount(1, $stored);
        self::assertSame($installation['id'], $stored[0]['id']);
        self::assertSame(
            hash('sha256', $installation['apiKey']),
            $stored[0]['api_key_hash'],
        );
        self::assertSame(
            ['id', 'api_key_hash', 'created_at', 'last_used_at'],
            array_keys($stored[0]),
        );
    }

    public function testAnalysisRequiresAValidInstallationApiKey(): void
    {
        $installation = $this->register();
        $rejectedKeys = [
            null,
            'jpk_00000000000000000000000000000000000000000',
            $installation['id'],
            'fcm-token-like-value',
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
        $response = $this->analyse($this->register()['apiKey']);
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
        $apiKey = $this->register()['apiKey'];
        $first = $this->analyse($apiKey);
        $second = $this->analyse($apiKey);

        self::assertSame(200, $second->getStatusCode());
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame(1, $this->analyzer->callCount);
    }

    public function testSameKeyWithADifferentRequestIsRejected(): void
    {
        $apiKey = $this->register()['apiKey'];
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
        $apiKey = $this->register()['apiKey'];
        $this->analyse($apiKey);
        $response = $this->analyse(
            $apiKey,
            key: '9c1e0f47-3ab6-4d52-8e10-7f2b5a6c93d8',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->analyzer->callCount);
    }

    public function testConcurrentRequestWithTheSameKeyIsAskedToRetry(): void
    {
        $installation = $this->register();
        $this->beginConcurrentAnalysis($installation['id'], 'now');

        $response = $this->analyse($installation['apiKey']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'analysis_in_progress',
            self::payload($response)['error']['code'],
        );
        self::assertSame('15', $response->getHeaderLine('Retry-After'));
        self::assertSame(0, $this->analyzer->callCount);
    }

    /**
     * 処理が中断して完了記録が残らなかった場合、同じkeyの再送が引き継げる。
     */
    public function testAbandonedAnalysisIsTakenOverByARetry(): void
    {
        $installation = $this->register();
        $this->beginConcurrentAnalysis(
            $installation['id'],
            sprintf(
                '-%d seconds',
                CreateAnalysisAction::PROCESSING_TIMEOUT_SECONDS + 60,
            ),
        );

        $response = $this->analyse($installation['apiKey']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->analyzer->callCount);
    }

    /**
     * 解析に失敗したkeyを占有したままにせず、同じkeyで再試行できる。
     */
    public function testFailedAnalysisReleasesTheKeyForARetry(): void
    {
        $apiKey = $this->register()['apiKey'];
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
     * AI providerを差し替えるまでは、解析直前で503を返す（既定のAnalyzer）。
     */
    public function testAnalysisProviderIsNotConfiguredYet(): void
    {
        $response = $this->analyse(
            $this->register()['apiKey'],
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
        $apiKey = $this->register()['apiKey'];
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
     * JournalEntry本文をDBへ保存しない。解析結果本文は引き渡しバッファへ
     * 保持期間の間だけ残り、metadataとは別のテーブルへ分離する。
     */
    public function testJournalEntryContentIsNeverPersisted(): void
    {
        $this->analyse($this->register()['apiKey']);

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
     * 保持期間を過ぎたidempotency metadataと解析結果は、次の解析requestが
     * 削除する。Cronを増やさずに保持期間の上限を守る。
     */
    public function testRetentionWindowRemovesMetadataAndBufferedResults(): void
    {
        $apiKey = $this->register()['apiKey'];
        $this->analyse($apiKey);

        $this->connection->exec(
            sprintf(
                'UPDATE analysis_requests SET expires_at = expires_at'
                    . ' - INTERVAL %d SECOND',
                CreateAnalysisAction::RETENTION_SECONDS + 60,
            ),
        );

        $response = $this->analyse($apiKey);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->analyzer->callCount);
        self::assertSame(
            1,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM analysis_deliveries')
                ->fetchColumn(),
        );
    }

    /**
     * 完了記録だけが残った場合でも、結果を作り直さずAndroidへ状態を伝える。
     */
    public function testCompletedRequestWithoutABufferedResultIsReported(): void
    {
        $apiKey = $this->register()['apiKey'];
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
     * 処理中の解析requestを、Serverの外から作れない形で再現する。
     */
    private function beginConcurrentAnalysis(
        string $installationId,
        string $startedAtModifier,
    ): void {
        $now = new DateTimeImmutable('now');
        $startedAt = $now->modify($startedAtModifier);

        $this->connection
            ->prepare(
                'INSERT INTO analysis_requests
                    (installation_id, idempotency_key, request_fingerprint,
                     started_at, expires_at)
                 VALUES (:installation_id, :idempotency_key, :fingerprint,
                         :started_at, :expires_at)',
            )
            ->execute([
                'installation_id' => $installationId,
                'idempotency_key' => self::KEY,
                'fingerprint' => AnalysisRequestParser::parse(
                    self::requestPayload(),
                )->fingerprint(),
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

    /**
     * @return array{id: string, apiKey: string}
     */
    private function register(): array
    {
        return self::payload(
            $this->send('POST', '/v1/installations'),
        )['installation'];
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
