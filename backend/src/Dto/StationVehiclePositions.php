<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

final readonly class StationVehiclePositions
{
    /**
     * @param list<VehicleState> $nearbyVehicles
     * @param list<VehicleState> $servingVehicles
     */
    public function __construct(
        public array $nearbyVehicles,
        public array $servingVehicles,
    ) {
    }
}
