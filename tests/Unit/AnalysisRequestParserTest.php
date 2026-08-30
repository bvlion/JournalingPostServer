<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use JournalingPostServer\Analysis\AnalysisRequest;
use JournalingPostServer\Analysis\AnalysisRequestParser;
use JournalingPostServer\Http\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * 解析requestの契約。fixtureの値はすべて架空のものを使用する。
 */
final class AnalysisRequestParserTest extends TestCase
{
    public function testAcceptsMoodOnlyNoteOnlyAndCombinedEntries(): void
    {
        $request = self::parse(self::payload([
            ['recordedAt' => '2026-08-29T00:30:00Z', 'mood' => self::mood()],
            ['recordedAt' => '2026-08-29T01:30:00Z', 'note' => '架空のメモ'],
            [
                'recordedAt' => '2026-08-29T02:30:00Z',
                'mood' => self::mood(),
                'note' => '架空のメモ',
            ],
        ]));

        self::assertCount(3, $request->entries);
        self::assertNull($request->entries[0]->note);
        self::assertNull($request->entries[1]->moodEmoji);
        self::assertNull($request->entries[1]->moodLabel);
        self::assertSame('😐', $request->entries[2]->moodEmoji);
        self::assertSame('架空のメモ', $request->entries[2]->note);
    }

    public function testPeriodIsNormalisedToUtc(): void
    {
        $request = self::parse([
            'period' => [
                'start' => '2026-08-29T09:00:00+09:00',
                'end' => '2026-08-29T18:00:00+09:00',
            ],
            'entries' => [
                ['recordedAt' => '2026-08-29T12:00:00+09:00', 'mood' => self::mood()],
            ],
        ]);

        self::assertSame(
            '2026-08-29T00:00:00Z',
            $request->periodStart->format('Y-m-d\TH:i:s\Z'),
        );
        self::assertSame(
            '2026-08-29T03:00:00Z',
            $request->entries[0]->recordedAt->format('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * 同じ内容のrequestは、timezone表記やキー順序が違っても同じhashになる。
     * network timeoutからの再送を同一requestと判定するために必要。
     */
    public function testFingerprintIgnoresEquivalentRepresentations(): void
    {
        $utc = self::parse([
            'period' => [
                'start' => '2026-08-29T00:00:00Z',
                'end' => '2026-08-29T09:00:00Z',
            ],
            'entries' => [
                ['recordedAt' => '2026-08-29T03:00:00.000Z', 'note' => '架空のメモ'],
            ],
        ]);
        $offset = self::parse([
            'entries' => [
                ['note' => '架空のメモ', 'recordedAt' => '2026-08-29T12:00:00+09:00'],
            ],
            'period' => [
                'end' => '2026-08-29T18:00:00+09:00',
                'start' => '2026-08-29T09:00:00+09:00',
            ],
        ]);

        self::assertSame($utc->fingerprint(), $offset->fingerprint());
    }

    public function testFingerprintChangesWithContent(): void
    {
        $original = self::parse(self::payload([
            ['recordedAt' => '2026-08-29T00:30:00Z', 'note' => '架空のメモ'],
        ]));
        $edited = self::parse(self::payload([
            ['recordedAt' => '2026-08-29T00:30:00Z', 'note' => '別の架空のメモ'],
        ]));
        $reordered = self::parse(self::payload([
            ['recordedAt' => '2026-08-29T00:30:00Z', 'note' => '架空のメモ'],
            ['recordedAt' => '2026-08-29T01:30:00Z', 'note' => '別の架空のメモ'],
        ]));

        self::assertNotSame($original->fingerprint(), $edited->fingerprint());
        self::assertNotSame($original->fingerprint(), $reordered->fingerprint());
    }

    /**
     * 対象期間に記録が無い場合、AI呼び出しへ進ませない。
     */
    public function testEmptyEntriesAreRejected(): void
    {
        $violations = self::violations(self::payload([]));

        self::assertContains(
            'entries: must contain at least one entry.',
            $violations,
        );
    }

    public function testEntryCountUpperBoundIsEnforced(): void
    {
        $entries = array_fill(
            0,
            AnalysisRequestParser::MAX_ENTRIES + 1,
            ['recordedAt' => '2026-08-29T00:30:00Z', 'mood' => self::mood()],
        );

        self::assertContains(
            sprintf(
                'entries: must not contain more than %d entries.',
                AnalysisRequestParser::MAX_ENTRIES,
            ),
            self::violations(self::payload($entries)),
        );
    }

    public function testAllViolationsAreReportedTogether(): void
    {
        $violations = self::violations([
            'period' => [
                'start' => '2026-08-29T09:00:00Z',
                'end' => '2026-08-29T09:00:00Z',
            ],
            'entries' => [
                ['recordedAt' => '29/08/2026 00:30'],
                [
                    'recordedAt' => '2026-08-29T00:30:00Z',
                    'mood' => ['emoji' => '😐'],
                ],
                [
                    'recordedAt' => '2026-08-29T00:30:00Z',
                    'note' => str_repeat(
                        'あ',
                        AnalysisRequestParser::MAX_NOTE_LENGTH + 1,
                    ),
                ],
            ],
        ]);

        self::assertSame(
            [
                'period.end: must be later than period.start.',
                'entries[0].recordedAt: must be an RFC 3339 timestamp.',
                'entries[1].mood.label: must be a non-empty string.',
                sprintf(
                    'entries[2].note: must not be longer than %d characters.',
                    AnalysisRequestParser::MAX_NOTE_LENGTH,
                ),
            ],
            $violations,
        );
    }

    /**
     * error responseへJournalEntry本文を出さない。
     */
    public function testViolationsDoNotContainEntryContent(): void
    {
        $note = 'サーバーの応答へ出てはいけない架空のメモ';
        $violations = self::violations([
            'entries' => [
                ['recordedAt' => 'not-a-timestamp', 'note' => $note],
            ],
        ]);

        self::assertNotSame([], $violations);

        foreach ($violations as $violation) {
            self::assertStringNotContainsString($note, $violation);
        }
    }

    /**
     * `DateTimeImmutable`は存在しない暦日を正規化してしまうため、桁数だけでは
     * 契約違反を弾けない。RFC 3339として不正な値を受理しないことを確認する。
     */
    public function testInvalidTimestampsAreRejected(): void
    {
        $invalidTimestamps = [
            '2026-02-30T00:00:00Z',
            '2025-02-29T00:00:00Z',
            '2026-13-01T00:00:00Z',
            '2026-00-10T00:00:00Z',
            '2026-08-00T00:00:00Z',
            '2026-08-32T00:00:00Z',
            '2026-08-29T24:00:00Z',
            '2026-08-29T00:60:00Z',
            '2026-08-29T00:00:60Z',
            '2026-08-29T00:00:00+24:00',
            '2026-08-29T00:00:00+09:60',
            '2026-08-29 00:00:00Z',
            '2026-08-29T00:00:00z',
            '2026-08-29T00:00:00',
            '2026-08-29',
        ];

        foreach ($invalidTimestamps as $invalidTimestamp) {
            self::assertSame(
                ['period.start: must be an RFC 3339 timestamp.'],
                self::violations([
                    'period' => [
                        'start' => $invalidTimestamp,
                        'end' => '2026-08-29T09:00:00Z',
                    ],
                    'entries' => [
                        [
                            'recordedAt' => '2026-08-29T00:30:00Z',
                            'mood' => self::mood(),
                        ],
                    ],
                ]),
                sprintf('受理されました: %s', $invalidTimestamp),
            );
        }
    }

    public function testValidBoundaryTimestampsAreAccepted(): void
    {
        $request = self::parse([
            'period' => [
                'start' => '2024-02-29T00:00:00Z',
                'end' => '2024-03-01T23:59:59.999999+13:45',
            ],
            'entries' => [
                [
                    'recordedAt' => '2024-02-29T23:59:59.999-11:30',
                    'mood' => self::mood(),
                ],
            ],
        ]);

        self::assertSame(
            '2024-02-29T00:00:00Z',
            $request->periodStart->format('Y-m-d\TH:i:s\Z'),
        );
        self::assertSame(
            '2024-03-01T10:14:59Z',
            $request->periodEnd->format('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * `{"0":…}`のようなJSON objectは、associativeな`json_decode()`では整数キーの
     * 配列になり`array_is_list()`を通過する。JSON上の型で判定し、JSON arrayの
     * 場合だけ受理する。
     */
    public function testEntriesGivenAsAJsonObjectAreRejected(): void
    {
        $payload = json_decode(
            '{"period":{"start":"2026-08-29T00:00:00Z",'
                . '"end":"2026-08-29T09:00:00Z"},'
                . '"entries":{"0":{"recordedAt":"2026-08-29T00:30:00Z"}}}',
            false,
            flags: JSON_THROW_ON_ERROR,
        );

        try {
            AnalysisRequestParser::parse($payload);
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame(
                ['entries: must be an array.'],
                $exception->details(),
            );

            return;
        }

        self::fail('JSON objectのentriesが受理されました。');
    }

    public function testMissingPeriodAndEntriesAreReported(): void
    {
        self::assertSame(
            ['period: must be an object.', 'entries: must be an array.'],
            self::violations([]),
        );
    }

    /**
     * Android側が項目を追加してもServerを先に更新せずに済むよう、未知のキーは
     * 無視する。
     */
    public function testUnknownFieldsAreIgnored(): void
    {
        $request = self::parse([
            'period' => [
                'start' => '2026-08-29T00:00:00Z',
                'end' => '2026-08-29T09:00:00Z',
                'label' => '架空のラベル',
            ],
            'entries' => [
                [
                    'recordedAt' => '2026-08-29T00:30:00Z',
                    'mood' => self::mood() + ['id' => 'NEUTRAL'],
                    'source' => 'WIDGET',
                ],
            ],
            'clientVersion' => '0.0.0',
        ]);

        self::assertCount(1, $request->entries);
    }

    /**
     * parserはassociativeにしない`json_decode()`の結果を受け取る。JSON上の型を
     * 保ったまま渡すため、テストの配列もJSONを経由して変換する。連想配列は
     * JSON object（`stdClass`）、listはJSON array（配列）になる。
     *
     * @param array<string, mixed> $payload
     */
    private static function parse(array $payload): AnalysisRequest
    {
        return AnalysisRequestParser::parse(
            json_decode(
                json_encode((object) $payload, JSON_THROW_ON_ERROR),
                false,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private static function payload(array $entries): array
    {
        return [
            'period' => [
                'start' => '2026-08-29T00:00:00Z',
                'end' => '2026-08-29T09:00:00Z',
            ],
            'entries' => $entries,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function mood(): array
    {
        return ['emoji' => '😐', 'label' => '架空の気分'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function violations(array $payload): array
    {
        try {
            self::parse($payload);
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('validation_error', $exception->errorCode());

            return $exception->details();
        }

        self::fail('検証エラーが発生しませんでした。');
    }
}
