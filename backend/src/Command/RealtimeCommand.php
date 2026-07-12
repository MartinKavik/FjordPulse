<?php

declare(strict_types=1);

namespace FjordPulse\Command;

use DateTimeInterface;
use Cake\Command\Command;
use Cake\Console\ConsoleOptionParser;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Domain\EnturService;
use FjordPulse\Domain\Scenario;
use FjordPulse\Domain\VehicleFreshnessPolicy;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Fake\FakeJourneyPlanner;
use FjordPulse\Entur\Fake\FakeVehiclePositions;
use FjordPulse\Entur\Http\AmpTransport;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\ScenarioProviderInterface;
use FjordPulse\Entur\Real\RealJourneyPlanner;
use FjordPulse\Entur\Real\RealVehiclePositions;
use FjordPulse\Entur\RepositoryRequestBudget;
use FjordPulse\Entur\VehiclePositionsInterface;
use FjordPulse\Realtime\ActiveWatchRegistry;
use FjordPulse\Realtime\DemandDrivenCollector;
use FjordPulse\Realtime\JsonLineLogger;
use FjordPulse\Realtime\ProtocolDecoder;
use FjordPulse\Realtime\ProtocolRouter;
use FjordPulse\Realtime\RealtimeService;
use FjordPulse\Realtime\RealtimeServiceConfig;
use FjordPulse\Realtime\RealtimeTelemetry;
use FjordPulse\Realtime\RepositoryEnturRequestObserver;
use FjordPulse\Realtime\RoomEventSink;
use FjordPulse\Realtime\RoomRegistry;
use FjordPulse\Realtime\SignedRealtimeTokenVerifier;
use FjordPulse\Realtime\SingleProcessLock;
use FjordPulse\Realtime\SurrealSnapshotProvider;
use FjordPulse\Realtime\SurrealScenarioProvider;
use FjordPulse\Realtime\SurrealWatchStore;
use FjordPulse\Realtime\WatchScheduler;
use FjordPulse\Security\SignedToken;
use FjordPulse\Surreal\SdkSurrealConnectionFactory;
use FjordPulse\Surreal\SupervisedLiveQueryBridge;
use FjordPulse\Surreal\SurrealRepositories;
use FjordPulse\Surreal\SystemStatus;

final class RealtimeCommand extends Command
{
    public static function getDescription(): string
    {
        return 'Run FjordPulse\'s single-replica AMPHP/Revolt realtime service.';
    }

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->addArgument('action', [
                'required' => true,
                'choices' => ['start'],
                'help' => 'Realtime service action.',
            ])
            ->addOption('host', [
                'default' => self::env('REALTIME_HOST', '127.0.0.1'),
                'help' => 'Address to bind.',
            ])
            ->addOption('port', [
                'default' => self::env('REALTIME_PORT', '8081'),
                'help' => 'Port to bind.',
            ])
            ->addOption('shutdown-file', [
                'default' => null,
                'help' => 'Optional supervisor-owned file whose creation requests graceful shutdown.',
            ]);
    }

    public function execute(): int
    {
        if ($this->args->getArgument('action') !== 'start') {
            $this->io->err('Only `bin/cake realtime start` is supported.');

            return self::CODE_ERROR;
        }
        $config = RuntimeConfig::fromEnvironment();
        $host = (string)$this->args->getOption('host');
        $portValue = (string)$this->args->getOption('port');
        $shutdownFileValue = $this->args->getOption('shutdown-file');
        $shutdownFile = is_string($shutdownFileValue) && $shutdownFileValue !== '' ? $shutdownFileValue : null;
        if (!ctype_digit($portValue) || (int)$portValue < 1 || (int)$portValue > 65_535) {
            $this->io->err('Realtime port must be between 1 and 65535.');

            return self::CODE_ERROR;
        }

        $lock = new SingleProcessLock(sys_get_temp_dir() . '/fjordpulse-realtime.lock');
        $lock->acquire();
        $logger = new JsonLineLogger();
        $factory = new SdkSurrealConnectionFactory($config->surreal);
        $commandConnection = $factory->ampCommand();
        $repositories = new SurrealRepositories($commandConnection);

        try {
            $scenarios = new SurrealScenarioProvider($repositories->systemStatus, $config->defaultScenario);
            $telemetry = new RealtimeTelemetry($config->dataMode);
            [$journeys, $vehicles] = self::sourceAdapters($config, $repositories, $scenarios, $telemetry);
            $rooms = new RoomRegistry($telemetry, $logger);
            $activeWatches = new ActiveWatchRegistry(new SurrealWatchStore($repositories->watches), $config->watchTtlSeconds);
            $snapshots = new SurrealSnapshotProvider(
                $repositories->stationSnapshots,
                $repositories->currentVehicles,
                $repositories->vehicleObservations,
                $repositories->journeySnapshots,
            );
            $router = new ProtocolRouter(
                new ProtocolDecoder(),
                $rooms,
                $activeWatches,
                $snapshots,
                $telemetry,
                $logger,
            );
            $collector = new DemandDrivenCollector(
                $journeys,
                $vehicles,
                $repositories->stations,
                $repositories->stationSnapshots,
                $repositories->currentVehicles,
                $repositories->vehicleObservations,
                $repositories->journeySnapshots,
                $scenarios,
                $config->observationRetentionHours,
                vehicleFreshness: new VehicleFreshnessPolicy(
                    $config->vehicleStaleSeconds,
                    $config->vehicleLostSeconds,
                ),
            );
            $scheduler = new WatchScheduler(
                $activeWatches,
                $collector,
                $logger,
                static function (Watch $watch, \Throwable $error) use ($router, $telemetry): void {
                    $retryAt = $error instanceof RateLimited
                        ? $error->retryAt
                        : new \DateTimeImmutable('+' . WatchScheduler::SOURCE_RETRY_SECONDS . ' seconds');
                    $router->sourceBackoff(
                        $watch,
                        $error->getMessage() !== '' ? $error->getMessage() : 'Source refresh failed.',
                        $retryAt->format(DateTimeInterface::RFC3339_EXTENDED),
                    );
                    $telemetry->sourceBackoff($retryAt);
                },
            );
            $bridge = new SupervisedLiveQueryBridge($factory, $logger);
            $eventSink = new RoomEventSink($rooms, $logger);
            $forcedScenarioDegraded = false;
            $service = new RealtimeService(
                new RealtimeServiceConfig($host, (int)$portValue, $config->allowedOrigins),
                $rooms,
                $router,
                $telemetry,
                $scheduler,
                $bridge,
                $eventSink,
                new SignedRealtimeTokenVerifier(new SignedToken($config->adminSessionSecret)),
                $logger,
                static function (array $health) use ($repositories, $scenarios, $router, &$forcedScenarioDegraded): void {
                    $scenario = $scenarios->current();
                    $forced = in_array($scenario, [Scenario::Fallback, Scenario::RealtimeReconnect], true);
                    if ($forced) {
                        $router->bridgeDegraded(
                            $scenario === Scenario::Fallback
                                ? 'Deterministic fallback scenario is active.'
                                : 'Deterministic reconnect scenario is active.',
                            $scenario === Scenario::Fallback ? 'degraded' : 'reconnecting',
                        );
                        $health['status'] = 'degraded';
                    } elseif ($forcedScenarioDegraded) {
                        $router->bridgeRecovered();
                    }
                    $forcedScenarioDegraded = $forced;
                    $state = is_string($health['status'] ?? null) ? $health['status'] : 'unknown';
                    $checkedAt = new \DateTimeImmutable();
                    $repositories->systemStatus->save(new SystemStatus(
                        'realtime',
                        $state,
                        $state === 'healthy' ? 'Realtime service and live-query bridge are healthy.' : 'Realtime service is degraded.',
                        $checkedAt,
                        null,
                        $health,
                    ));
                    $bridge = is_array($health['bridge'] ?? null) ? $health['bridge'] : [];
                    $bridgeRuntimeState = is_string($bridge['state'] ?? null) ? $bridge['state'] : 'unknown';
                    $bridgeState = $forced ? 'degraded' : ($bridgeRuntimeState === 'healthy' ? 'healthy' : 'degraded');
                    $lastError = is_string($bridge['lastError'] ?? null) ? $bridge['lastError'] : null;
                    $repositories->systemStatus->save(new SystemStatus(
                        'live_query_bridge',
                        $bridgeState,
                        $bridgeState === 'healthy'
                            ? 'SurrealDB live-query bridge is subscribed and receiving database events.'
                            : ($lastError ?? 'SurrealDB live-query bridge is not healthy; HTTP polling fallback is active.'),
                        $checkedAt,
                        null,
                        [
                            'runtimeState' => $bridgeRuntimeState,
                            'queryId' => is_string($bridge['queryId'] ?? null) ? $bridge['queryId'] : null,
                            'connectedAt' => is_string($bridge['connectedAt'] ?? null) ? $bridge['connectedAt'] : null,
                            'lastEventAt' => is_string($bridge['lastEventAt'] ?? null) ? $bridge['lastEventAt'] : null,
                            'lastErrorAt' => is_string($bridge['lastErrorAt'] ?? null) ? $bridge['lastErrorAt'] : null,
                            'failureCount' => is_int($bridge['failureCount'] ?? null) ? $bridge['failureCount'] : 0,
                            'subscriptionCount' => is_int($bridge['subscriptionCount'] ?? null) ? $bridge['subscriptionCount'] : 0,
                        ],
                    ));
                },
            );

            $this->io->out(sprintf('Starting FjordPulse realtime on %s:%d (one v1 replica).', $host, (int)$portValue));
            $service->runUntilSignal($shutdownFile);

            return self::CODE_SUCCESS;
        } finally {
            $commandConnection->close();
            $lock->release();
        }
    }

    /**
     * @return array{JourneyPlannerInterface, VehiclePositionsInterface}
     */
    private static function sourceAdapters(
        RuntimeConfig $config,
        SurrealRepositories $repositories,
        ScenarioProviderInterface $scenarios,
        RealtimeTelemetry $telemetry,
    ): array {
        if ($config->dataMode === 'fake') {
            return [new FakeJourneyPlanner($scenarios), new FakeVehiclePositions($scenarios)];
        }
        $limits = [
            EnturService::StopPlaceRegister->value => self::positiveEnv('ENTUR_STOP_PLACE_REQUESTS_PER_MINUTE', 5),
            EnturService::Geocoder->value => self::positiveEnv('ENTUR_GEOCODER_REQUESTS_PER_MINUTE', 20),
            EnturService::JourneyPlanner->value => self::positiveEnv('ENTUR_JOURNEY_REQUESTS_PER_MINUTE', 30),
            EnturService::VehiclePositions->value => self::positiveEnv('ENTUR_VEHICLE_REQUESTS_PER_MINUTE', 30),
        ];
        $client = new EnturApiClient(
            new AmpTransport(),
            new RepositoryRequestBudget(
                $repositories->enturRequestLogs,
                self::positiveEnv('ENTUR_GLOBAL_REQUESTS_PER_MINUTE', 60),
                $limits,
            ),
            new RepositoryEnturRequestObserver(
                $repositories->enturRequestLogs,
                static function (EnturRequestLog $entry) use ($telemetry): void {
                    $telemetry->sourceOutcome($entry->outcome, $entry->retryAt);
                },
            ),
            $config->enturClientName,
        );

        return [
            new RealJourneyPlanner($client, new JourneyPlannerMapper(), $config->enturJourneyPlannerUrl),
            new RealVehiclePositions(
                $client,
                new VehicleMapper($config->vehicleStaleSeconds, $config->vehicleLostSeconds),
                $config->enturVehiclePositionsUrl,
            ),
        ];
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function positiveEnv(string $name, int $default): int
    {
        $value = self::env($name, (string)$default);
        if (!ctype_digit($value) || (int)$value < 1) {
            throw new \InvalidArgumentException("{$name} must be a positive integer.");
        }

        return (int)$value;
    }
}
