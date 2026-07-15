<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Dto\Departure;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\StationVehicle;
use FjordPulse\Dto\VehicleState;

final readonly class StationSnapshotRepository extends AbstractSurrealRepository
{
    public function save(StationSnapshot $snapshot): StationSnapshot
    {
        $results = $this->connection->run(<<<'SURQL'
UPDATE ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id))) SET
    updated_at = type::datetime(type::string_lossy(encoding::base64::decode($updated_at))),
    last_successful_at = IF $last_successful_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($last_successful_at))) },
    departure_window_started_at = IF $departure_window_started_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($departure_window_started_at))) },
    departure_window_ends_at = IF $departure_window_ends_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($departure_window_ends_at))) },
    departure_limit = IF $departure_limit = NULL { NONE } ELSE { $departure_limit },
    departure_has_more = IF $departure_has_more = NULL { NONE } ELSE { $departure_has_more },
    serving_window_started_at = IF $serving_window_started_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($serving_window_started_at))) },
    serving_window_ends_at = IF $serving_window_ends_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($serving_window_ends_at))) }
WHERE content_hash = type::string_lossy(encoding::base64::decode($content_hash))
  AND updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at)));
UPSERT ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id))) CONTENT {
    station_id: type::string_lossy(encoding::base64::decode($station_id)),
    state: type::string_lossy(encoding::base64::decode($state)),
    version: type::string_lossy(encoding::base64::decode($version)),
    content_hash: type::string_lossy(encoding::base64::decode($content_hash)),
    updated_at: type::datetime(type::string_lossy(encoding::base64::decode($updated_at))),
    last_successful_at: IF $last_successful_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($last_successful_at))) },
    warning: IF $warning = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($warning)) },
    departures: encoding::json::decode($departures),
    departure_window_started_at: IF $departure_window_started_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($departure_window_started_at))) },
    departure_window_ends_at: IF $departure_window_ends_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($departure_window_ends_at))) },
    departure_limit: IF $departure_limit = NULL { NONE } ELSE { $departure_limit },
    departure_has_more: IF $departure_has_more = NULL { NONE } ELSE { $departure_has_more },
    nearby_vehicles: encoding::json::decode($nearby_vehicles),
    serving_vehicles: encoding::json::decode($serving_vehicles),
    serving_window_started_at: IF $serving_window_started_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($serving_window_started_at))) },
    serving_window_ends_at: IF $serving_window_ends_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($serving_window_ends_at))) },
    serving_candidate_journey_count: $serving_candidate_journey_count,
    serving_queried_journey_count: $serving_queried_journey_count,
    serving_vehicles_truncated: $serving_vehicles_truncated
}
WHERE (version = NONE OR type::datetime(type::string_lossy(encoding::base64::decode($version))) > type::datetime(version))
  AND (content_hash = NONE OR content_hash != type::string_lossy(encoding::base64::decode($content_hash)))
RETURN AFTER;
SELECT * FROM ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id)));
SURQL, $this->bindings($snapshot));

        return SurrealDtoMapper::stationSnapshot(self::lastRecord($results, 'station snapshot save'));
    }

    /**
     * Persist a snapshot produced by a refresh of the supplied authoritative
     * base version.
     *
     * Unlike save(), this path deliberately serializes semantic writers that
     * raced from the same base. SurrealDB allocates the committed version from
     * the record's current updated_at, so a collision advances by one
     * millisecond instead of silently discarding the later writer.
     */
    public function saveRefresh(StationSnapshot $snapshot, ?string $baseVersion): StationSnapshot
    {
        $bindings = $this->bindings($snapshot);
        $bindings['has_base_version'] = $baseVersion !== null;
        $bindings['base_version'] = SurrealEncoding::string($baseVersion ?? $snapshot->version);

        $results = $this->connection->run(<<<'SURQL'
LET $candidate_at = type::datetime(time::format(
    type::datetime(type::string_lossy(encoding::base64::decode($version))),
    "%Y-%m-%dT%H:%M:%S%.3fZ"
));
LET $new_hash = type::string_lossy(encoding::base64::decode($content_hash));
LET $base_token = IF $has_base_version {
    type::string_lossy(encoding::base64::decode($base_version))
} ELSE {
    "__missing_station_snapshot__"
};
UPSERT ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id))) SET
    station_id = type::string_lossy(encoding::base64::decode($station_id)),
    state = IF content_hash = NONE OR content_hash != $new_hash { type::string_lossy(encoding::base64::decode($state)) } ELSE { state },
    version = IF content_hash = NONE OR content_hash != $new_hash {
        time::format(
            IF updated_at = NONE OR $candidate_at > updated_at { $candidate_at } ELSE { updated_at + 1ms },
            "%Y-%m-%dT%H:%M:%S%.3fZ"
        )
    } ELSE { version },
    refresh_base_version = $base_token,
    content_hash = IF content_hash = NONE OR content_hash != $new_hash { $new_hash } ELSE { content_hash },
    updated_at = IF content_hash = NONE OR content_hash != $new_hash {
        type::datetime(time::format(
            IF updated_at = NONE OR $candidate_at > updated_at { $candidate_at } ELSE { updated_at + 1ms },
            "%Y-%m-%dT%H:%M:%S%.3fZ"
        ))
    } ELSE IF updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at))) {
        type::datetime(type::string_lossy(encoding::base64::decode($updated_at)))
    } ELSE { updated_at },
    last_successful_at = IF content_hash = NONE
        OR content_hash != $new_hash
        OR updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at))) {
        IF $last_successful_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($last_successful_at))) }
    } ELSE { last_successful_at },
    warning = IF content_hash = NONE OR content_hash != $new_hash {
        IF $warning = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($warning)) }
    } ELSE { warning },
    departures = IF content_hash = NONE OR content_hash != $new_hash { encoding::json::decode($departures) } ELSE { departures },
    departure_window_started_at = IF content_hash = NONE
        OR content_hash != $new_hash
        OR updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at))) {
        IF $departure_window_started_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($departure_window_started_at))) }
    } ELSE { departure_window_started_at },
    departure_window_ends_at = IF content_hash = NONE
        OR content_hash != $new_hash
        OR updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at))) {
        IF $departure_window_ends_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($departure_window_ends_at))) }
    } ELSE { departure_window_ends_at },
    departure_limit = IF content_hash = NONE
        OR content_hash != $new_hash
        OR updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at))) {
        IF $departure_limit = NULL { NONE } ELSE { $departure_limit }
    } ELSE { departure_limit },
    departure_has_more = IF content_hash = NONE
        OR content_hash != $new_hash
        OR updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at))) {
        IF $departure_has_more = NULL { NONE } ELSE { $departure_has_more }
    } ELSE { departure_has_more },
    nearby_vehicles = IF content_hash = NONE OR content_hash != $new_hash { encoding::json::decode($nearby_vehicles) } ELSE { nearby_vehicles },
    serving_vehicles = IF content_hash = NONE OR content_hash != $new_hash { encoding::json::decode($serving_vehicles) } ELSE { serving_vehicles },
    serving_window_started_at = IF content_hash = NONE
        OR content_hash != $new_hash
        OR updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at))) {
        IF $serving_window_started_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($serving_window_started_at))) }
    } ELSE { serving_window_started_at },
    serving_window_ends_at = IF content_hash = NONE
        OR content_hash != $new_hash
        OR updated_at < type::datetime(type::string_lossy(encoding::base64::decode($updated_at))) {
        IF $serving_window_ends_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($serving_window_ends_at))) }
    } ELSE { serving_window_ends_at },
    serving_candidate_journey_count = IF content_hash = NONE OR content_hash != $new_hash { $serving_candidate_journey_count } ELSE { serving_candidate_journey_count },
    serving_queried_journey_count = IF content_hash = NONE OR content_hash != $new_hash { $serving_queried_journey_count } ELSE { serving_queried_journey_count },
    serving_vehicles_truncated = IF content_hash = NONE OR content_hash != $new_hash { $serving_vehicles_truncated } ELSE { serving_vehicles_truncated }
WHERE content_hash = NONE
   OR (
       content_hash = $new_hash
       AND IF $has_base_version {
           version = $base_token OR refresh_base_version = $base_token
       } ELSE {
           refresh_base_version = NONE OR refresh_base_version = $base_token
       }
   )
   OR (
       content_hash != $new_hash
       AND IF $has_base_version {
           version = $base_token OR refresh_base_version = $base_token
       } ELSE {
           refresh_base_version = NONE OR refresh_base_version = $base_token
       }
   )
RETURN AFTER;
SELECT * FROM ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id)));
SURQL, $bindings);

        return SurrealDtoMapper::stationSnapshot(self::lastRecord($results, 'station snapshot refresh save'));
    }

    /** @return array<string, mixed> */
    private function bindings(StationSnapshot $snapshot): array
    {
        $departureBoard = $snapshot->departureBoardCoverage();

        return [
            'station_id' => SurrealEncoding::string($snapshot->stationId),
            'state' => SurrealEncoding::string($snapshot->state->value),
            'version' => SurrealEncoding::string($snapshot->version),
            'content_hash' => SurrealEncoding::string($snapshot->contentHash),
            'updated_at' => SurrealEncoding::string(self::timestamp($snapshot->updatedAt)),
            'last_successful_at' => $snapshot->lastSuccessfulAt === null ? null : SurrealEncoding::string(self::timestamp($snapshot->lastSuccessfulAt)),
            'warning' => SurrealEncoding::nullableString($snapshot->warning),
            'departures' => SurrealEncoding::json(array_map(static fn(Departure $departure): array => $departure->toArray(), $snapshot->departures)),
            'departure_window_started_at' => SurrealEncoding::string(self::timestamp($departureBoard->windowStart)),
            'departure_window_ends_at' => SurrealEncoding::string(self::timestamp($departureBoard->windowEnd)),
            'departure_limit' => $departureBoard->limit,
            'departure_has_more' => $departureBoard->hasMore,
            'nearby_vehicles' => SurrealEncoding::json(array_map(static fn(VehicleState $vehicle): array => $vehicle->toSummaryArray(), $snapshot->nearbyVehicles)),
            'serving_vehicles' => SurrealEncoding::json(array_map(static fn(StationVehicle $vehicle): array => $vehicle->toArray(), $snapshot->servingVehicles)),
            'serving_window_started_at' => $snapshot->servingWindowStartedAt === null ? null : SurrealEncoding::string(self::timestamp($snapshot->servingWindowStartedAt)),
            'serving_window_ends_at' => $snapshot->servingWindowEndsAt === null ? null : SurrealEncoding::string(self::timestamp($snapshot->servingWindowEndsAt)),
            'serving_candidate_journey_count' => $snapshot->servingCandidateJourneyCount,
            'serving_queried_journey_count' => $snapshot->servingQueriedJourneyCount,
            'serving_vehicles_truncated' => $snapshot->servingVehiclesTruncated,
        ];
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
