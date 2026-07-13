<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AppliedMigration
{
    public function __construct(
        public string $name,
        public string $checksum,
        public DateTimeImmutable $appliedAt,
    ) {
        self::assertString($name, 'schema_migration.name', 300);
        self::assertString($checksum, 'schema_migration.checksum', 128);
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

    private static function assertString(string $value, string $field, int $maximumLength): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException("Expected {$field} to be valid UTF-8.");
        }

        $length = mb_strlen($value, 'UTF-8');
        if ($length < 1 || $length > $maximumLength) {
            throw new InvalidArgumentException(
                "Expected {$field} length to be between 1 and {$maximumLength} characters.",
            );
        }
    }
}
