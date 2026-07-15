<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\StationKind;
use FjordPulse\Domain\StationVehicleCallRole;
use FjordPulse\Domain\StationVehicleProgress;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Domain\VehicleTransportMode;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\DepartureBoard;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\JourneyGeometry;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\MonitoredCallReference;
use FjordPulse\Dto\ProgressBetweenStops;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\StationTimetable;
use FjordPulse\Dto\StationVehicle;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\Fake\FixtureFactory;
use FjordPulse\Surreal\SurrealRepositories;
use FjordPulse\Surreal\SurrealEncoding;
use FjordPulse\Surreal\DatabaseRecord;
use FjordPulse\Surreal\MigrationException;
use FjordPulse\Surreal\MigrationRunner;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class SurrealPersistenceIntegrationTest extends SurrealIntegrationTestCase
{
    public function testVersionedStationTimetableCacheSupportsFreshAndCursorLookups(): void
    {
        [$factory] = $this->database('station_timetable_cache');
        $connection = $factory->sync();
        try {
            $repositories = new SurrealRepositories($connection);
            $now = new DateTimeImmutable();
            $windowStart = $now->setTimezone(new DateTimeZone('Europe/Oslo'))->setTime(0, 0);
            $timetable = StationTimetable::create(
                'NSR:StopPlace:36025',
                $windowStart->format('Y-m-d'),
                'Europe/Oslo',
                $windowStart,
                $windowStart->modify('+1 day'),
                FixtureFactory::departures('NSR:StopPlace:36025'),
                true,
                $now,
            );

            $saved = $repositories->stationTimetables->save($timetable, $now->modify('+1 hour'));

            self::assertSame($timetable->version, $saved->version);
            self::assertCount(4, $saved->departures);
            self::assertSame(
                $saved->version,
                $repositories->stationTimetables->findFresh(
                    $saved->stationId,
                    $saved->serviceDate,
                    $now->modify('-1 second'),
                )?->version,
            );
            self::assertSame(
                $saved->version,
                $repositories->stationTimetables->findVersion(
                    $saved->stationId,
                    $saved->serviceDate,
                    $saved->version,
                )?->version,
            );
            self::assertNull($repositories->stationTimetables->findFresh(
                $saved->stationId,
                $saved->serviceDate,
                $now->modify('+1 second'),
            ));
        } finally {
            $connection->close();
        }
    }

    public function testVersionedMigrationsAreOrderedIdempotentAndAppUserIsDatabaseScoped(): void
    {
        [$factory, $firstReport] = $this->database('migrations');

        self::assertSame(
            [
                '001_core_schema.surql',
                '002_realtime_events.surql',
                '003_semantic_event_filter.surql',
                '004_source_provenance_and_search.surql',
                '005_vehicle_journeys.surql',
                '006_vehicle_transport_mode.surql',
                '007_station_serving_vehicles.surql',
                '008_vehicle_passenger_service_state.surql',
                '009_entur_budget_reservations.surql',
                '010_migration_attempt_history.surql',
                '011_station_timetable_cache.surql',
                '012_station_refresh_version_allocation.surql',
            ],
            array_map(static fn($migration): string => $migration->name, $firstReport->applied),
        );

        $app = $factory->sync();
        try {
            self::assertSame('surrealdb-3.2.0', $app->version());
            $tables = $app->run('INFO FOR DB;');
            self::assertIsArray($tables[0]);
            self::assertArrayHasKey('tables', $tables[0]);
            $schema = (new SurrealRepositories($app))->databaseSchema->inspect()->toArray();
            self::assertCount(13, $schema['tables']);
            $schemaJson = json_encode($schema, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('PASSHASH', $schemaJson);
            self::assertStringNotContainsString('fjordpulse_app', $schemaJson);
            self::assertStringNotContainsString('users', $schemaJson);
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
            self::assertCount(12, $secondReport->alreadyApplied);
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
            $sameSnapshotContent = self::snapshot('2026-07-10T10:00:01.000000Z', 'snapshot-one', 1);
            $snapshot2 = self::snapshot('2026-07-10T10:00:02.000000Z', 'snapshot-two');
            $older = self::snapshot('2026-07-10T09:59:59.000000Z', 'older-ignored');

            self::assertSame($snapshot1->version, $repositories->stationSnapshots->save($snapshot1)->version);
            $metadataRefresh = $repositories->stationSnapshots->save($sameSnapshotContent);
            self::assertSame($snapshot1->version, $metadataRefresh->version);
            self::assertEquals($sameSnapshotContent->updatedAt, $metadataRefresh->updatedAt);
            self::assertEquals($sameSnapshotContent->lastSuccessfulAt, $metadataRefresh->lastSuccessfulAt);
            self::assertEquals($sameSnapshotContent->servingWindowStartedAt, $metadataRefresh->servingWindowStartedAt);
            self::assertEquals($sameSnapshotContent->servingWindowEndsAt, $metadataRefresh->servingWindowEndsAt);
            self::assertEquals($sameSnapshotContent->departureBoard, $metadataRefresh->departureBoard);
            self::assertCount(1, $repositories->realtimeEvents->recent(limit: 20));
            self::assertSame($snapshot1->version, $repositories->stationSnapshots->save($snapshot1)->version);
            self::assertEquals($sameSnapshotContent->updatedAt, $repositories->stationSnapshots->find(self::STATION_ID)?->updatedAt);
            self::assertSame($snapshot1->version, $repositories->stationSnapshots->save($older)->version);
            self::assertSame($snapshot2->version, $repositories->stationSnapshots->save($snapshot2)->version);
            $storedSnapshot = $repositories->stationSnapshots->find(self::STATION_ID);
            self::assertNotNull($storedSnapshot);
            self::assertCount(1, $storedSnapshot->servingVehicles);
            self::assertSame(StationVehicleCallRole::CallsHere, $storedSnapshot->servingVehicles[0]->callRole);
            self::assertSame(StationVehicleProgress::BeforeStation, $storedSnapshot->servingVehicles[0]->progress);
            self::assertSame(1, $storedSnapshot->servingQueriedJourneyCount);
            self::assertSame(20, $storedSnapshot->departureBoard?->limit);

            $vehicleLive = self::vehicle('2026-07-10T10:00:03.000000Z', VehicleFreshness::Live, 'vehicle-live');
            $vehicleStale = self::vehicle('2026-07-10T10:00:04.000000Z', VehicleFreshness::Stale, 'vehicle-stale');
            $vehicleLost = self::vehicle('2026-07-10T10:00:05.000000Z', VehicleFreshness::Lost, 'vehicle-lost');
            self::assertSame(VehicleFreshness::Live, $repositories->currentVehicles->save($vehicleLive)->state);
            $refreshedOnly = new VehicleState(
                $vehicleLive->id,
                '2026-07-10T10:00:03.500000Z',
                $vehicleLive->contentHash,
                $vehicleLive->state,
                $vehicleLive->coordinate,
                $vehicleLive->lineCode,
                $vehicleLive->routeName,
                $vehicleLive->destination,
                $vehicleLive->bearing,
                $vehicleLive->delaySeconds,
                $vehicleLive->distanceMeters,
                $vehicleLive->lastSeenAt,
                $vehicleLive->updatedAt,
                $vehicleLive->nextStop,
                refreshedAt: self::at('2026-07-10T10:00:03.500000Z'),
                transportMode: $vehicleLive->transportMode,
            );
            $refreshResult = $repositories->currentVehicles->save($refreshedOnly);
            self::assertSame($vehicleLive->version, $refreshResult->version);
            self::assertSame('2026-07-10T10:00:03+00:00', $refreshResult->refreshedAt?->format(DateTimeInterface::RFC3339));
            self::assertSame(VehicleFreshness::Stale, $repositories->currentVehicles->save($vehicleStale)->state);
            self::assertSame(VehicleFreshness::Lost, $repositories->currentVehicles->save($vehicleLost)->state);
            self::assertSame(VehicleFreshness::Lost, $repositories->currentVehicles->save($vehicleStale)->state);
            self::assertSame([], $repositories->currentVehicles->search('Line 100'));

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
            $eventServingVehicles = $events[0]->payload['servingVehicles'] ?? null;
            self::assertIsArray($eventServingVehicles);
            self::assertCount(1, $eventServingVehicles);
            self::assertIsArray($eventServingVehicles[0]);
            self::assertSame('calls_here', $eventServingVehicles[0]['callRole'] ?? null);
            self::assertSame('before_station', $eventServingVehicles[0]['progress'] ?? null);
            self::assertArrayNotHasKey('relation', $eventServingVehicles[0]);
            $eventDepartureBoard = $events[0]->payload['departureBoard'] ?? null;
            self::assertIsArray($eventDepartureBoard);
            self::assertSame(20, $eventDepartureBoard['limit'] ?? null);
            self::assertFalse($eventDepartureBoard['hasMore'] ?? true);

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
            self::assertCount(12, $diagnostics->recentMigrations);

            $cleanup = $repositories->cleanup->prune(
                self::at('2099-01-01T00:00:00Z'),
                self::at('2099-01-01T00:00:00Z'),
                self::at('2099-01-01T00:00:00Z'),
                self::at('2099-01-01T00:00:00Z'),
            );
            self::assertSame(1, $cleanup->vehicleObservations);
            self::assertSame(5, $cleanup->realtimeEvents);
            self::assertSame(1, $cleanup->expiredWatches);
            self::assertSame(1, $cleanup->enturRequestLogs);
        } finally {
            $connection->close();
        }
    }

    public function testLatestEnturAvailabilityEvidenceSkipsAnyNewerCacheHitsInTheDatabase(): void
    {
        [$factory] = $this->database('latest_entur_evidence');
        $connection = $factory->sync();
        $logs = (new SurrealRepositories($connection))->enturRequestLogs;

        try {
            $logs->append(new EnturRequestLog(
                'outbound-evidence',
                'journey_planner',
                'station:' . self::STATION_ID,
                self::at('2026-07-10T10:00:00Z'),
                200,
                42,
                3,
                'miss',
                'success',
                null,
                'request-outbound',
            ));
            for ($index = 0; $index < 1_005; $index++) {
                $logs->append(new EnturRequestLog(
                    'cache-' . $index,
                    'journey_planner',
                    'station:' . self::STATION_ID,
                    self::at('2026-07-10T10:01:00Z')->modify('+' . $index . ' milliseconds'),
                    200,
                    0,
                    3,
                    'hit',
                    'cache_hit',
                    null,
                    'request-cache-' . $index,
                ));
            }

            self::assertSame('outbound-evidence', $logs->latestNonCacheEvidence()?->id);
            self::assertSame(
                'outbound-evidence',
                $logs->filtered(
                    service: 'journey_planner',
                    scope: strtolower('STATION:' . self::STATION_ID),
                    limit: 1_000,
                    outboundOnly: true,
                )[0]->id ?? null,
                'Database-side outbound filtering must not let 1,005 newer cache hits hide the real call.',
            );
        } finally {
            $connection->close();
        }
    }

    public function testWatchRepositoryDeletesOnlyExpiredRecordsDuringRealtimeStartup(): void
    {
        [$factory] = $this->database('watch_process_cleanup');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);

        try {
            foreach ([
                new Watch(
                    'watch-vehicle',
                    WatchType::Vehicle,
                    'vehicle:' . self::VEHICLE_ID,
                    self::VEHICLE_ID,
                    1,
                    WatchPriority::Vehicle,
                    null,
                    self::at('2026-07-10T10:00:00Z'),
                    self::at('2099-01-01T00:00:00Z'),
                    WatchState::Active,
                ),
                new Watch(
                    'watch-focus',
                    WatchType::Focus,
                    'focus:previous-process:' . self::VEHICLE_ID,
                    self::VEHICLE_ID,
                    0,
                    WatchPriority::Focus,
                    null,
                    self::at('2026-07-10T10:00:00Z'),
                    self::at('2026-07-10T10:01:00Z'),
                    WatchState::Expired,
                ),
            ] as $watch) {
                $repositories->watches->save($watch);
            }

            self::assertCount(2, $repositories->watches->all());
            self::assertSame(1, $repositories->watches->deleteExpired(self::at('2027-01-01T00:00:00Z')));
            $retained = $repositories->watches->all();
            self::assertCount(1, $retained);
            self::assertSame('watch-vehicle', $retained[0]->id);
            self::assertSame(0, $repositories->watches->deleteExpired(self::at('2027-01-01T00:00:00Z')));
            self::assertSame(1, $repositories->watches->deleteAll());
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
            $failedAttempts = $root->run(
                'SELECT name, state, failure_message, started_at FROM schema_migration_attempt WHERE name = "101_bad.surql" ORDER BY started_at DESC LIMIT 1;',
            );
            $failedAttempt = DatabaseRecord::one($failedAttempts[0] ?? null);
            self::assertNotNull($failedAttempt);
            self::assertSame('failed', DatabaseRecord::string($failedAttempt['state'] ?? null, 'attempt.state'));
            self::assertNotSame(
                '',
                DatabaseRecord::string($failedAttempt['failure_message'] ?? null, 'attempt.failure_message'),
            );

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

            try {
                $runner->migrate();
                self::fail('Checksum drift must stop the runner.');
            } catch (MigrationException $error) {
                self::assertStringContainsString('Checksum mismatch', $error->getMessage());
            }
            $mismatchAttempts = $root->run(
                'SELECT name, state, failure_message, started_at FROM schema_migration_attempt WHERE name = "100_good.surql" ORDER BY started_at DESC LIMIT 1;',
            );
            $mismatchAttempt = DatabaseRecord::one($mismatchAttempts[0] ?? null);
            self::assertNotNull($mismatchAttempt);
            self::assertSame('checksum_mismatch', DatabaseRecord::string($mismatchAttempt['state'] ?? null, 'attempt.state'));
            self::assertStringContainsString(
                'Checksum mismatch',
                DatabaseRecord::string($mismatchAttempt['failure_message'] ?? null, 'attempt.failure_message'),
            );
        } finally {
            $root->close();
            foreach (glob($directory . '/*.surql') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testJourneySnapshotRoundTripsWithoutASecondEventPath(): void
    {
        [$factory] = $this->database('journey_snapshots');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);
        $at = self::at('2026-07-10T10:00:00Z');
        $reference = new VehicleJourneyReference(
            'SKY:ServiceJourney:100-1',
            '2026-07-10',
            null,
            self::STATION_ID,
            'Nationaltheatret',
            'NSR:StopPlace:337',
            'Oslo S',
        );
        $calls = [
            new StopCall(self::STATION_ID, 'Nationaltheatret', $at, $at, 0, 'NSR:Quay:1', new Coordinate(59.9147, 10.7346), $at, $at, true),
            new StopCall('NSR:StopPlace:337', 'Oslo S', self::at('2026-07-10T10:10:00Z'), self::at('2026-07-10T10:11:00Z'), 1, 'NSR:Quay:2', new Coordinate(59.9111, 10.7528), null, null, true),
        ];
        $journey = new JourneySnapshot(
            $reference->serviceJourneyId,
            $reference->operatingDate,
            null,
            '2026-07-10T10:00:00.000000Z',
            'journey-content',
            SourceState::Fresh,
            new JourneyGeometry([new Coordinate(59.9147, 10.7346), new Coordinate(59.9111, 10.7528)], 1_200.0),
            $calls,
            $at,
            $at,
        );

        try {
            $stored = $repositories->journeySnapshots->save($journey);
            self::assertSame($journey->key(), $stored->key());
            self::assertSame([[10.7346, 59.9147], [10.7528, 59.9111]], $stored->route?->toArray()['coordinates']);
            self::assertCount(2, $stored->calls);
            self::assertCount(0, $repositories->realtimeEvents->recent());

            $vehicle = new VehicleState(
                self::VEHICLE_ID,
                '2026-07-10T10:00:01.000000Z',
                'vehicle-with-journey',
                VehicleFreshness::Live,
                new Coordinate(59.9139, 10.7522),
                '100',
                'Nationaltheatret–Oslo S',
                'Oslo S',
                90.0,
                30,
                null,
                $at,
                self::at('2026-07-10T10:00:01Z'),
                $calls[1],
                [],
                $reference,
                new MonitoredCallReference('NSR:Quay:2', 1, false),
                new ProgressBetweenStops(1_200.0, 0.5),
                $journey->version,
                0.75,
                self::at('2026-07-10T10:00:01Z'),
                VehicleTransportMode::Rail,
                VehiclePassengerServiceState::Passenger,
            );
            $roundTrip = $repositories->currentVehicles->save($vehicle);
            self::assertSame($reference->key(), $roundTrip->journeyReference?->key());
            self::assertSame(0.75, $roundTrip->routeProgress);
            self::assertSame($journey->version, $roundTrip->journeyVersion);
            self::assertSame(VehicleTransportMode::Rail, $roundTrip->transportMode);
            self::assertSame(VehiclePassengerServiceState::Passenger, $roundTrip->passengerServiceState);
            $events = $repositories->realtimeEvents->recent();
            self::assertCount(1, $events);
            foreach ($events as $event) {
                $vehiclePayload = $event->payload['vehicle'] ?? null;
                self::assertIsArray($vehiclePayload);
                self::assertSame($journey->version, $vehiclePayload['journeyVersion'] ?? null);
                self::assertSame('rail', $vehiclePayload['transportMode'] ?? null);
                self::assertSame('passenger', $vehiclePayload['passengerServiceState'] ?? null);
                self::assertArrayNotHasKey('journey', $event->payload);
            }
        } finally {
            $connection->close();
        }
    }

    public function testLegacyUnknownPassengerServiceStateIsDerivedFromStoredOperationalSignals(): void
    {
        [$factory] = $this->database('legacy_passenger_service_state');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);
        $at = self::at('2026-07-12T01:53:41Z');

        try {
            $legacy = new VehicleState(
                '3350447622',
                '2026-07-12T01:53:41.000Z',
                'legacy-operational-record',
                VehicleFreshness::Live,
                new Coordinate(60.4835, 5.3744),
                '4',
                'Flaktveit - Hesjaholtet',
                'skyss.no',
                27.3,
                1_080,
                null,
                $at,
                $at,
                null,
                [],
                new VehicleJourneyReference(
                    '21255797_200969',
                    '2026-07-11',
                    null,
                    'NSR:Quay:53799',
                    'Flaktveit snuplass',
                    'GAR4.402',
                    'skyss.no',
                ),
                new MonitoredCallReference('GAR4.402', 1, false),
                transportMode: VehicleTransportMode::Bus,
                passengerServiceState: VehiclePassengerServiceState::Unknown,
            );

            $roundTrip = $repositories->currentVehicles->save($legacy);
            self::assertSame(VehiclePassengerServiceState::NonPassenger, $roundTrip->passengerServiceState);
            self::assertSame(VehiclePassengerServiceState::NonPassenger, $repositories->currentVehicles->find($legacy->id)?->passengerServiceState);
            self::assertSame('4', $roundTrip->lineCode, 'Classification must not erase raw operational metadata.');
            self::assertSame(1_080, $roundTrip->delaySeconds);
        } finally {
            $connection->close();
        }
    }

    public function testRefreshWritersFromTheSameBaseReceiveStrictlyIncreasingDatabaseVersions(): void
    {
        [$factory] = $this->database('station_refresh_version_collision');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);

        try {
            $base = self::snapshot('2026-07-10T10:00:00.000Z', 'refresh-base');
            $firstChange = self::snapshot('2026-07-10T10:00:00.001Z', 'refresh-first');
            $secondChange = self::snapshot('2026-07-10T10:00:00.001Z', 'refresh-second');

            $savedBase = $repositories->stationSnapshots->save($base);
            $savedFirst = $repositories->stationSnapshots->saveRefresh($firstChange, $savedBase->version);
            $savedSecond = $repositories->stationSnapshots->saveRefresh($secondChange, $savedBase->version);

            self::assertSame('2026-07-10T10:00:00.001Z', $savedFirst->version);
            self::assertSame('2026-07-10T10:00:00.002Z', $savedSecond->version);
            self::assertSame($savedFirst->version, $savedFirst->updatedAt->format('Y-m-d\\TH:i:s.v\\Z'));
            self::assertSame($savedSecond->version, $savedSecond->updatedAt->format('Y-m-d\\TH:i:s.v\\Z'));
            self::assertSame('refresh-second', $savedSecond->contentHash);

            $metadataOnly = self::snapshot('2026-07-10T10:00:00.003Z', 'refresh-second', 1);
            $savedMetadata = $repositories->stationSnapshots->saveRefresh($metadataOnly, $savedSecond->version);
            self::assertSame($savedSecond->version, $savedMetadata->version);
            self::assertSame('2026-07-10T10:00:00.003Z', $savedMetadata->updatedAt->format('Y-m-d\\TH:i:s.v\\Z'));

            $staleMetadata = self::snapshot('2026-07-10T10:00:00.005Z', 'refresh-second', 2);
            $afterStaleMetadata = $repositories->stationSnapshots->saveRefresh($staleMetadata, $savedBase->version);
            self::assertSame($savedSecond->version, $afterStaleMetadata->version);
            self::assertSame('2026-07-10T10:00:00.003Z', $afterStaleMetadata->updatedAt->format('Y-m-d\\TH:i:s.v\\Z'));

            $staleSemanticAfterMetadata = self::snapshot('2026-07-10T10:00:00.004Z', 'stale-after-metadata-cohort');
            $afterStaleSemantic = $repositories->stationSnapshots->saveRefresh($staleSemanticAfterMetadata, $savedBase->version);
            self::assertSame($savedSecond->version, $afterStaleSemantic->version);
            self::assertSame('refresh-second', $afterStaleSemantic->contentHash);

            $newerCohort = self::snapshot('2026-07-10T10:00:00.004Z', 'refresh-newer-cohort');
            $savedNewerCohort = $repositories->stationSnapshots->saveRefresh($newerCohort, $savedSecond->version);
            self::assertSame('2026-07-10T10:00:00.004Z', $savedNewerCohort->version);

            $staleRefresh = self::snapshot('2026-07-10T10:00:00.005Z', 'stale-refresh-cohort');
            $afterStaleRefresh = $repositories->stationSnapshots->saveRefresh($staleRefresh, $savedBase->version);
            self::assertSame($savedNewerCohort->version, $afterStaleRefresh->version);
            self::assertSame('refresh-newer-cohort', $afterStaleRefresh->contentHash);

            $olderDirectWrite = self::snapshot('2026-07-10T09:59:59.999Z', 'older-direct-write');
            $afterOlderWrite = $repositories->stationSnapshots->save($olderDirectWrite);
            self::assertSame($savedNewerCohort->version, $afterOlderWrite->version);
            self::assertSame('refresh-newer-cohort', $afterOlderWrite->contentHash);

            $events = array_reverse($repositories->realtimeEvents->recent(limit: 10));
            self::assertCount(4, $events);
            self::assertSame([
                '2026-07-10T10:00:00.000Z',
                '2026-07-10T10:00:00.001Z',
                '2026-07-10T10:00:00.002Z',
                '2026-07-10T10:00:00.004Z',
            ], array_map(static fn($event): string => $event->version, $events));
        } finally {
            $connection->close();
        }
    }

    public function testStationSearchFoldsNorwegianCharactersAndAllowsBoundedTypos(): void
    {
        [$factory] = $this->database('normalized_station_search');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);

        try {
            $station = new Station(
                'NSR:StopPlace:36025',
                'Førde rutebilstasjon',
                StationKind::BusStation,
                new Coordinate(61.4522, 5.8572),
                'Førde',
                'Sunnfjord',
                ['bus'],
                self::at('2026-07-10T09:00:00Z'),
            );
            $repositories->stations->save($station, 'fake', 'deterministic-v1', 'fake', 'catalog-search');
            $distractors = array_map(static fn(int $index): Station => new Station(
                'NSR:StopPlace:Fo' . $index,
                sprintf('Fo%03d terminal', $index),
                StationKind::StopPlace,
                new Coordinate(60.0 + ($index / 10_000), 5.0),
                null,
                null,
                ['bus'],
                self::at('2026-07-10T09:00:00Z'),
            ), range(1, 150));
            $repositories->stations->saveMany($distractors, 'fake', 'deterministic-v1', 'fake', 'catalog-search');

            foreach (['Førde', 'Forde', 'Fo', 'Frode'] as $query) {
                self::assertSame($station->id, $repositories->stations->search($query, 5)[0]->id, $query);
            }
        } finally {
            $connection->close();
        }
    }

    public function testVehicleSearchUsesTheSameNormalizedRouteIndex(): void
    {
        [$factory] = $this->database('normalized_vehicle_search');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);

        try {
            $vehicle = FixtureFactory::vehicles()[0];
            $repositories->currentVehicles->save($vehicle);

            foreach (['Førde', 'Forde', 'Fo', 'Frode', 'Line 100'] as $query) {
                self::assertSame($vehicle->id, $repositories->currentVehicles->search($query, 5)[0]->id, $query);
            }
            self::assertSame([], $repositories->currentVehicles->search('Line 100', 5, self::at('2026-07-11T00:00:00Z')));
        } finally {
            $connection->close();
        }
    }

    public function testCatalogActivationRemovesRowsFromOtherRunsAndSourceModes(): void
    {
        [$factory] = $this->database('station_catalog_activation');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);

        try {
            $repositories->stations->save(self::station(), 'fake', 'deterministic-v1', 'fake', 'fake-run');
            $repositories->stationSnapshots->save(self::snapshot('2026-07-10T10:00:00.000000Z', 'old-source-snapshot'));
            $repositories->currentVehicles->save(self::vehicle(
                '2026-07-10T10:00:01.000000Z',
                VehicleFreshness::Live,
                'old-source-vehicle',
            ));
            $repositories->journeySnapshots->save(new JourneySnapshot(
                'SKY:ServiceJourney:old-source',
                '2026-07-10',
                null,
                '2026-07-10T10:00:01.000Z',
                'old-source-journey',
                SourceState::Fresh,
                null,
                [],
                self::at('2026-07-10T10:00:01Z'),
                self::at('2026-07-10T10:00:01Z'),
            ));
            $realStation = new Station(
                'NSR:StopPlace:36025',
                'Førde rutebilstasjon',
                StationKind::BusStation,
                new Coordinate(61.4522, 5.8572),
                'Sunnfjord',
                'Sunnfjord',
                ['bus'],
                self::at('2026-07-10T09:00:00Z'),
            );
            $repositories->stations->save($realStation, 'entur_stop_place', 'stop-places-v1', 'real', 'real-run');

            self::assertSame(1, $repositories->stations->activateCatalog('real-run', 'real', true));
            self::assertNull($repositories->stations->find(self::STATION_ID));
            self::assertSame($realStation->id, $repositories->stations->find($realStation->id)?->id);
            self::assertNull($repositories->stationSnapshots->find(self::STATION_ID));
            self::assertNull($repositories->currentVehicles->find(self::VEHICLE_ID));
            self::assertNull($repositories->journeySnapshots->find('SKY:ServiceJourney:old-source', '2026-07-10'));
            self::assertSame([], $repositories->realtimeEvents->recent());
        } finally {
            $connection->close();
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

    private static function snapshot(string $version, string $contentHash, int $coverageShiftMinutes = 0): StationSnapshot
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

        $vehicle = self::vehicle($version, VehicleFreshness::Live, 'nearby-' . $contentHash);

        return new StationSnapshot(
            self::STATION_ID,
            $version,
            $contentHash,
            self::at($version),
            SourceState::Fresh,
            [$departure],
            [$vehicle],
            self::at($version),
            null,
            [new StationVehicle(
                $vehicle,
                StationVehicleCallRole::CallsHere,
                StationVehicleProgress::BeforeStation,
                self::at('2026-07-10T10:10:00Z'),
            )],
            self::at('2026-07-10T04:00:00Z')->modify('+' . $coverageShiftMinutes . ' minutes'),
            self::at('2026-07-10T16:00:00Z')->modify('+' . $coverageShiftMinutes . ' minutes'),
            1,
            1,
            false,
            new DepartureBoard(
                self::at('2026-07-10T10:00:00Z')->modify('+' . $coverageShiftMinutes . ' minutes'),
                self::at('2026-07-11T00:00:00Z'),
                20,
                false,
            ),
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
            transportMode: VehicleTransportMode::Bus,
        );
    }

    private static function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
