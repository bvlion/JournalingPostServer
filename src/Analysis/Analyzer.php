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
 *    claimを解放する。追加call・課金が発生し得るが再試行を許可し、成功済み日
 *    にはしない。送信後timeoutは`504 analysis_timeout`、provider 5xxは4xxと
 *    同じユーザー向け応答`503 analysis_unavailable`。
 */
interface Analyzer
{
    public function analyze(AnalysisRequest $request): Analysis;
}
