<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis\OpenAi;

use DateTimeImmutable;
use DateTimeZone;
use JournalingPostServer\Analysis\Analysis;
use JournalingPostServer\Analysis\AnalysisEntry;
use JournalingPostServer\Analysis\AnalysisResultUnconfirmedException;
use JournalingPostServer\Analysis\AnalysisRequest;
use JournalingPostServer\Analysis\Analyzer;
use JournalingPostServer\Http\ApiException;
use JsonException;

/**
 * OpenAI Responses API を呼び出す Hosted 解析の Analyzer 実装。
 *
 * model / response schema は本実装で固定する。対象期間・JournalEntry の抽出は
 * Android 側の責務で、Server は `AnalysisRequest::$entries` をそのまま解析材料に
 * する。
 *
 * タイムゾーン変換はしない。entries 0件は既存契約どおりparserが`validation_error`
 * で弾き、ここへ到達しない。
 *
 * secret（API key）とprovider error bodyを、repository・response・通常ログ・例外
 * メッセージへ出さない。
 */
final class OpenAiAnalyzer implements Analyzer
{
    public const ENDPOINT = 'https://api.openai.com/v1/responses';

    // 既定値。変更しない。
    public const MODEL = 'gpt-5.6-luna';
    public const REASONING_EFFORT = 'none';
    public const MAX_OUTPUT_TOKENS = 800;
    public const VERBOSITY = 'low';
    public const SCHEMA_NAME = 'slack_log_emotion_analysis';
    public const SYSTEM_PROMPT = 'これはローカル検証用の架空の system prompt です。実データではありません。';

    /** 出力の strict JSON Schema。 */
    public const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => [
            'good', 'bad', 'score', 'emotion', 'summary', 'advice', 'tags',
        ],
        'properties' => [
            'good' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'maxItems' => 3,
            ],
            'bad' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'maxItems' => 3,
            ],
            'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'emotion' => [
                'type' => 'string',
                'enum' => ['中立', 'ポジティブ', 'ネガティブ'],
            ],
            'summary' => ['type' => 'string'],
            'advice' => ['type' => 'string'],
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'minItems' => 0,
                'maxItems' => 5,
            ],
        ],
    ];

    /** 構造化結果をAnalysis.textへ整形するときの、項目の固定順と見出し。 */
    private const TEXT_SECTIONS = [
        'good' => '良かったこと',
        'bad' => '嫌だったこと',
        'score' => '感情スコア',
        'emotion' => '感情タイプ',
        'summary' => '要約',
        'advice' => 'AI アドバイス',
        'tags' => 'タグ',
    ];

    /**
     * user プロンプト先頭へ置く分析ルール本文。
     *
     * 1行が長いため、行長の sniff を無効化する。
     */
    // phpcs:disable Generic.Files.LineLength.TooLong
    private const ANALYSIS_RULES = <<<'RULES'
これはローカル検証・テスト用のダミー指示文です。実データを含みません。
本番の指示本文は実行環境ごとに config/analysis-instruction.php で設定します。
このひな形は systemPrompt / rules が非空であることの確認だけに使います。

出力キーと形は OpenAiAnalyzer::SCHEMA が唯一の定義です。ここでは名前だけ挙げます。

- good: 文字列の配列。
- bad: 文字列の配列。
- score: 整数。
- emotion: SCHEMA の enum のいずれか1つ。
- summary: 文字列。
- advice: 文字列。
- tags: 文字列の配列。
RULES;
    // phpcs:enable Generic.Files.LineLength.TooLong

    public function __construct(
        private ResponsesTransport $transport,
        private string $apiKey,
    ) {
    }

    public function analyze(AnalysisRequest $request): Analysis
    {
        try {
            $body = json_encode(
                self::buildRequestPayload($request),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            // 送信前の失敗。AIは呼ばれていない。
            throw self::providerUnavailable();
        }

        try {
            $result = $this->transport->post(
                self::ENDPOINT,
                [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                $body,
            );
        } catch (OpenAiUnreachableException $exception) {
            // requestはOpenAIへ到達していない。確定失敗として扱う。
            throw self::providerUnavailable();
        } catch (OpenAiUnconfirmedException $exception) {
            // 送信後に結果を確認できない。claimを解放しない側へ倒す。
            throw new AnalysisResultUnconfirmedException(
                $exception->timedOut()
                    ? self::analysisTimedOut()
                    : self::resultUnconfirmed(),
                $exception->getMessage(),
            );
        }

        if ($result->status >= 500) {
            // provider 5xx。OpenAIがrequestを受理・処理した後の一時的な5xxか、
            // 処理前の拒否かを、HTTPエラー応答を受け取ったことだけからは確定
            // できない。生成・課金が行われた可能性がある側へ倒し、claimを解放
            // しない（同じkeyの即時retryでAIを再実行しない）。ユーザー向け応答は
            // 4xxと同じ503 analysis_unavailableのまま。raw bodyは出さない。
            throw new AnalysisResultUnconfirmedException(
                self::providerUnavailable(),
                'OpenAI returned a 5xx response; the request outcome is not '
                    . 'confirmed.',
            );
        }

        if ($result->status < 200 || $result->status >= 300) {
            // 4xx（および1xx / 3xx）。処理前の拒否と確定できる確定失敗として
            // 扱い、claimを解放して同じkeyで再試行できるようにする。OpenAI error
            // responseのraw bodyは例外・ログ・応答へ出さない（本文は握り潰す）。
            throw self::providerUnavailable();
        }

        $structured = self::extractStructuredResult($result->body);

        if ($structured === null) {
            // 2xxでも必要なoutput_text / strict schemaの結果が無い。AIは呼ばれて
            // おり課金され得るため、正常終了扱いにせず、即時再実行もしない。
            throw new AnalysisResultUnconfirmedException(
                self::resultUnconfirmed(),
                'OpenAI returned a 2xx response without a usable structured '
                    . 'result.',
            );
        }

        return new Analysis(
            new DateTimeImmutable('now'),
            self::MODEL,
            self::formatAnalysisText($structured),
        );
    }

    /**
     * Responses API payload。model / input / reasoning / max_output_tokens /
     * text.verbosity / text.format / store:false を設定する。
     *
     * @return array<string, mixed>
     */
    public static function buildRequestPayload(AnalysisRequest $request): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                [
                    'role' => 'user',
                    'content' => self::buildUserPrompt(
                        self::buildTranscript($request),
                    ),
                ],
            ],
            'reasoning' => ['effort' => self::REASONING_EFFORT],
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            'text' => [
                'verbosity' => self::VERBOSITY,
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::SCHEMA_NAME,
                    'strict' => true,
                    'schema' => self::SCHEMA,
                ],
            ],
            'store' => false,
        ];
    }

    /**
     * AIへ渡すログ文字列をentriesから構築する。
     *
     * - recordedAt昇順で並べる。
     * - moodがあるentryの本文は次のとおり。
     *   moodに絵文字があれば絵文字、無ければ名称（label）を使い、moodだけ
     *   なら「気分は{X}とのこと」、noteもあればその後へtrimしたnoteを続ける。
     *   noteだけならnoteを使う（Issue #11）。
     * - recordedAtはUTCの絶対時刻として扱う。タイムゾーン変換はしない。
     * - 日時だけのログ行は作らない。Moodもnoteも無いentryはparserが
     *   `validation_error`で弾き、ここへ到達しない。
     */
    public static function buildTranscript(AnalysisRequest $request): string
    {
        $entries = $request->entries;
        usort(
            $entries,
            static fn (AnalysisEntry $a, AnalysisEntry $b): int
                => $a->recordedAt <=> $b->recordedAt,
        );

        $lines = array_map(
            static function (AnalysisEntry $entry): string {
                $timestamp = $entry->recordedAt
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');

                return $timestamp . ' ' . self::entryMessage($entry);
            },
            $entries,
        );

        return implode("\n", $lines);
    }

    private static function entryMessage(AnalysisEntry $entry): string
    {
        // Moodにemojiがあればemoji、無ければlabelを解析材料に使う。label-onlyの
        // Moodでlabelが失われないようにするため（Issue #11）。
        $mood = $entry->moodEmoji ?? $entry->moodLabel;

        if ($mood !== null) {
            $note = $entry->note !== null ? trim($entry->note) : '';

            return $note === ''
                ? sprintf('気分は%sとのこと', $mood)
                : sprintf('気分は%sとのこと。%s', $mood, $note);
        }

        return $entry->note ?? '';
    }

    private static function buildUserPrompt(string $transcript): string
    {
        // 分析ルール本文の後ろへ `## Slackのログ` 見出しと対象期間のログ文字列を
        // 続ける。
        return self::ANALYSIS_RULES
            . "\n\n## Slackのログ\n"
            . $transcript
            . "\n";
    }

    /**
     * Responses APIのoutputから最初の
     * output_textを取得し、trimしてJSONとして解釈し、strict schemaの7項目を
     * 取り出す。どこかで取得できなければnullを返し、正常終了扱いにしない。
     *
     * Responses APIはHTTP 200でもtop-level `status`が`incomplete`（例:
     * `incomplete_details.reason` = `max_output_tokens`）や`failed`を返す。
     * OpenAIのStructured Outputs仕様に合わせ、`status` = `completed`のResponse
     * だけを構造化結果の成功候補にする。`completed`以外はschema-validな
     * output_textを含んでいても成功にしない（呼び出し済みだが結果を確定できず、
     * 呼び出し元が`AnalysisResultUnconfirmedException`側へ倒す）。
     *
     * @return array{
     *     good: list<string>, bad: list<string>, score: int, emotion: string,
     *     summary: string, advice: string, tags: list<string>
     * }|null
     */
    public static function extractStructuredResult(string $responseBody): ?array
    {
        try {
            $response = json_decode(
                $responseBody,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            return null;
        }

        if (
            !is_array($response)
            || ($response['status'] ?? null) !== 'completed'
        ) {
            return null;
        }

        $outputText = self::firstOutputText($response);

        if ($outputText === null) {
            return null;
        }

        try {
            $structured = json_decode(
                trim($outputText),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            return null;
        }

        return self::normaliseStructuredResult($structured);
    }

    private static function firstOutputText(mixed $response): ?string
    {
        if (!is_array($response) || !is_array($response['output'] ?? null)) {
            return null;
        }

        foreach ($response['output'] as $item) {
            if (
                !is_array($item)
                || ($item['type'] ?? null) !== 'message'
                || !is_array($item['content'] ?? null)
            ) {
                continue;
            }

            foreach ($item['content'] as $content) {
                if (
                    is_array($content)
                    && ($content['type'] ?? null) === 'output_text'
                    && is_string($content['text'] ?? null)
                ) {
                    return $content['text'];
                }
            }
        }

        return null;
    }

    /**
     * @return array{
     *     good: list<string>, bad: list<string>, score: int, emotion: string,
     *     summary: string, advice: string, tags: list<string>
     * }|null
     */
    private static function normaliseStructuredResult(mixed $structured): ?array
    {
        if (!is_array($structured)) {
            return null;
        }

        $good = self::stringList($structured['good'] ?? null);
        $bad = self::stringList($structured['bad'] ?? null);
        $tags = self::stringList($structured['tags'] ?? null);
        $score = $structured['score'] ?? null;
        $emotion = $structured['emotion'] ?? null;
        $summary = $structured['summary'] ?? null;
        $advice = $structured['advice'] ?? null;

        if (
            $good === null
            || $bad === null
            || $tags === null
            || !is_int($score)
            || !is_string($emotion)
            || !is_string($summary)
            || !is_string($advice)
        ) {
            return null;
        }

        return [
            'good' => $good,
            'bad' => $bad,
            'score' => $score,
            'emotion' => $emotion,
            'summary' => $summary,
            'advice' => $advice,
            'tags' => $tags,
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function stringList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * 構造化7項目を、AndroidのAnalysisResult.bodyへ保存できる単一のプレーン
     * テキストへ固定順で整形する。
     * good/badは箇条書き、空配列は「なし」。JSON文字列をそのままbodyにしない。
     *
     * @param array{
     *     good: list<string>, bad: list<string>, score: int, emotion: string,
     *     summary: string, advice: string, tags: list<string>
     * } $structured
     */
    public static function formatAnalysisText(array $structured): string
    {
        $blocks = [];

        foreach (self::TEXT_SECTIONS as $key => $heading) {
            $blocks[] = '【' . $heading . '】' . "\n"
                . self::sectionBody($key, $structured[$key]);
        }

        return implode("\n\n", $blocks);
    }

    private static function sectionBody(string $key, mixed $value): string
    {
        if ($key === 'good' || $key === 'bad') {
            /** @var list<string> $value */
            return $value === []
                ? 'なし'
                : implode(
                    "\n",
                    array_map(static fn (string $item): string => '- ' . $item, $value),
                );
        }

        if ($key === 'tags') {
            /** @var list<string> $value */
            return $value === [] ? 'なし' : implode(', ', $value);
        }

        if ($key === 'score') {
            return $value . ' / 100';
        }

        return (string) $value;
    }

    private static function providerUnavailable(): ApiException
    {
        return new ApiException(
            503,
            'analysis_unavailable',
            'The analysis provider is not available.',
            headers: ['Retry-After' => '60'],
        );
    }

    private static function analysisTimedOut(): ApiException
    {
        return new ApiException(
            504,
            'analysis_timeout',
            'The analysis did not complete in time.',
            headers: ['Retry-After' => '15'],
        );
    }

    private static function resultUnconfirmed(): ApiException
    {
        return new ApiException(
            500,
            'internal_error',
            'The server failed to process the request.',
        );
    }
}
