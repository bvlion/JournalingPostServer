<?php

declare(strict_types=1);

namespace JournalingPostServer\Tests\Unit;

use JournalingPostServer\Analysis\AnalysisRequestParser;
use JournalingPostServer\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class AnalysisDateTest extends TestCase
{
    public function testCalendarFormatAndRecordedAtBoundaries(): void
    {
        $cases = [
            ['20260907', ['2026-09-06T00:00:00Z', '2026-09-07T00:00:00Z'], true],
            ['20260907', ['2026-09-07T00:00:00Z', '2026-09-08T00:00:00Z'], true],
            ['20260907', ['2026-09-06T00:00:00Z', '2026-09-08T00:00:00Z'], false],
            ['20260907', ['2026-09-08T00:00:00.000001Z'], false],
            ['20260907', ['2026-09-05T23:59:59.999999Z'], false],
            ['20260901', ['2026-09-01T00:00:00Z'], true],
            ['20260831', ['2026-08-31T00:00:00Z'], true],
            ['20260908', ['2026-09-08T00:00:00Z'], true],
            ['20260230', ['2026-03-02T00:00:00Z'], false],
            [20260907, ['2026-09-07T00:00:00Z'], false],
            [null, ['2026-09-07T00:00:00Z'], false],
        ];
        foreach ($cases as [$date, $times, $isExpected]) {
            $payload = (object) ['analysisDate' => $date,
                'period' => (object) ['start' => '2020-01-01T00:00:00Z', 'end' => '2020-01-02T00:00:00Z'],
                'entries' => array_map(
                    static fn (string $time): object => (object) ['recordedAt' => $time, 'note' => '架空'],
                    $times,
                )];
            try {
                $request = AnalysisRequestParser::parse($payload);
                self::assertTrue($isExpected);
                self::assertSame($date, $request->analysisDate);
            } catch (ApiException $exception) {
                self::assertFalse($isExpected);
                self::assertSame(422, $exception->status());
            }
        }
    }

    public function testLocalAnalysisExampleAcceptsItsFixedPastDate(): void
    {
        $payload = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/config/local-analysis-request.example.json'),
        );

        $request = AnalysisRequestParser::parse($payload);

        self::assertSame('20260829', $request->analysisDate);
        self::assertCount(2, $request->entries);
    }
}
