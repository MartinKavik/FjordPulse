<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class MigrationRunner
{
    private const int FAILURE_MESSAGE_MAX_LENGTH = 2_000;

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
                    $message = sprintf(
                        'Checksum mismatch for applied migration %s (database %s, file %s).',
                        $migration->name,
                        $existing->checksum,
                        $migration->checksum,
                    );
                    $attemptId = $this->beginAttempt($migration);
                    $this->finishAttemptSafely($attemptId, 'checksum_mismatch', $message);

                    throw new MigrationException($message);
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
DEFINE TABLE IF NOT EXISTS schema_migration_attempt SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD IF NOT EXISTS attempt_id ON TABLE schema_migration_attempt TYPE string;
DEFINE FIELD IF NOT EXISTS name ON TABLE schema_migration_attempt TYPE string;
DEFINE FIELD IF NOT EXISTS checksum ON TABLE schema_migration_attempt TYPE string;
DEFINE FIELD IF NOT EXISTS state ON TABLE schema_migration_attempt TYPE string;
DEFINE FIELD IF NOT EXISTS started_at ON TABLE schema_migration_attempt TYPE datetime;
DEFINE FIELD IF NOT EXISTS finished_at ON TABLE schema_migration_attempt TYPE option<datetime>;
DEFINE FIELD IF NOT EXISTS failure_message ON TABLE schema_migration_attempt TYPE option<string>;
DEFINE INDEX IF NOT EXISTS schema_migration_attempt_id_unique ON TABLE schema_migration_attempt FIELDS attempt_id UNIQUE;
DEFINE INDEX IF NOT EXISTS schema_migration_attempt_name_started ON TABLE schema_migration_attempt FIELDS name, started_at;
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
        $attemptId = $this->beginAttempt($migration);
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
            $this->finishAttemptSafely($attemptId, 'failed', $error->getMessage());
            throw new MigrationException("Migration {$migration->name} failed: {$error->getMessage()}", 0, $error);
        }

        $lastIndex = count($result) - 1;
        $row = DatabaseRecord::one($lastIndex >= 0 ? $result[$lastIndex] : null);

        if ($row === null) {
            $this->finishAttemptSafely($attemptId, 'failed', 'Migration committed without a ledger record.');
            throw new MigrationException("Migration {$migration->name} committed without a ledger record.");
        }

        $applied = AppliedMigration::fromRecord($row);
        $this->finishAttemptSafely($attemptId, 'applied');

        return $applied;
    }

    private function beginAttempt(Migration $migration): string
    {
        $attemptId = bin2hex(random_bytes(16));
        $this->connection->run(<<<'SURQL'
CREATE schema_migration_attempt CONTENT {
    attempt_id: $attempt_id,
    name: $migration_name,
    checksum: $migration_checksum,
    state: "running",
    started_at: time::now()
};
SURQL, [
            'attempt_id' => $attemptId,
            'migration_name' => $migration->name,
            'migration_checksum' => $migration->checksum,
        ]);

        return $attemptId;
    }

    private function finishAttemptSafely(string $attemptId, string $state, ?string $failureMessage = null): void
    {
        if (!in_array($state, ['applied', 'failed', 'checksum_mismatch'], true)) {
            throw new \InvalidArgumentException("Unknown migration attempt completion state: {$state}");
        }
        $message = $failureMessage === null
            ? null
            : mb_substr($failureMessage, 0, self::FAILURE_MESSAGE_MAX_LENGTH);

        try {
            if ($message === null) {
                $this->connection->run(<<<'SURQL'
UPDATE schema_migration_attempt
SET state = $attempt_state, finished_at = time::now(), failure_message = NONE
WHERE attempt_id = $attempt_id;
SURQL, [
                    'attempt_id' => $attemptId,
                    'attempt_state' => $state,
                ]);
            } else {
                $this->connection->run(<<<'SURQL'
UPDATE schema_migration_attempt
SET state = $attempt_state, finished_at = time::now(), failure_message = $failure_message
WHERE attempt_id = $attempt_id;
SURQL, [
                    'attempt_id' => $attemptId,
                    'attempt_state' => $state,
                    'failure_message' => $message,
                ]);
            }
        } catch (\Throwable) {
            // The migration result remains authoritative. A stranded `running`
            // row honestly signals that completion could not be recorded.
        }
    }
}
