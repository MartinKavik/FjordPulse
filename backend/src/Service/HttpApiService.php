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
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehicleFreshnessPolicy;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Domain\WatchState;
use FjordPulse\Dto\BoundingBox;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\RealtimeEvent;
use FjordPulse\Dto\SearchCandidate;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\GeocoderInterface;
use FjordPulse\Entur\DegradedJourneyFactory;
use FjordPulse\Entur\JourneyProgressMatcher;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\MutableScenarioProvider;
use FjordPulse\Entur\NearbyVehicleSelector;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\RequestBudgetInterface;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Entur\StationRegistryInterface;
use FjordPulse\Entur\StationSourceRefresher;
use FjordPulse\Entur\VehiclePositionsInterface;
use FjordPulse\Surreal\SurrealRepositories;
use FjordPulse\Surreal\SystemStatus;
use FjordPulse\Surreal\Migration;
use Throwable;

final readonly class HttpApiService
{
    private const int JOURNEY_REFRESH_SECONDS = 30;
    private const int ENTUR_HEALTH_MAX_AGE_SECONDS = 300;

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
        private SearchRanker $searchRanker,
        private SearchNormalizer $searchNormalizer,
        private HostResourceDiagnostics $hostResources,
        private VehicleFreshnessPolicy $vehicleFreshness,
    ) {
    }

    /** @return array<string, mixed> */
    public function stationMap(BoundingBox $bounds, float $zoom): array
    {
        $this->ensureStations();

        return [
            'bounds' => [
                'minLongitude' => $bounds->minLongitude,
                'minLatitude' => $bounds->minLatitude,
                'maxLongitude' => $bounds->maxLongitude,
                'maxLatitude' => $bounds->maxLatitude,
            ],
            'zoom' => $zoom,
            'dataSource' => 'surrealdb',
            'items' => $this->clusterer->boundedItems($this->repositories->stations, $bounds, $zoom),
        ];
    }

    /** @return array<string, mixed> */
    public function search(string $query, int $limit): array
    {
        $this->ensureStations();
        $candidateLimit = min(100, max(50, $limit * 5));
        $exactVehicleId = $this->searchNormalizer->exactVehicleId($query);
        $stations = $this->repositories->stations->search($query, $candidateLimit);
        $geocoded = [];
        try {
            $geocoded = $this->geocoder->search($query, min(50, $candidateLimit));
        } catch (RateLimited | SourceUnavailable) {
            // Local authoritative search stays available during upstream degradation.
        }

        $canonicalGeocoded = array_values(array_filter(
            $geocoded,
            static fn(Station $station): bool => str_starts_with($station->id, 'NSR:StopPlace:'),
        ));
        if ($this->config->dataMode === 'fake') {
            $known = $this->repositories->currentVehicles->search($query, $candidateLimit);
            if ($known === []) {
                foreach ($this->vehicles->current() as $vehicle) {
                    $this->persistVehicle($vehicle);
                }
            }
        }
        if ($exactVehicleId !== null || $this->vehicleSearchIntent($query)) {
            try {
                $persisted = 0;
                foreach ($this->vehicles->current() as $sourceVehicle) {
                    $exactVehicleMatch = $exactVehicleId !== null && $sourceVehicle->id === $exactVehicleId;
                    if ($sourceVehicle->state === VehicleFreshness::Lost && !$exactVehicleMatch) {
                        continue;
                    }
                    $aliases = $sourceVehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger
                        ? [$sourceVehicle->id]
                        : array_values(array_filter([
                            $sourceVehicle->id,
                            $sourceVehicle->lineCode,
                            $sourceVehicle->routeName,
                            $sourceVehicle->destination,
                        ], static fn(?string $value): bool => $value !== null));
                    $vehicleResult = self::vehicleSearchResult($sourceVehicle);
                    $vehicleCandidate = $this->searchRanker->candidate($query, $vehicleResult, $aliases);
                    $lineCandidate = $sourceVehicle->lineCode === null
                        || $sourceVehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger
                        ? null
                        : $this->searchRanker->candidate($query, self::lineSearchResult($sourceVehicle), $aliases);
                    if ($vehicleCandidate->rank >= 1_000 && ($lineCandidate === null || $lineCandidate->rank >= 1_000)) {
                        continue;
                    }
                    $this->persistVehicle($sourceVehicle);
                    $persisted++;
                    if ($persisted >= $candidateLimit) {
                        break;
                    }
                }
            } catch (RateLimited | SourceUnavailable) {
                // Fresh persisted matches remain available within the bounded age window.
            }
        }
        $vehicleCutoff = $this->config->dataMode === 'real'
            ? (new DateTimeImmutable())->sub(new DateInterval('PT' . $this->config->vehicleLostSeconds . 'S'))
            : null;
        $vehicles = $this->repositories->currentVehicles->search($query, $candidateLimit, $vehicleCutoff);
        if ($exactVehicleId !== null) {
            $exactVehicle = $this->repositories->currentVehicles->find($exactVehicleId);
            if ($exactVehicle !== null
                && !array_any($vehicles, static fn(VehicleState $vehicle): bool => $vehicle->id === $exactVehicle->id)) {
                array_unshift($vehicles, $exactVehicle);
            }
        }

        $candidates = [];
        $seen = [];

        foreach ([...$stations, ...$canonicalGeocoded] as $stationPriority => $station) {
            $this->appendSearchCandidate($query, self::stationSearchResult($station), array_values(array_filter([
                $station->locality,
                $station->municipality,
            ], static fn(?string $value): bool => $value !== null)), $candidates, $seen, $stationPriority);
        }
        foreach ($geocoded as $place) {
            if (str_starts_with($place->id, 'NSR:StopPlace:')) {
                continue;
            }
            $this->appendSearchCandidate($query, [
                'type' => 'place',
                'id' => $place->id,
                'label' => $place->name,
                'secondaryText' => $place->locality ?? $place->municipality,
                'stationId' => null,
                'lineCode' => null,
                'latitude' => $place->coordinate->latitude,
                'longitude' => $place->coordinate->longitude,
            ], array_values(array_filter([
                $place->locality,
                $place->municipality,
            ], static fn(?string $value): bool => $value !== null)), $candidates, $seen);
        }

        $lines = [];
        foreach ($vehicles as $vehicle) {
            if ($vehicle->passengerServiceState !== VehiclePassengerServiceState::NonPassenger
                && $vehicle->lineCode !== null && !isset($lines[$vehicle->lineCode])) {
                $lines[$vehicle->lineCode] = true;
                $this->appendSearchCandidate($query, self::lineSearchResult($vehicle), array_values(array_filter([
                    $vehicle->lineCode,
                    $vehicle->routeName,
                    $vehicle->destination,
                ], static fn(?string $value): bool => $value !== null)), $candidates, $seen);
            }
            $vehicleAliases = $vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger
                ? [$vehicle->id]
                : array_values(array_filter([
                    $vehicle->lineCode,
                    $vehicle->routeName,
                    $vehicle->destination,
                ], static fn(?string $value): bool => $value !== null));
            $this->appendSearchCandidate($query, self::vehicleSearchResult($vehicle), $vehicleAliases, $candidates, $seen);
        }

        return [
            'query' => $query,
            'results' => $this->searchRanker->ordered($candidates, $limit, $this->vehicleSearchIntent($query)),
        ];
    }

    private function vehicleSearchIntent(string $query): bool
    {
        $normalized = $this->searchNormalizer->normalize($query);

        return preg_match('/^(?:line|linje|vehicle|kjoretoy)(?:\s|$)/u', $normalized) === 1
            || preg_match('/^(?:[a-z]{1,4})?\d{1,8}(?:\s|$)/u', $normalized) === 1
            || str_contains($normalized, ':');
    }

    /** @return array<string, mixed> */
    private static function lineSearchResult(VehicleState $vehicle): array
    {
        return [
            'type' => 'line',
            'id' => 'line:' . $vehicle->lineCode,
            'label' => 'Line ' . $vehicle->lineCode,
            'secondaryText' => $vehicle->routeName ?? $vehicle->destination,
            'stationId' => null,
            'lineCode' => $vehicle->lineCode,
            'latitude' => $vehicle->coordinate?->latitude,
            'longitude' => $vehicle->coordinate?->longitude,
        ];
    }

    /** @return array<string, mixed> */
    private static function vehicleSearchResult(VehicleState $vehicle): array
    {
        $nonPassenger = $vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger;

        return [
            'type' => 'vehicle',
            'id' => $vehicle->id,
            'label' => 'Vehicle ' . $vehicle->id,
            'transportMode' => $vehicle->transportMode->value,
            'secondaryText' => $nonPassenger ? 'Not in passenger service' : implode(' · ', array_filter([
                $vehicle->lineCode === null ? null : 'Line ' . $vehicle->lineCode,
                $vehicle->destination,
            ])),
            'stationId' => null,
            'lineCode' => $nonPassenger ? null : $vehicle->lineCode,
            'latitude' => $vehicle->coordinate?->latitude,
            'longitude' => $vehicle->coordinate?->longitude,
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

    /**
     * @param array<string, mixed> $row
     * @param list<string> $aliases
     * @param list<SearchCandidate> $candidates
     * @param array<string, true> $seen
     */
    private function appendSearchCandidate(
        string $query,
        array $row,
        array $aliases,
        array &$candidates,
        array &$seen,
        int $entityPriority = 0,
    ): void {
        $type = $row['type'] ?? null;
        $id = $row['id'] ?? null;
        if (!is_string($type) || !is_string($id)) {
            throw new \LogicException('Search results require string type and id fields.');
        }
        $label = $row['label'] ?? null;
        $secondary = $row['secondaryText'] ?? null;
        $idKey = $type . ':' . $id;
        if (isset($seen[$idKey])) {
            return;
        }
        $groupKey = null;
        if (is_string($label) && $type === 'station') {
            $normalizedLabel = $this->searchNormalizer->normalize($label);
            $normalizedQuery = $this->searchNormalizer->normalize($query);
            $prefixTokens = mb_strlen($normalizedQuery) <= 3
                ? array_values(array_filter($this->searchNormalizer->tokens($normalizedLabel), static fn(string $token): bool => str_starts_with($token, $normalizedQuery)))
                : [];
            usort($prefixTokens, static fn(string $left, string $right): int => [mb_strlen($left), $left] <=> [mb_strlen($right), $right]);
            $groupKey = 'station:label:' . ($prefixTokens[0] ?? $normalizedLabel);
        } elseif (is_string($label) && $type === 'place') {
            $groupKey = 'place:label:' . $this->searchNormalizer->normalize($label) . ':' . (is_string($secondary) ? $this->searchNormalizer->normalize($secondary) : '');
        }
        if ($groupKey !== null && isset($seen[$groupKey])) {
            return;
        }
        $seen[$idKey] = true;
        if ($groupKey !== null) {
            $seen[$groupKey] = true;
        }
        $candidates[] = $this->searchRanker->candidate($query, $row, $aliases, $entityPriority);
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
            'searchRadiusMeters' => NearbyVehicleSelector::DEFAULT_RADIUS_METERS,
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
        if ($existing === null || $refresh || !self::isFresh($existing->refreshedAt ?? $existing->updatedAt, $this->config->vehicleFreshSeconds)) {
            $sourceVehicle = $this->vehicles->vehicle($vehicleId);
            if ($sourceVehicle !== null) {
                $vehicle = $this->persistVehicle($this->enrichVehicleJourney($sourceVehicle));
            } elseif ($existing !== null) {
                $vehicle = $this->persistVehicle($this->vehicleFreshness->withoutNewObservation($existing, new DateTimeImmutable()));
            }
        } elseif ($existing !== null) {
            $this->recordCacheHit('vehicle_positions', 'vehicle:' . $vehicleId, 1);
        }
        if ($vehicle === null) {
            return null;
        }
        $reference = $vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger
            ? null
            : $vehicle->journeyReference;
        $journey = $reference === null
            ? null
            : $this->repositories->journeySnapshots->find(
                $reference->serviceJourneyId,
                $reference->operatingDate,
            );
        if ($reference !== null && (
            $journey === null
            || !self::isFresh($journey->refreshedAt, self::JOURNEY_REFRESH_SECONDS)
            || $vehicle->journeyVersion !== $journey->version
        )) {
            $enriched = $this->enrichVehicleJourney($vehicle);
            if ($enriched->contentHash !== $vehicle->contentHash) {
                $vehicle = $this->persistVehicle($enriched);
            } else {
                $vehicle = $enriched;
            }
            $journey = $this->repositories->journeySnapshots->find(
                $reference->serviceJourneyId,
                $reference->operatingDate,
            );
        }
        $trail = $this->repositories->vehicleObservations->recent($vehicleId, 100);
        $upcomingStops = $journey === null
            ? []
            : array_map(
                static fn(\FjordPulse\Dto\StopCall $stop): array => $stop->toArray(),
                (new JourneyProgressMatcher())->upcoming($journey, $vehicle),
            );

        return [
            'vehicle' => $vehicle->toArray(),
            'trail' => array_map(static fn(VehicleObservation $observation): array => $observation->toArray(), $trail),
            'journey' => $journey?->toArray(),
            'upcomingStops' => $upcomingStops,
        ];
    }

    private function enrichVehicleJourney(VehicleState $vehicle): VehicleState
    {
        if ($vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger) {
            return $vehicle;
        }
        $reference = $vehicle->journeyReference;
        if ($reference === null) {
            return $vehicle;
        }
        $journey = $this->repositories->journeySnapshots->find($reference->serviceJourneyId, $reference->operatingDate);
        if ($journey === null || !self::isFresh($journey->refreshedAt, self::JOURNEY_REFRESH_SECONDS)) {
            $now = new DateTimeImmutable();
            try {
                $refreshed = $this->journeys->journey($reference);
                if ($refreshed !== null) {
                    $journey = $this->repositories->journeySnapshots->save($refreshed);
                } else {
                    $journey = $this->repositories->journeySnapshots->save(DegradedJourneyFactory::create($reference, $journey, SourceState::Unavailable, 'Entur did not return the referenced service journey.', $now));
                }
            } catch (RateLimited $error) {
                $journey = $this->repositories->journeySnapshots->save(DegradedJourneyFactory::create($reference, $journey, SourceState::RateLimited, $error->getMessage(), $now));
            } catch (SourceUnavailable $error) {
                $journey = $this->repositories->journeySnapshots->save(DegradedJourneyFactory::create($reference, $journey, SourceState::Error, $error->getMessage(), $now));
            }
        } else {
            $this->recordCacheHit('journey_planner', 'journey:' . $reference->serviceJourneyId . ':' . $reference->operatingDate, count($journey->calls));
        }

        if ($vehicle->journeyVersion !== $journey->version) {
            $vehicle = $this->reversionVehicle($vehicle);
        }

        return (new JourneyProgressMatcher())->enrich($vehicle, $journey);
    }

    private function reversionVehicle(VehicleState $vehicle): VehicleState
    {
        $now = new DateTimeImmutable();
        $currentVersion = new DateTimeImmutable($vehicle->version);
        if ($now <= $currentVersion) {
            $now = $currentVersion->modify('+1 millisecond');
        }

        return new VehicleState(
            $vehicle->id,
            $now->format('Y-m-d\\TH:i:s.v\\Z'),
            $vehicle->contentHash,
            $vehicle->state,
            $vehicle->coordinate,
            $vehicle->lineCode,
            $vehicle->routeName,
            $vehicle->destination,
            $vehicle->bearing,
            $vehicle->delaySeconds,
            $vehicle->distanceMeters,
            $vehicle->lastSeenAt,
            $now,
            $vehicle->nextStop,
            $vehicle->observations,
            $vehicle->journeyReference,
            $vehicle->monitoredCall,
            $vehicle->progressBetweenStops,
            $vehicle->journeyVersion,
            $vehicle->routeProgress,
            $vehicle->refreshedAt,
            $vehicle->transportMode,
            $vehicle->passengerServiceState,
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
        $now = new DateTimeImmutable();
        $watches = array_values(array_filter(
            array_map(
                static fn(Watch $watch): Watch => self::effectiveWatch($watch),
                $this->repositories->watches->all(10_000),
            ),
            static fn(Watch $watch): bool => ($type === null || $watch->type->value === $type)
                && ($state === null || $watch->state->value === $state)
                && ($scope === null || str_contains(strtolower($watch->scope), strtolower($scope))),
        ));
        $watches = array_values(array_filter(
            $watches,
            static fn(Watch $watch): bool => $watch->expiresAt > $now,
        ));
        $watches = array_slice($watches, 0, $limit);

        return [
            'summary' => [
                'total' => count($watches),
                'focus' => count(array_filter($watches, static fn(Watch $watch): bool => $watch->type->value === 'focus')),
                'expiringSoon' => count(array_filter($watches, static fn(Watch $watch): bool => $watch->state === WatchState::Expired)),
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
                'p95LatencyMs' => $p95Index === null ? null : (float)$latencies[$p95Index],
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
        $now = new DateTimeImmutable();
        $activeWatches = array_values(array_filter(
            $watches,
            static fn(Watch $watch): bool => $watch->clientCount > 0
                && $watch->expiresAt > $now
                && $watch->state !== WatchState::Expired,
        ));
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
            'database' => $this->config->databaseDiagnostic(),
            'resources' => $this->hostResources->snapshot(),
            'services' => $services,
            'metrics' => [
                'activeClients' => self::nonNegativeInt($realtimeHealth['clients'] ?? $telemetry['activeClients'] ?? null),
                'stationWatches' => count(array_filter($activeWatches, static fn(Watch $watch): bool => $watch->type->value === 'station')),
                'vehicleWatches' => count(array_filter($activeWatches, static fn(Watch $watch): bool => $watch->type->value === 'vehicle')),
                'focusWatches' => count(array_filter($activeWatches, static fn(Watch $watch): bool => $watch->type->value === 'focus')),
                'messagesPerMinute' => self::messagesPerMinute($telemetry),
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
            'recentEvents' => array_map(self::eventRow(...), $this->repositories->realtimeEvents->recent(limit: 5)),
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
        $enturRecent = $recentEntur !== null
            && $recentEntur->requestedAt >= $now->sub(new DateInterval('PT' . self::ENTUR_HEALTH_MAX_AGE_SECONDS . 'S'));
        $realtimeHealthy = $realtime?->state === 'healthy' && self::recentStatus($realtime, $now, 20);
        $bridgeHealthy = $bridge?->state === 'healthy' && self::recentStatus($bridge, $now, 20);
        $enturDegraded = $this->config->dataMode === 'real' && $enturRecent
            && in_array($recentEntur->outcome, ['rate_limited', 'backoff', 'timeout', 'error', 'skipped_budget'], true);
        $enturStatus = $this->config->dataMode === 'fake'
            ? 'healthy'
            : (!$enturRecent ? 'unknown' : ($enturDegraded ? 'degraded' : 'healthy'));
        $catalogStatus = $this->repositories->systemStatus->find('station_catalog');
        $catalogCount = $this->repositories->stations->count();
        $catalogReady = $this->stationCatalogReady($catalogStatus, $catalogCount);
        $fallback = !$realtimeHealthy || !$bridgeHealthy || $enturDegraded || $this->scenarios->current() === Scenario::Fallback;
        $mapTilesConfigured = $this->config->mapTilesConfigured();
        $degraded = $fallback || !$mapTilesConfigured || !$catalogReady;
        $realtimeCheckedAt = $realtime === null
            ? $nowString
            : $realtime->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED);
        $bridgeCheckedAt = $bridge === null
            ? $nowString
            : $bridge->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED);

        return [
            'status' => $degraded ? 'degraded' : 'healthy',
            'mode' => $fallback ? 'fallback_polling' : 'normal',
            'dataMode' => $this->config->dataMode,
            'checkedAt' => $nowString,
            'version' => getenv('APP_VERSION') ?: 'dev',
            'fallbackAvailable' => true,
            'dependencies' => [
                'http' => $this->serviceHealth('healthy', $nowString, 'CakePHP HTTP/control plane is serving.', null),
                'realtime' => $this->serviceHealth($realtimeHealthy ? 'healthy' : 'degraded', $realtimeCheckedAt, $realtimeHealthy ? $realtime->detail : 'Realtime status is missing, degraded, or stale.', $realtime?->latencyMs),
                'surrealdb' => $this->serviceHealth(
                    $catalogReady ? 'healthy' : 'degraded',
                    $catalogStatus?->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED) ?? $nowString,
                    $catalogReady
                        ? sprintf('Authoritative state database is reachable; the %s station catalog contains %d records.', $this->config->dataMode, $catalogCount)
                        : 'Authoritative state database is reachable, but the configured station catalog is missing, partial, failed, or has different source provenance.',
                    null,
                ),
                'entur' => $this->serviceHealth(
                    $enturStatus,
                    $enturRecent ? $recentEntur->requestedAt->format(DateTimeInterface::RFC3339_EXTENDED) : $nowString,
                    $this->config->dataMode === 'fake'
                        ? 'Demo fake adapters active; Entur is not being queried.'
                        : (!$enturRecent ? 'No Entur request recorded in five minutes. Availability will be checked on the next demand-driven request.' : 'Latest Entur outcome: ' . $recentEntur->outcome . '.'),
                    !$enturRecent ? null : (float)$recentEntur->latencyMs,
                ),
                'liveQueryBridge' => $this->serviceHealth($bridgeHealthy ? 'healthy' : 'degraded', $bridgeCheckedAt, $bridgeHealthy ? $bridge->detail : 'Live-query bridge status is missing, degraded, or stale.', $bridge?->latencyMs),
                'mapTiles' => $this->serviceHealth(
                    $mapTilesConfigured ? 'configured' : 'misconfigured',
                    $nowString,
                    $mapTilesConfigured
                        ? 'MapTiler browser configuration is present; provider availability is verified by the browser at load time, not by this endpoint.'
                        : 'MAPTILER_API_KEY is not configured; browser maps are unavailable.',
                    null,
                ),
            ],
        ];
    }

    /**
     * @return array{
     *   imported: int,
     *   total: int,
     *   source: string,
     *   sourceVersion: string,
     *   sourceMode: string,
     *   skipped: bool,
     *   complete: bool,
     *   resumed: bool,
     *   nextOffset: int
     * }
     * @param null|callable(array{source: string, sourceVersion: string, sourceMode: string, imported: int, nextOffset: int, complete: bool}): void $progress
     */
    public function importStations(
        ?int $maximumStations = null,
        bool $force = false,
        ?callable $progress = null,
    ): array
    {
        if ($maximumStations !== null && ($maximumStations < 1 || $maximumStations > 250_000)) {
            throw new \InvalidArgumentException('Station import maximum must be between 1 and 250000, or null for all stations.');
        }
        $identity = $this->stationCatalogIdentity();
        $previous = $this->repositories->systemStatus->find('station_catalog');
        $before = $this->repositories->stations->count();
        if (!$force && $this->stationCatalogReady($previous, $before)) {
            return [
                'imported' => 0,
                'total' => $before,
                ...$identity,
                'skipped' => true,
                'complete' => true,
                'resumed' => false,
                'nextOffset' => self::metadataInt($previous?->metadata, 'nextOffset'),
            ];
        }

        $resume = !$force && $this->stationCatalogResumable($previous);
        $runId = $resume
            ? self::metadataString($previous?->metadata, 'runId')
            : 'catalog_' . bin2hex(random_bytes(16));
        if ($runId === null) {
            throw new \LogicException('A resumable station catalog must contain a run id.');
        }
        $offset = $resume ? self::metadataInt($previous?->metadata, 'nextOffset') : 0;
        $startedAt = $resume
            ? (self::metadataString($previous?->metadata, 'startedAt') ?? (new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED))
            : (new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED);
        $writtenThisAttempt = 0;
        $sourceItemsThisAttempt = 0;
        $stagedCount = $resume ? $this->repositories->stations->countForCatalog($runId) : 0;
        $previousIdentityMatches = self::metadataString($previous?->metadata, 'source') === $identity['source']
            && self::metadataString($previous?->metadata, 'sourceVersion') === $identity['sourceVersion']
            && self::metadataString($previous?->metadata, 'sourceMode') === $identity['sourceMode'];
        $clearDerivedState = $resume
            ? self::metadataBool($previous?->metadata, 'replaceDerivedState')
            : ($before > 0 && !$previousIdentityMatches);

        $metadata = [
            ...$identity,
            'runId' => $runId,
            'nextOffset' => $offset,
            'importedCount' => $stagedCount,
            'complete' => false,
            'replaceDerivedState' => $clearDerivedState,
            'startedAt' => $startedAt,
            'completedAt' => null,
            'lastError' => null,
        ];
        $this->saveStationCatalogStatus('importing', 'Station catalog import is in progress.', $metadata);

        try {
            while (true) {
                if ($maximumStations !== null && $sourceItemsThisAttempt >= $maximumStations) {
                    return [
                        'imported' => $writtenThisAttempt,
                        'total' => $stagedCount,
                        ...$identity,
                        'skipped' => false,
                        'complete' => false,
                        'resumed' => $resume,
                        'nextOffset' => $offset,
                    ];
                }
                $remaining = $maximumStations === null
                    ? $this->config->stationImportPageSize
                    : $maximumStations - $sourceItemsThisAttempt;
                $pageSize = min($this->config->stationImportPageSize, $remaining);
                $page = $this->stationRegistry->page($offset, $pageSize);
                $writeChunkSize = $this->config->stationImportWriteChunkSize;
                if ($writeChunkSize < 1) {
                    throw new \LogicException('RuntimeConfig must guarantee a positive station import write chunk size.');
                }
                foreach (array_chunk($page->stations, $writeChunkSize) as $chunk) {
                    $writtenThisAttempt += $this->repositories->stations->saveMany(
                        $chunk,
                        $identity['source'],
                        $identity['sourceVersion'],
                        $identity['sourceMode'],
                        $runId,
                    );
                }
                $offset += $page->sourceItemCount;
                $sourceItemsThisAttempt += $page->sourceItemCount;
                $stagedCount = $this->repositories->stations->countForCatalog($runId);
                $metadata = [
                    ...$metadata,
                    'nextOffset' => $offset,
                    'importedCount' => $stagedCount,
                ];

                if ($page->terminal($pageSize)) {
                    if ($stagedCount === 0) {
                        throw new SourceUnavailable('The station source returned no usable Stop Places.');
                    }
                    $total = $this->repositories->stations->activateCatalog(
                        $runId,
                        $identity['sourceMode'],
                        $clearDerivedState,
                    );
                    $completedAt = new DateTimeImmutable();
                    $metadata = [
                        ...$metadata,
                        'complete' => true,
                        'importedCount' => $total,
                        'completedAt' => $completedAt->format(DateTimeInterface::RFC3339_EXTENDED),
                    ];
                    $this->saveStationCatalogStatus(
                        'healthy',
                        sprintf('Canonical station catalog contains %d records from %s.', $total, $identity['source']),
                        $metadata,
                        $completedAt,
                    );
                    if ($progress !== null) {
                        $progress([
                            ...$identity,
                            'imported' => $total,
                            'nextOffset' => $offset,
                            'complete' => true,
                        ]);
                    }

                    return [
                        'imported' => $writtenThisAttempt,
                        'total' => $total,
                        ...$identity,
                        'skipped' => false,
                        'complete' => true,
                        'resumed' => $resume,
                        'nextOffset' => $offset,
                    ];
                }

                $this->saveStationCatalogStatus('importing', 'Station catalog import is in progress.', $metadata);
                if ($progress !== null) {
                    $progress([
                        ...$identity,
                        'imported' => $stagedCount,
                        'nextOffset' => $offset,
                        'complete' => false,
                    ]);
                }
            }
        } catch (Throwable $error) {
            try {
                $this->saveStationCatalogStatus('failed', 'Station catalog import failed and can be resumed.', [
                    ...$metadata,
                    'nextOffset' => $offset,
                    'importedCount' => $stagedCount,
                    'lastError' => mb_substr($error->getMessage(), 0, 500),
                ]);
            } catch (Throwable) {
                // Preserve the source/import error when persistence also becomes unavailable.
            }
            throw $error;
        }
    }

    public function close(): void
    {
        $this->repositories->connection->close();
    }

    public function stationCatalogReadyForRuntime(): bool
    {
        return $this->stationCatalogReady(
            $this->repositories->systemStatus->find('station_catalog'),
            $this->repositories->stations->count(),
        );
    }

    private function ensureStations(): void
    {
        $status = $this->repositories->systemStatus->find('station_catalog');
        $count = $this->repositories->stations->count();
        if ($this->stationCatalogReady($status, $count)) {
            return;
        }
        if ($this->config->dataMode === 'fake') {
            $this->importStations();
            return;
        }

        throw new \Cake\Http\Exception\ServiceUnavailableException(
            'The real Entur station catalog is not ready. Run `backend/bin/cake stations import` and retry.',
        );
    }

    /** @return array{source: string, sourceVersion: string, sourceMode: string} */
    private function stationCatalogIdentity(): array
    {
        return $this->config->dataMode === 'fake'
            ? ['source' => 'fake', 'sourceVersion' => 'deterministic-v1', 'sourceMode' => 'fake']
            : ['source' => 'entur_stop_place', 'sourceVersion' => 'stop-places-v1', 'sourceMode' => 'real'];
    }

    private function stationCatalogReady(?SystemStatus $status, int $stationCount): bool
    {
        if ($status?->state !== 'healthy' || $stationCount < 1) {
            return false;
        }
        $identity = $this->stationCatalogIdentity();

        return ($status->metadata['complete'] ?? null) === true
            && self::metadataString($status->metadata, 'source') === $identity['source']
            && self::metadataString($status->metadata, 'sourceVersion') === $identity['sourceVersion']
            && self::metadataString($status->metadata, 'sourceMode') === $identity['sourceMode']
            && self::metadataInt($status->metadata, 'importedCount') === $stationCount;
    }

    private function stationCatalogResumable(?SystemStatus $status): bool
    {
        if ($status === null || !in_array($status->state, ['importing', 'failed'], true)) {
            return false;
        }
        $identity = $this->stationCatalogIdentity();

        return self::metadataString($status->metadata, 'source') === $identity['source']
            && self::metadataString($status->metadata, 'sourceVersion') === $identity['sourceVersion']
            && self::metadataString($status->metadata, 'sourceMode') === $identity['sourceMode']
            && self::metadataString($status->metadata, 'runId') !== null;
    }

    /** @param array<string, mixed> $metadata */
    private function saveStationCatalogStatus(
        string $state,
        string $detail,
        array $metadata,
        ?DateTimeImmutable $checkedAt = null,
    ): void {
        $this->repositories->systemStatus->save(new SystemStatus(
            'station_catalog',
            $state,
            $detail,
            $checkedAt ?? new DateTimeImmutable(),
            null,
            $metadata,
        ));
    }

    /** @param array<string, mixed>|null $metadata */
    private static function metadataString(?array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed>|null $metadata */
    private static function metadataInt(?array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? null;

        return is_int($value) && $value >= 0 ? $value : 0;
    }

    /** @param array<string, mixed>|null $metadata */
    private static function metadataBool(?array $metadata, string $key): bool
    {
        return ($metadata[$key] ?? null) === true;
    }

    private function refreshStation(Station $station, ?StationSnapshot $previous): StationSnapshot
    {
        $now = new DateTimeImmutable();
        $outcome = (new StationSourceRefresher(
            $this->journeys,
            $this->vehicles,
            $this->scenarios,
        ))->refresh($station, $previous, $now);
        if ($outcome->nearbyVehiclesRefreshed || $outcome->servingVehiclesRefreshed) {
            $vehiclesToPersist = [];
            foreach ($outcome->servingVehicles as $stationVehicle) {
                $vehiclesToPersist[$stationVehicle->vehicle->id] = $stationVehicle->vehicle;
            }
            foreach ($outcome->nearbyVehicles as $vehicle) {
                $vehiclesToPersist[$vehicle->id] = $vehicle;
            }
            foreach ($vehiclesToPersist as $vehicle) {
                $this->persistVehicle($vehicle);
            }
        }
        $snapshot = new StationSnapshot(
            $station->id,
            $now->format('Y-m-d\\TH:i:s.v\\Z'),
            StationSnapshot::semanticHash(
                $outcome->state,
                $outcome->departures,
                $outcome->nearbyVehicles,
                $outcome->warning,
                $outcome->servingVehicles,
                $outcome->servingCandidateJourneyCount,
                $outcome->servingQueriedJourneyCount,
                $outcome->servingVehiclesTruncated,
            ),
            $now,
            $outcome->state,
            $outcome->departures,
            $outcome->nearbyVehicles,
            $outcome->lastSuccessfulAt,
            $outcome->warning,
            $outcome->servingVehicles,
            $outcome->servingWindowStartedAt,
            $outcome->servingWindowEndsAt,
            $outcome->servingCandidateJourneyCount,
            $outcome->servingQueriedJourneyCount,
            $outcome->servingVehiclesTruncated,
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

    /** @return list<array{service: string, limit: int, remaining: int, windowSeconds: int, backoffUntil: string|null}> */
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

    private static function effectiveWatch(Watch $watch): Watch
    {
        if ($watch->clientCount > 0 || $watch->state === WatchState::Expired) {
            return $watch;
        }

        return new Watch(
            $watch->id,
            $watch->type,
            $watch->scope,
            $watch->entityId,
            $watch->clientCount,
            $watch->priority,
            $watch->lastRefreshAt,
            $watch->nextRefreshAt,
            $watch->expiresAt,
            WatchState::Expired,
            $watch->lastErrorCode,
        );
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
