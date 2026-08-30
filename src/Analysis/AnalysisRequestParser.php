<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use JournalingPostServer\Http\ApiException;
use stdClass;

/**
 * Hosted解析requestのJSONを検証し、`AnalysisRequest`へ変換する。
 *
 * 契約の詳細は`docs/hosted-analysis-api.md`にまとめている。
 *
 * 未知のキーは無視する。Android側が項目を追加しても、Server側を先に更新せずに
 * 済ませるためである（追加は互換、削除・意味変更は非互換）。
 *
 * 検証エラーはすべて集めて1度に返す。`details`にはフィールドパスと違反内容だけを
 * 入れ、受け取った値（JournalEntry本文を含む）は入れない。
 *
 * 入力は`json_decode()`をassociativeにせず復号した値である。JSON objectは
 * `stdClass`、JSON arrayはPHPの配列になり、JSON上の型がそのまま残る。
 * associativeで復号すると`{"0":…}`のようなJSON objectが整数キーの配列になり、
 * `array_is_list()`でJSON arrayと区別できなくなる。
 */
final class AnalysisRequestParser
{
    /** 1requestあたりのentry数の上限。AI呼び出し費用の主な変動要因。 */
    public const MAX_ENTRIES = 200;

    /** noteの文字数上限（バイト数ではなく文字数）。 */
    public const MAX_NOTE_LENGTH = 2000;

    public const MAX_MOOD_LABEL_LENGTH = 100;

    public const MAX_MOOD_EMOJI_LENGTH = 16;

    /**
     * RFC 3339のうち、Androidの`Instant.toString()`が生成する表記だけを
     * 受け付ける。区切りの`T`と`Z`は大文字のみ、秒未満は任意桁、オフセットは
     * `Z`または`+09:00`形式とする。
     *
     * 桁数だけでは`2026-02-30`のような存在しない日付を弾けないため、
     * `validateTimestampParts()`で暦日・時刻・オフセットの範囲も検証する。
     */
    private const TIMESTAMP_PATTERN = '/\A'
        . '(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})'
        . 'T(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})'
        . '(?:\.\d{1,9})?'
        . '(?:Z|(?<offsetSign>[+-])(?<offsetHour>\d{2}):(?<offsetMinute>\d{2}))'
        . '\z/';

    public static function parse(stdClass $payload): AnalysisRequest
    {
        /** @var list<string> $violations */
        $violations = [];

        [$periodStart, $periodEnd] = self::parsePeriod($payload, $violations);
        $entries = self::parseEntries($payload, $violations);

        if ($violations !== []) {
            throw new ApiException(
                422,
                'validation_error',
                'The request does not satisfy the analysis request contract.',
                $violations,
            );
        }

        return new AnalysisRequest($periodStart, $periodEnd, $entries);
    }

    /**
     * @param list<string> $violations
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function parsePeriod(
        stdClass $payload,
        array &$violations,
    ): array {
        $epoch = new DateTimeImmutable('@0');
        $period = $payload->period ?? null;

        if (!$period instanceof stdClass) {
            $violations[] = 'period: must be an object.';

            return [$epoch, $epoch];
        }

        $start = self::parseTimestamp(
            $period->start ?? null,
            'period.start',
            $violations,
        );
        $end = self::parseTimestamp(
            $period->end ?? null,
            'period.end',
            $violations,
        );

        if ($start !== null && $end !== null && $start >= $end) {
            $violations[] = 'period.end: must be later than period.start.';
        }

        return [$start ?? $epoch, $end ?? $epoch];
    }

    /**
     * @param list<string> $violations
     * @return list<AnalysisEntry>
     */
    private static function parseEntries(
        stdClass $payload,
        array &$violations,
    ): array {
        $entries = $payload->entries ?? null;

        // JSON arrayだけが配列になる。JSON objectは`stdClass`のままなので、
        // `{"0":…}`のようなキーでもarrayとして受理されない。
        if (!is_array($entries)) {
            $violations[] = 'entries: must be an array.';

            return [];
        }

        if ($entries === []) {
            // 0件でAI呼び出しを行わないための契約。対象期間に記録が無い場合、
            // Androidは解析requestを送らない。
            $violations[] = 'entries: must contain at least one entry.';

            return [];
        }

        if (count($entries) > self::MAX_ENTRIES) {
            $violations[] = sprintf(
                'entries: must not contain more than %d entries.',
                self::MAX_ENTRIES,
            );

            return [];
        }

        $parsed = [];

        foreach ($entries as $index => $entry) {
            $path = sprintf('entries[%d]', $index);

            if (!$entry instanceof stdClass) {
                $violations[] = sprintf('%s: must be an object.', $path);

                continue;
            }

            $recordedAt = self::parseTimestamp(
                $entry->recordedAt ?? null,
                $path . '.recordedAt',
                $violations,
            );
            [$moodEmoji, $moodLabel] = self::parseMood(
                $entry->mood ?? null,
                $path . '.mood',
                $violations,
            );
            $note = self::parseNote(
                $entry->note ?? null,
                $path . '.note',
                $violations,
            );

            if ($recordedAt === null) {
                continue;
            }

            $parsed[] = new AnalysisEntry(
                $recordedAt,
                $moodEmoji,
                $moodLabel,
                $note,
            );
        }

        return $parsed;
    }

    /**
     * moodは省略できる（noteだけのentry）。指定する場合は`emoji`と`label`の
     * 両方が必要で、これはAndroid側でmoodId/moodEmoji/moodLabelが常に揃って
     * 保存されることに対応する。`moodId`はServerの解析に不要なため受け取らない。
     *
     * @param list<string> $violations
     * @return array{?string, ?string}
     */
    private static function parseMood(
        mixed $mood,
        string $path,
        array &$violations,
    ): array {
        if ($mood === null) {
            return [null, null];
        }

        if (!$mood instanceof stdClass) {
            $violations[] = sprintf('%s: must be an object or null.', $path);

            return [null, null];
        }

        $emoji = self::parseRequiredText(
            $mood->emoji ?? null,
            $path . '.emoji',
            self::MAX_MOOD_EMOJI_LENGTH,
            $violations,
        );
        $label = self::parseRequiredText(
            $mood->label ?? null,
            $path . '.label',
            self::MAX_MOOD_LABEL_LENGTH,
            $violations,
        );

        return [$emoji, $label];
    }

    /**
     * @param list<string> $violations
     */
    private static function parseRequiredText(
        mixed $value,
        string $path,
        int $maximumLength,
        array &$violations,
    ): ?string {
        if (!is_string($value) || trim($value) === '') {
            $violations[] = sprintf(
                '%s: must be a non-empty string.',
                $path,
            );

            return null;
        }

        if (mb_strlen($value) > $maximumLength) {
            $violations[] = sprintf(
                '%s: must not be longer than %d characters.',
                $path,
                $maximumLength,
            );

            return null;
        }

        return $value;
    }

    /**
     * noteは省略できる（moodだけのentry）。空白のみのnoteは情報を持たないため
     * 未指定と同じに扱う。値そのものは変更しない。
     *
     * @param list<string> $violations
     */
    private static function parseNote(
        mixed $note,
        string $path,
        array &$violations,
    ): ?string {
        if ($note === null) {
            return null;
        }

        if (!is_string($note)) {
            $violations[] = sprintf('%s: must be a string or null.', $path);

            return null;
        }

        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            $violations[] = sprintf(
                '%s: must not be longer than %d characters.',
                $path,
                self::MAX_NOTE_LENGTH,
            );

            return null;
        }

        return trim($note) === '' ? null : $note;
    }

    /**
     * @param list<string> $violations
     */
    private static function parseTimestamp(
        mixed $value,
        string $path,
        array &$violations,
    ): ?DateTimeImmutable {
        $matched = is_string($value)
            && preg_match(self::TIMESTAMP_PATTERN, $value, $parts) === 1;

        if (!$matched || !self::validateTimestampParts($parts)) {
            $violations[] = sprintf(
                '%s: must be an RFC 3339 timestamp.',
                $path,
            );

            return null;
        }

        try {
            $moment = new DateTimeImmutable($value);
        } catch (Exception) {
            $violations[] = sprintf(
                '%s: must be an RFC 3339 timestamp.',
                $path,
            );

            return null;
        }

        // 以降は絶対時刻としてのみ扱うため、UTCへ正規化する。Serverは
        // 端末のtimezoneを解釈しない。
        return $moment->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * 暦日・時刻・オフセットの範囲を検証する。
     *
     * `DateTimeImmutable`は`2026-02-30`を3月2日へ繰り上げるなど、存在しない
     * 日付を例外なしで正規化する。契約どおり422で拒否するため、生成前に確認する。
     *
     * うるう秒（`:60`）は受け付けない。Androidの`Instant.toString()`が生成せず、
     * 受け付けても解析の入力として意味を持たないためである。
     *
     * @param array<string, string> $parts
     */
    private static function validateTimestampParts(array $parts): bool
    {
        if (
            !checkdate(
                (int) $parts['month'],
                (int) $parts['day'],
                (int) $parts['year'],
            )
        ) {
            return false;
        }

        if (
            (int) $parts['hour'] > 23
            || (int) $parts['minute'] > 59
            || (int) $parts['second'] > 59
        ) {
            return false;
        }

        // オフセットが無い（`Z`）場合、名前付きグループは設定されない。
        if (($parts['offsetHour'] ?? '') === '') {
            return true;
        }

        return (int) $parts['offsetHour'] <= 23
            && (int) $parts['offsetMinute'] <= 59;
    }
}
