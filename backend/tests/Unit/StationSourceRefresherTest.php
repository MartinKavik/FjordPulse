<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\Scenario;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\Fake\FixtureFactory;
use FjordPulse\Entur\Fake\FakeJourneyPlanner;
use FjordPulse\Entur\Fake\FakeVehiclePositions;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\MutableScenarioProvider;
use FjordPulse\Entur\NearbyVehicleSelector;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Entur\StationSourceRefresher;
use FjordPulse\Entur\VehiclePositionsInterface;
use PHPUnit\Framework\TestCase;
use Throwable;

final class StationSourceRefresherTest extends TestCase
{
    public function testJourneyFailureKeepsSavedDeparturesAndFreshNearbyVehicles(): void
    {
        $previous = self::snapshot();
        $freshNearby = [FixtureFactory::vehicles(2)[0]];
        $outcome = self::refresher(
            journeyFailure: new SourceUnavailable('Journey Planner timed out.'),
            nearby: $freshNearby,
        )->refresh(self::station(), $previous, self::now());

        self::assertSame(SourceState::Stale, $outcome->state);
        self::assertSame($previous->departures, $outcome->departures);
        self::assertSame($freshNearby, $outcome->nearbyVehicles);
        self::assertFalse($outcome->departuresRefreshed);
        self::assertTrue($outcome->nearbyVehiclesRefreshed);
        self::assertInstanceOf(SourceUnavailable::class, $outcome->retryFailure);
        self::assertSame($previous->lastSuccessfulAt, $outcome->lastSuccessfulAt);
        self::assertSame(
            'Departures could not be refreshed; showing saved departure information. Nearby vehicle positions were refreshed.',
            $outcome->warning,
        );
    }

    public function testNearbyFailureKeepsSavedVehiclesAndFreshDepartures(): void
    {
        $previous = self::snapshot();
        $freshDepartures = [FixtureFactory::departures(self::station()->id)[1]];
        $outcome = self::refresher(
            departures: $freshDepartures,
            vehicleFailure: new SourceUnavailable('Vehicle Positions timed out.'),
        )->refresh(self::station(), $previous, self::now());

        self::assertSame(SourceState::Stale, $outcome->state);
        self::assertSame($freshDepartures, $outcome->departures);
        self::assertSame($previous->nearbyVehicles, $outcome->nearbyVehicles);
        self::assertTrue($outcome->departuresRefreshed);
        self::assertFalse($outcome->nearbyVehiclesRefreshed);
        self::assertInstanceOf(SourceUnavailable::class, $outcome->retryFailure);
        self::assertSame(
            'Departures were refreshed. Nearby vehicle positions could not be refreshed; showing saved positions.',
            $outcome->warning,
        );
    }

    public function testCompleteFailureKeepsAuthoritativeCacheInStaleState(): void
    {
        $previous = self::snapshot();
        $outcome = self::refresher(
            journeyFailure: new SourceUnavailable('Journey Planner timed out.'),
            vehicleFailure: new SourceUnavailable('Vehicle Positions timed out.'),
        )->refresh(self::station(), $previous, self::now());

        self::assertSame(SourceState::Stale, $outcome->state);
        self::assertSame($previous->departures, $outcome->departures);
        self::assertSame($previous->nearbyVehicles, $outcome->nearbyVehicles);
        self::assertInstanceOf(SourceUnavailable::class, $outcome->retryFailure);
        self::assertStringContainsString('showing saved departure information', $outcome->warning ?? '');
        self::assertStringContainsString('showing saved positions', $outcome->warning ?? '');
    }

    public function testCompleteFailureWithoutSavedDataRemainsAnError(): void
    {
        $outcome = self::refresher(
            journeyFailure: new SourceUnavailable('Journey Planner timed out.'),
            vehicleFailure: new SourceUnavailable('Vehicle Positions timed out.'),
        )->refresh(self::station(), null, self::now());

        self::assertSame(SourceState::Error, $outcome->state);
        self::assertSame([], $outcome->departures);
        self::assertSame([], $outcome->nearbyVehicles);
        self::assertNull($outcome->lastSuccessfulAt);
        self::assertInstanceOf(SourceUnavailable::class, $outcome->retryFailure);
    }

    public function testPartialRateLimitPreservesDataAndUsesTheLatestRetryBoundary(): void
    {
        $previous = self::snapshot();
        $retryAt = self::now()->add(new DateInterval('PT45S'));
        $outcome = self::refresher(
            journeyFailure: new RateLimited($retryAt),
            nearby: [FixtureFactory::vehicles(3)[0]],
        )->refresh(self::station(), $previous, self::now());

        self::assertSame(SourceState::RateLimited, $outcome->state);
        self::assertInstanceOf(RateLimited::class, $outcome->retryFailure);
        self::assertSame($retryAt, $outcome->retryFailure->retryAt);
        self::assertStringContainsString($retryAt->format('Y-m-d'), $outcome->warning ?? '');
    }

    public function testDeterministicStationErrorRemainsAFullErrorWithSavedData(): void
    {
        $scenarios = new MutableScenarioProvider(Scenario::StationError);
        $outcome = (new StationSourceRefresher(
            new FakeJourneyPlanner($scenarios),
            new FakeVehiclePositions($scenarios),
            $scenarios,
        ))->refresh(self::station(), self::snapshot(), self::now());

        self::assertSame(SourceState::Error, $outcome->state);
        self::assertSame('Deterministic station source failure.', $outcome->warning);
        self::assertInstanceOf(SourceUnavailable::class, $outcome->retryFailure);
        self::assertFalse($outcome->departuresRefreshed);
        self::assertFalse($outcome->nearbyVehiclesRefreshed);
    }

    /**
     * @param list<Departure>|null $departures
     * @param list<VehicleState>|null $nearby
     */
    private static function refresher(
        ?array $departures = null,
        ?Throwable $journeyFailure = null,
        ?array $nearby = null,
        ?Throwable $vehicleFailure = null,
    ): StationSourceRefresher {
        return new StationSourceRefresher(
            new StubJourneyPlanner($departures ?? FixtureFactory::departures(self::station()->id), $journeyFailure),
            new StubVehiclePositions($nearby ?? FixtureFactory::vehicles(2), $vehicleFailure),
            new MutableScenarioProvider(),
        );
    }

    private static function station(): Station
    {
        return FixtureFactory::stations()[0];
    }

    private static function snapshot(): StationSnapshot
    {
        $at = new DateTimeImmutable('2026-07-11T12:00:00Z');
        $departures = FixtureFactory::departures(self::station()->id);
        $nearby = FixtureFactory::vehicles(1);

        return new StationSnapshot(
            self::station()->id,
            $at->format('Y-m-d\\TH:i:s.v\\Z'),
            StationSnapshot::semanticHash(SourceState::Fresh, $departures, $nearby),
            $at,
            SourceState::Fresh,
            $departures,
            $nearby,
            $at,
        );
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-11T12:01:00Z');
    }
}

final readonly class StubJourneyPlanner implements JourneyPlannerInterface
{
    /** @param list<Departure> $departures */
    public function __construct(
        private array $departures,
        private ?Throwable $failure = null,
    ) {
    }

    public function departures(string $stationId, int $limit = 20): array
    {
        unset($stationId);
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_slice($this->departures, 0, $limit);
    }

    public function journey(VehicleJourneyReference $reference): ?JourneySnapshot
    {
        unset($reference);

        return null;
    }
}

final readonly class StubVehiclePositions implements VehiclePositionsInterface
{
    /** @param list<VehicleState> $vehicles */
    public function __construct(
        private array $vehicles,
        private ?Throwable $failure = null,
    ) {
    }

    public function current(): array
    {
        return $this->vehicles;
    }

    public function nearby(
        Coordinate $center,
        float $radiusKm = NearbyVehicleSelector::DEFAULT_RADIUS_KM,
        int $limit = NearbyVehicleSelector::DEFAULT_LIMIT,
    ): array {
        unset($center, $radiusKm);
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_slice($this->vehicles, 0, $limit);
    }

    public function vehicle(string $vehicleId): ?VehicleState
    {
        foreach ($this->vehicles as $vehicle) {
            if ($vehicle->id === $vehicleId) {
                return $vehicle;
            }
        }

        return null;
    }
}
