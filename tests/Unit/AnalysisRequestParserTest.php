<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

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
        $request = AnalysisRequestParser::parse(self::payload([
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
        $request = AnalysisRequestParser::parse([
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
        $utc = AnalysisRequestParser::parse([
            'period' => [
                'start' => '2026-08-29T00:00:00Z',
                'end' => '2026-08-29T09:00:00Z',
            ],
            'entries' => [
                ['recordedAt' => '2026-08-29T03:00:00.000Z', 'note' => '架空のメモ'],
            ],
        ]);
        $offset = AnalysisRequestParser::parse([
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
        $original = AnalysisRequestParser::parse(self::payload([
            ['recordedAt' => '2026-08-29T00:30:00Z', 'note' => '架空のメモ'],
        ]));
        $edited = AnalysisRequestParser::parse(self::payload([
            ['recordedAt' => '2026-08-29T00:30:00Z', 'note' => '別の架空のメモ'],
        ]));
        $reordered = AnalysisRequestParser::parse(self::payload([
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
        $request = AnalysisRequestParser::parse([
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
     * @param array<array-key, mixed> $payload
     * @return list<string>
     */
    private static function violations(array $payload): array
    {
        try {
            AnalysisRequestParser::parse($payload);
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('validation_error', $exception->errorCode());

            return $exception->details();
        }

        self::fail('検証エラーが発生しませんでした。');
    }
}
