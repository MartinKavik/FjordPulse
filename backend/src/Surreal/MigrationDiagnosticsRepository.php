<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class MigrationDiagnosticsRepository extends AbstractSurrealRepository
{
    public function snapshot(): MigrationDiagnosticsSnapshot
    {
        $results = $this->connection->run(<<<'SURQL'
RETURN {
    applied: (SELECT name, checksum, applied_at FROM schema_migration ORDER BY name ASC),
    attempts: IF "schema_migration_attempt" IN (INFO FOR DB).tables.keys() {
        (SELECT name, checksum, state, started_at, finished_at, failure_message
         FROM schema_migration_attempt ORDER BY started_at DESC)
    } ELSE {
        []
    }
};
SURQL);
        $record = DatabaseRecord::one($results[0] ?? null);
        if ($record === null) {
            throw new \RuntimeException('SurrealDB migration diagnostics did not return a record.');
        }

        return new MigrationDiagnosticsSnapshot(
            array_map(
                AppliedMigration::fromRecord(...),
                DatabaseRecord::many($record['applied'] ?? null),
            ),
            array_map(
                MigrationAttempt::fromRecord(...),
                DatabaseRecord::many($record['attempts'] ?? null),
            ),
        );
    }
}
