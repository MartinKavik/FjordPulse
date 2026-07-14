<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Domain\EnturService;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\StationBoard;
use FjordPulse\Dto\StationTimetable;
use FjordPulse\Dto\StationVehiclePositions;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Fake\FakeVehiclePositions;
use FjordPulse\Entur\Fake\FixtureFactory;
use FjordPulse\Entur\Http\AmpTransport;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Entur\MutableScenarioProvider;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\Real\RealJourneyPlanner;
use FjordPulse\Entur\Real\RealVehiclePositions;
use FjordPulse\Entur\RepositoryRequestBudget;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Entur\VehiclePositionsInterface;
use FjordPulse\Realtime\ActiveWatchRegistry;
use FjordPulse\Realtime\DemandDrivenCollector;
use FjordPulse\Realtime\RealtimeTelemetry;
use FjordPulse\Realtime\RepositoryEnturRequestObserver;
use FjordPulse\Realtime\SurrealWatchStore;
use FjordPulse\Realtime\WatchScheduler;
use FjordPulse\Surreal\SurrealRepositories;
use PHPUnit\Framework\Attributes\CoversNothing;
use Throwable;

#[CoversNothing]
final class EnturOutageRecoveryIntegrationTest extends SurrealIntegrationTestCase
{
    public function testOperationalVehicleRefreshSkipsJourneyPlannerAndPersistsLivePosition(): void
    {
        [$factory] = $this->database('operational_vehicle_skips_journey');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);
        try {
            $mapped = (new VehicleMapper(
                clock: static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-12T01:54:00Z'),
            ))->map(['data' => ['vehicles' => [[
                'vehicleId' => '3350447622',
                'mode' => 'BUS',
                'lastUpdated' => '2026-07-12T01:53:41Z',
                'location' => ['latitude' => 60.483532, 'longitude' => 5.374388],
                'delay' => 1_080,
                'line' => ['lineName' => 'Flaktveit - Hesjaholtet', 'publicCode' => '4'],
                'destinationName' => 'skyss.no',
                'serviceJourney' => ['id' => '21255797_200969', 'date' => '2026-07-11'],
                'originRef' => 'NSR:Quay:53799',
                'destinationRef' => 'GAR4.402',
                'monitoredCall' => ['stopPointRef' => 'GAR4.402', 'order' => 2, 'vehicleAtStop' => false],
            ]]]]);
            self::assertCount(1, $mapped);
            $operational = $mapped[0];
            self::assertSame(VehiclePassengerServiceState::NonPassenger, $operational->passengerServiceState);

            $journeys = new class implements JourneyPlannerInterface {
                public int $journeyCalls = 0;

                public function departures(string $stationId, int $limit = 20): array
                {
                    unset($stationId, $limit);

                    return [];
                }

                public function stationBoard(string $stationId, DateTimeImmutable $now, int $limit = 20): StationBoard
                {
                    unset($stationId, $limit);

                    return new StationBoard([], [], $now, $now, 0, 0, false);
                }

                public function dailyTimetable(string $stationId, DateTimeImmutable $serviceDay): StationTimetable
                {
                    return StationTimetable::create(
                        $stationId,
                        $serviceDay->format('Y-m-d'),
                        $serviceDay->getTimezone()->getName(),
                        $serviceDay,
                        $serviceDay->modify('+1 day'),
                        [],
                        true,
                    );
                }

                public function journey(VehicleJourneyReference $reference): ?JourneySnapshot
                {
                    unset($reference);
                    $this->journeyCalls++;
                    throw new \LogicException('Journey Planner must not be called for a non-passenger movement.');
                }
            };
            $positions = new class($operational) implements VehiclePositionsInterface {
                public function __construct(private readonly VehicleState $operational)
                {
                }

                public function current(): array
                {
                    return [$this->operational];
                }

                public function nearby(Coordinate $center, float $radiusKm = 5.0, int $limit = 20): array
                {
                    unset($center, $radiusKm, $limit);

                    return [$this->operational];
                }

                public function stationVehicles(Coordinate $center, array $journeys, float $radiusKm = 5.0, int $nearbyLimit = 20): StationVehiclePositions
                {
                    unset($center, $journeys, $radiusKm, $nearbyLimit);

                    return new StationVehiclePositions([$this->operational], []);
                }

                public function vehicle(string $vehicleId): ?VehicleState
                {
                    return $vehicleId === $this->operational->id ? $this->operational : null;
                }
            };
            $scenarios = new MutableScenarioProvider();
            $collector = new DemandDrivenCollector(
                $journeys,
                $positions,
                $repositories->stations,
                $repositories->stationSnapshots,
                $repositories->currentVehicles,
                $repositories->vehicleObservations,
                $repositories->journeySnapshots,
                $scenarios,
            );
            $now = new DateTimeImmutable('2026-07-12T01:54:00Z');
            $collector->refresh(new Watch(
                'operational-focus',
                WatchType::Focus,
                'vehicle:' . $operational->id,
                $operational->id,
                1,
                WatchPriority::Focus,
                null,
                $now,
                $now->modify('+1 minute'),
                WatchState::Active,
            ));

            self::assertSame(0, $journeys->journeyCalls);
            $stored = $repositories->currentVehicles->find($operational->id);
            self::assertNotNull($stored);
            self::assertSame(VehiclePassengerServiceState::NonPassenger, $stored->passengerServiceState);
            self::assertSame($operational->coordinate?->latitude, $stored->coordinate?->latitude);
            self::assertNull($repositories->journeySnapshots->find('21255797_200969', '2026-07-11'));
        } finally {
            $connection->close();
        }
    }

    public function testPartialStationSourceFailurePreservesDataAndBacksOffTheWatch(): void
    {
        [$factory] = $this->database('entur_partial_station_failure');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);
        try {
            $station = FixtureFactory::stations()[0];
            $repositories->stations->save($station);
            $journeys = new class implements JourneyPlannerInterface {
                private int $attempt = 0;

                public function departures(string $stationId, int $limit = 20): array
                {
                    return array_slice(FixtureFactory::departures($stationId), 0, $limit);
                }

                public function stationBoard(string $stationId, DateTimeImmutable $now, int $limit = 20): StationBoard
                {
                    if (++$this->attempt > 1) {
                        throw new SourceUnavailable('Controlled Journey Planner failure.');
                    }

                    return new StationBoard(
                        $this->departures($stationId, $limit),
                        [],
                        $now->modify('-6 hours'),
                        $now->modify('+6 hours'),
                        0,
                        0,
                        false,
                    );
                }

                public function dailyTimetable(string $stationId, DateTimeImmutable $serviceDay): StationTimetable
                {
                    return StationTimetable::create(
                        $stationId,
                        $serviceDay->format('Y-m-d'),
                        $serviceDay->getTimezone()->getName(),
                        $serviceDay,
                        $serviceDay->modify('+1 day'),
                        $this->departures($stationId, 50),
                        true,
                    );
                }

                public function journey(VehicleJourneyReference $reference): ?JourneySnapshot
                {
                    unset($reference);

                    return null;
                }
            };
            $scenarios = new MutableScenarioProvider();
            $collector = new DemandDrivenCollector(
                $journeys,
                new FakeVehiclePositions($scenarios),
                $repositories->stations,
                $repositories->stationSnapshots,
                $repositories->currentVehicles,
                $repositories->vehicleObservations,
                $repositories->journeySnapshots,
                $scenarios,
            );
            $registry = new ActiveWatchRegistry(new SurrealWatchStore($repositories->watches), 60);
            $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $watch = $registry->acquire(
                'partial-failure-client',
                WatchType::Station,
                'station:' . $station->id,
                $station->id,
                WatchPriority::Station,
                $startedAt,
            );
            $scheduler = new WatchScheduler($registry, $collector);

            $scheduler->tick($startedAt);
            $fresh = $repositories->stationSnapshots->find($station->id);
            self::assertNotNull($fresh);
            self::assertSame(SourceState::Fresh, $fresh->state);
            self::assertNotEmpty($fresh->departures);
            self::assertNotEmpty($fresh->nearbyVehicles);
            $freshDepartureIds = array_map(static fn($departure): string => $departure->id, $fresh->departures);
            $lastSuccessfulAt = $fresh->lastSuccessfulAt;

            $failedAt = $startedAt->add(new DateInterval('PT15S'));
            $scheduler->tick($failedAt);

            $degraded = $repositories->stationSnapshots->find($station->id);
            self::assertNotNull($degraded);
            self::assertSame(SourceState::Stale, $degraded->state);
            self::assertSame($freshDepartureIds, array_map(static fn($departure): string => $departure->id, $degraded->departures));
            self::assertNotEmpty($degraded->nearbyVehicles, 'The independent Vehicle Positions result must survive a Journey Planner failure.');
            self::assertSame(
                $lastSuccessfulAt?->format(DateTimeInterface::RFC3339_EXTENDED),
                $degraded->lastSuccessfulAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            );
            self::assertSame(
                'Departures could not be refreshed; showing saved departure information. Nearby vehicle positions were refreshed; station-serving matches are unavailable until departures reconnect.',
                $degraded->warning,
            );

            $failedWatch = $registry->all()[0];
            self::assertSame($watch->id, $failedWatch->id);
            self::assertSame(WatchState::Backoff, $failedWatch->state);
            self::assertSame('source_unavailable', $failedWatch->lastErrorCode);
            self::assertSame(
                $failedAt->add(new DateInterval('PT15S'))->format(DateTimeInterface::RFC3339_EXTENDED),
                $failedWatch->nextRefreshAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            );
        } finally {
            $connection->close();
        }
    }

    public function testScheduledRefreshRecoversAfterEnturProcessRestartWithoutBackendRestart(): void
    {
        [$factory] = $this->database('entur_outage_recovery');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);
        $entur = ControllableEnturServer::start();

        try {
            $entur->setAvailable(true);
            $station = FixtureFactory::stations()[0];
            $repositories->stations->save($station);

            $telemetry = new RealtimeTelemetry('real');
            $limits = array_fill_keys(
                array_map(static fn(EnturService $service): string => $service->value, EnturService::cases()),
                10,
            );
            $client = new EnturApiClient(
                new AmpTransport(),
                new RepositoryRequestBudget($repositories->enturBudgets, 20, $limits),
                new RepositoryEnturRequestObserver(
                    $repositories->enturRequestLogs,
                    static fn(EnturRequestLog $entry) => $telemetry->sourceOutcome($entry->outcome, $entry->retryAt),
                ),
                'martinkavik-fjordpulse-recovery-test',
            );
            $collector = new DemandDrivenCollector(
                new RealJourneyPlanner($client, new JourneyPlannerMapper(), $entur->endpoint()),
                new RealVehiclePositions($client, new VehicleMapper(), $entur->endpoint()),
                $repositories->stations,
                $repositories->stationSnapshots,
                $repositories->currentVehicles,
                $repositories->vehicleObservations,
                $repositories->journeySnapshots,
                new MutableScenarioProvider(),
            );
            $registry = new ActiveWatchRegistry(new SurrealWatchStore($repositories->watches), 60);
            $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $watch = $registry->acquire(
                'recovery-client',
                WatchType::Station,
                'station:' . $station->id,
                $station->id,
                WatchPriority::Station,
                $startedAt,
            );
            $scheduler = new WatchScheduler(
                $registry,
                $collector,
                onFailure: static function (Watch $failedWatch, Throwable $error) use ($telemetry): void {
                    unset($failedWatch, $error);
                    $telemetry->sourceBackoff(new DateTimeImmutable('+15 seconds'));
                },
            );

            $scheduler->tick($startedAt);

            $initialWatch = $registry->all()[0];
            self::assertSame($watch->id, $initialWatch->id);
            self::assertSame(WatchState::Active, $initialWatch->state);
            self::assertNull($initialWatch->lastErrorCode);
            self::assertSame('ok', $telemetry->enturState());
            self::assertCount(2, $entur->requests());
            $fresh = $repositories->stationSnapshots->find($station->id);
            self::assertNotNull($fresh);
            self::assertSame(SourceState::Fresh, $fresh->state);
            self::assertSame('ENT:ServiceJourney:recovered', $fresh->departures[0]->id ?? null);
            self::assertNotNull($fresh->lastSuccessfulAt);
            $freshDepartureIds = array_map(static fn($departure): string => $departure->id, $fresh->departures);
            $lastSuccessfulAt = $fresh->lastSuccessfulAt;

            $endpoint = $entur->endpoint();
            $entur->stopServing();
            usleep(2_000);
            $scheduler->tick($startedAt->add(new DateInterval('PT15S')));

            $failedWatch = $registry->all()[0];
            self::assertSame(WatchState::Backoff, $failedWatch->state);
            self::assertSame('source_unavailable', $failedWatch->lastErrorCode);
            self::assertNotNull($failedWatch->nextRefreshAt);
            self::assertGreaterThanOrEqual(
                $startedAt->add(new DateInterval('PT30S')),
                $failedWatch->nextRefreshAt,
                'The 15-second source delay must begin after the failed transport attempt completes.',
            );
            self::assertSame('backoff', $telemetry->enturState());
            self::assertCount(2, $entur->requests(), 'A stopped upstream cannot receive the failed request.');

            $degraded = $repositories->stationSnapshots->find($station->id);
            self::assertNotNull($degraded);
            self::assertSame(SourceState::Stale, $degraded->state);
            self::assertSame(
                $lastSuccessfulAt->format(DateTimeInterface::RFC3339_EXTENDED),
                $degraded->lastSuccessfulAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            );
            self::assertSame(
                $freshDepartureIds,
                array_map(static fn($departure): string => $departure->id, $degraded->departures),
            );
            self::assertSame(
                'Departures could not be refreshed; showing saved departure information. Station vehicle positions are temporarily unavailable.',
                $degraded->warning,
            );

            $scheduler->tick($failedWatch->nextRefreshAt->sub(new DateInterval('PT1S')));
            self::assertCount(2, $entur->requests(), 'Backoff must not hammer the unavailable Entur boundary.');
            self::assertCount(4, $repositories->enturRequestLogs->recent());

            $entur->restart();
            self::assertSame($endpoint, $entur->endpoint(), 'Entur must return on the same endpoint.');
            usleep(2_000);
            $scheduler->tick($failedWatch->nextRefreshAt);

            $recoveredWatch = $registry->all()[0];
            self::assertSame(WatchState::Active, $recoveredWatch->state);
            self::assertNull($recoveredWatch->lastErrorCode);
            self::assertSame('ok', $telemetry->enturState());
            self::assertCount(4, $entur->requests());
            self::assertSame(
                array_fill(0, 4, 'martinkavik-fjordpulse-recovery-test'),
                array_column($entur->requests(), 'clientName'),
            );

            $recovered = $repositories->stationSnapshots->find($station->id);
            self::assertNotNull($recovered);
            self::assertSame(SourceState::Fresh, $recovered->state);
            self::assertNull($recovered->warning);
            self::assertSame('ENT:ServiceJourney:recovered', $recovered->departures[0]->id ?? null);
            self::assertGreaterThan($lastSuccessfulAt, $recovered->lastSuccessfulAt);

            $logs = array_reverse($repositories->enturRequestLogs->recent());
            self::assertSame(
                ['success', 'success', 'error', 'error', 'success', 'success'],
                array_map(static fn(EnturRequestLog $entry): string => $entry->outcome, $logs),
            );
            self::assertSame(
                ['journey_planner', 'vehicle_positions', 'journey_planner', 'vehicle_positions', 'journey_planner', 'vehicle_positions'],
                array_map(static fn(EnturRequestLog $entry): string => $entry->service, $logs),
            );
        } finally {
            $entur->stop();
            $connection->close();
        }
    }

    public function testSharedBudgetCapsExplicitRetriesWhileEnturReturnsUnavailable(): void
    {
        [$factory] = $this->database('entur_outage_budget');
        $connection = $factory->sync();
        $repositories = new SurrealRepositories($connection);
        $entur = ControllableEnturServer::start();

        try {
            $limits = array_fill_keys(
                array_map(static fn(EnturService $service): string => $service->value, EnturService::cases()),
                2,
            );
            $client = new EnturApiClient(
                new AmpTransport(),
                new RepositoryRequestBudget($repositories->enturBudgets, 2, $limits),
                new RepositoryEnturRequestObserver($repositories->enturRequestLogs),
                'martinkavik-fjordpulse-recovery-test',
            );
            $journeys = new RealJourneyPlanner($client, new JourneyPlannerMapper(), $entur->endpoint());

            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $journeys->departures('NSR:StopPlace:36025');
                    self::fail('The controlled Entur outage must reject the first two explicit attempts.');
                } catch (SourceUnavailable $error) {
                    self::assertStringContainsString('HTTP 503', $error->getMessage());
                }
            }
            try {
                $journeys->departures('NSR:StopPlace:36025');
                self::fail('The shared budget must stop a third explicit upstream attempt.');
            } catch (RateLimited $error) {
                self::assertGreaterThan(new DateTimeImmutable(), $error->retryAt);
            }

            self::assertCount(2, $entur->requests());
            $logs = $repositories->enturRequestLogs->recent();
            self::assertCount(3, $logs);
            self::assertEquals(
                ['error' => 2, 'skipped_budget' => 1],
                array_count_values(array_map(static fn(EnturRequestLog $entry): string => $entry->outcome, $logs)),
            );
            $budgetSkip = array_values(array_filter(
                $logs,
                static fn(EnturRequestLog $entry): bool => $entry->outcome === 'skipped_budget',
            ));
            self::assertNotNull($budgetSkip[0]->retryAt ?? null);
            self::assertSame('internal_budget', $budgetSkip[0]->errorCode ?? null);
        } finally {
            $entur->stop();
            $connection->close();
        }
    }
}
