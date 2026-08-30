<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use JournalingPostServer\Http\ApiException;

/**
 * AI providerを未実装のまま公開しないための既定のAnalyzer。
 *
 * Issue #4がAI provider実装へ差し替えるまで、解析requestは認証・検証・
 * idempotencyまで通常どおり処理され、AI呼び出しの直前で503を返す。
 * 503はretry可能なため、Androidは対象期間のJournalEntryを失わない。
 */
final class UnavailableAnalyzer implements Analyzer
{
    private const RETRY_AFTER_SECONDS = 60;

    public function analyze(AnalysisRequest $request): Analysis
    {
        throw new ApiException(
            503,
            'analysis_unavailable',
            'The analysis provider is not available.',
            headers: ['Retry-After' => (string) self::RETRY_AFTER_SECONDS],
        );
    }
}
