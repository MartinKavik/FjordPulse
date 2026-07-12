<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Real;

use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\StationVehiclePositions;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Entur\NearbyVehicleSelector;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Entur\VehiclePositionsInterface;

final class RealVehiclePositions implements VehiclePositionsInterface
{
    private const string SELECTION = <<<'GRAPHQL'
vehicleId
mode
lastUpdated
destinationName
location { latitude longitude }
bearing
delay
monitored
line { lineRef lineName publicCode }
monitoredCall { stopPointRef order vehicleAtStop }
progressBetweenStops { linkDistance percentage }
serviceJourney { id date }
datedServiceJourney { id serviceJourney { id date } }
originRef
originName
destinationRef
GRAPHQL;

    /** @var list<VehicleState> */
    private array $nationwideCache = [];
    private ?DateTimeImmutable $nationwideCachedAt = null;
    /** @var \Closure(): DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly EnturApiClient $client,
        private readonly VehicleMapper $mapper,
        private readonly string $url = 'https://api.entur.io/realtime/v2/vehicles/graphql',
        private readonly int $nationwideCacheSeconds = 2,
        ?\Closure $clock = null,
    ) {
        if ($nationwideCacheSeconds < 1 || $nationwideCacheSeconds > 30) {
            throw new \InvalidArgumentException('Vehicle Positions cache must be between 1 and 30 seconds.');
        }
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @return list<VehicleState> */
    public function current(): array
    {
        return $this->nationwide();
    }

    /** @return list<VehicleState> */
    public function nearby(
        Coordinate $center,
        float $radiusKm = NearbyVehicleSelector::DEFAULT_RADIUS_KM,
        int $limit = NearbyVehicleSelector::DEFAULT_LIMIT,
    ): array
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

        return NearbyVehicleSelector::select($center, $this->mapper->map($payload), $radiusKm, $limit);
    }

    /** @param list<VehicleJourneyReference> $journeys */
    public function stationVehicles(
        Coordinate $center,
        array $journeys,
        float $radiusKm = NearbyVehicleSelector::DEFAULT_RADIUS_KM,
        int $nearbyLimit = NearbyVehicleSelector::DEFAULT_LIMIT,
    ): StationVehiclePositions {
        if (!is_finite($radiusKm) || $radiusKm <= 0.0) {
            throw new \InvalidArgumentException('Station vehicle radius must be a positive finite distance.');
        }

        /** @var array<string, VehicleJourneyReference> $uniqueJourneys */
        $uniqueJourneys = [];
        foreach ($journeys as $journey) {
            $uniqueJourneys[$journey->key()] = $journey;
        }
        if (count($uniqueJourneys) > 200) {
            throw new \InvalidArgumentException('Station vehicle lookup supports at most 200 dated service journeys.');
        }

        $latitudeDelta = max(0.01, $radiusKm / 111.0);
        $longitudeScale = max(0.1, cos(deg2rad($center->latitude)));
        $longitudeDelta = max(0.01, $radiusKm / (111.0 * $longitudeScale));
        /** @var array<string, mixed> $variables */
        $variables = ['bbox' => [
            'minLat' => $center->latitude - $latitudeDelta,
            'minLon' => $center->longitude - $longitudeDelta,
            'maxLat' => $center->latitude + $latitudeDelta,
            'maxLon' => $center->longitude + $longitudeDelta,
        ]];
        if ($uniqueJourneys === []) {
            $query = 'query StationVehicles($bbox: BoundingBox!) { nearby: vehicles(boundingBox: $bbox) { '
                . self::SELECTION . ' } }';
        } else {
            $query = 'query StationVehicles($bbox: BoundingBox!, $journeys: [ServiceJourneyIdAndDate!]!) {'
                . ' nearby: vehicles(boundingBox: $bbox) { ' . self::SELECTION . ' }'
                . ' serving: vehicles(serviceJourneyIdAndDates: $journeys) { ' . self::SELECTION . ' }'
                . ' }';
            $variables['journeys'] = array_map(
                static fn(VehicleJourneyReference $journey): array => [
                    'id' => $journey->serviceJourneyId,
                    'date' => $journey->operatingDate,
                ],
                array_values($uniqueJourneys),
            );
        }

        $payload = $this->client->json(
            EnturService::VehiclePositions,
            'POST',
            $this->url,
            sprintf('station:%.5f,%.5f:%d', $center->latitude, $center->longitude, count($uniqueJourneys)),
            ['query' => $query, 'variables' => $variables],
        );
        if (isset($payload['errors'])) {
            throw new SourceUnavailable('Entur Vehicle Positions returned GraphQL errors.');
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new SourceUnavailable('Entur Vehicle Positions returned invalid station vehicle data.');
        }
        $nearbyRecords = $data['nearby'] ?? [];
        $servingRecords = $data['serving'] ?? [];
        $nearby = $this->mapper->map(['data' => ['vehicles' => $nearbyRecords]]);
        $serving = $uniqueJourneys === []
            ? []
            : $this->mapper->map(['data' => ['vehicles' => $servingRecords]]);

        return new StationVehiclePositions(
            NearbyVehicleSelector::select($center, $nearby, $radiusKm, $nearbyLimit),
            $serving,
        );
    }

    public function vehicle(string $vehicleId): ?VehicleState
    {
        foreach ($this->nationwide() as $vehicle) {
            if ($vehicle->id === $vehicleId) {
                return $vehicle;
            }
        }

        return null;
    }

    /** @return list<VehicleState> */
    private function nationwide(): array
    {
        $now = ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
        if ($this->nationwideCachedAt !== null
            && $this->nationwideCachedAt >= $now->modify('-' . $this->nationwideCacheSeconds . ' seconds')) {
            return $this->nationwideCache;
        }
        $query = 'query CurrentVehicles { vehicles { ' . self::SELECTION . ' } }';
        $payload = $this->client->json(
            EnturService::VehiclePositions,
            'POST',
            $this->url,
            'vehicles:nationwide',
            ['query' => $query],
        );
        $this->nationwideCache = $this->mapper->map($payload);
        $this->nationwideCachedAt = $now;

        return $this->nationwideCache;
    }
}
