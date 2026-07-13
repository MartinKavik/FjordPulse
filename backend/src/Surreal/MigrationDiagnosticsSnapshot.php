<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class MigrationDiagnosticsSnapshot
{
    /**
     * @param list<AppliedMigration> $applied
     * @param list<MigrationAttempt> $attempts
     */
    public function __construct(
        public array $applied,
        public array $attempts,
    ) {
    }
}
