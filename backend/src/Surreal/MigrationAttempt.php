<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;

final readonly class MigrationAttempt
{
    public function __construct(
        public string $name,
        public string $checksum,
        public string $state,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?string $failureMessage,
    ) {
        self::assertString($name, 'schema_migration_attempt.name', 300, 1);
        self::assertString($checksum, 'schema_migration_attempt.checksum', 128, 1);
        if (!in_array($state, ['running', 'applied', 'failed', 'checksum_mismatch'], true)) {
            throw new \InvalidArgumentException("Unknown migration attempt state: {$state}");
        }
        if ($failureMessage !== null) {
            self::assertString($failureMessage, 'schema_migration_attempt.failure_message', 2_000, 0);
        }
    }

    /** @param array<string, mixed> $record */
    public static function fromRecord(array $record): self
    {
        return new self(
            DatabaseRecord::string($record['name'] ?? null, 'schema_migration_attempt.name'),
            DatabaseRecord::string($record['checksum'] ?? null, 'schema_migration_attempt.checksum'),
            DatabaseRecord::string($record['state'] ?? null, 'schema_migration_attempt.state'),
            DatabaseRecord::dateTime($record['started_at'] ?? null, 'schema_migration_attempt.started_at'),
            DatabaseRecord::nullableDateTime($record['finished_at'] ?? null, 'schema_migration_attempt.finished_at'),
            DatabaseRecord::nullableString($record['failure_message'] ?? null, 'schema_migration_attempt.failure_message'),
        );
    }

    private static function assertString(string $value, string $field, int $maximumLength, int $minimumLength): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException("Expected {$field} to be valid UTF-8.");
        }

        $length = mb_strlen($value, 'UTF-8');
        if ($length < $minimumLength || $length > $maximumLength) {
            throw new \InvalidArgumentException(
                "Expected {$field} length to be between {$minimumLength} and {$maximumLength} characters.",
            );
        }
    }
}
