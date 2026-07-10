<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use SurrealDB\SDK\Types\DateTime as SurrealDateTime;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Uuid;

final class DatabaseRecord
{
    private function __construct()
    {
    }

    /** @return array<string, mixed>|null */
    public static function one(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && array_is_list($value)) {
            if ($value === []) {
                return null;
            }

            $value = $value[0];
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Expected a SurrealDB record.');
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Expected SurrealDB record keys to be strings.');
            }
            $record[$key] = $item;
        }

        return $record;
    }

    /** @return list<array<string, mixed>> */
    public static function many(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            $record = self::one($value);

            return $record === null ? [] : [$record];
        }

        $records = [];

        foreach ($value as $item) {
            $record = self::one($item);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public static function string(mixed $value, string $field): string
    {
        if ($value instanceof Uuid || $value instanceof RecordId) {
            return (string) $value;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException("Expected {$field} to be a string.");
        }

        return $value;
    }

    public static function nullableString(mixed $value, string $field): ?string
    {
        return $value === null ? null : self::string($value, $field);
    }

    public static function int(mixed $value, string $field): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException("Expected {$field} to be an integer.");
        }

        return $value;
    }

    public static function nullableInt(mixed $value, string $field): ?int
    {
        return $value === null ? null : self::int($value, $field);
    }

    public static function float(mixed $value, string $field): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException("Expected {$field} to be numeric.");
        }

        return (float) $value;
    }

    public static function nullableFloat(mixed $value, string $field): ?float
    {
        return $value === null ? null : self::float($value, $field);
    }

    public static function dateTime(mixed $value, string $field): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof SurrealDateTime) {
            return $value->toDateTimeImmutable();
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException("Expected {$field} to be an RFC3339 datetime.");
        }

        return new DateTimeImmutable($value);
    }

    public static function nullableDateTime(mixed $value, string $field): ?DateTimeImmutable
    {
        return $value === null ? null : self::dateTime($value, $field);
    }

    /**
     * Normalize SDK value objects recursively before DTO validation.
     *
     * @return mixed
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof SurrealDateTime) {
            return $value->toIso();
        }

        if ($value instanceof Uuid || $value instanceof RecordId) {
            return (string) $value;
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
