<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

/**
 * 対象期間のJournalEntryから振り返りを生成する。
 *
 * 実装（`OpenAi\OpenAiAnalyzer`）はIssue #4で追加した。request契約・認証・
 * idempotency・error契約はこのinterfaceに依存せず、テストはこのseamでAI
 * providerを差し替える。
 *
 * 失敗は2種類を区別して投げる。
 *
 * 1. AIが成功していないと確定できる失敗。`JournalingPostServer\Http\ApiException`
 *    を投げる。`CreateAnalysisAction`はclaimを解放し、同じIdempotency-Keyでの
 *    再試行をそのまま許可する。provider未到達・provider 4xx（429等を含む）は
 *    `503 analysis_unavailable`。
 *
 * 2. AIへ送信後、処理・課金済みかServerから確定できない失敗。
 *    `AnalysisResultUnconfirmedException`を投げる。`CreateAnalysisAction`は
 *    claimを解放せず、同じkeyの即時retryがAIを再実行して二重課金しない側へ
 *    倒す。送信後timeoutは`504 analysis_timeout`、provider 5xxは4xxと同じ
 *    ユーザー向け応答`503 analysis_unavailable`（ただしclaimは解放しない）。
 */
interface Analyzer
{
    public function analyze(AnalysisRequest $request): Analysis;
}
