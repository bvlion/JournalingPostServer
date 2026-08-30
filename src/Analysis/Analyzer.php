<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

/**
 * 対象期間のJournalEntryから振り返りを生成する。
 *
 * AI providerの実装はIssue #4で追加する。#2はこのinterfaceをAPI境界として
 * 定義するだけで、request契約・認証・idempotency・error契約は実装側に依存しない。
 *
 * 失敗時は`JournalingPostServer\Http\ApiException`を投げ、Androidが
 * retry可能かどうかを判断できるstatusとcodeを指定する。
 */
interface Analyzer
{
    public function analyze(AnalysisRequest $request): Analysis;
}
