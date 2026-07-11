<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use FjordPulse\Domain\Scenario;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\ScenarioProviderInterface;
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
    public function nearby(Coordinate $center, float $radiusKm = 5.0, int $limit = 20): array
    {
        unset($center, $radiusKm);

        return array_slice($this->currentVehicles(), 0, max(0, $limit));
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
