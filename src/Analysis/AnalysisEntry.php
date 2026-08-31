<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use DateTimeImmutable;

/**
 * 解析対象として受け取った1件のJournalEntry。
 *
 * Android側のJournalEntryのうち、解析に必要な項目だけを写した値である。
 * `id`・`source`・`deliveryStatus`・`moodId`はServerの解析に不要なため受け取らない。
 * Moodは絵文字だけ（`moodLabel`がnull）・名称だけ（`moodEmoji`がnull）・両方の
 * いずれもあり得る。Moodのみのentry（`note`がnull）と、noteのみのentry
 * （`moodEmoji`・`moodLabel`がともにnull）の双方を表現する。意味のある内容を
 * 最低1つ持ち、Moodもnoteも無いentryはparserが受け付けない（Issue #11）。
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
