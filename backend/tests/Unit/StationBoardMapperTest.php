<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Dto\StationServiceCall;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use PHPUnit\Framework\TestCase;

final class StationBoardMapperTest extends TestCase
{
    public function testPreservesEnturOperatingDateAcrossLocalMidnight(): void
    {
        $now = new DateTimeImmutable('2026-07-11T22:55:00Z');
        $call = self::serviceCall(
            'VYG:ServiceJourney:overnight',
            '2026-07-11',
            9,
            '2026-07-12T00:57:00+02:00',
        );
        $board = (new JourneyPlannerMapper())->mapStationBoard(self::payload(
            departureCalls: [self::departureCall('VYG:ServiceJourney:overnight', '2026-07-12T00:57:00+02:00')],
            upcomingCalls: [$call],
        ), $now);

        self::assertCount(1, $board->serviceCalls);
        self::assertSame('2026-07-11', $board->serviceCalls[0]->journeyReference->operatingDate);
        self::assertSame('2026-07-11T22:57:00+00:00', $board->serviceCalls[0]->displayAt()?->format(DATE_RFC3339));
        self::assertEquals($now->modify('-6 hours'), $board->serviceWindowStartedAt);
        self::assertEquals($now->modify('+6 hours'), $board->serviceWindowEndsAt);
        self::assertEquals($now, $board->departureWindowStartedAt);
        self::assertSame('2026-07-13T00:00:00+02:00', $board->departureWindowEndsAt?->format(DATE_RFC3339));
    }

    public function testDisplayedDepartureJourneySurvivesTheTwoHundredJourneyCap(): void
    {
        $now = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $upcoming = [];
        for ($index = 0; $index < 200; $index++) {
            $upcoming[] = self::serviceCall(
                'TEST:ServiceJourney:ordinary-' . $index,
                '2026-07-12',
                $index,
                $now->modify('+' . ($index + 1) . ' seconds')->format(DATE_RFC3339),
            );
        }
        $upcoming[] = self::serviceCall(
            'TEST:ServiceJourney:displayed-departure',
            '2026-07-12',
            201,
            $now->modify('+30 minutes')->format(DATE_RFC3339),
        );

        $board = (new JourneyPlannerMapper())->mapStationBoard(self::payload(
            departureCalls: [self::departureCall('TEST:ServiceJourney:displayed-departure', $now->modify('+30 minutes')->format(DATE_RFC3339))],
            upcomingCalls: $upcoming,
        ), $now);

        self::assertSame(201, $board->candidateJourneyCount);
        self::assertSame(200, $board->queriedJourneyCount);
        self::assertTrue($board->serviceCallsTruncated);
        self::assertCount(200, self::uniqueJourneyKeys($board->serviceCalls));
        self::assertContains(
            'TEST:ServiceJourney:displayed-departure|2026-07-12',
            self::uniqueJourneyKeys($board->serviceCalls),
        );
        self::assertNotContains(
            'TEST:ServiceJourney:ordinary-199|2026-07-12',
            self::uniqueJourneyKeys($board->serviceCalls),
        );
    }

    public function testNewestRecentJourneyWinsTheFinalAvailableSlot(): void
    {
        $now = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $upcoming = [];
        for ($index = 0; $index < 199; $index++) {
            $upcoming[] = self::serviceCall(
                'TEST:ServiceJourney:upcoming-' . $index,
                '2026-07-12',
                $index,
                $now->modify('+' . ($index + 1) . ' seconds')->format(DATE_RFC3339),
            );
        }
        $recent = [
            self::serviceCall('TEST:ServiceJourney:older-recent', '2026-07-12', 300, $now->modify('-2 hours')->format(DATE_RFC3339)),
            self::serviceCall('TEST:ServiceJourney:newer-recent', '2026-07-12', 301, $now->modify('-1 minute')->format(DATE_RFC3339)),
        ];

        $board = (new JourneyPlannerMapper())->mapStationBoard(self::payload(
            upcomingCalls: $upcoming,
            recentCalls: $recent,
        ), $now);
        $keys = self::uniqueJourneyKeys($board->serviceCalls);

        self::assertSame(201, $board->candidateJourneyCount);
        self::assertSame(200, $board->queriedJourneyCount);
        self::assertTrue($board->serviceCallsTruncated);
        self::assertContains('TEST:ServiceJourney:newer-recent|2026-07-12', $keys);
        self::assertNotContains('TEST:ServiceJourney:older-recent|2026-07-12', $keys);
    }

    public function testAnEnturResultCeilingMakesTheObservedCandidateCountALowerBound(): void
    {
        $now = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $upcoming = [];
        for ($index = 0; $index < 200; $index++) {
            $upcoming[] = self::serviceCall(
                'TEST:ServiceJourney:capped-' . $index,
                '2026-07-12',
                $index,
                $now->modify('+' . ($index + 1) . ' seconds')->format(DATE_RFC3339),
            );
        }

        $board = (new JourneyPlannerMapper())->mapStationBoard(self::payload(upcomingCalls: $upcoming), $now);

        self::assertSame(200, $board->candidateJourneyCount);
        self::assertSame(200, $board->queriedJourneyCount);
        self::assertTrue(
            $board->serviceCallsTruncated,
            'At the Entur response ceiling, 200 candidates is an observed lower bound rather than a complete total.',
        );
    }

    /**
     * @param list<array<string, mixed>> $departureCalls
     * @param list<array<string, mixed>> $upcomingCalls
     * @param list<array<string, mixed>> $recentCalls
     * @return array<string, mixed>
     */
    private static function payload(
        array $departureCalls = [],
        array $upcomingCalls = [],
        array $recentCalls = [],
    ): array {
        return ['data' => ['stopPlace' => [
            'departureCalls' => $departureCalls,
            'upcomingVehicleCalls' => $upcomingCalls,
            'recentVehicleCalls' => $recentCalls,
        ]]];
    }

    /** @return array<string, mixed> */
    private static function departureCall(string $serviceJourneyId, string $at): array
    {
        return [
            'aimedDepartureTime' => $at,
            'expectedDepartureTime' => $at,
            'actualDepartureTime' => null,
            'cancellation' => false,
            'quay' => ['id' => 'NSR:Quay:station', 'publicCode' => '1'],
            'destinationDisplay' => ['frontText' => 'Destination'],
            'serviceJourney' => [
                'id' => $serviceJourneyId,
                'journeyPattern' => ['line' => ['id' => 'TEST:Line:1', 'publicCode' => '1', 'name' => 'Test line']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function serviceCall(
        string $serviceJourneyId,
        string $operatingDate,
        int $order,
        string $at,
    ): array {
        return [
            'date' => $operatingDate,
            'stopPositionInPattern' => $order,
            'aimedArrivalTime' => $at,
            'expectedArrivalTime' => $at,
            'actualArrivalTime' => null,
            'aimedDepartureTime' => $at,
            'expectedDepartureTime' => $at,
            'actualDepartureTime' => null,
            'cancellation' => false,
            'quay' => ['id' => 'NSR:Quay:station', 'stopPlace' => ['id' => 'NSR:StopPlace:station']],
            'serviceJourney' => ['id' => $serviceJourneyId],
        ];
    }

    /**
     * @param list<StationServiceCall> $calls
     * @return list<string>
     */
    private static function uniqueJourneyKeys(array $calls): array
    {
        return array_values(array_unique(array_map(
            static fn(StationServiceCall $call): string => $call->journeyReference->key(),
            $calls,
        )));
    }
}
