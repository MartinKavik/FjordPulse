<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleState;

interface VehiclePositionsInterface
{
    /** @return list<VehicleState> */
    public function nearby(Coordinate $center, float $radiusKm = 5.0, int $limit = 20): array;

    public function vehicle(string $vehicleId): ?VehicleState;
}
