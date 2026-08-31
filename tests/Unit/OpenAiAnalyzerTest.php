<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use DateTimeImmutable;
use JournalingPostServer\Analysis\AnalysisEntry;
use JournalingPostServer\Analysis\AnalysisRequest;
use JournalingPostServer\Analysis\AnalysisResultUnconfirmedException;
use JournalingPostServer\Analysis\OpenAi\OpenAiAnalyzer;
use JournalingPostServer\Http\ApiException;
use JournalingPostServer\Tests\Support\FakeResponsesTransport;
use PHPUnit\Framework\TestCase;

/**
 * 実OpenAI Analyzerのrequest構築・入力整形・応答解析・失敗時の扱い。
 *
 * 実OpenAIへは接続しない。
 *
 * fixtureはすべて架空の値であり、実データを含まない。
 */
final class OpenAiAnalyzerTest extends TestCase
{
    private const SECRET_KEY = 'sk-fake-secret-not-real-abcdef0123456789';

    private FakeResponsesTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeResponsesTransport();
    }

    /**
     * 想定どおりの model / reasoning / max_output_tokens / verbosity / schema /
     * store:false でrequestを構築する。
     */
    public function testRequestIsBuiltWithTheExpectedOpenAiContract(): void
    {
        $payload = OpenAiAnalyzer::buildRequestPayload(self::request());

        self::assertSame('gpt-5.6-luna', $payload['model']);
        self::assertSame(['effort' => 'none'], $payload['reasoning']);
        self::assertSame(800, $payload['max_output_tokens']);
        self::assertSame('low', $payload['text']['verbosity']);
        self::assertFalse($payload['store']);
        self::assertSame(
            'これはローカル検証用の架空の system prompt です。実データではありません。',
            $payload['input'][0]['content'],
        );
        self::assertSame('system', $payload['input'][0]['role']);
        self::assertSame('user', $payload['input'][1]['role']);

        $format = $payload['text']['format'];
        self::assertSame('json_schema', $format['type']);
        self::assertSame('slack_log_emotion_analysis', $format['name']);
        self::assertTrue($format['strict']);
        self::assertSame(
            [
                'good', 'bad', 'score', 'emotion', 'summary', 'advice', 'tags',
            ],
            $format['schema']['required'],
        );
        self::assertFalse($format['schema']['additionalProperties']);
        self::assertSame(
            ['中立', 'ポジティブ', 'ネガティブ'],
            $format['schema']['properties']['emotion']['enum'],
        );
        self::assertSame(3, $format['schema']['properties']['good']['maxItems']);
        self::assertSame(5, $format['schema']['properties']['tags']['maxItems']);
    }

    /**
     * 設定から渡した分析ルール本文を、そのまま user プロンプトへ組み込む。
     */
    public function testUserPromptKeepsTheConfiguredAnalysisRules(): void
    {
        $prompt = OpenAiAnalyzer::buildRequestPayload(self::request())
            ['input'][1]['content'];

        self::assertStringContainsString(
            'これはローカル検証・テスト用のダミー指示文です',
            $prompt,
        );
        self::assertStringContainsString(
            '- good: 文字列の配列。',
            $prompt,
        );
        self::assertStringContainsString(
            '- tags: 文字列の配列。',
            $prompt,
        );
        self::assertStringContainsString("\n## Slackのログ\n", $prompt);
    }

    /**
     * moodのみ / mood + note / noteのみを、想定どおりの解析材料へ変換する。
     * recordedAt昇順で並べる。
     */
    public function testTranscriptFormatsEntriesAsExpected(): void
    {
        $request = new AnalysisRequest(
            new DateTimeImmutable('2026-08-29T00:00:00Z'),
            new DateTimeImmutable('2026-08-29T23:59:59Z'),
            [
                new AnalysisEntry(
                    new DateTimeImmutable('2026-08-29T08:00:00Z'),
                    null,
                    null,
                    '  夜に近所を散歩した  ',
                ),
                new AnalysisEntry(
                    new DateTimeImmutable('2026-08-29T01:15:00Z'),
                    '😐',
                    'ふつう',
                    null,
                ),
                new AnalysisEntry(
                    new DateTimeImmutable('2026-08-29T05:40:00Z'),
                    '🙂',
                    'すこし上向き',
                    "  カフェで集中できた  ",
                ),
            ],
        );

        self::assertSame(
            implode("\n", [
                '2026-08-29T01:15:00Z 気分は😐とのこと',
                '2026-08-29T05:40:00Z 気分は🙂とのこと。カフェで集中できた',
                '2026-08-29T08:00:00Z   夜に近所を散歩した  ',
            ]),
            OpenAiAnalyzer::buildTranscript($request),
        );
    }

    /**
     * moodに絵文字がある場合はemojiを解析材料に使い、labelは使わない（現在の
     * 入力にlabelが無いため）。
     */
    public function testMoodLabelIsNotUsedWhenAnEmojiIsPresent(): void
    {
        $transcript = OpenAiAnalyzer::buildTranscript(
            new AnalysisRequest(
                new DateTimeImmutable('2026-08-29T00:00:00Z'),
                new DateTimeImmutable('2026-08-29T09:00:00Z'),
                [
                    new AnalysisEntry(
                        new DateTimeImmutable('2026-08-29T01:15:00Z'),
                        '😐',
                        'この文字列はAI入力に現れてはならない',
                        null,
                    ),
                ],
            ),
        );

        self::assertSame(
            '2026-08-29T01:15:00Z 気分は😐とのこと',
            $transcript,
        );
    }

    /**
     * 名称だけのMood（emojiが空）は、labelを解析材料に使う。label-only Moodが
     * AI入力から失われないことを固定する（Issue #11の完了条件）。
     */
    public function testLabelOnlyMoodUsesTheLabelAsAnalysisMaterial(): void
    {
        $transcript = OpenAiAnalyzer::buildTranscript(
            new AnalysisRequest(
                new DateTimeImmutable('2026-08-29T00:00:00Z'),
                new DateTimeImmutable('2026-08-29T09:00:00Z'),
                [
                    new AnalysisEntry(
                        new DateTimeImmutable('2026-08-29T01:15:00Z'),
                        null,
                        'すこし上向き',
                        null,
                    ),
                    new AnalysisEntry(
                        new DateTimeImmutable('2026-08-29T05:40:00Z'),
                        null,
                        'しずか',
                        '  カフェで集中できた  ',
                    ),
                ],
            ),
        );

        self::assertSame(
            implode("\n", [
                '2026-08-29T01:15:00Z 気分はすこし上向きとのこと',
                '2026-08-29T05:40:00Z 気分はしずかとのこと。カフェで集中できた',
            ]),
            $transcript,
        );
    }

    /**
     * OpenAIの構造化7項目を欠落させずAnalysis.textへ変換する。
     * good / badは箇条書き、空配列は「なし」。JSON文字列をそのままbodyにしない。
     */
    public function testStructuredResultIsFormattedWithoutLosingAnyOfTheSevenItems(): void
    {
        $this->transport->willReturn(200, self::responsesBody([
            'good' => ['朝の散歩ができた', '家族と夕食をとった'],
            'bad' => [],
            'score' => 71,
            'emotion' => 'ポジティブ',
            'summary' => '日中は在宅で作業し、夜に家族と過ごした。',
            'advice' => '在宅作業の合間に短い休憩を挟むと良いかもしれません。',
            'tags' => ['在宅作業', '家族', '散歩'],
        ]));

        $analysis = $this->analyzer()->analyze(self::request());

        self::assertSame('gpt-5.6-luna', $analysis->model);

        $text = $analysis->text;
        self::assertStringContainsString(
            "【良かったこと】\n- 朝の散歩ができた\n- 家族と夕食をとった",
            $text,
        );
        self::assertStringContainsString("【嫌だったこと】\nなし", $text);
        self::assertStringContainsString("【感情スコア】\n71 / 100", $text);
        self::assertStringContainsString("【感情タイプ】\nポジティブ", $text);
        self::assertStringContainsString(
            "【要約】\n日中は在宅で作業し、夜に家族と過ごした。",
            $text,
        );
        self::assertStringContainsString(
            "【AI アドバイス】\n在宅作業の合間に短い休憩を挟むと良いかもしれません。",
            $text,
        );
        self::assertStringContainsString(
            "【タグ】\n在宅作業, 家族, 散歩",
            $text,
        );
        // JSON文字列をそのまま返さない。
        self::assertStringNotContainsString('{"good"', $text);
        self::assertStringNotContainsString('"score"', $text);
    }

    public function testEmptyTagsBecomeNashi(): void
    {
        $this->transport->willReturn(200, self::responsesBody([
            'good' => [],
            'bad' => ['寝不足だった'],
            'score' => 40,
            'emotion' => 'ネガティブ',
            'summary' => '架空の要約。',
            'advice' => '架空の助言。',
            'tags' => [],
        ]));

        $text = $this->analyzer()->analyze(self::request())->text;

        self::assertStringContainsString("【良かったこと】\nなし", $text);
        self::assertStringContainsString("【嫌だったこと】\n- 寝不足だった", $text);
        self::assertStringContainsString("【タグ】\nなし", $text);
    }

    /**
     * provider 4xx（429等を含む）は処理前の拒否と確定できる確定失敗。
     * `503 analysis_unavailable`のApiExceptionを投げ、`CreateAnalysisAction`が
     * claimを解放できる（＝`AnalysisResultUnconfirmedException`にしない）。
     * raw bodyやAPI keyを例外メッセージへも応答へも出さない。
     */
    public function testProviderFourXxIsAConfirmedFailureWithoutLeakingDetails(): void
    {
        $rawBody = '{"error":{"message":"secret rate limit detail for org_ABC123"}}';

        foreach ([400, 401, 403, 404, 429] as $status) {
            $this->transport->willReturn($status, $rawBody);

            try {
                $this->analyzer()->analyze(self::request());
                self::fail(sprintf('%d でApiExceptionが投げられませんでした。', $status));
            } catch (AnalysisResultUnconfirmedException $exception) {
                self::fail(sprintf(
                    '%d を結果不明（claim非解放）にしてはいけません。',
                    $status,
                ));
            } catch (ApiException $exception) {
                self::assertSame(503, $exception->status());
                self::assertSame(
                    'analysis_unavailable',
                    $exception->errorCode(),
                );
                self::assertStringNotContainsString(
                    'secret rate limit detail',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'org_ABC123',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    self::SECRET_KEY,
                    $exception->getMessage(),
                );
                self::assertSame([], $exception->details());
            }
        }
    }

    /**
     * provider 5xx は、HTTPエラー応答を受け取っただけではOpenAI側で生成・課金が
     * 行われていないと確定できない。結果不明として`AnalysisResultUnconfirmedException`
     * で扱い、`CreateAnalysisAction`がclaimを解放しない。ユーザー向け応答は4xxと
     * 同じ`503 analysis_unavailable`。raw bodyやAPI keyは漏らさない。
     */
    public function testProviderFiveXxIsReportedAsUnconfirmed(): void
    {
        $rawBody = '{"error":{"message":"secret upstream detail org_XYZ789"}}';

        foreach ([500, 502, 503, 504, 529] as $status) {
            $this->transport->willReturn($status, $rawBody);

            try {
                $this->analyzer()->analyze(self::request());
                self::fail(sprintf(
                    '%d でAnalysisResultUnconfirmedExceptionが投げられませんでした。',
                    $status,
                ));
            } catch (AnalysisResultUnconfirmedException $exception) {
                self::assertSame(503, $exception->response()->status());
                self::assertSame(
                    'analysis_unavailable',
                    $exception->response()->errorCode(),
                );
                self::assertStringNotContainsString(
                    'secret upstream detail',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'org_XYZ789',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    self::SECRET_KEY,
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'secret upstream detail',
                    $exception->response()->getMessage(),
                );
                self::assertSame([], $exception->response()->details());
            }
        }
    }

    /**
     * timeout等の結果不明ケースは、AnalysisResultUnconfirmedExceptionで
     * 504 analysis_timeoutへ対応させる（claimは呼び出し元が解放しない）。
     */
    public function testTimeoutIsReportedAsAnUnconfirmedResult(): void
    {
        $this->transport->willTimeOut();

        try {
            $this->analyzer()->analyze(self::request());
            self::fail('AnalysisResultUnconfirmedExceptionが投げられませんでした。');
        } catch (AnalysisResultUnconfirmedException $exception) {
            self::assertSame(504, $exception->response()->status());
            self::assertSame(
                'analysis_timeout',
                $exception->response()->errorCode(),
            );
        }
    }

    /**
     * 2xxでも必要なoutput_text / strict schemaの結果を取得できない場合を
     * 正常終了扱いにしない。AI呼び出し済みなので即時再実行しない側へ倒す。
     */
    public function testTwoHundredWithoutUsableResultIsNotTreatedAsSuccess(): void
    {
        $unusable = [
            '{}',
            '{"output":[]}',
            self::wrapOutputText('これはJSONではない'),
            self::wrapOutputText('{"good":["x"],"bad":[],"score":"NaN"}'),
            self::responsesBody([
                'good' => [], 'bad' => [], 'score' => 10, 'emotion' => '中立',
                'summary' => 'x', 'advice' => 'x',
                // tags 欠落
            ]),
        ];

        foreach ($unusable as $body) {
            $this->transport->willReturn(200, $body);

            try {
                $this->analyzer()->analyze(self::request());
                self::fail(sprintf('正常終了しました: %s', $body));
            } catch (AnalysisResultUnconfirmedException $exception) {
                self::assertSame(500, $exception->response()->status());
                self::assertSame(
                    'internal_error',
                    $exception->response()->errorCode(),
                );
            }
        }
    }

    /**
     * HTTP 200でも、top-level `status`が`completed`でないResponseは成功に
     * しない。`status` = `incomplete`（`incomplete_details.reason` =
     * `max_output_tokens`）でschema-validなoutput_textが含まれていても、
     * OpenAI呼び出し済みで結果を確定できないため`AnalysisResultUnconfirmedException`
     * 側へ倒し、claimを解放しない。
     */
    public function testHttpTwoHundredWithIncompleteStatusIsNotTreatedAsSuccess(): void
    {
        $schemaValidOutputText = json_encode(
            self::structuredResult(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $this->transport->willReturn(200, self::wrapOutputText(
            $schemaValidOutputText,
            status: 'incomplete',
            incompleteReason: 'max_output_tokens',
        ));

        try {
            $this->analyzer()->analyze(self::request());
            self::fail('incompleteなResponseが正常終了扱いになりました。');
        } catch (AnalysisResultUnconfirmedException $exception) {
            self::assertSame(500, $exception->response()->status());
            self::assertSame(
                'internal_error',
                $exception->response()->errorCode(),
            );
        }
    }

    /**
     * requestがOpenAIへ到達していない失敗は確定失敗（503 analysis_unavailable）。
     */
    public function testUnreachableProviderIsAConfirmedFailure(): void
    {
        $this->transport->willBeUnreachable();

        try {
            $this->analyzer()->analyze(self::request());
            self::fail('ApiExceptionが投げられませんでした。');
        } catch (ApiException $exception) {
            self::assertSame(503, $exception->status());
            self::assertSame('analysis_unavailable', $exception->errorCode());
        }
    }

    public function testRequestIsSentToTheResponsesEndpointWithBearerAuth(): void
    {
        $this->transport->willReturn(200, self::responsesBody(self::structuredResult()));

        $this->analyzer()->analyze(self::request());

        self::assertSame(
            'https://api.openai.com/v1/responses',
            $this->transport->lastUrl,
        );
        self::assertSame(
            'Bearer ' . self::SECRET_KEY,
            $this->transport->lastHeaders['Authorization'],
        );
        self::assertSame(
            'application/json',
            $this->transport->lastHeaders['Content-Type'],
        );
    }

    private function analyzer(): OpenAiAnalyzer
    {
        return new OpenAiAnalyzer($this->transport, self::SECRET_KEY);
    }

    private static function request(): AnalysisRequest
    {
        return new AnalysisRequest(
            new DateTimeImmutable('2026-08-29T00:00:00Z'),
            new DateTimeImmutable('2026-08-29T09:00:00Z'),
            [
                new AnalysisEntry(
                    new DateTimeImmutable('2026-08-29T01:15:00Z'),
                    '😐',
                    '架空の気分',
                    null,
                ),
                new AnalysisEntry(
                    new DateTimeImmutable('2026-08-29T05:40:00Z'),
                    null,
                    null,
                    '架空のメモ',
                ),
            ],
        );
    }

    /**
     * @return array{
     *     good: list<string>, bad: list<string>, score: int, emotion: string,
     *     summary: string, advice: string, tags: list<string>
     * }
     */
    private static function structuredResult(): array
    {
        return [
            'good' => ['架空の良かったこと'],
            'bad' => [],
            'score' => 60,
            'emotion' => '中立',
            'summary' => '架空の要約。',
            'advice' => '架空の助言。',
            'tags' => ['架空タグ'],
        ];
    }

    /**
     * @param array<string, mixed> $structured
     */
    private static function responsesBody(array $structured): string
    {
        return self::wrapOutputText(
            json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private static function wrapOutputText(
        string $text,
        string $status = 'completed',
        ?string $incompleteReason = null,
    ): string {
        $response = [
            // 正常Response fixtureも status=completed を持つ。statusを見ない
            // 実装へ戻すと testHttpTwoHundredWithIncompleteStatus... が失敗する。
            'status' => $status,
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => $text],
                    ],
                ],
            ],
        ];

        if ($incompleteReason !== null) {
            $response['incomplete_details'] = ['reason' => $incompleteReason];
        }

        return json_encode(
            $response,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
