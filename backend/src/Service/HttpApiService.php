<?php

declare(strict_types=1);

namespace FjordPulse\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Domain\EnturService;
use FjordPulse\Domain\Scenario;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\BoundingBox;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\RealtimeEvent;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\GeocoderInterface;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\MutableScenarioProvider;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\RequestBudgetInterface;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Entur\StationRegistryInterface;
use FjordPulse\Entur\VehiclePositionsInterface;
use FjordPulse\Surreal\SurrealRepositories;
use FjordPulse\Surreal\SystemStatus;
use FjordPulse\Surreal\Migration;

final readonly class HttpApiService
{
    public function __construct(
        private RuntimeConfig $config,
        private SurrealRepositories $repositories,
        private MutableScenarioProvider $scenarios,
        private StationRegistryInterface $stationRegistry,
        private GeocoderInterface $geocoder,
        private JourneyPlannerInterface $journeys,
        private VehiclePositionsInterface $vehicles,
        private RequestBudgetInterface $budget,
        private StationClusterer $clusterer,
    ) {
    }

    /** @return array<string, mixed> */
    public function stationMap(BoundingBox $bounds, float $zoom): array
    {
        $this->ensureStations();
        $stations = $this->repositories->stations->withinBounds(
            $bounds->minLongitude,
            $bounds->minLatitude,
            $bounds->maxLongitude,
            $bounds->maxLatitude,
        );

        return [
            'bounds' => [
                'minLongitude' => $bounds->minLongitude,
                'minLatitude' => $bounds->minLatitude,
                'maxLongitude' => $bounds->maxLongitude,
                'maxLatitude' => $bounds->maxLatitude,
            ],
            'zoom' => $zoom,
            'dataSource' => 'surrealdb',
            'items' => $this->clusterer->items($stations, $zoom),
        ];
    }

    /** @return array<string, mixed> */
    public function search(string $query, int $limit): array
    {
        $this->ensureStations();
        $stations = $this->repositories->stations->search($query, $limit);
        $geocoded = [];
        try {
            $geocoded = $this->geocoder->search($query, $limit);
        } catch (RateLimited | SourceUnavailable) {
            // Local authoritative search stays available during upstream degradation.
        }

        $canonicalGeocoded = array_values(array_filter(
            $geocoded,
            static fn(Station $station): bool => str_starts_with($station->id, 'NSR:StopPlace:'),
        ));
        $this->repositories->stations->saveMany(
            $canonicalGeocoded,
            $this->config->dataMode === 'fake' ? 'fake' : 'entur_geocoder',
        );

        if ($this->config->dataMode === 'fake') {
            $known = $this->repositories->currentVehicles->search($query, $limit);
            if ($known === []) {
                $anchor = $this->repositories->stations->withinBounds(-180.0, -90.0, 180.0, 90.0, 1)[0] ?? null;
                if ($anchor !== null) {
                    foreach ($this->vehicles->nearby($anchor->coordinate) as $vehicle) {
                        $this->persistVehicle($vehicle);
                    }
                }
            }
        }
        $vehicles = $this->repositories->currentVehicles->search($query, $limit);

        $results = [];
        $seen = [];
        $append = static function (array $row) use (&$results, &$seen, $limit): void {
            $type = $row['type'] ?? null;
            $id = $row['id'] ?? null;
            if (!is_string($type) || !is_string($id)) {
                throw new \LogicException('Search results require string type and id fields.');
            }
            $key = $type . ':' . $id;
            if (isset($seen[$key]) || count($results) >= $limit) {
                return;
            }
            $seen[$key] = true;
            $results[] = $row;
        };

        foreach ([...$stations, ...$canonicalGeocoded] as $station) {
            $append(self::stationSearchResult($station));
        }
        foreach ($geocoded as $place) {
            if (str_starts_with($place->id, 'NSR:StopPlace:')) {
                continue;
            }
            $nearest = $this->repositories->stations->nearest(
                $place->coordinate->latitude,
                $place->coordinate->longitude,
            );
            $append([
                'type' => 'place',
                'id' => $place->id,
                'label' => $place->name,
                'secondaryText' => $place->locality ?? $place->municipality,
                'stationId' => $nearest?->id,
                'lineCode' => null,
                'latitude' => $place->coordinate->latitude,
                'longitude' => $place->coordinate->longitude,
            ]);
        }

        $lines = [];
        foreach ($vehicles as $vehicle) {
            if ($vehicle->lineCode !== null && !isset($lines[$vehicle->lineCode])) {
                $lines[$vehicle->lineCode] = true;
                $append([
                    'type' => 'line',
                    'id' => 'line:' . $vehicle->lineCode,
                    'label' => 'Line ' . $vehicle->lineCode,
                    'secondaryText' => $vehicle->routeName ?? $vehicle->destination,
                    'stationId' => null,
                    'lineCode' => $vehicle->lineCode,
                    'latitude' => $vehicle->coordinate?->latitude,
                    'longitude' => $vehicle->coordinate?->longitude,
                ]);
            }
            $append([
                'type' => 'vehicle',
                'id' => $vehicle->id,
                'label' => 'Vehicle ' . $vehicle->id,
                'secondaryText' => implode(' · ', array_filter([
                    $vehicle->lineCode === null ? null : 'Line ' . $vehicle->lineCode,
                    $vehicle->destination,
                ])),
                'stationId' => null,
                'lineCode' => $vehicle->lineCode,
                'latitude' => $vehicle->coordinate?->latitude,
                'longitude' => $vehicle->coordinate?->longitude,
            ]);
        }

        return [
            'query' => $query,
            'results' => $results,
        ];
    }

    /** @return array<string, mixed> */
    private static function stationSearchResult(Station $station): array
    {
        return [
            'type' => 'station',
            'id' => $station->id,
            'label' => $station->name,
            'secondaryText' => $station->locality,
            'stationId' => $station->id,
            'lineCode' => null,
            'latitude' => $station->coordinate->latitude,
            'longitude' => $station->coordinate->longitude,
        ];
    }

    /** @return array<string, mixed>|null */
    public function station(string $stationId, bool $refresh = false): ?array
    {
        $this->ensureStations();
        $station = $this->repositories->stations->find($stationId);
        if ($station === null) {
            return null;
        }
        $snapshot = $this->repositories->stationSnapshots->find($stationId);
        if ($refresh || $snapshot === null || !self::isFresh($snapshot->updatedAt, $this->config->stationFreshSeconds)) {
            $snapshot = $this->refreshStation($station, $snapshot);
        } else {
            $this->recordCacheHit('journey_planner', 'station:' . $stationId, count($snapshot->departures));
            $this->recordCacheHit('vehicle_positions', 'station:' . $stationId, count($snapshot->nearbyVehicles));
        }

        return ['station' => $station->toArray(), 'snapshot' => $snapshot->toArray()];
    }

    /** @return array<string, mixed>|null */
    public function departures(string $stationId): ?array
    {
        $data = $this->station($stationId);
        if ($data === null || !is_array($data['snapshot'] ?? null)) {
            return null;
        }
        $snapshot = $data['snapshot'];

        return [
            'stationId' => $snapshot['stationId'],
            'state' => $snapshot['state'],
            'version' => $snapshot['version'],
            'updatedAt' => $snapshot['updatedAt'],
            'lastSuccessfulAt' => $snapshot['lastSuccessfulAt'],
            'warning' => $snapshot['warning'],
            'departures' => $snapshot['departures'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function nearbyVehicles(string $stationId): ?array
    {
        $data = $this->station($stationId);
        if ($data === null || !is_array($data['snapshot'] ?? null)) {
            return null;
        }
        $snapshot = $data['snapshot'];

        return [
            'stationId' => $snapshot['stationId'],
            'state' => $snapshot['state'],
            'version' => $snapshot['version'],
            'updatedAt' => $snapshot['updatedAt'],
            'lastSuccessfulAt' => $snapshot['lastSuccessfulAt'],
            'warning' => $snapshot['warning'],
            'vehicles' => $snapshot['nearbyVehicles'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function vehicle(string $vehicleId, bool $refresh = false): ?array
    {
        $existing = $this->repositories->currentVehicles->find($vehicleId);
        $vehicle = $existing;
        if ($existing === null || $refresh || !self::isFresh($existing->updatedAt, $this->config->vehicleFreshSeconds)) {
            $sourceVehicle = $this->vehicles->vehicle($vehicleId);
            if ($sourceVehicle !== null) {
                $vehicle = $this->persistVehicle($sourceVehicle);
            } elseif ($existing !== null) {
                $vehicle = $this->persistVehicle($this->lostVehicle($existing));
            }
        } elseif ($existing !== null) {
            $this->recordCacheHit('vehicle_positions', 'vehicle:' . $vehicleId, 1);
        }
        if ($vehicle === null) {
            return null;
        }
        $trail = $this->repositories->vehicleObservations->recent($vehicleId, 100);
        $upcomingStops = $vehicle->nextStop === null ? [] : [$vehicle->nextStop->toArray()];

        return [
            'vehicle' => $vehicle->toArray(),
            'trail' => array_map(static fn(VehicleObservation $observation): array => $observation->toArray(), $trail),
            'upcomingStops' => $upcomingStops,
        ];
    }

    private function lostVehicle(VehicleState $existing): VehicleState
    {
        $now = new DateTimeImmutable();
        $semantic = [
            'id' => $existing->id,
            'state' => 'lost',
            'latitude' => $existing->coordinate?->latitude,
            'longitude' => $existing->coordinate?->longitude,
            'lastSeenAt' => $existing->lastSeenAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];

        return new VehicleState(
            $existing->id,
            $now->format('Y-m-d\\TH:i:s.v\\Z'),
            hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR)),
            \FjordPulse\Domain\VehicleFreshness::Lost,
            $existing->coordinate,
            $existing->lineCode,
            $existing->routeName,
            $existing->destination,
            $existing->bearing,
            $existing->delaySeconds,
            $existing->distanceMeters,
            $existing->lastSeenAt,
            $now,
            $existing->nextStop,
            $existing->observations,
        );
    }

    private function recordCacheHit(string $service, string $scope, int $itemCount): void
    {
        $now = new DateTimeImmutable();
        $id = 'cache_' . bin2hex(random_bytes(12));
        $this->repositories->enturRequestLogs->append(new EnturRequestLog(
            $id,
            $service,
            $scope,
            $now,
            null,
            0,
            $itemCount,
            'hit',
            'cache_hit',
            null,
            $id,
        ));
    }

    private static function isFresh(DateTimeImmutable $updatedAt, int $seconds): bool
    {
        return $updatedAt >= (new DateTimeImmutable())->sub(new DateInterval('PT' . $seconds . 'S'));
    }

    /** @return array<string, mixed> */
    public function watches(?string $type = null, ?string $state = null, ?string $scope = null, int $limit = 100): array
    {
        $watches = array_values(array_filter(
            $this->repositories->watches->all(1_000),
            static fn(Watch $watch): bool => ($type === null || $watch->type->value === $type)
                && ($state === null || $watch->state->value === $state)
                && ($scope === null || str_contains(strtolower($watch->scope), strtolower($scope))),
        ));
        $watches = array_slice($watches, 0, $limit);
        $now = new DateTimeImmutable();

        return [
            'summary' => [
                'total' => count($watches),
                'focus' => count(array_filter($watches, static fn(Watch $watch): bool => $watch->type->value === 'focus')),
                'expiringSoon' => count(array_filter($watches, static fn(Watch $watch): bool => $watch->expiresAt <= $now->add(new DateInterval('PT30S')))),
                'failed' => count(array_filter($watches, static fn(Watch $watch): bool => $watch->state->value === 'failed')),
            ],
            'watches' => array_map(static fn(Watch $watch): array => $watch->toArray(), $watches),
        ];
    }

    /** @return array<string, mixed> */
    public function enturLog(
        ?string $service = null,
        ?string $outcome = null,
        ?string $scope = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $limit = 100,
    ): array
    {
        $entries = array_values(array_filter(
            $this->repositories->enturRequestLogs->recent($service, 1_000),
            static fn(EnturRequestLog $entry): bool => ($outcome === null || $entry->outcome === $outcome)
                && ($scope === null || str_contains(strtolower($entry->scope), strtolower($scope)))
                && ($from === null || $entry->requestedAt >= $from)
                && ($to === null || $entry->requestedAt <= $to),
        ));
        $entries = array_slice($entries, 0, $limit);
        $latencies = array_map(static fn(EnturRequestLog $entry): int => $entry->latencyMs, $entries);
        sort($latencies);
        $p95Index = $latencies === [] ? null : (int)floor((count($latencies) - 1) * 0.95);
        $cacheHits = count(array_filter($entries, static fn(EnturRequestLog $entry): bool => $entry->cache === 'hit'));

        return [
            'metrics' => [
                'requestsPerMinute' => count(array_filter($entries, static fn(EnturRequestLog $entry): bool => $entry->requestedAt > (new DateTimeImmutable())->sub(new DateInterval('PT60S')))),
                'cacheHitRate' => $entries === [] ? 0.0 : $cacheHits / count($entries),
                'p95LatencyMs' => $p95Index === null ? 0.0 : (float)$latencies[$p95Index],
                'inBackoff' => count(array_filter($entries, static fn(EnturRequestLog $entry): bool => $entry->outcome === 'backoff' || $entry->outcome === 'rate_limited')) > 0,
            ],
            'entries' => array_map(static fn(EnturRequestLog $entry): array => $entry->toArray(), $entries),
        ];
    }

    /** @return array{scenario: string, activatedAt: string} */
    public function scenario(): array
    {
        $status = $this->repositories->systemStatus->find('dev_scenario');

        return [
            'scenario' => $this->scenarios->current()->value,
            'activatedAt' => ($status->checkedAt ?? new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    /** @return array{scenario: string, activatedAt: string} */
    public function selectScenario(Scenario $scenario): array
    {
        if (!$this->config->isDevelopmentLike()) {
            throw new \LogicException('Development scenarios are disabled.');
        }
        $this->scenarios->select($scenario);
        $now = new DateTimeImmutable();
        $this->repositories->systemStatus->save(new SystemStatus(
            'dev_scenario',
            'healthy',
            'Deterministic development scenario selected.',
            $now,
            null,
            ['scenario' => $scenario->value],
        ));

        return ['scenario' => $scenario->value, 'activatedAt' => $now->format(DateTimeInterface::RFC3339_EXTENDED)];
    }

    /** @return array<string, array{limit: int, remaining: int}> */
    public function budgetStatus(): array
    {
        return $this->budget->status();
    }

    /** @return array<string, mixed> */
    public function adminStatus(): array
    {
        $diagnostics = $this->repositories->diagnostics->snapshot(100);
        $health = $this->health();
        $watches = $this->repositories->watches->all(10_000);
        $realtime = $this->repositories->systemStatus->find('realtime');
        $realtimeHealth = self::object($realtime->metadata ?? []);
        $telemetry = self::object($realtimeHealth['telemetry'] ?? null);
        $services = self::object($health['dependencies'] ?? null);
        $services['backend'] = self::object($services['http'] ?? null);
        unset($services['http']);

        return [
            'build' => [
                'version' => getenv('APP_VERSION') ?: 'dev',
                'environment' => $this->config->environment,
                'dataMode' => $this->config->dataMode,
            ],
            'services' => $services,
            'metrics' => [
                'activeClients' => self::nonNegativeInt($realtimeHealth['clients'] ?? $telemetry['activeClients'] ?? null),
                'stationWatches' => count(array_filter($watches, static fn(Watch $watch): bool => $watch->type->value === 'station' && $watch->state->value !== 'expired')),
                'vehicleWatches' => count(array_filter($watches, static fn(Watch $watch): bool => $watch->type->value === 'vehicle' && $watch->state->value !== 'expired')),
                'focusWatches' => count(array_filter($watches, static fn(Watch $watch): bool => $watch->type->value === 'focus' && $watch->state->value !== 'expired')),
                'messagesPerMinute' => self::messagesPerMinute($telemetry),
                'httpP95LatencyMs' => 0.0,
            ],
            'dataCounts' => [
                'stations' => $diagnostics->stations,
                'stationSnapshots' => $diagnostics->stationSnapshots,
                'currentVehicles' => $diagnostics->currentVehicles,
                'vehicleObservations' => $diagnostics->vehicleObservations,
                'watches' => $diagnostics->watches,
                'realtimeEvents' => $diagnostics->realtimeEvents,
                'enturRequestLogs' => $diagnostics->enturRequestLogs,
            ],
            'stationImport' => [
                'count' => $diagnostics->stations,
                'lastImportedAt' => $diagnostics->lastStationImportedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
                'sourceVersion' => $diagnostics->stationSourceVersion,
            ],
            'enturBudgets' => $this->enturBudgets(),
            'recentEvents' => array_map(self::eventRow(...), $this->repositories->realtimeEvents->recent(limit: 25)),
        ];
    }

    /** @return array<string, mixed> */
    public function adminRealtime(): array
    {
        $now = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED);
        $realtime = $this->repositories->systemStatus->find('realtime');
        $bridgeStatus = $this->repositories->systemStatus->find('live_query_bridge');
        $health = self::object($realtime->metadata ?? []);
        $bridge = self::object($health['bridge'] ?? null);
        $telemetry = self::object($health['telemetry'] ?? null);
        $roomValues = $health['roomDetails'] ?? [];
        $rooms = [];
        if (is_array($roomValues)) {
            foreach ($roomValues as $room) {
                if (!is_array($room) || !is_string($room['scope'] ?? null)) {
                    continue;
                }
                $rooms[] = [
                    'scope' => $room['scope'],
                    'clientCount' => self::nonNegativeInt($room['clientCount'] ?? null),
                ];
            }
        }
        $realtimeHealthy = $realtime?->state === 'healthy';
        $bridgeHealthy = $bridgeStatus?->state === 'healthy' || ($bridge['state'] ?? null) === 'healthy';
        $realtimeCheckedAt = $realtime === null
            ? $now
            : $realtime->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED);
        $bridgeCheckedAt = $bridgeStatus === null
            ? $now
            : $bridgeStatus->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED);

        return [
            'server' => $this->serviceHealth(
                $realtimeHealthy ? 'healthy' : 'degraded',
                $realtimeCheckedAt,
                $realtime->detail ?? 'Realtime status has not reported yet.',
                $realtime?->latencyMs,
            ),
            'liveQueryBridge' => $this->serviceHealth(
                $bridgeHealthy ? 'healthy' : 'degraded',
                $bridgeCheckedAt,
                $bridgeStatus->detail ?? 'Live-query bridge status has not reported yet.',
                $bridgeStatus?->latencyMs,
            ),
            'activeClients' => self::nonNegativeInt($health['clients'] ?? $telemetry['activeClients'] ?? null),
            'rooms' => $rooms,
            'messagesPerMinute' => self::messagesPerMinute($telemetry),
            'reconnectCount' => max(
                self::nonNegativeInt($telemetry['bridgeRecoveries'] ?? null),
                max(0, self::nonNegativeInt($bridge['subscriptionCount'] ?? null) - 1),
            ),
            'failureCount' => self::nonNegativeInt($bridge['failureCount'] ?? null)
                + self::nonNegativeInt($telemetry['sendFailures'] ?? null),
            'lastBroadcastAt' => is_string($telemetry['lastBroadcastAt'] ?? null) ? $telemetry['lastBroadcastAt'] : null,
        ];
    }

    /** @return array{events: list<array<string, mixed>>} */
    public function adminEvents(?string $scope = null, ?string $type = null, int $limit = 100): array
    {
        $events = array_values(array_filter(
            $this->repositories->realtimeEvents->recent($scope, min(1_000, max($limit, 100))),
            static fn(RealtimeEvent $event): bool => $type === null || $event->type->value === $type,
        ));

        return ['events' => array_map(self::eventRow(...), array_slice($events, 0, $limit))];
    }

    /** @return array{migrations: list<array{name: string, checksum: string, state: string, appliedAt: string|null}>} */
    public function adminMigrations(): array
    {
        $applied = [];
        foreach ($this->repositories->diagnostics->snapshot(100)->recentMigrations as $migration) {
            $applied[$migration->name] = $migration;
        }
        $rows = [];
        foreach (Migration::discover(dirname(__DIR__, 2) . '/migrations') as $migration) {
            $ledger = $applied[$migration->name] ?? null;
            $rows[] = [
                'name' => $migration->name,
                'checksum' => $migration->checksum,
                'state' => $ledger === null ? 'pending' : 'applied',
                'appliedAt' => $ledger?->appliedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            ];
        }

        return ['migrations' => $rows];
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $now = new DateTimeImmutable();
        $nowString = $now->format(DateTimeInterface::RFC3339_EXTENDED);
        $realtime = $this->repositories->systemStatus->find('realtime');
        $bridge = $this->repositories->systemStatus->find('live_query_bridge');
        $recentEntur = $this->repositories->enturRequestLogs->recent(limit: 1)[0] ?? null;
        $realtimeHealthy = $realtime?->state === 'healthy' && self::recentStatus($realtime, $now, 20);
        $bridgeHealthy = $bridge?->state === 'healthy' && self::recentStatus($bridge, $now, 20);
        $enturDegraded = $this->config->dataMode === 'real' && $recentEntur !== null
            && in_array($recentEntur->outcome, ['rate_limited', 'backoff', 'timeout', 'error', 'skipped_budget'], true);
        $enturStatus = $this->config->dataMode === 'fake'
            ? 'healthy'
            : ($recentEntur === null ? 'unknown' : ($enturDegraded ? 'degraded' : 'healthy'));
        $fallback = !$realtimeHealthy || !$bridgeHealthy || $enturDegraded || $this->scenarios->current() === Scenario::Fallback;
        $realtimeCheckedAt = $realtime === null
            ? $nowString
            : $realtime->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED);
        $bridgeCheckedAt = $bridge === null
            ? $nowString
            : $bridge->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED);

        return [
            'status' => $fallback ? 'degraded' : 'healthy',
            'mode' => $fallback ? 'fallback_polling' : 'normal',
            'checkedAt' => $nowString,
            'version' => getenv('APP_VERSION') ?: 'dev',
            'fallbackAvailable' => true,
            'dependencies' => [
                'http' => $this->serviceHealth('healthy', $nowString, 'CakePHP HTTP/control plane is serving.', null),
                'realtime' => $this->serviceHealth($realtimeHealthy ? 'healthy' : 'degraded', $realtimeCheckedAt, $realtimeHealthy ? $realtime->detail : 'Realtime status is missing, degraded, or stale.', $realtime?->latencyMs),
                'surrealdb' => $this->serviceHealth('healthy', $nowString, 'Authoritative state database is reachable.', null),
                'entur' => $this->serviceHealth(
                    $enturStatus,
                    $recentEntur?->requestedAt->format(DateTimeInterface::RFC3339_EXTENDED) ?? $nowString,
                    $this->config->dataMode === 'fake'
                        ? 'Development fake adapters active.'
                        : ($recentEntur === null ? 'Entur adapters configured; no request recorded yet.' : 'Latest Entur outcome: ' . $recentEntur->outcome . '.'),
                    $recentEntur === null ? null : (float)$recentEntur->latencyMs,
                ),
                'liveQueryBridge' => $this->serviceHealth($bridgeHealthy ? 'healthy' : 'degraded', $bridgeCheckedAt, $bridgeHealthy ? $bridge->detail : 'Live-query bridge status is missing, degraded, or stale.', $bridge?->latencyMs),
            ],
        ];
    }

    /**
     * @return array{imported: int, total: int, source: string, sourceVersion: string, skipped: bool}
     */
    public function importStations(int $limit, bool $force = false): array
    {
        if ($limit < 1 || $limit > 50_000) {
            throw new \InvalidArgumentException('Station import limit must be between 1 and 50000.');
        }
        $before = $this->repositories->stations->count();
        $source = $this->config->dataMode === 'fake' ? 'fake' : 'entur_stop_place';
        $sourceVersion = $this->config->dataMode === 'fake' ? 'deterministic-v1' : 'stop-places-v1';
        if ($before > 0 && !$force) {
            return [
                'imported' => 0,
                'total' => $before,
                'source' => $source,
                'sourceVersion' => $sourceVersion,
                'skipped' => true,
            ];
        }
        $stations = $this->stationRegistry->stations($limit);
        $imported = $this->repositories->stations->saveMany($stations, $source, $sourceVersion);
        $now = new DateTimeImmutable();
        $total = $this->repositories->stations->count();
        $this->repositories->systemStatus->save(new SystemStatus(
            'station_import',
            'healthy',
            sprintf('Imported %d canonical stations from %s.', $imported, $source),
            $now,
            null,
            [
                'count' => $imported,
                'total' => $total,
                'source' => $source,
                'sourceVersion' => $sourceVersion,
            ],
        ));

        return [
            'imported' => $imported,
            'total' => $total,
            'source' => $source,
            'sourceVersion' => $sourceVersion,
            'skipped' => false,
        ];
    }

    public function close(): void
    {
        $this->repositories->connection->close();
    }

    private function ensureStations(): void
    {
        if ($this->repositories->stations->count() > 0) {
            return;
        }
        $this->importStations(1_000, true);
    }

    private function refreshStation(Station $station, ?StationSnapshot $previous): StationSnapshot
    {
        $now = new DateTimeImmutable();
        $departures = $previous->departures ?? [];
        $nearby = $previous->nearbyVehicles ?? [];
        $state = SourceState::Fresh;
        $warning = null;

        try {
            $departures = $this->journeys->departures($station->id);
            $nearby = $this->vehicles->nearby($station->coordinate);
            foreach ($nearby as $vehicle) {
                $this->persistVehicle($vehicle);
            }
            $state = $departures === [] ? SourceState::Empty : SourceState::Fresh;
            if ($this->scenarios->current() === Scenario::StationStale) {
                $state = SourceState::Stale;
                $warning = 'Showing deterministic stale station data.';
            } elseif ($this->scenarios->current() === Scenario::Fallback) {
                $warning = 'Realtime unavailable; polling fallback is active.';
            }
        } catch (RateLimited $error) {
            $state = SourceState::RateLimited;
            $warning = 'Entur is rate limited until ' . $error->retryAt->format(DateTimeInterface::RFC3339_EXTENDED) . '.';
        } catch (SourceUnavailable $error) {
            $state = SourceState::Error;
            $warning = $error->getMessage();
        }
        $snapshot = new StationSnapshot(
            $station->id,
            $now->format('Y-m-d\\TH:i:s.v\\Z'),
            StationSnapshot::semanticHash($state, $departures, $nearby, $warning),
            $now,
            $state,
            $departures,
            $nearby,
            in_array($state, [SourceState::Fresh, SourceState::Empty], true) ? $now : $previous?->lastSuccessfulAt,
            $warning,
        );

        return $this->repositories->stationSnapshots->save($snapshot);
    }

    private function persistVehicle(VehicleState $vehicle): VehicleState
    {
        $saved = $this->repositories->currentVehicles->save($vehicle);
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . $this->config->observationRetentionHours . 'H'));
        foreach ($vehicle->observations as $observation) {
            $this->repositories->vehicleObservations->append($observation, $expiresAt);
        }

        return $saved;
    }

    /** @return list<array{service: string, limit: int, remaining: int, windowSeconds: int, resetsAt: string, backoffUntil: string|null}> */
    private function enturBudgets(): array
    {
        $now = new DateTimeImmutable();
        $status = $this->budget->status();
        $backoffs = [];
        foreach ($this->repositories->enturRequestLogs->recent(limit: 1_000) as $entry) {
            if ($entry->retryAt === null || $entry->retryAt <= $now) {
                continue;
            }
            $current = $backoffs[$entry->service] ?? null;
            if (!$current instanceof DateTimeImmutable || $entry->retryAt > $current) {
                $backoffs[$entry->service] = $entry->retryAt;
            }
        }
        $reset = $now->add(new DateInterval('PT60S'))->format(DateTimeInterface::RFC3339_EXTENDED);
        $rows = [];
        foreach (['global', ...array_map(static fn(EnturService $service): string => $service->value, EnturService::cases())] as $service) {
            $budget = $status[$service] ?? ['limit' => 0, 'remaining' => 0];
            $backoff = $service === 'global'
                ? ($backoffs === [] ? null : max($backoffs))
                : ($backoffs[$service] ?? null);
            $rows[] = [
                'service' => $service,
                'limit' => $budget['limit'],
                'remaining' => $budget['remaining'],
                'windowSeconds' => 60,
                'resetsAt' => $reset,
                'backoffUntil' => $backoff instanceof DateTimeImmutable
                    ? $backoff->format(DateTimeInterface::RFC3339_EXTENDED)
                    : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function eventRow(RealtimeEvent $event): array
    {
        return [
            'eventId' => $event->eventId,
            'type' => $event->type->value,
            'scope' => $event->scope,
            'entityId' => $event->entityId,
            'version' => $event->version,
            'source' => $event->type->value === 'station_snapshot_changed' ? 'station_snapshot' : 'current_vehicle',
            'payload' => $event->payload,
            'createdAt' => $event->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $result[$key] = $entry;
            }
        }

        return $result;
    }

    private static function nonNegativeInt(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }

    /** @param array<string, mixed> $telemetry */
    private static function messagesPerMinute(array $telemetry): float
    {
        $received = self::nonNegativeInt($telemetry['messagesReceived'] ?? null);
        $sent = self::nonNegativeInt($telemetry['messagesSent'] ?? null);
        $startedAt = $telemetry['startedAt'] ?? null;
        if (!is_string($startedAt)) {
            return 0.0;
        }
        try {
            $elapsedMinutes = max(1.0, (time() - (new DateTimeImmutable($startedAt))->getTimestamp()) / 60.0);
        } catch (\Throwable) {
            return 0.0;
        }

        return round(($received + $sent) / $elapsedMinutes, 2);
    }

    private static function recentStatus(SystemStatus $status, DateTimeImmutable $now, int $maximumAgeSeconds): bool
    {
        return $status->checkedAt >= $now->sub(new DateInterval('PT' . $maximumAgeSeconds . 'S'));
    }

    /** @return array{status: string, checkedAt: string, lastSuccessAt: string|null, message: string|null, latencyMs: float|null} */
    private function serviceHealth(string $status, string $checkedAt, ?string $message, ?float $latencyMs): array
    {
        return [
            'status' => $status,
            'checkedAt' => $checkedAt,
            'lastSuccessAt' => $status === 'healthy' ? $checkedAt : null,
            'message' => $message,
            'latencyMs' => $latencyMs,
        ];
    }
}
