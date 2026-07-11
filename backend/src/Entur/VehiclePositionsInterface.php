<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleState;

interface VehiclePositionsInterface
{
    /** @return list<VehicleState> */
    public function current(): array;

    /** @return list<VehicleState> */
    public function nearby(
        Coordinate $center,
        float $radiusKm = NearbyVehicleSelector::DEFAULT_RADIUS_KM,
        int $limit = NearbyVehicleSelector::DEFAULT_LIMIT,
    ): array;

    public function vehicle(string $vehicleId): ?VehicleState;
}
