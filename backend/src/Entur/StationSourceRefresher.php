<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\Scenario;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\DepartureBoard;
use FjordPulse\Dto\StationServiceCall;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\StationVehicle;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;

final readonly class StationSourceRefresher
{
    public function __construct(
        private JourneyPlannerInterface $journeys,
        private VehiclePositionsInterface $vehicles,
        private ScenarioProviderInterface $scenarios,
    ) {
    }

    public function refresh(
        Station $station,
        ?StationSnapshot $previous,
        DateTimeImmutable $now,
    ): StationRefreshOutcome {
        $departures = self::savedDepartures($previous);
        $nearbyVehicles = self::savedNearbyVehicles($previous);
        $servingVehicles = self::passengerServingVehicles($previous === null ? [] : $previous->servingVehicles);
        $servingWindowStartedAt = $previous?->servingWindowStartedAt;
        $servingWindowEndsAt = $previous?->servingWindowEndsAt;
        $servingCandidateJourneyCount = $previous === null ? 0 : $previous->servingCandidateJourneyCount;
        $servingQueriedJourneyCount = $previous === null ? 0 : $previous->servingQueriedJourneyCount;
        $servingVehiclesTruncated = $previous !== null && $previous->servingVehiclesTruncated;
        $departureBoard = $previous?->departureBoardCoverage();
        $serviceCalls = [];
        $board = null;
        $departureFailure = null;
        $vehicleFailure = null;

        try {
            $board = $this->journeys->stationBoard($station->id, $now, 20);
            $departures = $board->departures;
            $serviceCalls = $board->serviceCalls;
            if ($board->departureWindowStartedAt !== null && $board->departureWindowEndsAt !== null) {
                $departureBoard = new DepartureBoard(
                    $board->departureWindowStartedAt,
                    $board->departureWindowEndsAt,
                    $board->departureLimit,
                    $board->departureHasMore,
                );
            }
        } catch (RateLimited | SourceUnavailable $failure) {
            $departureFailure = $failure;
        }

        try {
            $positions = $this->vehicles->stationVehicles(
                $station->coordinate,
                self::journeyReferences($serviceCalls),
            );
            $nearbyVehicles = $positions->nearbyVehicles;
            if ($departureFailure === null) {
                $servingVehicles = (new StationVehicleMatcher())->match($positions->servingVehicles, $serviceCalls, $now);
                $servingWindowStartedAt = $board->serviceWindowStartedAt;
                $servingWindowEndsAt = $board->serviceWindowEndsAt;
                $servingCandidateJourneyCount = $board->candidateJourneyCount;
                $servingQueriedJourneyCount = $board->queriedJourneyCount;
                $servingVehiclesTruncated = $board->serviceCallsTruncated;
            } else {
                $servingVehicles = self::refreshSavedServingVehicles($servingVehicles, $nearbyVehicles);
            }
        } catch (RateLimited | SourceUnavailable $failure) {
            $vehicleFailure = $failure;
        }

        if ($departureFailure === null && $vehicleFailure === null) {
            $state = $departures === [] && $nearbyVehicles === [] && $servingVehicles === []
                ? SourceState::Empty
                : SourceState::Fresh;
            $warning = null;
            if ($this->scenarios->current() === Scenario::StationStale) {
                $state = SourceState::Stale;
                $warning = 'Showing deterministic stale station data.';
            } elseif ($this->scenarios->current() === Scenario::Fallback) {
                $warning = 'Realtime unavailable; polling fallback is active.';
            }

            return new StationRefreshOutcome(
                $departures,
                $nearbyVehicles,
                $state,
                in_array($state, [SourceState::Fresh, SourceState::Empty], true) ? $now : $previous?->lastSuccessfulAt,
                $warning,
                null,
                true,
                true,
                $servingVehicles,
                $servingWindowStartedAt,
                $servingWindowEndsAt,
                $servingCandidateJourneyCount,
                $servingQueriedJourneyCount,
                $servingVehiclesTruncated,
                true,
                $departureBoard,
            );
        }

        $rateLimited = self::latestRateLimit($departureFailure, $vehicleFailure);
        $hasSuccessfulSource = $departureFailure === null || $vehicleFailure === null;
        $hasSavedData = $previous !== null && (
            $previous->lastSuccessfulAt !== null
            || $previous->departures !== []
            || $previous->nearbyVehicles !== []
            || $previous->servingVehicles !== []
            || $previous->state === SourceState::Stale
        );
        $deterministicError = $this->scenarios->current() === Scenario::StationError;
        $state = $deterministicError
            ? SourceState::Error
            : ($rateLimited !== null
                ? SourceState::RateLimited
                : ($hasSuccessfulSource || $hasSavedData ? SourceState::Stale : SourceState::Error));
        $retryFailure = $rateLimited ?? $departureFailure ?? $vehicleFailure;
        $warning = $deterministicError
            ? ($departureFailure?->getMessage() ?? $vehicleFailure?->getMessage() ?? 'Deterministic station source failure.')
            : self::warning($departureFailure, $vehicleFailure, $previous, $rateLimited, $servingVehicles !== []);

        return new StationRefreshOutcome(
            $departures,
            $nearbyVehicles,
            $state,
            $previous?->lastSuccessfulAt,
            $warning,
            $retryFailure,
            $departureFailure === null,
            $vehicleFailure === null,
            $servingVehicles,
            $servingWindowStartedAt,
            $servingWindowEndsAt,
            $servingCandidateJourneyCount,
            $servingQueriedJourneyCount,
            $servingVehiclesTruncated,
            false,
            $departureBoard,
        );
    }

    /** @return list<\FjordPulse\Dto\Departure> */
    private static function savedDepartures(?StationSnapshot $previous): array
    {
        return $previous === null ? [] : $previous->departures;
    }

    /** @return list<\FjordPulse\Dto\VehicleState> */
    private static function savedNearbyVehicles(?StationSnapshot $previous): array
    {
        return $previous === null ? [] : $previous->nearbyVehicles;
    }

    /**
     * @param list<StationServiceCall> $calls
     * @return list<VehicleJourneyReference>
     */
    private static function journeyReferences(array $calls): array
    {
        $references = [];
        foreach ($calls as $call) {
            $references[$call->journeyReference->key()] = $call->journeyReference;
        }

        return array_values($references);
    }

    /**
     * Keep the last authoritative station relation while Journey Planner is
     * unavailable, but do not discard a newer Vehicle Positions observation
     * for a retained vehicle that is still inside the nearby radius.
     *
     * @param list<StationVehicle> $saved
     * @param list<VehicleState> $nearby
     * @return list<StationVehicle>
     */
    private static function refreshSavedServingVehicles(array $saved, array $nearby): array
    {
        $nearbyById = [];
        foreach ($nearby as $vehicle) {
            $nearbyById[$vehicle->id] = $vehicle;
        }

        $refreshed = [];
        foreach ($saved as $stationVehicle) {
            $vehicle = $nearbyById[$stationVehicle->vehicle->id] ?? null;
            if ($stationVehicle->vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger
                || ($vehicle !== null && (
                    $vehicle->state === VehicleFreshness::Lost
                    || $vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger
                    || !self::sameJourney($stationVehicle->vehicle, $vehicle)
                ))) {
                continue;
            }
            $refreshed[] = $vehicle === null
                ? $stationVehicle
                : new StationVehicle(
                    $vehicle,
                    $stationVehicle->callRole,
                    $stationVehicle->progress,
                    $stationVehicle->stationCallAt,
                );
        }

        return $refreshed;
    }

    private static function sameJourney(VehicleState $saved, VehicleState $fresh): bool
    {
        return $saved->journeyReference !== null
            && $fresh->journeyReference !== null
            && $saved->journeyReference->key() === $fresh->journeyReference->key();
    }

    /**
     * @param list<StationVehicle> $vehicles
     * @return list<StationVehicle>
     */
    private static function passengerServingVehicles(array $vehicles): array
    {
        return array_values(array_filter(
            $vehicles,
            static fn(StationVehicle $vehicle): bool =>
                $vehicle->vehicle->passengerServiceState !== VehiclePassengerServiceState::NonPassenger,
        ));
    }

    private static function latestRateLimit(
        RateLimited|SourceUnavailable|null $departureFailure,
        RateLimited|SourceUnavailable|null $vehicleFailure,
    ): ?RateLimited
    {
        $latest = null;
        foreach ([$departureFailure, $vehicleFailure] as $failure) {
            if ($failure instanceof RateLimited && ($latest === null || $failure->retryAt > $latest->retryAt)) {
                $latest = $failure;
            }
        }

        return $latest;
    }

    private static function warning(
        RateLimited|SourceUnavailable|null $departureFailure,
        RateLimited|SourceUnavailable|null $vehicleFailure,
        ?StationSnapshot $previous,
        ?RateLimited $rateLimited,
        bool $savedServingMatchesRemain,
    ): string {
        $savedDepartures = $previous !== null && ($previous->lastSuccessfulAt !== null || $previous->departures !== []);
        $savedVehicles = $previous !== null && (
            $previous->nearbyVehicles !== []
            || $previous->servingVehicles !== []
        );
        $parts = [];
        if ($departureFailure !== null) {
            $parts[] = $savedDepartures
                ? 'Departures could not be refreshed; showing saved departure information.'
                : 'Departures are temporarily unavailable.';
        } else {
            $parts[] = 'Departures were refreshed.';
        }
        if ($vehicleFailure !== null) {
            $parts[] = $savedVehicles
                ? 'Station vehicle positions could not be refreshed; showing saved positions.'
                : 'Station vehicle positions are temporarily unavailable.';
        } elseif ($departureFailure !== null) {
            $parts[] = $savedServingMatchesRemain
                ? 'Nearby vehicle positions were refreshed; saved station-serving matches remain until departures reconnect.'
                : 'Nearby vehicle positions were refreshed; station-serving matches are unavailable until departures reconnect.';
        } else {
            $parts[] = 'Nearby and station-serving vehicle positions were refreshed.';
        }
        if ($rateLimited !== null) {
            $parts[] = 'Entur will be retried after ' . $rateLimited->retryAt->format(DateTimeInterface::RFC3339_EXTENDED) . '.';
        }

        return implode(' ', $parts);
    }
}
