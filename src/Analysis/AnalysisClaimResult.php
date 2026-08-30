<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use DateTimeImmutable;

/**
 * `AnalysisRequestRepository::claim()`の判定結果。
 *
 * `claimedAt`は判定対象になった行の`started_at`であり、同じIdempotency-Keyの
 * 世代を識別する。同じkeyでも、保持期間切れで削除された後に作り直された行は
 * 別の世代である。完了記録・引き渡しバッファの読み書き・claimの解放は、いずれも
 * この値で世代を固定してから行う。
 */
final class AnalysisClaimResult
{
    public function __construct(
        public readonly AnalysisClaim $status,
        public readonly DateTimeImmutable $claimedAt,
    ) {
    }
}
