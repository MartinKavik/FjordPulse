<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\StationTimetable;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\Fake\FixtureFactory;
use FjordPulse\Surreal\DatabaseRecord;
use FjordPulse\Surreal\CurrentVehicleRepository;
use FjordPulse\Surreal\SurrealEncoding;
use FjordPulse\Surreal\SurrealRepositories;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class NativeRecordLinksIntegrationTest extends SurrealIntegrationTestCase
{
    public function testRepositoriesBackfillTraversableLinksAndApplyDeletionPolicies(): void
    {
        [$factory] = $this->database('native_record_links');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);
        $station = FixtureFactory::stations()[0];
        $vehicle = FixtureFactory::vehicles()[0];
        $reference = $vehicle->journeyReference;
        self::assertNotNull($reference);
        $journey = FixtureFactory::journey($reference);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $osloStart = $now->setTimezone(new DateTimeZone('Europe/Oslo'))->setTime(0, 0);
        $snapshot = new StationSnapshot(
            $station->id,
            $now->format('Y-m-d\TH:i:s.v\Z'),
            'native-link-snapshot',
            $now,
            SourceState::Fresh,
            [],
            [],
            $now,
        );
        $timetable = StationTimetable::create(
            $station->id,
            $osloStart->format('Y-m-d'),
            'Europe/Oslo',
            $osloStart,
            $osloStart->modify('+1 day'),
            [],
            true,
            $now,
        );
        $expiredTimetable = StationTimetable::create(
            $station->id,
            $osloStart->format('Y-m-d'),
            'Europe/Oslo',
            $osloStart,
            $osloStart->modify('+1 day'),
            [],
            true,
            $now->modify('-2 hours'),
        );
        $observation = $vehicle->observations[0];
        $missingReference = new VehicleJourneyReference(
            'missing-service-journey',
            $reference->operatingDate,
        );
        $missingVehicle = self::withJourneyReference($vehicle, 'vehicle:missing-journey', $missingReference);

        try {
            $repositories->stations->save($station);
            $repositories->stationSnapshots->save($snapshot);
            $repositories->stationTimetables->save($timetable, $now->modify('+1 hour'));
            $repositories->stationTimetables->save($expiredTimetable, $now->modify('-1 hour'));
            $repositories->currentVehicles->save($vehicle);
            $repositories->currentVehicles->save($vehicle);
            self::assertSame(0, self::journeyLinkCount($connection, $vehicle->id));
            self::assertSame($reference->key(), self::storedJourneyReferenceKey($repositories->currentVehicles, $vehicle->id));
            $repositories->currentVehicles->save($missingVehicle);
            self::assertSame(0, self::journeyLinkCount($connection, $missingVehicle->id));
            self::assertSame($missingReference->key(), self::storedJourneyReferenceKey($repositories->currentVehicles, $missingVehicle->id));
            $repositories->journeySnapshots->save($journey);
            $repositories->currentVehicles->save($vehicle);
            $repositories->vehicleObservations->append($observation, $now->modify('+1 day'));

            $diagnostics = $repositories->diagnostics->snapshot();
            self::assertSame(2, $diagnostics->stationTimetables);
            self::assertSame(1, $diagnostics->journeySnapshots);

            self::assertSame($station->id, self::linkedValue(
                $connection->run(
                    'SELECT station.station_id AS linked FROM ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($id)));',
                    ['id' => SurrealEncoding::string($station->id)],
                ),
                'station_snapshot.station',
            ));
            $incomingSnapshots = DatabaseRecord::normalize($connection->run(
                'SELECT VALUE <~station_snapshot FROM ONLY type::record("station", type::string_lossy(encoding::base64::decode($id)));',
                ['id' => SurrealEncoding::string($station->id)],
            )[0] ?? null);
            self::assertIsArray($incomingSnapshots);
            self::assertCount(1, $incomingSnapshots);
            self::assertSame($station->id, self::linkedValue(
                $connection->run(
                    'SELECT station.station_id AS linked FROM station_timetable WHERE station_id = type::string_lossy(encoding::base64::decode($id)) LIMIT 1;',
                    ['id' => SurrealEncoding::string($station->id)],
                ),
                'station_timetable.station',
            ));
            self::assertSame($vehicle->id, self::linkedValue(
                $connection->run(
                    'SELECT vehicle.vehicle_id AS linked FROM ONLY type::record("vehicle_observation", type::string_lossy(encoding::base64::decode($id)));',
                    ['id' => SurrealEncoding::string($observation->id)],
                ),
                'vehicle_observation.vehicle',
            ));
            self::assertSame($journey->key(), self::linkedValue(
                $connection->run(
                    'SELECT journey.journey_key AS linked FROM ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($id)));',
                    ['id' => SurrealEncoding::string($vehicle->id)],
                ),
                'current_vehicle.journey',
            ));

            $root = $factory->syncRoot(self::ROOT_USERNAME, self::ROOT_PASSWORD);
            try {
                $root->run(<<<'SURQL'
REMOVE FIELD station ON TABLE station_snapshot;
REMOVE FIELD station ON TABLE station_timetable;
REMOVE FIELD vehicle ON TABLE vehicle_observation;
REMOVE FIELD journey ON TABLE current_vehicle;
UPDATE station_snapshot UNSET station;
UPDATE station_timetable UNSET station;
UPDATE vehicle_observation UNSET vehicle;
UPDATE current_vehicle UNSET journey;
DEFINE FIELD OVERWRITE serving_vehicles ON TABLE station_snapshot TYPE option<array<object>> FLEXIBLE;
DEFINE FIELD OVERWRITE serving_candidate_journey_count ON TABLE station_snapshot TYPE option<int>;
DEFINE FIELD OVERWRITE serving_queried_journey_count ON TABLE station_snapshot TYPE option<int>;
DEFINE FIELD OVERWRITE serving_vehicles_truncated ON TABLE station_snapshot TYPE option<bool>;
UPDATE station_snapshot SET serving_vehicles = NONE, serving_candidate_journey_count = NONE,
    serving_queried_journey_count = NONE, serving_vehicles_truncated = NONE;
DEFINE FIELD OVERWRITE serving_vehicles ON TABLE station_snapshot TYPE array<object> FLEXIBLE DEFAULT [];
DEFINE FIELD OVERWRITE serving_candidate_journey_count ON TABLE station_snapshot TYPE int DEFAULT 0 ASSERT $value >= 0;
DEFINE FIELD OVERWRITE serving_queried_journey_count ON TABLE station_snapshot TYPE int DEFAULT 0 ASSERT $value >= 0;
DEFINE FIELD OVERWRITE serving_vehicles_truncated ON TABLE station_snapshot TYPE bool DEFAULT false;
SURQL);
                $migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/013_native_record_links.surql');
                self::assertIsString($migration);
                $root->run($migration);
            } finally {
                $root->close();
            }

            self::assertSame($station->id, self::linkedValue(
                $connection->run(
                    'SELECT station.station_id AS linked FROM ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($id)));',
                    ['id' => SurrealEncoding::string($station->id)],
                ),
                'backfilled station_snapshot.station',
            ));
            $repaired = DatabaseRecord::one($connection->run(
                'SELECT serving_vehicles, serving_candidate_journey_count, serving_queried_journey_count, serving_vehicles_truncated FROM ONLY type::record("station_snapshot", type::string_lossy(encoding::base64::decode($id)));',
                ['id' => SurrealEncoding::string($station->id)],
            )[0] ?? null);
            self::assertNotNull($repaired);
            self::assertSame([], DatabaseRecord::normalize($repaired['serving_vehicles'] ?? null));
            self::assertSame(0, $repaired['serving_candidate_journey_count'] ?? null);
            self::assertSame(0, $repaired['serving_queried_journey_count'] ?? null);
            self::assertFalse($repaired['serving_vehicles_truncated'] ?? null);
            self::assertSame($station->id, self::linkedValue(
                $connection->run(
                    'SELECT station.station_id AS linked FROM station_timetable WHERE station_id = type::string_lossy(encoding::base64::decode($id)) LIMIT 1;',
                    ['id' => SurrealEncoding::string($station->id)],
                ),
                'backfilled station_timetable.station',
            ));
            self::assertSame($vehicle->id, self::linkedValue(
                $connection->run(
                    'SELECT vehicle.vehicle_id AS linked FROM ONLY type::record("vehicle_observation", type::string_lossy(encoding::base64::decode($id)));',
                    ['id' => SurrealEncoding::string($observation->id)],
                ),
                'backfilled vehicle_observation.vehicle',
            ));
            self::assertSame($journey->key(), self::linkedValue(
                $connection->run(
                    'SELECT journey.journey_key AS linked FROM ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($id)));',
                    ['id' => SurrealEncoding::string($vehicle->id)],
                ),
                'backfilled current_vehicle.journey',
            ));
            self::assertSame(0, self::journeyLinkCount($connection, $missingVehicle->id));
            self::assertSame($missingReference->key(), self::storedJourneyReferenceKey($repositories->currentVehicles, $missingVehicle->id));

            $cleanup = $repositories->cleanup->prune(
                $now,
                new DateTimeImmutable('2000-01-01T00:00:00Z'),
                new DateTimeImmutable('2000-01-01T00:00:00Z'),
                new DateTimeImmutable('2000-01-01T00:00:00Z'),
            );
            self::assertSame(1, $cleanup->stationTimetables);
            self::assertSame(0, $cleanup->vehicleObservations);

            $connection->run(
                'DELETE ONLY type::record("station", type::string_lossy(encoding::base64::decode($id)));',
                ['id' => SurrealEncoding::string($station->id)],
            );
            self::assertNull($repositories->stationSnapshots->find($station->id));
            self::assertNull($repositories->stationTimetables->findVersion(
                $station->id,
                $timetable->serviceDate,
                $timetable->version,
            ));

            $connection->run(
                'DELETE ONLY type::record("journey_snapshot", type::string_lossy(encoding::base64::decode($id)));',
                ['id' => SurrealEncoding::string($journey->key())],
            );
            $storedVehicle = $repositories->currentVehicles->find($vehicle->id);
            self::assertNotNull($storedVehicle);
            self::assertSame($vehicle->id, $storedVehicle->id);
            self::assertSame(0, DatabaseRecord::int(
                $connection->run('RETURN count(SELECT VALUE id FROM current_vehicle WHERE journey != NONE);')[0] ?? null,
                'current vehicles with journey link',
            ));
            self::assertSame($reference->key(), self::storedJourneyReferenceKey($repositories->currentVehicles, $vehicle->id));
            $repositories->currentVehicles->save($vehicle);
            $repositories->currentVehicles->save($vehicle);
            self::assertSame(0, self::journeyLinkCount($connection, $vehicle->id));

            $repositories->journeySnapshots->save($journey);
            $repositories->currentVehicles->save($vehicle);
            self::assertSame($journey->key(), self::linkedValue(
                $connection->run(
                    'SELECT journey.journey_key AS linked FROM ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($id)));',
                    ['id' => SurrealEncoding::string($vehicle->id)],
                ),
                'relinked current_vehicle.journey',
            ));

            $connection->run(
                'DELETE ONLY type::record("current_vehicle", type::string_lossy(encoding::base64::decode($id)));',
                ['id' => SurrealEncoding::string($vehicle->id)],
            );
            self::assertSame([], $repositories->vehicleObservations->recent($vehicle->id));
        } finally {
            $connection->close();
        }
    }

    /** @param list<mixed> $results */
    private static function linkedValue(array $results, string $field): string
    {
        $record = DatabaseRecord::one($results[0] ?? null);
        if ($record === null) {
            self::fail("Missing {$field} record.");
        }

        return DatabaseRecord::string($record['linked'] ?? null, $field);
    }

    private static function journeyLinkCount(\FjordPulse\Surreal\SurrealConnection $connection, string $vehicleId): int
    {
        return DatabaseRecord::int($connection->run(
            'RETURN count(SELECT VALUE id FROM current_vehicle WHERE vehicle_id = type::string_lossy(encoding::base64::decode($id)) AND journey != NONE);',
            ['id' => SurrealEncoding::string($vehicleId)],
        )[0] ?? null, 'vehicle journey link count');
    }

    private static function storedJourneyReferenceKey(
        CurrentVehicleRepository $repository,
        string $vehicleId,
    ): string {
        $stored = $repository->find($vehicleId);
        if ($stored === null || $stored->journeyReference === null) {
            self::fail("Vehicle {$vehicleId} lost its public journey reference.");
        }

        return $stored->journeyReference->key();
    }

    private static function withJourneyReference(
        VehicleState $vehicle,
        string $id,
        VehicleJourneyReference $reference,
    ): VehicleState {
        return new VehicleState(
            id: $id,
            version: $vehicle->version,
            contentHash: hash('sha256', $id . '|' . $reference->key()),
            state: $vehicle->state,
            coordinate: $vehicle->coordinate,
            lineCode: $vehicle->lineCode,
            routeName: $vehicle->routeName,
            destination: $vehicle->destination,
            bearing: $vehicle->bearing,
            delaySeconds: $vehicle->delaySeconds,
            distanceMeters: $vehicle->distanceMeters,
            lastSeenAt: $vehicle->lastSeenAt,
            updatedAt: $vehicle->updatedAt,
            nextStop: $vehicle->nextStop,
            observations: [],
            journeyReference: $reference,
            monitoredCall: $vehicle->monitoredCall,
            progressBetweenStops: $vehicle->progressBetweenStops,
            journeyVersion: $vehicle->journeyVersion,
            routeProgress: $vehicle->routeProgress,
            refreshedAt: $vehicle->refreshedAt,
            transportMode: $vehicle->transportMode,
            passengerServiceState: $vehicle->passengerServiceState,
        );
    }
}
