<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Dto\Station;
use FjordPulse\Service\SearchNormalizer;

final readonly class StationRepository extends AbstractSurrealRepository
{
    public function __construct(
        SurrealConnection $connection,
        private SearchNormalizer $normalizer = new SearchNormalizer(),
    ) {
        parent::__construct($connection);
    }

    public function save(
        Station $station,
        string $source = 'entur',
        ?string $sourceVersion = null,
        ?string $sourceMode = null,
        ?string $catalogId = null,
    ): Station
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
    search_name: type::string_lossy(encoding::base64::decode($search_name)),
    search_locality: IF $search_locality = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($search_locality)) },
    search_municipality: IF $search_municipality = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($search_municipality)) },
    search_tokens: encoding::json::decode($search_tokens),
    source: type::string_lossy(encoding::base64::decode($source)),
    source_mode: type::string_lossy(encoding::base64::decode($source_mode)),
    source_version: IF $source_version = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($source_version)) },
    catalog_id: IF $catalog_id = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($catalog_id)) },
    imported_at: type::datetime(type::string_lossy(encoding::base64::decode($imported_at))),
    updated_at: time::now(),
    metadata: {}
} RETURN AFTER;
SURQL, $this->bindings($station, $source, $sourceVersion, $sourceMode, $catalogId));

        return SurrealDtoMapper::station(self::lastRecord($results, 'station save'));
    }

    /** @param list<Station> $stations */
    public function saveMany(
        array $stations,
        string $source = 'entur',
        ?string $sourceVersion = null,
        ?string $sourceMode = null,
        ?string $catalogId = null,
    ): int
    {
        if ($stations === []) {
            return 0;
        }

        $records = array_map(
            fn(Station $station): array => $this->bindings($station, $source, $sourceVersion, $sourceMode, $catalogId),
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
        search_name: type::string_lossy(encoding::base64::decode($station.search_name)),
        search_locality: IF $station.search_locality = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($station.search_locality)) },
        search_municipality: IF $station.search_municipality = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($station.search_municipality)) },
        search_tokens: encoding::json::decode($station.search_tokens),
        source: type::string_lossy(encoding::base64::decode($station.source)),
        source_mode: type::string_lossy(encoding::base64::decode($station.source_mode)),
        source_version: IF $station.source_version = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($station.source_version)) },
        catalog_id: IF $station.catalog_id = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($station.catalog_id)) },
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
        ?int $limit = null,
    ): array {
        if ($limit !== null && ($limit < 1 || $limit > 100_000)) {
            throw new \InvalidArgumentException('Station bounds limit must be between 1 and 100000.');
        }

        $query = <<<'SURQL'
SELECT * FROM station
WHERE longitude >= $minimum_longitude AND longitude <= $maximum_longitude
  AND latitude >= $minimum_latitude AND latitude <= $maximum_latitude
ORDER BY station_id ASC
SURQL;
        $bindings = [
            'minimum_longitude' => $minimumLongitude,
            'minimum_latitude' => $minimumLatitude,
            'maximum_longitude' => $maximumLongitude,
            'maximum_latitude' => $maximumLatitude,
        ];
        if ($limit !== null) {
            $query .= " LIMIT \$limit;";
            $bindings['limit'] = $limit;
        } else {
            $query .= ';';
        }
        $results = $this->connection->run($query, $bindings);

        return array_map(SurrealDtoMapper::station(...), DatabaseRecord::many($results[0] ?? []));
    }

    public function countWithinBounds(
        float $minimumLongitude,
        float $minimumLatitude,
        float $maximumLongitude,
        float $maximumLatitude,
    ): int {
        $results = $this->connection->run(<<<'SURQL'
RETURN count(SELECT VALUE id FROM station
WHERE longitude >= $minimum_longitude AND longitude <= $maximum_longitude
  AND latitude >= $minimum_latitude AND latitude <= $maximum_latitude);
SURQL, self::boundsBindings(
            $minimumLongitude,
            $minimumLatitude,
            $maximumLongitude,
            $maximumLatitude,
        ));

        return DatabaseRecord::int($results[0] ?? null, 'station bounds count');
    }

    /**
     * Return only the fields needed by the public map. The caller deliberately
     * asks for one row beyond its output cap so a concurrent catalog change can
     * never make a detailed marker response silently incomplete.
     *
     * @return list<array{
     *     kind: 'station',
     *     id: string,
     *     name: string,
     *     latitude: float,
     *     longitude: float,
     *     transportModes: list<string>
     * }>
     */
    public function projectedMarkersWithinBounds(
        float $minimumLongitude,
        float $minimumLatitude,
        float $maximumLongitude,
        float $maximumLatitude,
        int $limit,
    ): array {
        self::assertMapLimit($limit);
        $bindings = self::boundsBindings(
            $minimumLongitude,
            $minimumLatitude,
            $maximumLongitude,
            $maximumLatitude,
        );
        $bindings['limit'] = $limit;
        $results = $this->connection->run(<<<'SURQL'
SELECT station_id, name, latitude, longitude, transport_modes FROM station
WHERE longitude >= $minimum_longitude AND longitude <= $maximum_longitude
  AND latitude >= $minimum_latitude AND latitude <= $maximum_latitude
ORDER BY station_id ASC
LIMIT $limit;
SURQL, $bindings);

        return array_map(self::mapMarker(...), DatabaseRecord::many($results[0] ?? []));
    }

    /**
     * Aggregate before data crosses the SurrealDB/PHP boundary. LIMIT bounds
     * deserialization memory; callers discard a 2,001-row probe and retry with
     * a larger cell, so a returned map never omits a cell.
     *
     * @return list<array{
     *     kind: 'station'|'cluster',
     *     id: string,
     *     latitude: float,
     *     longitude: float,
     *     name?: string,
     *     transportModes?: list<string>,
     *     count?: int,
     *     bounds?: array{minLongitude: float, minLatitude: float, maxLongitude: float, maxLatitude: float}
     * }>
     */
    public function projectedCellsWithinBounds(
        float $minimumLongitude,
        float $minimumLatitude,
        float $maximumLongitude,
        float $maximumLatitude,
        float $cellSize,
        int $limit,
    ): array {
        if (!is_finite($cellSize) || $cellSize <= 0.0 || $cellSize > 360.0) {
            throw new \InvalidArgumentException('Station map cell size must be between 0 and 360 degrees.');
        }
        self::assertMapLimit($limit);
        $bindings = self::boundsBindings(
            $minimumLongitude,
            $minimumLatitude,
            $maximumLongitude,
            $maximumLatitude,
        );
        $bindings['cell_size'] = $cellSize;
        $bindings['limit'] = $limit;
        $results = $this->connection->run(<<<'SURQL'
SELECT
    math::floor(latitude / $cell_size) AS lat_cell,
    math::floor(longitude / $cell_size) AS lon_cell,
    count() AS station_count,
    math::mean(latitude) AS latitude,
    math::mean(longitude) AS longitude,
    math::min(latitude) AS min_latitude,
    math::max(latitude) AS max_latitude,
    math::min(longitude) AS min_longitude,
    math::max(longitude) AS max_longitude,
    IF count() = 1 { array::first(array::group(station_id)) } ELSE { NONE } AS station_id,
    IF count() = 1 { array::first(array::group(name)) } ELSE { NONE } AS name,
    IF count() = 1 { array::first(array::group(transport_modes)) } ELSE { NONE } AS transport_modes
FROM station
WHERE longitude >= $minimum_longitude AND longitude <= $maximum_longitude
  AND latitude >= $minimum_latitude AND latitude <= $maximum_latitude
GROUP BY lat_cell, lon_cell
ORDER BY lat_cell ASC, lon_cell ASC
LIMIT $limit;
SURQL, $bindings);

        return array_map(
            static fn(array $record): array => self::mapCell($record, $cellSize),
            DatabaseRecord::many($results[0] ?? []),
        );
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

        $normalized = $this->normalizer->normalize($query);
        if ($normalized === '') {
            return [];
        }
        // Candidate lanes prevent a broad two-letter query from being truncated
        // alphabetically before relevance can be evaluated. The bound remains
        // small compared with the full national catalog.
        $candidateLimit = min(500, max(200, $limit * 10));
        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM station
WHERE search_name = type::string_lossy(encoding::base64::decode($query))
   OR (search_locality ?? "") = type::string_lossy(encoding::base64::decode($query))
   OR (search_municipality ?? "") = type::string_lossy(encoding::base64::decode($query))
ORDER BY search_name ASC, station_id ASC
LIMIT $limit;
SELECT * FROM station
WHERE string::starts_with(search_name, type::string_lossy(encoding::base64::decode($query)))
   OR string::starts_with(search_locality ?? "", type::string_lossy(encoding::base64::decode($query)))
   OR string::starts_with(search_municipality ?? "", type::string_lossy(encoding::base64::decode($query)))
   OR array::len(array::filter(search_tokens, |$term| string::starts_with(
       $term,
       type::string_lossy(encoding::base64::decode($query))
   ))) > 0
ORDER BY search_name ASC, station_id ASC
LIMIT $limit;
SELECT * FROM station
WHERE search_text CONTAINS type::string_lossy(encoding::base64::decode($query))
ORDER BY search_name ASC, station_id ASC
LIMIT $limit;
SURQL, ['query' => SurrealEncoding::string($normalized), 'limit' => $candidateLimit]);

        $matches = [];
        foreach ($results as $result) {
            foreach (array_map(SurrealDtoMapper::station(...), DatabaseRecord::many($result)) as $station) {
                $matches[$station->id] = $station;
            }
        }
        $maximumDistance = $this->normalizer->fuzzyDistance($normalized);
        if ($maximumDistance > 0) {
            $fuzzyResults = $this->connection->run(<<<'SURQL'
SELECT * FROM station
WHERE array::len(array::filter(search_tokens, |$term| string::distance::damerau_levenshtein(
    $term,
    type::string_lossy(encoding::base64::decode($query))
) <= $maximum_distance)) > 0
ORDER BY search_name ASC, station_id ASC
LIMIT $limit;
SURQL, [
            'query' => SurrealEncoding::string($normalized),
            'maximum_distance' => $maximumDistance,
            'limit' => $candidateLimit,
        ]);
            foreach (array_map(SurrealDtoMapper::station(...), DatabaseRecord::many($fuzzyResults[0] ?? [])) as $station) {
                $matches[$station->id] = $station;
            }
        }

        $ranked = array_values($matches);
        $prefixPopularity = [];
        if (mb_strlen($normalized) <= 3) {
            foreach ($ranked as $station) {
                $prefix = $this->matchingPrefixToken($station, $normalized);
                if ($prefix !== null) {
                    $prefixPopularity[$prefix] = ($prefixPopularity[$prefix] ?? 0) + 1;
                }
            }
        }
        usort($ranked, fn(Station $left, Station $right): int => $this->stationSearchRank($left, $normalized, $prefixPopularity)
            <=> $this->stationSearchRank($right, $normalized, $prefixPopularity));

        return array_slice($ranked, 0, $limit);
    }

    /**
     * @param array<string, int> $prefixPopularity
     * @return array{int, int, int, int, string, string}
     */
    private function stationSearchRank(Station $station, string $query, array $prefixPopularity): array
    {
        $name = $this->normalizer->normalize($station->name);
        $values = array_values(array_filter([
            $name,
            $station->locality === null ? null : $this->normalizer->normalize($station->locality),
            $station->municipality === null ? null : $this->normalizer->normalize($station->municipality),
        ], static fn(?string $value): bool => $value !== null && $value !== ''));
        $prefix = $this->matchingPrefixToken($station, $query);
        $popularityRank = -($prefixPopularity[$prefix ?? ''] ?? 0);
        $kindRank = match ($station->kind) {
            \FjordPulse\Domain\StationKind::BusStation, \FjordPulse\Domain\StationKind::RailStation,
            \FjordPulse\Domain\StationKind::MetroStation, \FjordPulse\Domain\StationKind::Station => 0,
            \FjordPulse\Domain\StationKind::FerryTerminal, \FjordPulse\Domain\StationKind::Airport,
            \FjordPulse\Domain\StationKind::TramStop => 1,
            \FjordPulse\Domain\StationKind::StopPlace => 2,
            \FjordPulse\Domain\StationKind::Unknown => 3,
        };
        if (in_array($query, $values, true)) {
            return [0, $popularityRank, $kindRank, 0, $name, $station->id];
        }
        $prefixLengths = array_map(
            static fn(string $value): int => mb_strlen($value) - mb_strlen($query),
            array_values(array_filter($values, static fn(string $value): bool => str_starts_with($value, $query))),
        );
        if ($prefixLengths !== []) {
            return [1, $popularityRank, $kindRank, min($prefixLengths), $name, $station->id];
        }
        $tokens = $this->normalizer->tokens(implode(' ', $values));
        $tokenLengths = array_map(
            static fn(string $value): int => mb_strlen($value) - mb_strlen($query),
            array_values(array_filter($tokens, static fn(string $value): bool => str_starts_with($value, $query))),
        );
        if ($tokenLengths !== []) {
            return [2, $popularityRank, $kindRank, min($tokenLengths), $name, $station->id];
        }
        if (count(array_filter($values, static fn(string $value): bool => str_contains($value, $query))) > 0) {
            return [3, $popularityRank, $kindRank, 0, $name, $station->id];
        }
        $distance = $tokens === []
            ? PHP_INT_MAX
            : min(array_map(fn(string $token): int => $this->normalizer->damerauLevenshtein($query, $token), $tokens));

        return [4, $popularityRank, $kindRank, $distance, $name, $station->id];
    }

    private function matchingPrefixToken(Station $station, string $query): ?string
    {
        $tokens = $this->normalizer->tokens(implode(' ', array_values(array_filter([
            $station->name,
            $station->locality,
            $station->municipality,
        ], static fn(?string $value): bool => $value !== null && $value !== ''))));
        $matches = array_values(array_filter($tokens, static fn(string $token): bool => str_starts_with($token, $query)));
        usort($matches, static fn(string $left, string $right): int => [mb_strlen($left), $left] <=> [mb_strlen($right), $right]);

        return $matches[0] ?? null;
    }

    /**
     * @param array<string, mixed> $record
     * @return array{
     *     kind: 'station',
     *     id: string,
     *     name: string,
     *     latitude: float,
     *     longitude: float,
     *     transportModes: list<string>
     * }
     */
    private static function mapMarker(array $record): array
    {
        return [
            'kind' => 'station',
            'id' => DatabaseRecord::string($record['station_id'] ?? null, 'station map station_id'),
            'name' => DatabaseRecord::string($record['name'] ?? null, 'station map name'),
            'latitude' => DatabaseRecord::float($record['latitude'] ?? null, 'station map latitude'),
            'longitude' => DatabaseRecord::float($record['longitude'] ?? null, 'station map longitude'),
            'transportModes' => self::transportModes($record['transport_modes'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{
     *     kind: 'station'|'cluster',
     *     id: string,
     *     latitude: float,
     *     longitude: float,
     *     name?: string,
     *     transportModes?: list<string>,
     *     count?: int,
     *     bounds?: array{minLongitude: float, minLatitude: float, maxLongitude: float, maxLatitude: float}
     * }
     */
    private static function mapCell(array $record, float $cellSize): array
    {
        $count = DatabaseRecord::int($record['station_count'] ?? null, 'station map cell count');
        if ($count < 1) {
            throw new \InvalidArgumentException('Station map cells must contain at least one station.');
        }
        if ($count === 1) {
            return self::mapMarker($record);
        }

        $latCell = DatabaseRecord::float($record['lat_cell'] ?? null, 'station map latitude cell');
        $lonCell = DatabaseRecord::float($record['lon_cell'] ?? null, 'station map longitude cell');

        return [
            'kind' => 'cluster',
            'id' => 'cluster_' . substr(hash('sha256', implode(':', [
                self::cellCoordinate($latCell),
                self::cellCoordinate($lonCell),
                sprintf('%.12F', $cellSize),
            ])), 0, 16),
            'latitude' => DatabaseRecord::float($record['latitude'] ?? null, 'station map cluster latitude'),
            'longitude' => DatabaseRecord::float($record['longitude'] ?? null, 'station map cluster longitude'),
            'count' => $count,
            'bounds' => [
                'minLongitude' => DatabaseRecord::float($record['min_longitude'] ?? null, 'station map cluster min longitude'),
                'minLatitude' => DatabaseRecord::float($record['min_latitude'] ?? null, 'station map cluster min latitude'),
                'maxLongitude' => DatabaseRecord::float($record['max_longitude'] ?? null, 'station map cluster max longitude'),
                'maxLatitude' => DatabaseRecord::float($record['max_latitude'] ?? null, 'station map cluster max latitude'),
            ],
        ];
    }

    /** @return array<string, float> */
    private static function boundsBindings(
        float $minimumLongitude,
        float $minimumLatitude,
        float $maximumLongitude,
        float $maximumLatitude,
    ): array {
        return [
            'minimum_longitude' => $minimumLongitude,
            'minimum_latitude' => $minimumLatitude,
            'maximum_longitude' => $maximumLongitude,
            'maximum_latitude' => $maximumLatitude,
        ];
    }

    private static function assertMapLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 2_001) {
            throw new \InvalidArgumentException('Station map query limit must be between 1 and 2001.');
        }
    }

    /** @return list<string> */
    private static function transportModes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Expected station map transport_modes to be a list.');
        }
        $modes = [];
        foreach ($value as $mode) {
            $modes[] = DatabaseRecord::string($mode, 'station map transport mode');
        }

        return $modes;
    }

    private static function cellCoordinate(float $value): string
    {
        return sprintf('%.0F', $value === -0.0 ? 0.0 : $value);
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

    public function countForCatalog(string $catalogId): int
    {
        $results = $this->connection->run(<<<'SURQL'
RETURN count(SELECT VALUE id FROM station
    WHERE catalog_id = type::string_lossy(encoding::base64::decode($catalog_id)));
SURQL, ['catalog_id' => SurrealEncoding::string($catalogId)]);

        return DatabaseRecord::int($results[0] ?? null, 'station catalog count');
    }

    public function activateCatalog(string $catalogId, string $sourceMode, bool $clearDerivedState): int
    {
        if (!in_array($sourceMode, ['fake', 'real'], true)) {
            throw new \InvalidArgumentException('Station catalog source mode must be fake or real.');
        }

        $this->connection->run(<<<'SURQL'
BEGIN TRANSACTION;
DELETE station WHERE catalog_id = NONE
    OR catalog_id != type::string_lossy(encoding::base64::decode($catalog_id))
    OR source_mode != type::string_lossy(encoding::base64::decode($source_mode));
DELETE station_snapshot WHERE station_id NOT IN (SELECT VALUE station_id FROM station);
COMMIT TRANSACTION;
SURQL, [
            'catalog_id' => SurrealEncoding::string($catalogId),
            'source_mode' => SurrealEncoding::string($sourceMode),
        ]);

        if ($clearDerivedState) {
            $this->connection->run(<<<'SURQL'
BEGIN TRANSACTION;
DELETE station_snapshot;
DELETE current_vehicle;
DELETE vehicle_observation;
DELETE journey_snapshot;
DELETE watch;
DELETE realtime_event;
COMMIT TRANSACTION;
SURQL);
        }

        return $this->count();
    }

    /** @return array<string, mixed> */
    private function bindings(
        Station $station,
        string $source,
        ?string $sourceVersion,
        ?string $sourceMode,
        ?string $catalogId,
    ): array
    {
        $sourceMode ??= $source === 'fake' ? 'fake' : 'real';
        if (!in_array($sourceMode, ['fake', 'real'], true)) {
            throw new \InvalidArgumentException('Station source mode must be fake or real.');
        }
        $searchName = $this->normalizer->normalize($station->name);
        $searchLocality = $station->locality === null ? null : $this->normalizer->normalize($station->locality);
        $searchMunicipality = $station->municipality === null ? null : $this->normalizer->normalize($station->municipality);
        $searchText = implode(' ', array_values(array_filter([
            $searchName,
            $searchLocality,
            $searchMunicipality,
        ], static fn(?string $value): bool => $value !== null && $value !== '')));
        $searchTokens = $this->normalizer->tokens($searchText);

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
            'search_name' => SurrealEncoding::string($searchName),
            'search_locality' => SurrealEncoding::nullableString($searchLocality),
            'search_municipality' => SurrealEncoding::nullableString($searchMunicipality),
            'search_tokens' => SurrealEncoding::json($searchTokens),
            'source' => SurrealEncoding::string($source),
            'source_mode' => SurrealEncoding::string($sourceMode),
            'source_version' => SurrealEncoding::nullableString($sourceVersion),
            'catalog_id' => SurrealEncoding::nullableString($catalogId),
            'imported_at' => SurrealEncoding::string(self::timestamp($station->importedAt)),
        ];
    }
}
