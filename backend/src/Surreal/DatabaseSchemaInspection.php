<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final readonly class DatabaseSchemaInspection
{
    /** @param list<array<string, mixed>> $tables */
    private function __construct(private array $tables)
    {
    }

    public static function fromResult(mixed $result): self
    {
        $records = self::records($result, 'database_schema.tables');
        $tables = [];
        foreach ($records as $table) {
            $tables[] = self::table($table);
        }
        usort($tables, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        return new self($tables);
    }

    /** @return array{readOnly: true, checkedAt: string, tables: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'readOnly' => true,
            'checkedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format(DateTimeInterface::RFC3339_EXTENDED),
            'tables' => $this->tables,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function table(array $record): array
    {
        $kind = strtolower(self::string(
            $record['kind'] ?? null,
            'database_schema.table.kind',
            100,
        ));
        if (!in_array($kind, ['normal', 'relation', 'any'], true)) {
            throw new \InvalidArgumentException("Unknown SurrealDB table kind: {$kind}");
        }
        $fields = array_map(
            self::field(...),
            self::requiredRecords($record, 'fields', 'database_schema.table.fields'),
        );
        $indexes = array_map(
            self::index(...),
            self::requiredRecords($record, 'indexes', 'database_schema.table.indexes'),
        );
        $events = array_map(
            self::event(...),
            self::requiredRecords($record, 'events', 'database_schema.table.events'),
        );
        usort($fields, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);
        usort($indexes, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);
        usort($events, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        return [
            'name' => self::string($record['name'] ?? null, 'database_schema.table.name', 300),
            'kind' => $kind,
            'schemaMode' => self::bool($record['schemafull'] ?? null, 'database_schema.table.schemafull')
                ? 'schemafull'
                : 'schemaless',
            'permissions' => self::permissions(
                $record['permissions'] ?? null,
                ['select', 'create', 'update', 'delete'],
                'database_schema.table.permissions',
            ),
            'fields' => $fields,
            'indexes' => $indexes,
            'events' => $events,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{
     *     name: string,
     *     type: string,
     *     readonly: bool,
     *     assertion: string|null,
     *     defaultValue: string|null,
     *     computedValue: string|null,
     *     valueExpression: string|null,
     *     referenceOnDelete: string|null,
     *     permissions: array<string, 'full'|'none'|'conditional'>
     * }
     */
    private static function field(array $record): array
    {
        return [
            'name' => self::string($record['name'] ?? null, 'database_schema.field.name', 300),
            'type' => self::string($record['kind'] ?? null, 'database_schema.field.kind', 1_000),
            'readonly' => self::bool($record['readonly'] ?? null, 'database_schema.field.readonly'),
            'assertion' => self::nullableString(
                $record['assertion'] ?? null,
                'database_schema.field.assertion',
                10_000,
            ),
            'defaultValue' => self::nullableString(
                $record['defaultValue'] ?? null,
                'database_schema.field.default',
                10_000,
            ),
            'computedValue' => self::nullableString(
                $record['computedValue'] ?? null,
                'database_schema.field.computed',
                10_000,
            ),
            'valueExpression' => self::nullableString(
                $record['valueExpression'] ?? null,
                'database_schema.field.value',
                10_000,
            ),
            'referenceOnDelete' => self::nullableString(
                $record['referenceOnDelete'] ?? null,
                'database_schema.field.reference_on_delete',
                100,
            ),
            'permissions' => self::permissions(
                $record['permissions'] ?? null,
                ['select', 'create', 'update'],
                'database_schema.field.permissions',
            ),
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{name: string, fields: list<string>, unique: bool, mode: string|null}
     */
    private static function index(array $record): array
    {
        $modeValue = self::nullableString($record['mode'] ?? null, 'database_schema.index.mode', 1_000);
        $mode = $modeValue === null || trim($modeValue) === '' ? null : trim($modeValue);

        return [
            'name' => self::string($record['name'] ?? null, 'database_schema.index.name', 300),
            'fields' => self::strings($record['columns'] ?? null, 'database_schema.index.columns', 300),
            'unique' => $mode !== null && str_contains(strtoupper($mode), 'UNIQUE'),
            'mode' => $mode,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{name: string, condition: string|null, actions: list<string>}
     */
    private static function event(array $record): array
    {
        return [
            'name' => self::string($record['name'] ?? null, 'database_schema.event.name', 300),
            'condition' => self::nullableString(
                $record['condition'] ?? null,
                'database_schema.event.condition',
                20_000,
            ),
            'actions' => self::strings($record['actions'] ?? null, 'database_schema.event.actions', 20_000),
        ];
    }

    /**
     * @param list<string> $names
     * @return array<string, 'full'|'none'|'conditional'>
     */
    private static function permissions(mixed $value, array $names, string $field): array
    {
        $record = DatabaseRecord::one($value);
        if ($record === null) {
            throw new \InvalidArgumentException("Expected {$field} to be a record.");
        }
        $permissions = [];
        foreach ($names as $name) {
            $permission = $record[$name] ?? null;
            if (is_bool($permission)) {
                $permissions[$name] = $permission ? 'full' : 'none';
            } elseif (is_string($permission) && $permission !== '') {
                $permissions[$name] = 'conditional';
            } else {
                throw new \InvalidArgumentException("Expected {$field}.{$name} to be a permission.");
            }
        }

        return $permissions;
    }

    private static function bool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Expected {$field} to be a boolean.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     * @return list<array<string, mixed>>
     */
    private static function requiredRecords(array $record, string $key, string $field): array
    {
        if (!array_key_exists($key, $record)) {
            throw new \InvalidArgumentException("Expected {$field} to be present.");
        }

        return self::records($record[$key], $field);
    }

    /** @return list<array<string, mixed>> */
    private static function records(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("Expected {$field} to be a list.");
        }

        $records = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new \InvalidArgumentException("Expected {$field}.{$index} to be a record.");
            }
            $record = DatabaseRecord::one($item);
            if ($record === null) {
                throw new \InvalidArgumentException("Expected {$field}.{$index} to be a record.");
            }
            $records[] = $record;
        }

        return $records;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $field, int $maximumLength): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("Expected {$field} to be a list.");
        }
        $strings = [];
        foreach ($value as $index => $item) {
            $strings[] = self::string($item, "{$field}.{$index}", $maximumLength);
        }

        return $strings;
    }

    private static function string(mixed $value, string $field, int $maximumLength): string
    {
        $string = DatabaseRecord::string($value, $field);
        self::assertLength($string, $field, 1, $maximumLength);

        return $string;
    }

    private static function nullableString(mixed $value, string $field, int $maximumLength): ?string
    {
        $string = DatabaseRecord::nullableString($value, $field);
        if ($string !== null) {
            self::assertLength($string, $field, 0, $maximumLength);
        }

        return $string;
    }

    private static function assertLength(string $value, string $field, int $minimum, int $maximum): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException("Expected {$field} to be valid UTF-8.");
        }

        $length = mb_strlen($value, 'UTF-8');
        if ($length < $minimum || $length > $maximum) {
            throw new \InvalidArgumentException(
                "Expected {$field} length to be between {$minimum} and {$maximum} characters.",
            );
        }
    }
}
