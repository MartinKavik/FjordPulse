<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class MigrationSourceInspection
{
    private const int MAX_DESCRIPTION_LENGTH = 2_000;
    private const int MAX_IDENTIFIER_LENGTH = 300;

    /**
     * @param list<array{kind: 'table'|'field'|'index'|'event', name: string, table: string|null, operation: 'define'|'remove'}> $affectedObjects
     */
    public function __construct(
        public ?string $description,
        public array $affectedObjects,
    ) {
    }

    public static function fromMigration(Migration $migration): self
    {
        self::assertMaximumLength(
            $migration->surql,
            'database_migration.source',
            Migration::MAX_SOURCE_LENGTH,
        );

        return new self(
            self::description($migration->surql),
            self::affectedObjects($migration->surql),
        );
    }

    private static function description(string $surql): ?string
    {
        if (preg_match('/\A(?:\xEF\xBB\xBF)?((?:[ \t]*--[^\r\n]*(?:\R|$))+)/', $surql, $match) !== 1) {
            return null;
        }

        $description = [];
        foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
            $line = preg_replace('/^[ \t]*--[ \t]?/', '', $line);
            if (!is_string($line) || trim($line) === '') {
                break;
            }
            $description[] = trim($line);
        }

        $value = trim(implode(' ', $description));
        if ($value !== '') {
            self::assertMaximumLength(
                $value,
                'database_migration.description',
                self::MAX_DESCRIPTION_LENGTH,
            );
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array{kind: 'table'|'field'|'index'|'event', name: string, table: string|null, operation: 'define'|'remove'}>
     */
    private static function affectedObjects(string $surql): array
    {
        $withoutLineComments = preg_replace('/^[ \t]*--.*$/m', '', $surql);
        if (!is_string($withoutLineComments)) {
            throw new \RuntimeException('Unable to inspect migration source.');
        }

        $identifier = '(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_.*-]*))';
        $objects = [];

        self::collect(
            $objects,
            $withoutLineComments,
            '/\b(DEFINE|REMOVE)\s+TABLE(?:\s+(?:OVERWRITE|IF\s+(?:NOT\s+)?EXISTS))?\s+' . $identifier . '/i',
            'table',
            false,
        );
        foreach (['field', 'index', 'event'] as $kind) {
            self::collect(
                $objects,
                $withoutLineComments,
                '/\b(DEFINE|REMOVE)\s+' . strtoupper($kind)
                    . '(?:\s+(?:OVERWRITE|IF\s+(?:NOT\s+)?EXISTS))?\s+' . $identifier
                    . '\s+ON(?:\s+TABLE)?\s+' . $identifier . '/i',
                $kind,
                true,
            );
        }

        usort($objects, static fn(array $left, array $right): int => [
            $left['kind'],
            $left['table'] ?? '',
            $left['name'],
            $left['operation'],
        ] <=> [
            $right['kind'],
            $right['table'] ?? '',
            $right['name'],
            $right['operation'],
        ]);

        return array_values(array_unique($objects, SORT_REGULAR));
    }

    /**
     * @param list<array{kind: 'table'|'field'|'index'|'event', name: string, table: string|null, operation: 'define'|'remove'}> $objects
     * @param 'table'|'field'|'index'|'event' $kind
     */
    private static function collect(array &$objects, string $surql, string $pattern, string $kind, bool $hasTable): void
    {
        $count = preg_match_all($pattern, $surql, $matches, PREG_SET_ORDER);
        if ($count === false) {
            throw new \RuntimeException('Unable to inspect migration objects.');
        }

        foreach ($matches as $match) {
            $name = self::matchedIdentifier($match, 2, 3);
            $table = $hasTable ? self::matchedIdentifier($match, 4, 5) : null;
            $operation = strtolower((string)($match[1] ?? ''));
            if ($operation !== 'define' && $operation !== 'remove') {
                throw new \RuntimeException('Unable to inspect migration operation.');
            }
            $objects[] = [
                'kind' => $kind,
                'name' => $name,
                'table' => $table,
                'operation' => $operation,
            ];
        }
    }

    /** @param array<int|string, mixed> $match */
    private static function matchedIdentifier(array $match, int $quoted, int $plain): string
    {
        $quotedValue = $match[$quoted] ?? null;
        $plainValue = $match[$plain] ?? null;
        $value = is_string($quotedValue) && $quotedValue !== '' ? $quotedValue : $plainValue;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('Unable to inspect migration identifier.');
        }

        self::assertMaximumLength(
            $value,
            'database_migration.affected_object.identifier',
            self::MAX_IDENTIFIER_LENGTH,
        );

        return $value;
    }

    private static function assertMaximumLength(string $value, string $field, int $maximumLength): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException("Expected {$field} to be valid UTF-8.");
        }

        if (mb_strlen($value, 'UTF-8') > $maximumLength) {
            throw new \InvalidArgumentException(
                "Expected {$field} length to be at most {$maximumLength} characters.",
            );
        }
    }
}
