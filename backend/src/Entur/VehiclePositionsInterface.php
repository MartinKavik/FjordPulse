<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\StationVehiclePositions;
use FjordPulse\Dto\VehicleJourneyReference;
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

    /**
     * @param list<VehicleJourneyReference> $journeys
     */
    public function stationVehicles(
        Coordinate $center,
        array $journeys,
        float $radiusKm = NearbyVehicleSelector::DEFAULT_RADIUS_KM,
        int $nearbyLimit = NearbyVehicleSelector::DEFAULT_LIMIT,
    ): StationVehiclePositions;

    public function vehicle(string $vehicleId): ?VehicleState;
}
