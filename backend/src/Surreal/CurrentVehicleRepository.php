<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Dto\VehicleState;

final readonly class CurrentVehicleRepository extends AbstractSurrealRepository
{
    public function save(VehicleState $vehicle): VehicleState
    {
        $results = $this->connection->run(<<<'SURQL'
UPSERT ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($vehicle_id))) CONTENT {
    vehicle_id: type::string_lossy(encoding::base64::decode($vehicle_id)),
    line_code: IF $line_code = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($line_code)) },
    route_name: IF $route_name = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($route_name)) },
    destination: IF $destination = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($destination)) },
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
    updated_at: type::datetime(type::string_lossy(encoding::base64::decode($updated_at)))
}
WHERE (version = NONE OR type::datetime(type::string_lossy(encoding::base64::decode($version))) > type::datetime(version))
  AND (content_hash = NONE OR content_hash != type::string_lossy(encoding::base64::decode($content_hash)))
RETURN AFTER;
SELECT * FROM ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($vehicle_id)));
SURQL, [
            'vehicle_id' => SurrealEncoding::string($vehicle->id),
            'line_code' => SurrealEncoding::nullableString($vehicle->lineCode),
            'route_name' => SurrealEncoding::nullableString($vehicle->routeName),
            'destination' => SurrealEncoding::nullableString($vehicle->destination),
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
    public function search(string $query, int $limit = 25): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Vehicle search limit must be between 1 and 100.');
        }

        $normalized = preg_replace('/^(?:line|linje)\s+/iu', '', $query) ?? $query;
        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM current_vehicle
WHERE string::lowercase(vehicle_id) CONTAINS string::lowercase(type::string_lossy(encoding::base64::decode($query)))
   OR string::lowercase(line_code ?? "") CONTAINS string::lowercase(type::string_lossy(encoding::base64::decode($normalized)))
   OR string::lowercase(route_name ?? "") CONTAINS string::lowercase(type::string_lossy(encoding::base64::decode($query)))
   OR string::lowercase(destination ?? "") CONTAINS string::lowercase(type::string_lossy(encoding::base64::decode($query)))
ORDER BY line_code COLLATE ASC, vehicle_id ASC
LIMIT $limit;
SURQL, [
            'query' => SurrealEncoding::string($query),
            'normalized' => SurrealEncoding::string($normalized),
            'limit' => $limit,
        ]);

        return array_map(SurrealDtoMapper::currentVehicle(...), DatabaseRecord::many($results[0] ?? []));
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
