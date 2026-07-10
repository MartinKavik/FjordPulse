<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Dto\Station;

final readonly class StationRepository extends AbstractSurrealRepository
{
    public function save(Station $station, string $source = 'entur', ?string $sourceVersion = null): Station
    {
        $results = $this->connection->run(<<<'SURQL'
UPSERT ONLY type::record("station", type::string_lossy(encoding::base64::decode($station_id))) CONTENT {
    station_id: type::string_lossy(encoding::base64::decode($station_id)),
    name: type::string_lossy(encoding::base64::decode($name)),
    kind: type::string_lossy(encoding::base64::decode($kind)),
    locality: IF $locality = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($locality)) },
    municipality: IF $municipality = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($municipality)) },
    transport_modes: encoding::json::decode($transport_modes),
    latitude: $latitude,
    longitude: $longitude,
    search_text: type::string_lossy(encoding::base64::decode($search_text)),
    source: type::string_lossy(encoding::base64::decode($source)),
    source_version: IF $source_version = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($source_version)) },
    imported_at: type::datetime(type::string_lossy(encoding::base64::decode($imported_at))),
    updated_at: time::now(),
    metadata: {}
} RETURN AFTER;
SURQL, self::bindings($station, $source, $sourceVersion));

        return SurrealDtoMapper::station(self::lastRecord($results, 'station save'));
    }

    /** @param list<Station> $stations */
    public function saveMany(array $stations, string $source = 'entur', ?string $sourceVersion = null): int
    {
        if ($stations === []) {
            return 0;
        }

        $records = array_map(
            static fn(Station $station): array => self::bindings($station, $source, $sourceVersion),
            $stations,
        );

        $this->connection->run(<<<'SURQL'
FOR $station IN $stations {
    UPSERT type::record("station", type::string_lossy(encoding::base64::decode($station.station_id))) CONTENT {
        station_id: type::string_lossy(encoding::base64::decode($station.station_id)),
        name: type::string_lossy(encoding::base64::decode($station.name)),
        kind: type::string_lossy(encoding::base64::decode($station.kind)),
        locality: IF $station.locality = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($station.locality)) },
        municipality: IF $station.municipality = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($station.municipality)) },
        transport_modes: encoding::json::decode($station.transport_modes),
        latitude: $station.latitude,
        longitude: $station.longitude,
        search_text: type::string_lossy(encoding::base64::decode($station.search_text)),
        source: type::string_lossy(encoding::base64::decode($station.source)),
        source_version: IF $station.source_version = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($station.source_version)) },
        imported_at: type::datetime(type::string_lossy(encoding::base64::decode($station.imported_at))),
        updated_at: time::now(),
        metadata: {}
    } RETURN NONE;
};
SURQL, ['stations' => $records]);

        return count($records);
    }

    public function find(string $stationId): ?Station
    {
        $results = $this->connection->run(
            'SELECT * FROM ONLY type::record("station", type::string_lossy(encoding::base64::decode($station_id)));',
            ['station_id' => SurrealEncoding::string($stationId)],
        );
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::station($record);
    }

    /** @return list<Station> */
    public function withinBounds(
        float $minimumLongitude,
        float $minimumLatitude,
        float $maximumLongitude,
        float $maximumLatitude,
        int $limit = 2_000,
    ): array {
        if ($limit < 1 || $limit > 10_000) {
            throw new \InvalidArgumentException('Station bounds limit must be between 1 and 10000.');
        }

        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM station
WHERE longitude >= $minimum_longitude AND longitude <= $maximum_longitude
  AND latitude >= $minimum_latitude AND latitude <= $maximum_latitude
ORDER BY station_id ASC
LIMIT $limit;
SURQL, [
            'minimum_longitude' => $minimumLongitude,
            'minimum_latitude' => $minimumLatitude,
            'maximum_longitude' => $maximumLongitude,
            'maximum_latitude' => $maximumLatitude,
            'limit' => $limit,
        ]);

        return array_map(SurrealDtoMapper::station(...), DatabaseRecord::many($results[0] ?? []));
    }

    /** @return list<Station> */
    public function search(string $query, int $limit = 25): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Station search limit must be between 1 and 100.');
        }

        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM station
WHERE search_text CONTAINS string::lowercase(type::string_lossy(encoding::base64::decode($query)))
ORDER BY name COLLATE ASC, station_id ASC
LIMIT $limit;
SURQL, ['query' => SurrealEncoding::string($query), 'limit' => $limit]);

        return array_map(SurrealDtoMapper::station(...), DatabaseRecord::many($results[0] ?? []));
    }

    public function nearest(float $latitude, float $longitude): ?Station
    {
        $results = $this->connection->run(<<<'SURQL'
SELECT *,
    ((latitude - $latitude) * (latitude - $latitude))
    + ((longitude - $longitude) * (longitude - $longitude)) AS coordinate_distance
FROM station
ORDER BY coordinate_distance ASC, station_id ASC
LIMIT 1;
SURQL, ['latitude' => $latitude, 'longitude' => $longitude]);
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::station($record);
    }

    public function count(): int
    {
        $results = $this->connection->run('RETURN count(SELECT VALUE id FROM station);');

        return DatabaseRecord::int($results[0] ?? null, 'station count');
    }

    /** @return array<string, mixed> */
    private static function bindings(Station $station, string $source, ?string $sourceVersion): array
    {
        $searchText = strtolower(implode(' ', array_filter([
            $station->name,
            $station->locality,
            $station->municipality,
        ], static fn(?string $value): bool => $value !== null && $value !== '')));

        return [
            'station_id' => SurrealEncoding::string($station->id),
            'name' => SurrealEncoding::string($station->name),
            'kind' => SurrealEncoding::string($station->kind->value),
            'locality' => SurrealEncoding::nullableString($station->locality),
            'municipality' => SurrealEncoding::nullableString($station->municipality),
            'transport_modes' => SurrealEncoding::json($station->transportModes),
            'latitude' => $station->coordinate->latitude,
            'longitude' => $station->coordinate->longitude,
            'search_text' => SurrealEncoding::string($searchText),
            'source' => SurrealEncoding::string($source),
            'source_version' => SurrealEncoding::nullableString($sourceVersion),
            'imported_at' => SurrealEncoding::string(self::timestamp($station->importedAt)),
        ];
    }
}
