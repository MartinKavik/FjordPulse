<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\Scenario;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\StationVehiclePositions;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\NearbyVehicleSelector;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\ScenarioProviderInterface;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Entur\VehiclePositionsInterface;

final class FakeVehiclePositions implements VehiclePositionsInterface
{
    private int $tick = 0;

    public function __construct(private readonly ScenarioProviderInterface $scenarios)
    {
    }

    /** @return list<VehicleState> */
    public function current(): array
    {
        return $this->currentVehicles();
    }

    /** @return list<VehicleState> */
    public function nearby(
        Coordinate $center,
        float $radiusKm = NearbyVehicleSelector::DEFAULT_RADIUS_KM,
        int $limit = NearbyVehicleSelector::DEFAULT_LIMIT,
    ): array
    {
        match ($this->scenarios->current()) {
            Scenario::StationError => throw new SourceUnavailable('Deterministic nearby-vehicle source failure.'),
            Scenario::EnturBackoff => throw new RateLimited((new DateTimeImmutable())->add(new DateInterval('PT30S'))),
            default => null,
        };
        if ($this->scenarios->current() === Scenario::StationEmpty) {
            return [];
        }

        return NearbyVehicleSelector::select($center, $this->currentVehicles(), $radiusKm, $limit);
    }

    public function stationVehicles(
        Coordinate $center,
        array $journeys,
        float $radiusKm = NearbyVehicleSelector::DEFAULT_RADIUS_KM,
        int $nearbyLimit = NearbyVehicleSelector::DEFAULT_LIMIT,
    ): StationVehiclePositions {
        match ($this->scenarios->current()) {
            Scenario::StationError => throw new SourceUnavailable('Deterministic station-vehicle source failure.'),
            Scenario::EnturBackoff => throw new RateLimited((new DateTimeImmutable())->add(new DateInterval('PT30S'))),
            default => null,
        };
        if ($this->scenarios->current() === Scenario::StationEmpty) {
            return new StationVehiclePositions([], []);
        }

        $vehicles = $this->currentVehicles();
        $journeyKeys = [];
        foreach ($journeys as $journey) {
            $journeyKeys[$journey->key()] = true;
        }
        $serving = array_values(array_filter(
            $vehicles,
            static fn(VehicleState $vehicle): bool => $vehicle->journeyReference !== null
                && isset($journeyKeys[$vehicle->journeyReference->key()]),
        ));

        return new StationVehiclePositions(
            NearbyVehicleSelector::select($center, $vehicles, $radiusKm, $nearbyLimit),
            $serving,
        );
    }

    public function vehicle(string $vehicleId): ?VehicleState
    {
        foreach ($this->currentVehicles() as $vehicle) {
            if ($vehicle->id === $vehicleId) {
                return $vehicle;
            }
        }

        return null;
    }

    /** @return list<VehicleState> */
    private function currentVehicles(): array
    {
        $this->tick++;
        $state = match ($this->scenarios->current()) {
            Scenario::VehicleStale => VehicleFreshness::Stale,
            Scenario::VehicleLost => VehicleFreshness::Lost,
            default => VehicleFreshness::Live,
        };

        return FixtureFactory::vehicles($this->tick, $state);
    }
}
