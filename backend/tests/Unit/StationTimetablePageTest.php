<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Dto\StationTimetable;
use FjordPulse\Dto\StationTimetablePage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StationTimetablePageTest extends TestCase
{
    public function testAcceptsBothExhaustedAndContinuedPages(): void
    {
        $timetable = self::timetable();

        self::assertNull((new StationTimetablePage($timetable, [], 1, false, null))->nextCursor);
        self::assertSame('opaque_cursor', (new StationTimetablePage($timetable, [], 50, true, 'opaque_cursor'))->nextCursor);
    }

    #[DataProvider('invalidPageArguments')]
    public function testRejectsInvalidLimitsAndCursorCoverage(int $limit, bool $hasMore, ?string $nextCursor): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StationTimetablePage(self::timetable(), [], $limit, $hasMore, $nextCursor);
    }

    /** @return iterable<string, array{int, bool, ?string}> */
    public static function invalidPageArguments(): iterable
    {
        yield 'zero limit' => [0, false, null];
        yield 'limit above contract maximum' => [51, false, null];
        yield 'continuation without cursor' => [50, true, null];
        yield 'cursor on exhausted page' => [50, false, 'unexpected_cursor'];
    }

    private static function timetable(): StationTimetable
    {
        return StationTimetable::create(
            'NSR:StopPlace:1',
            '2026-07-14',
            'Europe/Oslo',
            new DateTimeImmutable('2026-07-14T00:00:00+02:00'),
            new DateTimeImmutable('2026-07-15T00:00:00+02:00'),
            [],
            true,
            new DateTimeImmutable('2026-07-14T12:00:00+02:00'),
        );
    }
}
