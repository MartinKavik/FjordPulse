<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Real;

use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Entur\VehiclePositionsInterface;

final readonly class RealVehiclePositions implements VehiclePositionsInterface
{
    private const string SELECTION = <<<'GRAPHQL'
vehicleId
lastUpdated
destinationName
location { latitude longitude }
bearing
delay
monitored
line { lineRef lineName publicCode }
monitoredCall { stopPointRef order vehicleAtStop }
GRAPHQL;

    public function __construct(
        private EnturApiClient $client,
        private VehicleMapper $mapper,
        private string $url = 'https://api.entur.io/realtime/v2/vehicles/graphql',
    ) {
    }

    /** @return list<VehicleState> */
    public function nearby(Coordinate $center, float $radiusKm = 5.0, int $limit = 20): array
    {
        $latitudeDelta = max(0.01, $radiusKm / 111.0);
        $longitudeScale = max(0.1, cos(deg2rad($center->latitude)));
        $longitudeDelta = max(0.01, $radiusKm / (111.0 * $longitudeScale));
        $query = 'query Nearby($bbox: BoundingBox!) { vehicles(boundingBox: $bbox) { ' . self::SELECTION . ' } }';
        $payload = $this->client->json(
            EnturService::VehiclePositions,
            'POST',
            $this->url,
            sprintf('bbox:%.4f,%.4f', $center->latitude, $center->longitude),
            [
                'query' => $query,
                'variables' => ['bbox' => [
                    'minLat' => $center->latitude - $latitudeDelta,
                    'minLon' => $center->longitude - $longitudeDelta,
                    'maxLat' => $center->latitude + $latitudeDelta,
                    'maxLon' => $center->longitude + $longitudeDelta,
                ]],
            ],
        );

        return array_slice($this->mapper->map($payload), 0, max(1, min(100, $limit)));
    }

    public function vehicle(string $vehicleId): ?VehicleState
    {
        $query = 'query Vehicle($id: String!) { vehicles(vehicleId: $id) { ' . self::SELECTION . ' } }';
        $payload = $this->client->json(
            EnturService::VehiclePositions,
            'POST',
            $this->url,
            'vehicle:' . $vehicleId,
            ['query' => $query, 'variables' => ['id' => $vehicleId]],
        );

        return $this->mapper->map($payload)[0] ?? null;
    }
}
