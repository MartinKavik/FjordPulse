<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Domain\Scenario;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Entur\Fake\FakeGeocoder;
use FjordPulse\Entur\Fake\FakeJourneyPlanner;
use FjordPulse\Entur\Fake\FakeVehiclePositions;
use FjordPulse\Entur\Fake\FixtureFactory;
use FjordPulse\Entur\MutableScenarioProvider;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\SourceUnavailable;
use PHPUnit\Framework\TestCase;

final class FakeAdaptersTest extends TestCase
{
    public function testGeocoderIsUnicodeAwareAndDeterministic(): void
    {
        $results = (new FakeGeocoder())->search('førde');

        self::assertCount(2, $results);
        self::assertSame('NSR:StopPlace:36025', $results[0]->id);
        self::assertSame('Førde rutebilstasjon', $results[0]->name);
        self::assertSame('OSM:TopographicPlace:fjordpulse-forde', $results[1]->id);
        $ids = array_map(static fn($station): string => $station->id, $results);
        self::assertSame($ids, array_map(static fn($station): string => $station->id, (new FakeGeocoder())->search('Forde')));
        self::assertSame($ids, array_map(static fn($station): string => $station->id, (new FakeGeocoder())->search('Fo')));
    }

    public function testJourneyPlannerCoversEmptyErrorAndBackoffScenarios(): void
    {
        $scenarios = new MutableScenarioProvider(Scenario::StationEmpty);
        $planner = new FakeJourneyPlanner($scenarios);
        self::assertSame([], $planner->departures('NSR:StopPlace:36025'));

        $scenarios->select(Scenario::StationError);
        try {
            $planner->departures('NSR:StopPlace:36025');
            self::fail('Station error scenario must fail.');
        } catch (SourceUnavailable) {
            self::addToAssertionCount(1);
        }

        $scenarios->select(Scenario::EnturBackoff);
        $this->expectException(RateLimited::class);
        $planner->departures('NSR:StopPlace:36025');
    }

    public function testVehicleScenariosChangeOnlyOnSourceUpdates(): void
    {
        $scenarios = new MutableScenarioProvider(Scenario::VehicleLive);
        $adapter = new FakeVehiclePositions($scenarios);
        $center = new Coordinate(61.4522, 5.8572);
        $first = $adapter->nearby($center);
        $second = $adapter->nearby($center);

        self::assertSame(VehicleFreshness::Live, $first[0]->state);
        self::assertNotSame($first[0]->version, $second[0]->version);

        $scenarios->select(Scenario::VehicleStale);
        self::assertSame(VehicleFreshness::Stale, $adapter->nearby($center)[0]->state);

        $scenarios->select(Scenario::VehicleLost);
        $lost = $adapter->vehicle('SKY:Vehicle:1001');
        self::assertNotNull($lost);
        self::assertSame(VehicleFreshness::Lost, $lost->state);
        self::assertNull($lost->coordinate);
    }

    public function testFakeNearbyVehiclesUseCircularNearestFirstSelection(): void
    {
        $scenarios = new MutableScenarioProvider(Scenario::VehicleLive);
        $adapter = new FakeVehiclePositions($scenarios);
        $positions = $adapter->current();
        $center = $positions[2]->coordinate;
        self::assertNotNull($center);

        $nearby = $adapter->nearby($center, 0.1, 1);

        self::assertCount(1, $nearby);
        self::assertSame('SKY:Vehicle:5903', $nearby[0]->id);
        self::assertSame([], $adapter->nearby(new Coordinate(59.9111, 10.7528)));
        $scenarios->select(Scenario::StationEmpty);
        self::assertSame([], $adapter->nearby($center), 'The station-empty scenario must match the browser fixture and expose a completed empty nearby result.');
    }

    public function testStationErrorAndBackoffScenariosCoverBothStationSources(): void
    {
        $scenarios = new MutableScenarioProvider(Scenario::StationError);
        $vehicles = new FakeVehiclePositions($scenarios);
        $center = new Coordinate(61.4522, 5.8572);

        try {
            $vehicles->nearby($center);
            self::fail('The full station-error scenario must fail nearby Vehicle Positions too.');
        } catch (SourceUnavailable) {
            self::addToAssertionCount(1);
        }

        $scenarios->select(Scenario::EnturBackoff);
        $this->expectException(RateLimited::class);
        $vehicles->nearby($center);
    }

    public function testStationSemanticHashIsStableAcrossProducers(): void
    {
        $departures = FixtureFactory::departures('NSR:StopPlace:36025');
        $vehicles = FixtureFactory::vehicles(1);

        self::assertSame(
            StationSnapshot::semanticHash(SourceState::Fresh, $departures, $vehicles),
            StationSnapshot::semanticHash(SourceState::Fresh, [...$departures], [...$vehicles]),
        );
        self::assertNotSame(
            StationSnapshot::semanticHash(SourceState::Fresh, $departures, $vehicles),
            StationSnapshot::semanticHash(SourceState::Stale, $departures, $vehicles, 'Delayed.'),
        );
    }
}
