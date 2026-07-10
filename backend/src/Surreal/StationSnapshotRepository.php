<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Dto\Departure;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\VehicleState;

final readonly class StationSnapshotRepository extends AbstractSurrealRepository
{
    public function save(StationSnapshot $snapshot): StationSnapshot
    {
        $results = $this->connection->run(<<<'SURQL'
UPSERT ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id))) CONTENT {
    station_id: type::string_lossy(encoding::base64::decode($station_id)),
    state: type::string_lossy(encoding::base64::decode($state)),
    version: type::string_lossy(encoding::base64::decode($version)),
    content_hash: type::string_lossy(encoding::base64::decode($content_hash)),
    updated_at: type::datetime(type::string_lossy(encoding::base64::decode($updated_at))),
    last_successful_at: IF $last_successful_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($last_successful_at))) },
    warning: IF $warning = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($warning)) },
    departures: encoding::json::decode($departures),
    nearby_vehicles: encoding::json::decode($nearby_vehicles)
}
WHERE (version = NONE OR type::datetime(type::string_lossy(encoding::base64::decode($version))) > type::datetime(version))
  AND (content_hash = NONE OR content_hash != type::string_lossy(encoding::base64::decode($content_hash)))
RETURN AFTER;
SELECT * FROM ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id)));
SURQL, [
            'station_id' => SurrealEncoding::string($snapshot->stationId),
            'state' => SurrealEncoding::string($snapshot->state->value),
            'version' => SurrealEncoding::string($snapshot->version),
            'content_hash' => SurrealEncoding::string($snapshot->contentHash),
            'updated_at' => SurrealEncoding::string(self::timestamp($snapshot->updatedAt)),
            'last_successful_at' => $snapshot->lastSuccessfulAt === null ? null : SurrealEncoding::string(self::timestamp($snapshot->lastSuccessfulAt)),
            'warning' => SurrealEncoding::nullableString($snapshot->warning),
            'departures' => SurrealEncoding::json(array_map(static fn(Departure $departure): array => $departure->toArray(), $snapshot->departures)),
            'nearby_vehicles' => SurrealEncoding::json(array_map(static fn(VehicleState $vehicle): array => $vehicle->toSummaryArray(), $snapshot->nearbyVehicles)),
        ]);

        return SurrealDtoMapper::stationSnapshot(self::lastRecord($results, 'station snapshot save'));
    }

    public function find(string $stationId): ?StationSnapshot
    {
        $results = $this->connection->run(
            'SELECT * FROM ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id)));',
            ['station_id' => SurrealEncoding::string($stationId)],
        );
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::stationSnapshot($record);
    }

}
