<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use DateTimeImmutable;

/**
 * 検証済みのHosted解析request。
 *
 * この値はrequest処理中だけ存在し、DBへ保存しない。DBへ残すのは
 * `fingerprint()`が返すhashだけで、hashからJournalEntry本文は復元できない。
 */
final class AnalysisRequest
{
    /**
     * @param list<AnalysisEntry> $entries
     */
    public function __construct(
        public readonly DateTimeImmutable $periodStart,
        public readonly DateTimeImmutable $periodEnd,
        public readonly array $entries,
    ) {
    }

    /**
     * 同じIdempotency-Keyで送られたrequestが同一内容かを判定するためのhash。
     *
     * 受信したJSONのbyte列ではなく、検証後の値を正規化してからhashする。
     * timestampはUTC・microsecond精度へ、keyの順序は固定へ揃えるため、
     * 意味が同じrequestは表記が違っても同じhashになる。
     */
    public function fingerprint(): string
    {
        $canonical = [
            'period' => [
                'start' => self::canonicalTimestamp($this->periodStart),
                'end' => self::canonicalTimestamp($this->periodEnd),
            ],
            'entries' => array_map(
                static fn (AnalysisEntry $entry): array => [
                    'recordedAt' => self::canonicalTimestamp($entry->recordedAt),
                    'moodEmoji' => $entry->moodEmoji,
                    'moodLabel' => $entry->moodLabel,
                    'note' => $entry->note,
                ],
                $this->entries,
            ),
        ];

        return hash(
            'sha256',
            json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    private static function canonicalTimestamp(
        DateTimeImmutable $moment,
    ): string {
        return $moment->format('Y-m-d\TH:i:s.u\Z');
    }
}
