<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Service\SearchNormalizer;

final readonly class CurrentVehicleRepository extends AbstractSurrealRepository
{
    public function __construct(
        SurrealConnection $connection,
        private SearchNormalizer $normalizer = new SearchNormalizer(),
    ) {
        parent::__construct($connection);
    }

    public function save(VehicleState $vehicle): VehicleState
    {
        $searchText = $this->normalizer->normalize(implode(' ', array_filter([
            $vehicle->id,
            $vehicle->lineCode,
            $vehicle->routeName,
            $vehicle->destination,
            $vehicle->transportMode->value,
        ], static fn(?string $value): bool => $value !== null && $value !== '')));
        $results = $this->connection->run(<<<'SURQL'
UPDATE ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($vehicle_id))) SET
    refreshed_at = type::datetime(type::string_lossy(encoding::base64::decode($refreshed_at))),
    search_text = type::string_lossy(encoding::base64::decode($search_text))
WHERE content_hash = type::string_lossy(encoding::base64::decode($content_hash));
UPSERT ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($vehicle_id))) CONTENT {
    vehicle_id: type::string_lossy(encoding::base64::decode($vehicle_id)),
    transport_mode: type::string_lossy(encoding::base64::decode($transport_mode)),
    passenger_service_state: type::string_lossy(encoding::base64::decode($passenger_service_state)),
    line_code: IF $line_code = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($line_code)) },
    route_name: IF $route_name = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($route_name)) },
    destination: IF $destination = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($destination)) },
    search_text: type::string_lossy(encoding::base64::decode($search_text)),
    state: type::string_lossy(encoding::base64::decode($state)),
    latitude: $latitude ?? NONE,
    longitude: $longitude ?? NONE,
    bearing: $bearing ?? NONE,
    delay_seconds: $delay_seconds ?? NONE,
    distance_meters: $distance_meters ?? NONE,
    last_seen_at: type::datetime(type::string_lossy(encoding::base64::decode($last_seen_at))),
    version: type::string_lossy(encoding::base64::decode($version)),
    content_hash: type::string_lossy(encoding::base64::decode($content_hash)),
    next_stop: IF $next_stop = NULL { NONE } ELSE { encoding::json::decode($next_stop) },
    updated_at: type::datetime(type::string_lossy(encoding::base64::decode($updated_at))),
    refreshed_at: type::datetime(type::string_lossy(encoding::base64::decode($refreshed_at))),
    journey_reference: IF $journey_reference = NULL { NONE } ELSE { encoding::json::decode($journey_reference) },
    monitored_call: IF $monitored_call = NULL { NONE } ELSE { encoding::json::decode($monitored_call) },
    progress_between_stops: IF $progress_between_stops = NULL { NONE } ELSE { encoding::json::decode($progress_between_stops) },
    journey_version: IF $journey_version = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($journey_version)) },
    route_progress: $route_progress ?? NONE
}
WHERE (version = NONE OR type::datetime(type::string_lossy(encoding::base64::decode($version))) > type::datetime(version))
  AND (content_hash = NONE OR content_hash != type::string_lossy(encoding::base64::decode($content_hash)))
RETURN AFTER;
SELECT * FROM ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($vehicle_id)));
SURQL, [
            'vehicle_id' => SurrealEncoding::string($vehicle->id),
            'transport_mode' => SurrealEncoding::string($vehicle->transportMode->value),
            'passenger_service_state' => SurrealEncoding::string($vehicle->passengerServiceState->value),
            'line_code' => SurrealEncoding::nullableString($vehicle->lineCode),
            'route_name' => SurrealEncoding::nullableString($vehicle->routeName),
            'destination' => SurrealEncoding::nullableString($vehicle->destination),
            'search_text' => SurrealEncoding::string($searchText),
            'state' => SurrealEncoding::string($vehicle->state->value),
            'latitude' => $vehicle->coordinate?->latitude,
            'longitude' => $vehicle->coordinate?->longitude,
            'bearing' => $vehicle->bearing,
            'delay_seconds' => $vehicle->delaySeconds,
            'distance_meters' => $vehicle->distanceMeters,
            'last_seen_at' => SurrealEncoding::string(self::timestamp($vehicle->lastSeenAt)),
            'version' => SurrealEncoding::string($vehicle->version),
            'content_hash' => SurrealEncoding::string($vehicle->contentHash),
            'next_stop' => $vehicle->nextStop === null ? null : SurrealEncoding::json($vehicle->nextStop->toArray()),
            'updated_at' => SurrealEncoding::string(self::timestamp($vehicle->updatedAt)),
            'refreshed_at' => SurrealEncoding::string(self::timestamp($vehicle->refreshedAt ?? $vehicle->updatedAt)),
            'journey_reference' => $vehicle->journeyReference === null ? null : SurrealEncoding::json($vehicle->journeyReference->toArray()),
            'monitored_call' => $vehicle->monitoredCall === null ? null : SurrealEncoding::json($vehicle->monitoredCall->toArray()),
            'progress_between_stops' => $vehicle->progressBetweenStops === null ? null : SurrealEncoding::json($vehicle->progressBetweenStops->toArray()),
            'journey_version' => SurrealEncoding::nullableString($vehicle->journeyVersion),
            'route_progress' => $vehicle->routeProgress,
        ]);

        return SurrealDtoMapper::currentVehicle(self::lastRecord($results, 'current vehicle save'));
    }

    public function find(string $vehicleId): ?VehicleState
    {
        $results = $this->connection->run(
            'SELECT * FROM ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($vehicle_id)));',
            ['vehicle_id' => SurrealEncoding::string($vehicleId)],
        );
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::currentVehicle($record);
    }

    /** @return list<VehicleState> */
    public function search(string $query, int $limit = 25, ?DateTimeImmutable $notBefore = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Vehicle search limit must be between 1 and 100.');
        }

        $normalizedQuery = $this->normalizer->normalize($query);
        $normalizedLine = preg_replace('/^(?:line|linje)\s+/u', '', $normalizedQuery) ?? $normalizedQuery;
        $queryPrefixes = array_map(
            static fn(string $token): string => 'p:' . $token,
            $this->normalizer->tokens($normalizedLine),
        );
        if ($queryPrefixes === []) {
            return [];
        }
        $candidateLimit = min(100, max(50, $limit * 5));
        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM current_vehicle
WHERE search_prefixes CONTAINSALL encoding::json::decode($query_prefixes)
  AND ($not_before = NULL OR refreshed_at >= type::datetime(type::string_lossy(encoding::base64::decode($not_before))))
  AND state != "lost"
ORDER BY line_code COLLATE ASC, vehicle_id ASC
LIMIT $limit;
SURQL, [
            'query_prefixes' => SurrealEncoding::json($queryPrefixes),
            'not_before' => $notBefore === null ? null : SurrealEncoding::string(self::timestamp($notBefore)),
            'limit' => $candidateLimit,
        ]);

        return array_slice(
            array_map(SurrealDtoMapper::currentVehicle(...), DatabaseRecord::many($results[0] ?? [])),
            0,
            $limit,
        );
    }

    /**
     * @param list<string> $vehicleIds
     * @return list<VehicleState>
     */
    public function byIds(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $results = $this->connection->run(
            'SELECT * FROM current_vehicle WHERE vehicle_id IN $vehicle_ids.map(|$id| type::string_lossy(encoding::base64::decode($id))) ORDER BY vehicle_id ASC;',
            ['vehicle_ids' => array_map(SurrealEncoding::string(...), $vehicleIds)],
        );

        return array_map(SurrealDtoMapper::currentVehicle(...), DatabaseRecord::many($results[0] ?? []));
    }
}
