<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Integration\Support;

use Closure;
use DateTimeImmutable;
use JournalingPostServer\Analysis\Analysis;
use JournalingPostServer\Analysis\AnalysisRequest;
use JournalingPostServer\Analysis\Analyzer;

/**
 * AI providerを呼ばずに解析API境界を検証するためのAnalyzer。
 *
 * 実際のAI provider実装はIssue #4で追加する。ここではAPI境界（認証・検証・
 * idempotency・error契約）が解析の中身から独立していることを確認する。
 */
final class FakeAnalyzer implements Analyzer
{
    public int $callCount = 0;

    /** @var (Closure(AnalysisRequest): Analysis)|null */
    private ?Closure $behaviour = null;

    /**
     * @param Closure(AnalysisRequest): Analysis $behaviour
     */
    public function behaveAs(Closure $behaviour): void
    {
        $this->behaviour = $behaviour;
    }

    public function analyze(AnalysisRequest $request): Analysis
    {
        $this->callCount++;

        if ($this->behaviour !== null) {
            return ($this->behaviour)($request);
        }

        return new Analysis(
            new DateTimeImmutable('2026-08-29T09:00:05Z'),
            'example/analysis-model',
            sprintf('架空の振り返り（%d件）', count($request->entries)),
        );
    }
}
