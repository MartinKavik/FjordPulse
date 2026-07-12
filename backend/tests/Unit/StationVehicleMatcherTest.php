<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\StationVehicleRelation;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\MonitoredCallReference;
use FjordPulse\Dto\StationServiceCall;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\StationVehicleMatcher;
use PHPUnit\Framework\TestCase;

final class StationVehicleMatcherTest extends TestCase
{
    private const string NOW = '2026-07-12T10:00:00Z';

    public function testClassifiesStartingAtAndApproachingFromObservedProgress(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        self::assertSame(
            StationVehicleRelation::StartingHere,
            $this->singleRelation(
                self::vehicle('starting', 0, false),
                self::call(order: 0, at: $now->modify('+5 minutes')),
                $now,
            ),
        );
        self::assertSame(
            StationVehicleRelation::AtStation,
            $this->singleRelation(
                self::vehicle('at-station', 2, true),
                self::call(order: 2, at: $now->modify('+2 minutes')),
                $now,
            ),
        );
        self::assertSame(
            StationVehicleRelation::Approaching,
            $this->singleRelation(
                self::vehicle('approaching', 2, false),
                self::call(order: 4, at: $now->modify('+20 minutes')),
                $now,
            ),
        );
    }

    public function testOnlyObservedProgressOrActualDepartureProvesDeparture(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $scheduledPast = self::call(order: 3, at: $now->modify('-20 minutes'));

        self::assertSame(
            StationVehicleRelation::ServesStation,
            $this->singleRelation(self::vehicle('schedule-only'), $scheduledPast, $now),
            'A past timetable value alone must not claim that a physical vehicle departed.',
        );
        self::assertSame(
            StationVehicleRelation::Approaching,
            $this->singleRelation(self::vehicle('overdue-but-monitored', 3, false), $scheduledPast, $now),
            'A monitored call that still points at the station proves the vehicle has not progressed past it.',
        );
        self::assertSame(
            StationVehicleRelation::StartingHere,
            $this->singleRelation(
                self::vehicle('overdue-origin', 0, false),
                self::call(order: 0, at: $now->modify('-20 minutes')),
                $now,
            ),
            'Observed progress at an overdue origin remains a starting-here relation until departure is observed.',
        );
        self::assertSame(
            StationVehicleRelation::Departed,
            $this->singleRelation(
                self::vehicle('progressed', 5, false),
                $scheduledPast,
                $now,
            ),
        );
        self::assertSame(
            StationVehicleRelation::Departed,
            $this->singleRelation(
                self::vehicle('actual-departure'),
                self::call(
                    order: 3,
                    at: $now->modify('-20 minutes'),
                    actualDepartureAt: $now->modify('-18 minutes'),
                ),
                $now,
            ),
        );
    }

    public function testFutureScheduleWithoutProgressUsesNeutralServingRelation(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        self::assertSame(
            StationVehicleRelation::ServesStation,
            $this->singleRelation(
                self::vehicle('schedule-only'),
                self::call(order: 3, at: $now->modify('+20 minutes')),
                $now,
            ),
            'A future timetable value without vehicle progress must not be labelled approaching.',
        );
    }

    public function testLoopJourneyChoosesFirstStationCallAtOrAfterVehicleProgress(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $matches = (new StationVehicleMatcher())->match(
            [self::vehicle('loop', 3, false)],
            [
                self::call(order: 1, at: $now->modify('-30 minutes')),
                self::call(order: 5, at: $now->modify('+30 minutes')),
            ],
            $now,
        );

        self::assertCount(1, $matches);
        self::assertSame(StationVehicleRelation::Approaching, $matches[0]->relation);
        self::assertEquals($now->modify('+30 minutes'), $matches[0]->stationCallAt);
    }

    public function testCancelledCallsAndLostVehiclesAreNotFocusableMatches(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $matcher = new StationVehicleMatcher();

        self::assertSame([], $matcher->match(
            [self::vehicle('cancelled')],
            [self::call(order: 2, at: $now->modify('+10 minutes'), cancellation: true)],
            $now,
        ));
        self::assertSame([], $matcher->match(
            [self::vehicle('lost', state: VehicleFreshness::Lost)],
            [self::call(order: 2, at: $now->modify('+10 minutes'))],
            $now,
        ));
    }

    public function testNonPassengerVehicleCannotMatchAStationCall(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        self::assertSame([], (new StationVehicleMatcher())->match(
            [self::vehicle('dead-run', passengerServiceState: VehiclePassengerServiceState::NonPassenger)],
            [self::call(order: 2, at: $now->modify('+10 minutes'))],
            $now,
        ));
    }

    public function testStaleVehicleRemainsSelectableWithItsTruthfulRelation(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $matches = (new StationVehicleMatcher())->match(
            [self::vehicle('stale', 1, false, VehicleFreshness::Stale)],
            [self::call(order: 3, at: $now->modify('+10 minutes'))],
            $now,
        );

        self::assertCount(1, $matches);
        self::assertSame(VehicleFreshness::Stale, $matches[0]->vehicle->state);
        self::assertSame(StationVehicleRelation::Approaching, $matches[0]->relation);
    }

    private function singleRelation(
        VehicleState $vehicle,
        StationServiceCall $call,
        DateTimeImmutable $now,
    ): StationVehicleRelation {
        $matches = (new StationVehicleMatcher())->match([$vehicle], [$call], $now);
        self::assertCount(1, $matches);

        return $matches[0]->relation;
    }

    private static function vehicle(
        string $id,
        ?int $monitoredOrder = null,
        bool $vehicleAtStop = false,
        VehicleFreshness $state = VehicleFreshness::Live,
        VehiclePassengerServiceState $passengerServiceState = VehiclePassengerServiceState::Unknown,
    ): VehicleState {
        $now = new DateTimeImmutable(self::NOW);

        return new VehicleState(
            id: 'vehicle-' . $id,
            version: '2026-07-12T10:00:00.000Z',
            contentHash: hash('sha256', $id),
            state: $state,
            coordinate: new Coordinate(60.0, 10.0),
            lineCode: '1',
            routeName: 'Test route',
            destination: 'Destination',
            bearing: null,
            delaySeconds: null,
            distanceMeters: null,
            lastSeenAt: $now,
            updatedAt: $now,
            nextStop: null,
            journeyReference: self::reference(),
            monitoredCall: $monitoredOrder === null
                ? null
                : new MonitoredCallReference('NSR:Quay:station', $monitoredOrder, $vehicleAtStop),
            refreshedAt: $now,
            passengerServiceState: $passengerServiceState,
        );
    }

    private static function call(
        int $order,
        DateTimeImmutable $at,
        ?DateTimeImmutable $actualDepartureAt = null,
        bool $cancellation = false,
    ): StationServiceCall {
        return new StationServiceCall(
            journeyReference: self::reference(),
            order: $order,
            quayId: 'NSR:Quay:station',
            aimedArrivalAt: $at,
            expectedArrivalAt: $at,
            actualArrivalAt: null,
            aimedDepartureAt: $at,
            expectedDepartureAt: $at,
            actualDepartureAt: $actualDepartureAt,
            cancellation: $cancellation,
        );
    }

    private static function reference(): VehicleJourneyReference
    {
        return new VehicleJourneyReference('TEST:ServiceJourney:loop', '2026-07-12');
    }
}
