<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\StationKind;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\Watch;
use FjordPulse\Surreal\SurrealRepositories;
use FjordPulse\Surreal\SurrealEncoding;
use FjordPulse\Surreal\MigrationException;
use FjordPulse\Surreal\MigrationRunner;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class SurrealPersistenceIntegrationTest extends SurrealIntegrationTestCase
{
    public function testVersionedMigrationsAreOrderedIdempotentAndAppUserIsDatabaseScoped(): void
    {
        [$factory, $firstReport] = $this->database('migrations');

        self::assertSame(
            ['001_core_schema.surql', '002_realtime_events.surql', '003_semantic_event_filter.surql'],
            array_map(static fn($migration): string => $migration->name, $firstReport->applied),
        );

        $app = $factory->sync();
        try {
            self::assertSame('surrealdb-3.2.0', $app->version());
            $tables = $app->run('INFO FOR DB;');
            self::assertIsArray($tables[0]);
            self::assertArrayHasKey('tables', $tables[0]);
        } finally {
            $app->close();
        }

        $root = $factory->syncRoot(self::ROOT_USERNAME, self::ROOT_PASSWORD);
        try {
            $secondReport = (new \FjordPulse\Surreal\MigrationRunner(
                $root,
                dirname(__DIR__, 2) . '/migrations',
            ))->migrate();
            self::assertFalse($secondReport->changed());
            self::assertCount(3, $secondReport->alreadyApplied);
        } finally {
            $root->close();
        }
    }

    public function testRepositoriesAndDatabaseEventsPreserveAuthoritativeVersionedState(): void
    {
        [$factory] = $this->database('repositories');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);

        try {
            $station = self::station();
            self::assertSame($station->id, $repositories->stations->save($station)->id);
            self::assertSame($station->id, $repositories->stations->find($station->id)?->id);
            self::assertCount(1, $repositories->stations->search('National'));
            self::assertSame($station->id, $repositories->stations->nearest(59.91, 10.73)?->id);

            $snapshot1 = self::snapshot('2026-07-10T10:00:00.000000Z', 'snapshot-one');
            $sameSnapshotContent = self::snapshot('2026-07-10T10:00:01.000000Z', 'snapshot-one');
            $snapshot2 = self::snapshot('2026-07-10T10:00:02.000000Z', 'snapshot-two');
            $older = self::snapshot('2026-07-10T09:59:59.000000Z', 'older-ignored');

            self::assertSame($snapshot1->version, $repositories->stationSnapshots->save($snapshot1)->version);
            self::assertSame($snapshot1->version, $repositories->stationSnapshots->save($sameSnapshotContent)->version);
            self::assertSame($snapshot1->version, $repositories->stationSnapshots->save($snapshot1)->version);
            self::assertSame($snapshot1->version, $repositories->stationSnapshots->save($older)->version);
            self::assertSame($snapshot2->version, $repositories->stationSnapshots->save($snapshot2)->version);

            $vehicleLive = self::vehicle('2026-07-10T10:00:03.000000Z', VehicleFreshness::Live, 'vehicle-live');
            $vehicleStale = self::vehicle('2026-07-10T10:00:04.000000Z', VehicleFreshness::Stale, 'vehicle-stale');
            $vehicleLost = self::vehicle('2026-07-10T10:00:05.000000Z', VehicleFreshness::Lost, 'vehicle-lost');
            self::assertSame(VehicleFreshness::Live, $repositories->currentVehicles->save($vehicleLive)->state);
            self::assertSame(VehicleFreshness::Stale, $repositories->currentVehicles->save($vehicleStale)->state);
            self::assertSame(VehicleFreshness::Lost, $repositories->currentVehicles->save($vehicleLost)->state);
            self::assertSame(VehicleFreshness::Lost, $repositories->currentVehicles->save($vehicleStale)->state);
            self::assertSame(self::VEHICLE_ID, $repositories->currentVehicles->search('Line 100')[0]->id);

            $events = array_reverse($repositories->realtimeEvents->recent(limit: 20));
            self::assertSame([
                'station_snapshot_changed',
                'station_snapshot_changed',
                'vehicle_moved',
                'vehicle_stale',
                'vehicle_lost',
            ], array_map(static fn($event): string => $event->type->value, $events));
            self::assertSame('station:' . self::STATION_ID, $events[0]->scope);
            self::assertSame(self::STATION_ID, $events[0]->payload['stationId']);

            $beforeRollbackCount = count($events);
            try {
                $connection->run(<<<'SURQL'
BEGIN TRANSACTION;
UPDATE type::record("station_snapshot", type::string_lossy(encoding::base64::decode($station_id))) SET
    version = type::string_lossy(encoding::base64::decode($version)),
    content_hash = "must-rollback",
    updated_at = type::datetime(type::string_lossy(encoding::base64::decode($version)));
THROW "force rollback";
COMMIT TRANSACTION;
SURQL, [
                    'station_id' => SurrealEncoding::string(self::STATION_ID),
                    'version' => SurrealEncoding::string('2026-07-10T10:00:06.000000Z'),
                ]);
                self::fail('The deliberate rollback query should fail.');
            } catch (\Throwable $error) {
                self::assertNotSame('', $error->getMessage());
            }

            self::assertSame($snapshot2->version, $repositories->stationSnapshots->find(self::STATION_ID)?->version);
            self::assertCount($beforeRollbackCount, $repositories->realtimeEvents->recent(limit: 20));

            $observation = new VehicleObservation(
                'observation-1',
                self::VEHICLE_ID,
                new Coordinate(59.9139, 10.7522),
                self::at('2026-07-10T10:00:05Z'),
                90.0,
            );
            self::assertSame('observation-1', $repositories->vehicleObservations->append(
                $observation,
                self::at('2026-07-11T10:00:05Z'),
            )->id);
            self::assertCount(1, $repositories->vehicleObservations->recent(self::VEHICLE_ID));

            $watch = new Watch(
                'watch-1',
                WatchType::Focus,
                'vehicle:' . self::VEHICLE_ID,
                self::VEHICLE_ID,
                2,
                WatchPriority::Focus,
                null,
                self::at('2026-07-10T10:00:00Z'),
                self::at('2026-07-10T10:01:00Z'),
                WatchState::Active,
            );
            self::assertSame('watch-1', $repositories->watches->save($watch)->id);
            self::assertSame('watch-1', $repositories->watches->findByScope($watch->scope)?->id);

            $log = new EnturRequestLog(
                'log-1',
                'journey_planner',
                'station:' . self::STATION_ID,
                self::at('2026-07-10T10:00:00Z'),
                200,
                42,
                3,
                'miss',
                'success',
                null,
                'request-1',
            );
            self::assertSame('log-1', $repositories->enturRequestLogs->append($log)->id);
            self::assertCount(1, $repositories->enturRequestLogs->recent());

            $status = new \FjordPulse\Surreal\SystemStatus(
                'live_query_bridge',
                'connected',
                'LIVE SELECT active',
                self::at('2026-07-10T10:00:00Z'),
                1.25,
                ['queryId' => 'query-1'],
            );
            self::assertSame('connected', $repositories->systemStatus->save($status)->state);
            self::assertSame('connected', $repositories->systemStatus->find('live_query_bridge')?->state);

            $diagnostics = $repositories->diagnostics->snapshot();
            self::assertSame(1, $diagnostics->stations);
            self::assertSame(1, $diagnostics->stationSnapshots);
            self::assertSame(1, $diagnostics->currentVehicles);
            self::assertSame(1, $diagnostics->vehicleObservations);
            self::assertSame(1, $diagnostics->watches);
            self::assertSame(5, $diagnostics->realtimeEvents);
            self::assertSame(1, $diagnostics->enturRequestLogs);
            self::assertSame('entur', $diagnostics->stationSource);
            self::assertCount(3, $diagnostics->recentMigrations);

            $cleanup = $repositories->cleanup->prune(
                self::at('2026-07-12T00:00:00Z'),
                self::at('2026-07-12T00:00:00Z'),
                self::at('2026-07-12T00:00:00Z'),
                self::at('2026-07-12T00:00:00Z'),
            );
            self::assertSame(1, $cleanup->vehicleObservations);
            self::assertSame(5, $cleanup->realtimeEvents);
            self::assertSame(1, $cleanup->expiredWatches);
            self::assertSame(1, $cleanup->enturRequestLogs);
        } finally {
            $connection->close();
        }
    }

    public function testFailedMigrationRollsBackAndChecksumDriftStopsRunner(): void
    {
        [$factory] = $this->database('migration_failure');
        $root = $factory->syncRoot(self::ROOT_USERNAME, self::ROOT_PASSWORD);
        $directory = sys_get_temp_dir() . '/fjordpulse-migrations-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0o700, true));

        try {
            file_put_contents(
                $directory . '/100_good.surql',
                "DEFINE TABLE migration_good SCHEMAFULL;\n",
            );
            file_put_contents(
                $directory . '/101_bad.surql',
                "DEFINE TABLE migration_must_rollback SCHEMAFULL;\nTHROW \"deliberate migration failure\";\n",
            );

            $runner = new MigrationRunner($root, $directory);
            try {
                $runner->migrate();
                self::fail('Bad migration must stop the runner.');
            } catch (MigrationException $error) {
                self::assertStringContainsString('101_bad.surql', $error->getMessage());
            }

            $appliedNames = array_map(static fn($migration): string => $migration->name, $runner->applied());
            self::assertContains('100_good.surql', $appliedNames);
            self::assertNotContains('101_bad.surql', $appliedNames);

            $info = $root->run('INFO FOR DB;');
            self::assertIsArray($info[0]);
            $tables = $info[0]['tables'] ?? null;
            self::assertIsArray($tables);
            self::assertArrayHasKey('migration_good', $tables);
            self::assertArrayNotHasKey('migration_must_rollback', $tables);

            unlink($directory . '/101_bad.surql');
            file_put_contents(
                $directory . '/100_good.surql',
                "DEFINE TABLE migration_good SCHEMAFULL COMMENT \"checksum drift\";\n",
            );

            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('Checksum mismatch');
            $runner->migrate();
        } finally {
            $root->close();
            foreach (glob($directory . '/*.surql') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private const string STATION_ID = 'NSR:StopPlace:58366';
    private const string VEHICLE_ID = 'SKY:Vehicle:12345';

    private static function station(): Station
    {
        return new Station(
            self::STATION_ID,
            'Oslo Nationaltheatret',
            StationKind::Station,
            new Coordinate(59.9147, 10.7346),
            'Oslo',
            'Oslo',
            ['rail', 'bus'],
            self::at('2026-07-10T09:00:00Z'),
        );
    }

    private static function snapshot(string $version, string $contentHash): StationSnapshot
    {
        $departure = new Departure(
            'departure-1',
            'service-1',
            'line-1',
            '100',
            'Drammen',
            self::at('2026-07-10T10:10:00Z'),
            self::at('2026-07-10T10:11:00Z'),
            DepartureStatus::Delayed,
            60,
            '1',
            true,
        );

        return new StationSnapshot(
            self::STATION_ID,
            $version,
            $contentHash,
            self::at($version),
            SourceState::Fresh,
            [$departure],
            [self::vehicle($version, VehicleFreshness::Live, 'nearby-' . $contentHash)],
            self::at($version),
        );
    }

    private static function vehicle(string $version, VehicleFreshness $state, string $contentHash): VehicleState
    {
        return new VehicleState(
            self::VEHICLE_ID,
            $version,
            $contentHash,
            $state,
            $state === VehicleFreshness::Lost ? null : new Coordinate(59.9139, 10.7522),
            '100',
            'Oslo–Drammen',
            'Drammen',
            90.0,
            30,
            120.5,
            self::at($version),
            self::at($version),
            new StopCall(
                self::STATION_ID,
                'Nationaltheatret',
                self::at('2026-07-10T10:12:00Z'),
                self::at('2026-07-10T10:12:30Z'),
            ),
        );
    }

    private static function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
