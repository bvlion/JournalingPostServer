<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use DateTimeImmutable;

/**
 * AI解析の結果。Androidはこれを自身のAnalysisResultとして保存する。
 *
 * `text`は本文であり、Serverの原本ではない。恒久保存せず、response送出後は
 * 再送への引き渡しバッファ（`analysis_deliveries`）に保持期間の間だけ残る。
 */
final class Analysis
{
    public function __construct(
        public readonly DateTimeImmutable $analyzedAt,
        public readonly string $model,
        public readonly string $text,
    ) {
    }
}
