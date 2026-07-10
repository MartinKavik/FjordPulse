<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;

final readonly class AppliedMigration
{
    public function __construct(
        public string $name,
        public string $checksum,
        public DateTimeImmutable $appliedAt,
    ) {
    }

    /** @param array<string, mixed> $record */
    public static function fromRecord(array $record): self
    {
        return new self(
            DatabaseRecord::string($record['name'] ?? null, 'schema_migration.name'),
            DatabaseRecord::string($record['checksum'] ?? null, 'schema_migration.checksum'),
            DatabaseRecord::dateTime($record['applied_at'] ?? null, 'schema_migration.applied_at'),
        );
    }
}
