<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis\OpenAi;

/**
 * OpenAI Responses APIへ1回POSTするだけの最小のtransport。
 *
 * `OpenAiAnalyzer`をこの1点でseam化し、テストが実OpenAIへ接続せずに
 * request構築・応答解析・失敗時の扱いを検証できるようにする。
 */
interface ResponsesTransport
{
    /**
     * @param array<string, string> $headers 送信するHTTPヘッダー
     *
     * @throws OpenAiUnreachableException requestがOpenAIへ到達していない
     * @throws OpenAiUnconfirmedException 送信後、結果を確認できない
     */
    public function post(string $url, array $headers, string $body): TransportResult;
}
