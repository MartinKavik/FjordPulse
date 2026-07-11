<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Domain\EnturService;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Fake\FixtureFactory;
use FjordPulse\Entur\Http\AmpTransport;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Entur\MutableScenarioProvider;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\Real\RealJourneyPlanner;
use FjordPulse\Entur\Real\RealVehiclePositions;
use FjordPulse\Entur\RepositoryRequestBudget;
use FjordPulse\Entur\SourceUnavailable;
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
                new RepositoryRequestBudget($repositories->enturRequestLogs, 20, $limits),
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
            self::assertSame(
                $startedAt->add(new DateInterval('PT30S'))->format(DateTimeInterface::RFC3339_EXTENDED),
                $failedWatch->nextRefreshAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            );
            self::assertSame('backoff', $telemetry->enturState());
            self::assertCount(2, $entur->requests(), 'A stopped upstream cannot receive the failed request.');

            $degraded = $repositories->stationSnapshots->find($station->id);
            self::assertNotNull($degraded);
            self::assertSame(SourceState::Error, $degraded->state);
            self::assertSame(
                $lastSuccessfulAt->format(DateTimeInterface::RFC3339_EXTENDED),
                $degraded->lastSuccessfulAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            );
            self::assertSame(
                $freshDepartureIds,
                array_map(static fn($departure): string => $departure->id, $degraded->departures),
            );
            self::assertMatchesRegularExpression(
                '/^Entur journey_planner (?:request failed|returned HTTP 503)\.$/',
                $degraded->warning ?? '',
            );

            $scheduler->tick($startedAt->add(new DateInterval('PT29S')));
            self::assertCount(2, $entur->requests(), 'Backoff must not hammer the unavailable Entur boundary.');
            self::assertCount(3, $repositories->enturRequestLogs->recent());

            $entur->restart();
            self::assertSame($endpoint, $entur->endpoint(), 'Entur must return on the same endpoint.');
            usleep(2_000);
            $scheduler->tick($startedAt->add(new DateInterval('PT30S')));

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
                ['success', 'success', 'error', 'success', 'success'],
                array_map(static fn(EnturRequestLog $entry): string => $entry->outcome, $logs),
            );
            self::assertSame(
                ['journey_planner', 'vehicle_positions', 'journey_planner', 'journey_planner', 'vehicle_positions'],
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
                new RepositoryRequestBudget($repositories->enturRequestLogs, 2, $limits),
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
