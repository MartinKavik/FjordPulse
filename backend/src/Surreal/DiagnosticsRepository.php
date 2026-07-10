<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class DiagnosticsRepository extends AbstractSurrealRepository
{
    public function snapshot(int $migrationLimit = 20): PersistenceDiagnostics
    {
        if ($migrationLimit < 1 || $migrationLimit > 100) {
            throw new \InvalidArgumentException('Migration diagnostics limit must be between 1 and 100.');
        }

        $results = $this->connection->run(<<<'SURQL'
RETURN count(SELECT VALUE id FROM station);
RETURN count(SELECT VALUE id FROM station_snapshot);
RETURN count(SELECT VALUE id FROM current_vehicle);
RETURN count(SELECT VALUE id FROM vehicle_observation);
RETURN count(SELECT VALUE id FROM watch);
RETURN count(SELECT VALUE id FROM realtime_event);
RETURN count(SELECT VALUE id FROM entur_request_log);
SELECT imported_at, source, source_version FROM station ORDER BY imported_at DESC LIMIT 1;
SELECT name, checksum, applied_at FROM schema_migration ORDER BY applied_at DESC LIMIT $migration_limit;
SURQL, ['migration_limit' => $migrationLimit]);

        $latestImport = DatabaseRecord::one($results[7] ?? null);
        $migrations = array_map(
            AppliedMigration::fromRecord(...),
            DatabaseRecord::many($results[8] ?? []),
        );

        return new PersistenceDiagnostics(
            DatabaseRecord::int($results[0] ?? null, 'diagnostics.stations'),
            DatabaseRecord::int($results[1] ?? null, 'diagnostics.station_snapshots'),
            DatabaseRecord::int($results[2] ?? null, 'diagnostics.current_vehicles'),
            DatabaseRecord::int($results[3] ?? null, 'diagnostics.vehicle_observations'),
            DatabaseRecord::int($results[4] ?? null, 'diagnostics.watches'),
            DatabaseRecord::int($results[5] ?? null, 'diagnostics.realtime_events'),
            DatabaseRecord::int($results[6] ?? null, 'diagnostics.entur_request_logs'),
            $latestImport === null ? null : DatabaseRecord::dateTime($latestImport['imported_at'] ?? null, 'station.imported_at'),
            $latestImport === null ? null : DatabaseRecord::nullableString($latestImport['source'] ?? null, 'station.source'),
            $latestImport === null ? null : DatabaseRecord::nullableString($latestImport['source_version'] ?? null, 'station.source_version'),
            $migrations,
        );
    }
}
