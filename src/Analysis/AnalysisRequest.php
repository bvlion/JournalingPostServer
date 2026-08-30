<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use DateTimeImmutable;

/**
 * 検証済みのHosted解析request。
 *
 * この値はrequest処理中だけ存在し、DBへ保存しない。DBへ残すのは
 * `fingerprint()`が返すhashだけである。
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
     *
     * 素のSHA-256にしない。mood 1件だけのrequestのように入力空間が狭い場合、
     * DBを読める側が期間・記録時刻・moodの候補から正規化JSONを列挙してhashを
     * 突き合わせ、JournalEntryの内容を言い当てられるためである。Serverだけが
     * 持つ`$secret`による鍵付きhash（HMAC）にして、DB単体では候補照合できない
     * ようにする。
     *
     * `$installationId`をhashの入力へ含め、installation単位にscopeする。
     * idempotencyの判定はinstallationの中で閉じており、installationを跨いだ
     * 同一requestの相関はServerの用途に不要なためである。
     */
    public function fingerprint(string $installationId, string $secret): string
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

        return hash_hmac(
            'sha256',
            // installation識別子は固定長（UUID）だが、正規化JSONとの境界を
            // 明示するため区切りを入れる。
            $installationId . "\n" . json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
            ),
            $secret,
        );
    }

    private static function canonicalTimestamp(
        DateTimeImmutable $moment,
    ): string {
        return $moment->format('Y-m-d\TH:i:s.u\Z');
    }
}
