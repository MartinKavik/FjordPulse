<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class MigrationReport
{
    /**
     * @param list<AppliedMigration> $applied
     * @param list<AppliedMigration> $alreadyApplied
     */
    public function __construct(
        public array $applied,
        public array $alreadyApplied,
    ) {
    }

    public function changed(): bool
    {
        return $this->applied !== [];
    }
}
