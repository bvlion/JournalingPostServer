<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use DateTimeImmutable;

/**
 * 解析対象として受け取った1件のJournalEntry。
 *
 * Android側のJournalEntryのうち、解析に必要な項目だけを写した値である。
 * `id`・`source`・`deliveryStatus`・`moodId`はServerの解析に不要なため受け取らない。
 * moodのみのentry（`note`がnull）と、noteのみのentry（moodがnull）の双方を表現する。
 *
 * この値はrequest処理中だけ存在し、DBへ保存しない。
 */
final class AnalysisEntry
{
    public function __construct(
        public readonly DateTimeImmutable $recordedAt,
        public readonly ?string $moodEmoji,
        public readonly ?string $moodLabel,
        public readonly ?string $note,
    ) {
    }
}
