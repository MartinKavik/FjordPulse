<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Domain\Scenario;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\ScenarioProviderInterface;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Entur\VehiclePositionsInterface;
use FjordPulse\Surreal\CurrentVehicleRepository;
use FjordPulse\Surreal\StationRepository;
use FjordPulse\Surreal\StationSnapshotRepository;
use FjordPulse\Surreal\VehicleObservationRepository;

final readonly class DemandDrivenCollector implements WatchRefreshHandler
{
    public function __construct(
        private JourneyPlannerInterface $journeys,
        private VehiclePositionsInterface $vehicles,
        private StationRepository $stations,
        private StationSnapshotRepository $stationSnapshots,
        private CurrentVehicleRepository $currentVehicles,
        private VehicleObservationRepository $observations,
        private ScenarioProviderInterface $scenarios,
        private int $observationRetentionHours = 24,
    ) {
        if ($observationRetentionHours < 1) {
            throw new \InvalidArgumentException('Observation retention must be positive.');
        }
    }

    public function refresh(Watch $watch): void
    {
        match ($watch->type) {
            WatchType::Station => $this->refreshStation($watch->entityId),
            WatchType::Vehicle, WatchType::Focus => $this->refreshVehicle($watch->entityId),
        };
    }

    private function refreshStation(string $stationId): void
    {
        $station = $this->stations->find($stationId);
        if ($station === null) {
            throw new SourceUnavailable('Watched station is not present in canonical station data.');
        }
        $previous = $this->stationSnapshots->find($stationId);
        $now = self::now();
        $departures = $previous->departures ?? [];
        $nearby = $previous->nearbyVehicles ?? [];
        $state = SourceState::Fresh;
        $warning = null;
        $failure = null;

        try {
            $scenario = $this->scenarios->current();
            $departures = $this->journeys->departures($stationId, 20);
            $nearby = $this->vehicles->nearby($station->coordinate, 5.0, 20);
            foreach ($nearby as $vehicle) {
                $this->persistVehicle($vehicle);
            }
            $state = $departures === [] ? SourceState::Empty : SourceState::Fresh;
            if ($scenario === Scenario::StationStale) {
                $state = SourceState::Stale;
                $warning = 'Showing deterministic stale station data.';
            } elseif ($scenario === Scenario::Fallback) {
                $warning = 'Realtime unavailable; polling fallback is active.';
            }
        } catch (RateLimited $error) {
            $state = SourceState::RateLimited;
            $warning = 'Entur is rate limited until ' . $error->retryAt->format(DateTimeInterface::RFC3339_EXTENDED) . '.';
            $failure = $error;
        } catch (SourceUnavailable $error) {
            $state = SourceState::Error;
            $warning = $error->getMessage();
            $failure = $error;
        }

        $snapshot = new StationSnapshot(
            $stationId,
            self::version($now),
            StationSnapshot::semanticHash($state, $departures, $nearby, $warning),
            $now,
            $state,
            $departures,
            $nearby,
            in_array($state, [SourceState::Fresh, SourceState::Empty], true) ? $now : $previous?->lastSuccessfulAt,
            $warning,
        );
        $this->stationSnapshots->save($snapshot);
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function refreshVehicle(string $vehicleId): void
    {
        $vehicle = $this->vehicles->vehicle($vehicleId);
        if ($vehicle === null) {
            $existing = $this->currentVehicles->find($vehicleId);
            if ($existing === null) {
                throw new SourceUnavailable('Watched vehicle is not available from the configured source.');
            }
            $now = self::now();
            $vehicle = new VehicleState(
                $existing->id,
                self::version($now),
                self::hash(['vehicleId' => $existing->id, 'state' => VehicleFreshness::Lost->value]),
                VehicleFreshness::Lost,
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
        $this->persistVehicle($vehicle);
    }

    private function persistVehicle(VehicleState $vehicle): void
    {
        $this->currentVehicles->save($vehicle);
        $expiry = self::now()->add(new DateInterval('PT' . $this->observationRetentionHours . 'H'));
        foreach ($vehicle->observations as $observation) {
            $this->observations->append($observation, $expiry);
        }
        if ($vehicle->observations === [] && $vehicle->coordinate !== null) {
            $this->observations->append(new VehicleObservation(
                'obs_' . substr(hash('sha256', $vehicle->id . '|' . $vehicle->version), 0, 32),
                $vehicle->id,
                $vehicle->coordinate,
                $vehicle->lastSeenAt,
                $vehicle->bearing,
            ), $expiry);
        }
    }

    /** @param array<string, mixed> $value */
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function version(DateTimeImmutable $date): string
    {
        return $date->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
