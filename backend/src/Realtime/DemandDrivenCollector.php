<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\VehicleFreshnessPolicy;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\DegradedJourneyFactory;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\JourneyProgressMatcher;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\ScenarioProviderInterface;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Entur\StationSourceRefresher;
use FjordPulse\Entur\VehiclePositionsInterface;
use FjordPulse\Surreal\CurrentVehicleRepository;
use FjordPulse\Surreal\JourneySnapshotRepository;
use FjordPulse\Surreal\StationRepository;
use FjordPulse\Surreal\StationSnapshotRepository;
use FjordPulse\Surreal\VehicleObservationRepository;
use FjordPulse\Time\ClockInterface;
use FjordPulse\Time\MonotonicTimestamp;
use FjordPulse\Time\SystemClock;

final readonly class DemandDrivenCollector implements WatchRefreshHandler
{
    public function __construct(
        private JourneyPlannerInterface $journeys,
        private VehiclePositionsInterface $vehicles,
        private StationRepository $stations,
        private StationSnapshotRepository $stationSnapshots,
        private CurrentVehicleRepository $currentVehicles,
        private VehicleObservationRepository $observations,
        private JourneySnapshotRepository $journeySnapshots,
        private ScenarioProviderInterface $scenarios,
        private int $observationRetentionHours = 24,
        private int $journeyRefreshSeconds = 30,
        private JourneyProgressMatcher $journeyProgress = new JourneyProgressMatcher(),
        private VehicleFreshnessPolicy $vehicleFreshness = new VehicleFreshnessPolicy(),
        private ClockInterface $clock = new SystemClock(),
    ) {
        if ($observationRetentionHours < 1 || $journeyRefreshSeconds < 1) {
            throw new \InvalidArgumentException('Observation retention and journey refresh interval must be positive.');
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
        $now = $this->now();
        $outcome = (new StationSourceRefresher(
            $this->journeys,
            $this->vehicles,
            $this->scenarios,
        ))->refresh($station, $previous, $now);
        $snapshotAt = MonotonicTimestamp::afterVersion($now, $previous?->version);
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
            $stationId,
            self::version($snapshotAt),
            StationSnapshot::semanticHash(
                $outcome->state,
                $outcome->departures,
                $outcome->nearbyVehicles,
                $outcome->warning,
                $outcome->servingVehicles,
                $outcome->servingCandidateJourneyCount,
                $outcome->servingQueriedJourneyCount,
                $outcome->servingVehiclesTruncated,
                $outcome->departureBoard,
            ),
            $snapshotAt,
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
            $outcome->departureBoard,
        );
        $this->stationSnapshots->saveRefresh($snapshot, $previous?->version);
        if ($outcome->retryFailure !== null) {
            throw $outcome->retryFailure;
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
            $vehicle = $this->vehicleFreshness->withoutNewObservation($existing, $this->now());
        }
        $vehicle = $this->enrichJourney($vehicle);
        $this->persistVehicle($vehicle);
    }

    private function enrichJourney(VehicleState $vehicle): VehicleState
    {
        if ($vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger) {
            return $vehicle;
        }
        $reference = $vehicle->journeyReference;
        if ($reference === null) {
            return $vehicle;
        }
        $now = $this->now();
        $cached = $this->journeySnapshots->find($reference->serviceJourneyId, $reference->operatingDate);
        if ($cached !== null && $cached->refreshedAt >= $now->sub(new DateInterval('PT' . $this->journeyRefreshSeconds . 'S'))) {
            return $this->journeyProgress->enrich($vehicle, $cached);
        }

        try {
            $journey = $this->journeys->journey($reference);
            if ($journey === null) {
                $journey = DegradedJourneyFactory::create($reference, $cached, SourceState::Unavailable, 'Entur did not return the referenced service journey.', $now);
            }
        } catch (RateLimited $error) {
            $journey = DegradedJourneyFactory::create($reference, $cached, SourceState::RateLimited, $error->getMessage(), $now);
        } catch (SourceUnavailable $error) {
            $journey = DegradedJourneyFactory::create($reference, $cached, SourceState::Error, $error->getMessage(), $now);
        }
        $stored = $this->journeySnapshots->save($journey);

        return $this->journeyProgress->enrich($vehicle, $stored);
    }

    private function persistVehicle(VehicleState $vehicle): void
    {
        $this->currentVehicles->save($vehicle);
        $expiry = $this->now()->add(new DateInterval('PT' . $this->observationRetentionHours . 'H'));
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

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private static function version(DateTimeImmutable $date): string
    {
        return $date->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
