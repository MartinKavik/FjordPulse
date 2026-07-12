<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\JourneyGeometry;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\MonitoredCallReference;
use FjordPulse\Dto\ProgressBetweenStops;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\JourneyProgressMatcher;
use PHPUnit\Framework\TestCase;

final class JourneyProgressMatcherTest extends TestCase
{
    public function testMatchesNormalizedOrderAndInterpolatesRouteProgress(): void
    {
        $at = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $reference = new VehicleJourneyReference('SKY:ServiceJourney:1', '2026-07-10');
        $calls = [
            new StopCall('NSR:StopPlace:1', 'Start', $at, $at, 0, 'NSR:Quay:1', new Coordinate(61.0, 5.0)),
            new StopCall('NSR:StopPlace:2', 'Middle', $at, $at, 1, 'NSR:Quay:2', new Coordinate(61.0, 5.1)),
            new StopCall('NSR:StopPlace:3', 'End', $at, $at, 2, 'NSR:Quay:3', new Coordinate(61.0, 5.2)),
        ];
        $journey = new JourneySnapshot(
            $reference->serviceJourneyId,
            $reference->operatingDate,
            null,
            '2026-07-10T10:00:00.000Z',
            'journey-hash',
            SourceState::Fresh,
            new JourneyGeometry([
                new Coordinate(61.0, 5.0),
                new Coordinate(61.0, 5.1),
                new Coordinate(61.0, 5.2),
            ], 11_000.0),
            $calls,
            $at,
            $at,
        );
        $vehicle = new VehicleState(
            'SKY:Vehicle:1',
            '2026-07-10T10:00:01.000Z',
            'vehicle-hash',
            VehicleFreshness::Live,
            new Coordinate(61.0, 5.05),
            '100',
            'Start–End',
            'End',
            90.0,
            0,
            null,
            $at,
            $at,
            null,
            [],
            $reference,
            new MonitoredCallReference('NSR:Quay:2', 1, false),
            new ProgressBetweenStops(null, 0.5),
            refreshedAt: $at,
        );

        $matcher = new JourneyProgressMatcher();
        $enriched = $matcher->enrich($vehicle, $journey);

        self::assertSame('Middle', $enriched->nextStop?->name);
        self::assertEqualsWithDelta(0.25, $enriched->routeProgress ?? -1, 0.01);
        self::assertSame(['Middle', 'End'], array_map(static fn(StopCall $call): string => $call->name, $matcher->upcoming($journey, $vehicle)));
    }

    public function testAtStopAdvancesUpcomingListToFollowingCall(): void
    {
        $at = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $reference = new VehicleJourneyReference('SKY:ServiceJourney:2', '2026-07-10');
        $calls = [
            new StopCall('NSR:StopPlace:1', 'Current', $at, $at, 0, 'NSR:Quay:1', new Coordinate(61.0, 5.0)),
            new StopCall('NSR:StopPlace:2', 'Next', $at, $at, 1, 'NSR:Quay:2', new Coordinate(61.1, 5.1)),
        ];
        $journey = new JourneySnapshot($reference->serviceJourneyId, $reference->operatingDate, null, '2026-07-10T10:00:00.000Z', 'hash', SourceState::Fresh, new JourneyGeometry([new Coordinate(61.0, 5.0), new Coordinate(61.1, 5.1)], null), $calls, $at, $at);
        $vehicle = new VehicleState('SKY:Vehicle:2', '2026-07-10T10:00:00.000Z', 'hash', VehicleFreshness::Live, new Coordinate(61.0, 5.0), '100', null, 'Next', null, null, null, $at, $at, null, [], $reference, new MonitoredCallReference('NSR:Quay:1', 0, true), refreshedAt: $at);

        $matcher = new JourneyProgressMatcher();
        self::assertSame('Next', $matcher->enrich($vehicle, $journey)->nextStop?->name);
        self::assertSame(['Next'], array_map(static fn(StopCall $call): string => $call->name, $matcher->upcoming($journey, $vehicle)));
    }

    public function testRiderFacingNextAndUpcomingStopsSkipCancelledCallsWithoutChangingJourneyIndices(): void
    {
        $at = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $reference = new VehicleJourneyReference('SKY:ServiceJourney:cancelled-call', '2026-07-10');
        $calls = [
            new StopCall('NSR:StopPlace:1', 'Current', $at, $at, 0, 'NSR:Quay:1', new Coordinate(61.0, 5.0)),
            new StopCall('NSR:StopPlace:2', 'Cancelled', $at, $at, 1, 'NSR:Quay:2', new Coordinate(61.1, 5.1), cancellation: true),
            new StopCall('NSR:StopPlace:3', 'Next served', $at, $at, 2, 'NSR:Quay:3', new Coordinate(61.2, 5.2)),
        ];
        $journey = new JourneySnapshot(
            $reference->serviceJourneyId,
            $reference->operatingDate,
            null,
            '2026-07-10T10:00:00.000Z',
            'hash',
            SourceState::Fresh,
            new JourneyGeometry([
                new Coordinate(61.0, 5.0),
                new Coordinate(61.1, 5.1),
                new Coordinate(61.2, 5.2),
            ], null),
            $calls,
            $at,
            $at,
        );
        $vehicle = new VehicleState(
            'SKY:Vehicle:cancelled-call',
            '2026-07-10T10:00:00.000Z',
            'hash',
            VehicleFreshness::Live,
            new Coordinate(61.0, 5.0),
            '100',
            null,
            'Next served',
            null,
            null,
            null,
            $at,
            $at,
            null,
            [],
            $reference,
            new MonitoredCallReference('NSR:Quay:1', 0, true),
            refreshedAt: $at,
        );

        $matcher = new JourneyProgressMatcher();
        self::assertSame('Next served', $matcher->enrich($vehicle, $journey)->nextStop?->name);
        self::assertSame(
            ['Next served'],
            array_map(static fn(StopCall $call): string => $call->name, $matcher->upcoming($journey, $vehicle)),
        );
        self::assertSame([0, 1, 2], array_map(static fn(StopCall $call): int => $call->order, $journey->calls));
    }

    public function testRepeatedQuayAtEndOfLoopUsesLaterGeometryOccurrence(): void
    {
        $at = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $reference = new VehicleJourneyReference('SKY:ServiceJourney:loop', '2026-07-10');
        $start = new Coordinate(61.0, 5.0);
        $calls = [
            new StopCall('NSR:StopPlace:loop', 'Loop start', $at, $at, 0, 'NSR:Quay:loop', $start),
            new StopCall('NSR:StopPlace:loop', 'Loop end', $at, $at, 1, 'NSR:Quay:loop', $start),
        ];
        $journey = new JourneySnapshot(
            $reference->serviceJourneyId,
            $reference->operatingDate,
            null,
            '2026-07-10T10:00:00.000Z',
            'loop-hash',
            SourceState::Fresh,
            new JourneyGeometry([$start, new Coordinate(61.1, 5.1), new Coordinate(61.0, 5.2), $start], null),
            $calls,
            $at,
            $at,
        );
        $vehicle = new VehicleState('SKY:Vehicle:loop', '2026-07-10T10:00:00.000Z', 'hash', VehicleFreshness::Live, $start, 'L', null, 'Loop end', null, null, null, $at, $at, null, [], $reference, new MonitoredCallReference('NSR:Quay:loop', 1, true), refreshedAt: $at);

        $enriched = (new JourneyProgressMatcher())->enrich($vehicle, $journey);
        self::assertEqualsWithDelta(1.0, $enriched->routeProgress ?? -1, 0.001);
        self::assertNull($enriched->nextStop);
    }

    public function testInfersUpcomingCallsFromPositionWithoutMonitoredCall(): void
    {
        $at = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $reference = new VehicleJourneyReference('SKY:ServiceJourney:position', '2026-07-10');
        $calls = [
            new StopCall('NSR:StopPlace:1', 'Start', null, null, 0, 'NSR:Quay:1', new Coordinate(61.0, 5.0)),
            new StopCall('NSR:StopPlace:2', 'Middle', null, null, 1, 'NSR:Quay:2', new Coordinate(61.0, 5.1)),
            new StopCall('NSR:StopPlace:3', 'End', null, null, 2, 'NSR:Quay:3', new Coordinate(61.0, 5.2)),
        ];
        $journey = new JourneySnapshot($reference->serviceJourneyId, $reference->operatingDate, null, '2026-07-10T10:00:00.000Z', 'hash', SourceState::Fresh, new JourneyGeometry([
            new Coordinate(61.0, 5.0),
            new Coordinate(61.0, 5.05),
            new Coordinate(61.0, 5.1),
            new Coordinate(61.0, 5.2),
        ], null), $calls, $at, $at);
        $vehicle = new VehicleState('SKY:Vehicle:position', '2026-07-10T10:00:01.000Z', 'hash', VehicleFreshness::Live, new Coordinate(61.0, 5.05), '100', null, 'End', null, null, null, $at, $at, null, [], $reference, refreshedAt: $at);

        $matcher = new JourneyProgressMatcher();
        self::assertSame('Middle', $matcher->enrich($vehicle, $journey)->nextStop?->name);
        self::assertSame(['Middle', 'End'], array_map(static fn(StopCall $call): string => $call->name, $matcher->upcoming($journey, $vehicle)));
    }

    public function testUsesScheduleWithoutPositionAndOnlyDeclaresCompletionAfterLastCall(): void
    {
        $reference = new VehicleJourneyReference('SKY:ServiceJourney:time', '2026-07-10');
        $calls = [
            new StopCall('NSR:StopPlace:1', 'Past', new DateTimeImmutable('2026-07-10T09:55:00Z'), null, 0),
            new StopCall('NSR:StopPlace:2', 'Future', new DateTimeImmutable('2026-07-10T10:05:00Z'), null, 1),
        ];
        $journey = new JourneySnapshot($reference->serviceJourneyId, $reference->operatingDate, null, '2026-07-10T10:00:00.000Z', 'hash', SourceState::Fresh, null, $calls, new DateTimeImmutable('2026-07-10T10:00:00Z'), new DateTimeImmutable('2026-07-10T10:00:00Z'));
        $active = new VehicleState('SKY:Vehicle:time', '2026-07-10T10:00:00.000Z', 'hash', VehicleFreshness::Live, null, '100', null, 'Future', null, null, null, new DateTimeImmutable('2026-07-10T10:00:00Z'), new DateTimeImmutable('2026-07-10T10:00:00Z'), null, [], $reference, refreshedAt: new DateTimeImmutable('2026-07-10T10:00:00Z'));
        $complete = new VehicleState('SKY:Vehicle:time', '2026-07-10T10:10:00.000Z', 'hash', VehicleFreshness::Live, null, '100', null, null, null, null, null, new DateTimeImmutable('2026-07-10T10:10:00Z'), new DateTimeImmutable('2026-07-10T10:10:00Z'), null, [], $reference, refreshedAt: new DateTimeImmutable('2026-07-10T10:10:00Z'));

        $matcher = new JourneyProgressMatcher();
        self::assertSame(['Future'], array_map(static fn(StopCall $call): string => $call->name, $matcher->upcoming($journey, $active)));
        self::assertSame([], $matcher->upcoming($journey, $complete));
    }
}
