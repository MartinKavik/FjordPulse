<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Surreal\AppliedMigration;
use FjordPulse\Surreal\DatabaseSchemaInspection;
use FjordPulse\Surreal\DatabaseSchemaRepository;
use FjordPulse\Surreal\Migration;
use FjordPulse\Surreal\MigrationAttempt;
use FjordPulse\Surreal\MigrationDiagnosticsReport;
use FjordPulse\Surreal\MigrationDiagnosticsRepository;
use FjordPulse\Surreal\MigrationDiagnosticsSnapshot;
use FjordPulse\Surreal\MigrationSourceInspection;
use FjordPulse\Surreal\SurrealConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseDiagnosticsTest extends TestCase
{
    public function testSchemaMapperAllowListsOutputAndNormalizesPermissions(): void
    {
        $inspection = DatabaseSchemaInspection::fromResult([[
            'name' => 'current_vehicle',
            'kind' => 'NORMAL',
            'schemafull' => true,
            'permissions' => ['select' => false, 'create' => false, 'update' => 'WHERE owner = $auth.id', 'delete' => false],
            'fields' => [[
                'name' => 'vehicle_id',
                'kind' => 'string',
                'readonly' => false,
                'assertion' => null,
                'defaultValue' => null,
                'computedValue' => null,
                'valueExpression' => null,
                'referenceOnDelete' => null,
                'permissions' => ['select' => true, 'create' => true, 'update' => true],
                'unknownSecret' => 'must-not-escape',
            ]],
            'indexes' => [[
                'name' => 'vehicle_id_unique',
                'columns' => ['vehicle_id'],
                'mode' => 'UNIQUE',
            ]],
            'events' => [[
                'name' => 'publish_vehicle',
                'condition' => '$event = "CREATE"',
                'actions' => ['CREATE realtime_event'],
            ]],
            'liveQueries' => ['internal-live-query-id'],
            'users' => ['fjordpulse_app' => 'PASSHASH secret'],
        ]]);

        $data = $inspection->toArray();
        $table = $data['tables'][0];
        self::assertSame('schemafull', $table['schemaMode'] ?? null);
        self::assertSame([
            'select' => 'none',
            'create' => 'none',
            'update' => 'conditional',
            'delete' => 'none',
        ], $table['permissions'] ?? null);
        $fields = $table['fields'] ?? null;
        self::assertIsArray($fields);
        $field = $fields[0] ?? null;
        self::assertIsArray($field);
        $fieldPermissions = $field['permissions'] ?? null;
        self::assertIsArray($fieldPermissions);
        self::assertSame('full', $fieldPermissions['select'] ?? null);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PASSHASH', $json);
        self::assertStringNotContainsString('must-not-escape', $json);
        self::assertStringNotContainsString('users', $json);
        self::assertStringNotContainsString('internal-live-query-id', $json);
        self::assertArrayNotHasKey('liveQueries', $table);
    }

    public function testSchemaMapperFailsClosedOnUnexpectedPermissionShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database_schema.table.permissions.select');

        DatabaseSchemaInspection::fromResult([[
            'name' => 'station',
            'kind' => 'NORMAL',
            'schemafull' => true,
            'permissions' => ['select' => ['unexpected'], 'create' => false, 'update' => false, 'delete' => false],
            'fields' => [],
            'indexes' => [],
            'events' => [],
        ]]);
    }

    public function testSchemaMapperExposesComputedFieldsWithoutRawStructureData(): void
    {
        $table = self::validSchemaTable(includeChildren: true);
        $table['fields'][0]['kind'] = 'computed';
        $table['fields'][0]['computedValue'] = 'type::point([longitude, latitude])';

        $data = DatabaseSchemaInspection::fromResult([$table])->toArray();
        $fields = $data['tables'][0]['fields'] ?? null;
        self::assertIsArray($fields);
        $field = $fields[0] ?? null;
        self::assertIsArray($field);
        self::assertSame('computed', $field['type'] ?? null);
        self::assertSame('type::point([longitude, latitude])', $field['computedValue'] ?? null);
    }

    public function testSchemaMapperExposesDerivedReferencePolicy(): void
    {
        $table = self::validSchemaTable(includeChildren: true);
        $table['fields'][0]['kind'] = 'record<station>';
        $table['fields'][0]['valueExpression'] = 'type::record("station", station_id)';
        $table['fields'][0]['referenceOnDelete'] = 'CASCADE';

        $data = DatabaseSchemaInspection::fromResult([$table])->toArray();
        $fields = $data['tables'][0]['fields'] ?? null;
        self::assertIsArray($fields);
        $field = $fields[0] ?? null;
        self::assertIsArray($field);
        self::assertSame('type::record("station", station_id)', $field['valueExpression'] ?? null);
        self::assertSame('CASCADE', $field['referenceOnDelete'] ?? null);
    }

    #[DataProvider('nonListSchemaResults')]
    public function testSchemaMapperFailsClosedUnlessTopLevelResultIsAList(mixed $result): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database_schema.tables');

        DatabaseSchemaInspection::fromResult($result);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonListSchemaResults(): iterable
    {
        yield 'null' => [null];
        yield 'record instead of list' => [self::validSchemaTable()];
    }

    #[DataProvider('invalidSchemaCollections')]
    public function testSchemaMapperFailsClosedOnMissingOrNonListCollections(
        string $collection,
        bool $present,
        mixed $value,
    ): void {
        $table = self::validSchemaTable();
        if ($present) {
            $table[$collection] = $value;
        } else {
            unset($table[$collection]);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("database_schema.table.{$collection}");

        DatabaseSchemaInspection::fromResult([$table]);
    }

    /** @return iterable<string, array{string, bool, mixed}> */
    public static function invalidSchemaCollections(): iterable
    {
        foreach (['fields', 'indexes', 'events'] as $collection) {
            yield "{$collection} missing" => [$collection, false, null];
            yield "{$collection} null" => [$collection, true, null];
        }

        yield 'fields record instead of list' => ['fields', true, ['name' => 'not-a-list']];
    }

    #[DataProvider('oversizedSchemaValues')]
    public function testSchemaMapperFailsClosedOnValuesBeyondContractBounds(string $path, string $value): void
    {
        $table = self::validSchemaTable(includeChildren: true);
        switch ($path) {
            case 'table.name':
                $table['name'] = $value;
                break;
            case 'table.kind':
                $table['kind'] = $value;
                break;
            case 'field.name':
                $table['fields'][0]['name'] = $value;
                break;
            case 'field.kind':
                $table['fields'][0]['kind'] = $value;
                break;
            case 'field.assertion':
                $table['fields'][0]['assertion'] = $value;
                break;
            case 'field.default':
                $table['fields'][0]['defaultValue'] = $value;
                break;
            case 'field.computed':
                $table['fields'][0]['computedValue'] = $value;
                break;
            case 'field.value':
                $table['fields'][0]['valueExpression'] = $value;
                break;
            case 'field.reference_on_delete':
                $table['fields'][0]['referenceOnDelete'] = $value;
                break;
            case 'index.name':
                $table['indexes'][0]['name'] = $value;
                break;
            case 'index.column':
                $index = $table['indexes'][0];
                $columns = $index['columns'] ?? null;
                self::assertIsArray($columns);
                $columns[0] = $value;
                $index['columns'] = $columns;
                $table['indexes'][0] = $index;
                break;
            case 'index.mode':
                $table['indexes'][0]['mode'] = $value;
                break;
            case 'event.name':
                $table['events'][0]['name'] = $value;
                break;
            case 'event.condition':
                $table['events'][0]['condition'] = $value;
                break;
            case 'event.action':
                $event = $table['events'][0];
                $actions = $event['actions'] ?? null;
                self::assertIsArray($actions);
                $actions[0] = $value;
                $event['actions'] = $actions;
                $table['events'][0] = $event;
                break;
            default:
                self::fail("Unknown schema fixture path: {$path}");
        }

        $this->expectException(\InvalidArgumentException::class);
        DatabaseSchemaInspection::fromResult([$table]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function oversizedSchemaValues(): iterable
    {
        yield 'table name' => ['table.name', str_repeat('n', 301)];
        yield 'table kind' => ['table.kind', str_repeat('n', 101)];
        yield 'field name' => ['field.name', str_repeat('n', 301)];
        yield 'field type' => ['field.kind', str_repeat('t', 1_001)];
        yield 'field assertion' => ['field.assertion', str_repeat('a', 10_001)];
        yield 'field default' => ['field.default', str_repeat('d', 10_001)];
        yield 'field computed' => ['field.computed', str_repeat('c', 10_001)];
        yield 'field value' => ['field.value', str_repeat('v', 10_001)];
        yield 'field reference on delete' => ['field.reference_on_delete', str_repeat('r', 101)];
        yield 'index name' => ['index.name', str_repeat('n', 301)];
        yield 'index column' => ['index.column', str_repeat('c', 301)];
        yield 'index mode' => ['index.mode', str_repeat('m', 1_001)];
        yield 'event name' => ['event.name', str_repeat('n', 301)];
        yield 'event condition' => ['event.condition', str_repeat('c', 20_001)];
        yield 'event action' => ['event.action', str_repeat('a', 20_001)];
    }

    public function testSchemaRepositoryDoesNotRequestOrReturnLiveQueryInternals(): void
    {
        $connection = $this->createMock(SurrealConnection::class);
        $connection->expects(self::once())
            ->method('run')
            ->with(self::callback(static function (string $query): bool {
                self::assertStringNotContainsString('.lives', $query);
                self::assertStringNotContainsString('liveQueries', $query);

                return true;
            }))
            ->willReturn([[self::validSchemaTable()]]);

        $data = (new DatabaseSchemaRepository($connection))->inspect()->toArray();
        $tables = $data['tables'];
        $table = $tables[0] ?? null;
        self::assertIsArray($table);
        self::assertArrayNotHasKey('liveQueries', $table);
    }

    public function testMigrationSourceInspectionReadsDescriptionAndAffectedObjects(): void
    {
        $migration = new Migration('100_example.surql', <<<'SURQL'
-- First sentence of the description.
-- Second sentence.

DEFINE TABLE OVERWRITE station SCHEMAFULL;
DEFINE FIELD IF NOT EXISTS name ON TABLE station TYPE string;
REMOVE INDEX IF EXISTS old_station_name ON TABLE station;
DEFINE EVENT publish_station
ON TABLE station
WHEN $event = "CREATE"
THEN (RETURN NONE);
REMOVE FIELD old_name ON TABLE station;
REMOVE TABLE IF EXISTS obsolete;
SURQL);

        $inspection = MigrationSourceInspection::fromMigration($migration);
        self::assertSame('First sentence of the description. Second sentence.', $inspection->description);
        self::assertSame([
            ['kind' => 'event', 'name' => 'publish_station', 'table' => 'station', 'operation' => 'define'],
            ['kind' => 'field', 'name' => 'name', 'table' => 'station', 'operation' => 'define'],
            ['kind' => 'field', 'name' => 'old_name', 'table' => 'station', 'operation' => 'remove'],
            ['kind' => 'index', 'name' => 'old_station_name', 'table' => 'station', 'operation' => 'remove'],
            ['kind' => 'table', 'name' => 'obsolete', 'table' => null, 'operation' => 'remove'],
            ['kind' => 'table', 'name' => 'station', 'table' => null, 'operation' => 'define'],
        ], $inspection->affectedObjects);
    }

    public function testMigrationSourceFailsClosedInsteadOfTruncatingOversizedSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source exceeds 250000 characters');

        new Migration('100_oversized.surql', str_repeat('X', 250_001));
    }

    public function testMigrationSourceInspectionRejectsOversizedDescription(): void
    {
        $migration = new Migration(
            '100_oversized_description.surql',
            '-- ' . str_repeat('d', 2_001) . "\nDEFINE TABLE station;",
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database_migration.description');

        MigrationSourceInspection::fromMigration($migration);
    }

    public function testMigrationSourceInspectionRejectsOversizedAffectedObjectIdentifier(): void
    {
        $migration = new Migration(
            '100_oversized_identifier.surql',
            "-- Oversized identifier.\nDEFINE TABLE `" . str_repeat('t', 301) . '`;',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database_migration.affected_object.identifier');

        MigrationSourceInspection::fromMigration($migration);
    }

    public function testPersistedMigrationDiagnosticsRejectValuesBeyondContractBounds(): void
    {
        $at = new DateTimeImmutable('2026-07-13T12:00:00Z');

        try {
            new AppliedMigration('100_example.surql', str_repeat('c', 129), $at);
            self::fail('Expected an oversized applied checksum to be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('schema_migration.checksum', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schema_migration_attempt.failure_message');
        new MigrationAttempt(
            '100_example.surql',
            str_repeat('c', 64),
            'failed',
            $at,
            $at,
            str_repeat('f', 2_001),
        );
    }

    public function testEveryRepositoryMigrationHasInspectableMetadata(): void
    {
        $migrations = Migration::discover(dirname(__DIR__, 2) . '/migrations');
        self::assertCount(16, $migrations);
        foreach ($migrations as $migration) {
            $inspection = MigrationSourceInspection::fromMigration($migration);
            self::assertNotNull($inspection->description, $migration->name);
            self::assertNotSame([], $inspection->affectedObjects, $migration->name);
        }
    }

    public function testMigrationComparisonReportsAllFiveTruthfulStates(): void
    {
        $applied = new Migration('100_applied.surql', "-- Applied.\nDEFINE TABLE applied;");
        $pending = new Migration('101_pending.surql', "-- Pending.\nDEFINE TABLE pending;");
        $mismatch = new Migration('102_mismatch.surql', "-- Mismatch.\nDEFINE TABLE mismatch;");
        $failed = new Migration('103_failed.surql', "-- Failed.\nDEFINE TABLE failed;");
        $at = new DateTimeImmutable('2026-07-13T12:00:00Z');
        $report = MigrationDiagnosticsReport::inspect(
            [$applied, $pending, $mismatch, $failed],
            new MigrationDiagnosticsSnapshot(
                [
                    new AppliedMigration($applied->name, $applied->checksum, $at),
                    new AppliedMigration($mismatch->name, str_repeat('a', 64), $at),
                    new AppliedMigration('099_orphaned.surql', str_repeat('b', 64), $at),
                ],
                [
                    new MigrationAttempt($mismatch->name, $mismatch->checksum, 'checksum_mismatch', $at, $at, 'checksum mismatch'),
                    new MigrationAttempt($failed->name, $failed->checksum, 'failed', $at, $at, 'deliberate failure'),
                ],
            ),
            new DateTimeImmutable('2026-07-13T12:01:00Z'),
        )->toArray();

        self::assertSame('failed', $report['state'] ?? null);
        self::assertSame([
            'applied' => 1,
            'pending' => 1,
            'checksumMismatch' => 1,
            'orphaned' => 1,
            'failed' => 1,
        ], $report['counts'] ?? null);
        $migrationRows = $report['migrations'] ?? null;
        self::assertIsArray($migrationRows);
        $rows = [];
        foreach ($migrationRows as $row) {
            self::assertIsArray($row);
            $name = $row['name'] ?? null;
            self::assertIsString($name);
            $rows[$name] = $row;
        }
        self::assertSame('applied', $rows[$applied->name]['state'] ?? null);
        self::assertSame('pending', $rows[$pending->name]['state'] ?? null);
        self::assertSame('checksum_mismatch', $rows[$mismatch->name]['state'] ?? null);
        self::assertSame('orphaned', $rows['099_orphaned.surql']['state'] ?? null);
        self::assertSame('', $rows['099_orphaned.surql']['description'] ?? null);
        self::assertNull($rows['099_orphaned.surql']['source'] ?? null);
        self::assertSame('failed', $rows[$failed->name]['state'] ?? null);
        self::assertSame('deliberate failure', $rows[$failed->name]['failureMessage'] ?? null);
        self::assertSame($mismatch->checksum, $rows[$mismatch->name]['releaseChecksum'] ?? null);
        self::assertSame(str_repeat('a', 64), $rows[$mismatch->name]['databaseChecksum'] ?? null);
    }

    public function testFailureEvidenceForAnotherChecksumDoesNotMarkCurrentReleaseFailed(): void
    {
        $migration = new Migration('100_pending.surql', "-- Pending.\nDEFINE TABLE pending;");
        $at = new DateTimeImmutable('2026-07-13T12:00:00Z');
        $report = MigrationDiagnosticsReport::inspect(
            [$migration],
            new MigrationDiagnosticsSnapshot([], [
                new MigrationAttempt($migration->name, str_repeat('f', 64), 'failed', $at, $at, 'old source failed'),
            ]),
            new DateTimeImmutable('2026-07-13T12:01:00Z'),
        )->toArray();

        $rows = $report['migrations'] ?? null;
        self::assertIsArray($rows);
        $row = $rows[0] ?? null;
        self::assertIsArray($row);
        self::assertSame('pending', $report['state'] ?? null);
        self::assertSame('pending', $row['state'] ?? null);
        self::assertNull($row['failureMessage'] ?? null);
        self::assertNull($row['lastAttemptedAt'] ?? null);
    }

    public function testAttemptOnlyFailedHistoryRemainsVisible(): void
    {
        $startedAt = new DateTimeImmutable('2026-07-13T12:02:00Z');
        $checkedAt = new DateTimeImmutable('2026-07-13T12:10:00Z');
        $report = MigrationDiagnosticsReport::inspect(
            [],
            new MigrationDiagnosticsSnapshot([], [
                new MigrationAttempt(
                    '777_removed_release_file.surql',
                    str_repeat('c', 64),
                    'failed',
                    $startedAt,
                    new DateTimeImmutable('2026-07-13T12:02:01Z'),
                    'database rejected the migration',
                ),
            ]),
            $checkedAt,
        )->toArray();

        self::assertSame('failed', $report['state'] ?? null);
        self::assertSame([
            'applied' => 0,
            'pending' => 0,
            'checksumMismatch' => 0,
            'orphaned' => 0,
            'failed' => 1,
        ], $report['counts'] ?? null);
        self::assertSame('2026-07-13T12:10:00.000+00:00', $report['checkedAt'] ?? null);
        $rows = $report['migrations'] ?? null;
        self::assertIsArray($rows);
        $row = $rows[0] ?? null;
        self::assertIsArray($row);
        self::assertSame('777_removed_release_file.surql', $row['name'] ?? null);
        self::assertSame('failed', $row['state'] ?? null);
        self::assertSame('', $row['description'] ?? null);
        self::assertNull($row['releaseChecksum'] ?? null);
        self::assertNull($row['databaseChecksum'] ?? null);
        self::assertNull($row['source'] ?? null);
        self::assertSame([], $row['affectedObjects'] ?? null);
        self::assertSame('2026-07-13T12:02:00.000+00:00', $row['lastAttemptedAt'] ?? null);
        self::assertSame('database rejected the migration', $row['failureMessage'] ?? null);
    }

    public function testRunningAttemptBecomesFailedOnlyAfterFiveMinutes(): void
    {
        $checkedAt = new DateTimeImmutable('2026-07-13T12:10:00Z');
        $report = MigrationDiagnosticsReport::inspect(
            [],
            new MigrationDiagnosticsSnapshot([], [
                new MigrationAttempt(
                    '201_recent_running.surql',
                    str_repeat('a', 64),
                    'running',
                    new DateTimeImmutable('2026-07-13T12:05:00Z'),
                    null,
                    null,
                ),
                new MigrationAttempt(
                    '200_stale_running.surql',
                    str_repeat('b', 64),
                    'running',
                    new DateTimeImmutable('2026-07-13T12:04:59Z'),
                    null,
                    null,
                ),
                new MigrationAttempt(
                    '201_recent_running.surql',
                    str_repeat('a', 64),
                    'failed',
                    new DateTimeImmutable('2026-07-13T12:01:00Z'),
                    new DateTimeImmutable('2026-07-13T12:01:01Z'),
                    'older attempt must not win',
                ),
            ]),
            $checkedAt,
        )->toArray();

        $migrationRows = $report['migrations'] ?? null;
        self::assertIsArray($migrationRows);
        $rows = [];
        foreach ($migrationRows as $row) {
            self::assertIsArray($row);
            $name = $row['name'] ?? null;
            self::assertIsString($name);
            $rows[$name] = $row;
        }

        self::assertSame('failed', $rows['200_stale_running.surql']['state'] ?? null);
        $staleMessage = $rows['200_stale_running.surql']['failureMessage'] ?? null;
        self::assertIsString($staleMessage);
        self::assertStringContainsString('interrupted', $staleMessage);
        self::assertSame('pending', $rows['201_recent_running.surql']['state'] ?? null);
        self::assertNull($rows['201_recent_running.surql']['failureMessage'] ?? null);
        self::assertSame([
            'applied' => 0,
            'pending' => 1,
            'checksumMismatch' => 0,
            'orphaned' => 0,
            'failed' => 1,
        ], $report['counts'] ?? null);
    }

    public function testMigrationDiagnosticsRemainReadOnlyBeforeAttemptTableExists(): void
    {
        $connection = $this->createMock(SurrealConnection::class);
        $connection->expects(self::once())
            ->method('run')
            ->with(self::callback(static function (string $query): bool {
                self::assertStringContainsString('INFO FOR DB', $query);
                self::assertStringContainsString('schema_migration_attempt', $query);
                self::assertStringNotContainsString('DEFINE ', $query);
                self::assertStringNotContainsString('CREATE ', $query);
                self::assertStringNotContainsString('UPDATE ', $query);

                return true;
            }))
            ->willReturn([[
                'applied' => [[
                    'name' => '001_core_schema.surql',
                    'checksum' => str_repeat('a', 64),
                    'applied_at' => '2026-07-13T12:00:00Z',
                ]],
                'attempts' => [],
            ]]);

        $snapshot = (new MigrationDiagnosticsRepository($connection))->snapshot();
        self::assertCount(1, $snapshot->applied);
        self::assertSame([], $snapshot->attempts);
    }

    /**
     * @return array{
     *     name: string,
     *     kind: string,
     *     schemafull: bool,
     *     permissions: array{select: bool, create: bool, update: bool, delete: bool},
     *     fields: list<array<string, mixed>>,
     *     indexes: list<array<string, mixed>>,
     *     events: list<array<string, mixed>>
     * }
     */
    private static function validSchemaTable(bool $includeChildren = false): array
    {
        return [
            'name' => 'station',
            'kind' => 'NORMAL',
            'schemafull' => true,
            'permissions' => ['select' => false, 'create' => false, 'update' => false, 'delete' => false],
            'fields' => $includeChildren ? [[
                'name' => 'name',
                'kind' => 'string',
                'readonly' => false,
                'assertion' => null,
                'defaultValue' => null,
                'computedValue' => null,
                'valueExpression' => null,
                'referenceOnDelete' => null,
                'permissions' => ['select' => false, 'create' => false, 'update' => false],
            ]] : [],
            'indexes' => $includeChildren ? [[
                'name' => 'station_name',
                'columns' => ['name'],
                'mode' => 'UNIQUE',
            ]] : [],
            'events' => $includeChildren ? [[
                'name' => 'publish_station',
                'condition' => '$event = "CREATE"',
                'actions' => ['RETURN NONE'],
            ]] : [],
        ];
    }
}
