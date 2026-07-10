<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class MigrationRunner
{
    public function __construct(
        private SurrealConnection $connection,
        private string $directory,
    ) {
    }

    public function migrate(): MigrationReport
    {
        $this->bootstrapLedger();
        $known = $this->appliedByName();
        $applied = [];
        $alreadyApplied = [];

        foreach (Migration::discover($this->directory) as $migration) {
            $existing = $known[$migration->name] ?? null;

            if ($existing !== null) {
                if (!hash_equals($existing->checksum, $migration->checksum)) {
                    throw new MigrationException(sprintf(
                        'Checksum mismatch for applied migration %s (database %s, file %s).',
                        $migration->name,
                        $existing->checksum,
                        $migration->checksum,
                    ));
                }

                $alreadyApplied[] = $existing;
                continue;
            }

            $appliedMigration = $this->apply($migration);
            $applied[] = $appliedMigration;
            $known[$migration->name] = $appliedMigration;
        }

        return new MigrationReport($applied, $alreadyApplied);
    }

    /** @return list<Migration> */
    public function pending(): array
    {
        $this->bootstrapLedger();
        $known = $this->appliedByName();

        return array_values(array_filter(
            Migration::discover($this->directory),
            static fn(Migration $migration): bool => !isset($known[$migration->name]),
        ));
    }

    /** @return list<AppliedMigration> */
    public function applied(): array
    {
        $this->bootstrapLedger();

        return array_values($this->appliedByName());
    }

    private function bootstrapLedger(): void
    {
        $this->connection->run(<<<'SURQL'
DEFINE TABLE IF NOT EXISTS schema_migration SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD IF NOT EXISTS name ON TABLE schema_migration TYPE string;
DEFINE FIELD IF NOT EXISTS checksum ON TABLE schema_migration TYPE string;
DEFINE FIELD IF NOT EXISTS applied_at ON TABLE schema_migration TYPE datetime;
DEFINE INDEX IF NOT EXISTS schema_migration_name_unique ON TABLE schema_migration FIELDS name UNIQUE;
SURQL);
    }

    /** @return array<string, AppliedMigration> */
    private function appliedByName(): array
    {
        $result = $this->connection->run(
            'SELECT name, checksum, applied_at FROM schema_migration ORDER BY name ASC;',
        );
        $rows = DatabaseRecord::many($result[0] ?? []);
        $applied = [];

        foreach ($rows as $row) {
            $migration = AppliedMigration::fromRecord($row);
            $applied[$migration->name] = $migration;
        }

        return $applied;
    }

    private function apply(Migration $migration): AppliedMigration
    {
        $surql = sprintf(
            "BEGIN TRANSACTION;\n%s\nCREATE schema_migration CONTENT { name: \$migration_name, checksum: \$migration_checksum, applied_at: time::now() };\nCOMMIT TRANSACTION;\nSELECT * FROM ONLY schema_migration WHERE name = \$migration_name;",
            rtrim($migration->surql),
        );

        try {
            $result = $this->connection->run($surql, [
                'migration_name' => $migration->name,
                'migration_checksum' => $migration->checksum,
            ]);
        } catch (\Throwable $error) {
            throw new MigrationException("Migration {$migration->name} failed: {$error->getMessage()}", 0, $error);
        }

        $lastIndex = count($result) - 1;
        $row = DatabaseRecord::one($lastIndex >= 0 ? $result[$lastIndex] : null);

        if ($row === null) {
            throw new MigrationException("Migration {$migration->name} committed without a ledger record.");
        }

        return AppliedMigration::fromRecord($row);
    }
}
