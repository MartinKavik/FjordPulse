<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\StationTimetable;
use FjordPulse\Dto\StationTimetableCursor;
use PHPUnit\Framework\TestCase;

final class StationTimetableCursorTest extends TestCase
{
    public function testRoundTripsAnOpaqueVersionedOffset(): void
    {
        $cursor = new StationTimetableCursor('NSR:StopPlace:1', '2026-07-14', str_repeat('a', 64), 50);

        self::assertEquals($cursor, StationTimetableCursor::decode($cursor->encode()));
        self::assertStringNotContainsString('NSR:StopPlace', $cursor->encode());
    }

    public function testRejectsMalformedCursor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StationTimetableCursor::decode('not-valid-json');
    }

    public function testBusyDayFirstPageStartsWithUpcomingCallsAndKeepsHistoryReachable(): void
    {
        $windowStart = new DateTimeImmutable('2026-07-14T00:00:00+02:00');
        $fetchedAt = new DateTimeImmutable('2026-07-14T12:00:00+02:00');
        $departures = [];
        for ($index = 0; $index < 60; $index++) {
            $departures[] = self::departure('history-' . $index, $windowStart->modify('+' . $index . ' minutes'));
        }
        $departures[] = self::departure('upcoming-1', $fetchedAt->modify('+5 minutes'));
        $departures[] = self::departure('upcoming-2', $fetchedAt->modify('+10 minutes'));
        $departures[] = self::departure('upcoming-3', $fetchedAt->modify('+15 minutes'));
        $timetable = StationTimetable::create(
            'NSR:StopPlace:1',
            '2026-07-14',
            'Europe/Oslo',
            $windowStart,
            $windowStart->modify('+1 day'),
            $departures,
            true,
            $fetchedAt,
        );

        $ordered = $timetable->displayOrderedDepartures();
        $firstPage = array_slice($ordered, 0, 2);

        self::assertSame(['upcoming-1', 'upcoming-2'], array_column($firstPage, 'id'));
        self::assertSame('history-59', $ordered[3]->id);
        self::assertCount(63, $ordered);
    }

    private static function departure(string $id, DateTimeImmutable $at): Departure
    {
        return new Departure(
            $id,
            'TEST:ServiceJourney:' . $id,
            'TEST:Line:1',
            '1',
            'Destination',
            $at,
            null,
            DepartureStatus::Scheduled,
            0,
            'A',
            false,
        );
    }
}
