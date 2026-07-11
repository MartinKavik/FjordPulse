<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\Scenario;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StationSnapshot;

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
        $departureFailure = null;
        $vehicleFailure = null;

        try {
            $departures = $this->journeys->departures($station->id, 20);
        } catch (RateLimited | SourceUnavailable $failure) {
            $departureFailure = $failure;
        }

        try {
            $nearbyVehicles = $this->vehicles->nearby($station->coordinate);
        } catch (RateLimited | SourceUnavailable $failure) {
            $vehicleFailure = $failure;
        }

        if ($departureFailure === null && $vehicleFailure === null) {
            $state = $departures === [] ? SourceState::Empty : SourceState::Fresh;
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
            );
        }

        $rateLimited = self::latestRateLimit($departureFailure, $vehicleFailure);
        $hasSuccessfulSource = $departureFailure === null || $vehicleFailure === null;
        $hasSavedData = $previous !== null && (
            $previous->lastSuccessfulAt !== null
            || $previous->departures !== []
            || $previous->nearbyVehicles !== []
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
            : self::warning($departureFailure, $vehicleFailure, $previous, $rateLimited);

        return new StationRefreshOutcome(
            $departures,
            $nearbyVehicles,
            $state,
            $previous?->lastSuccessfulAt,
            $warning,
            $retryFailure,
            $departureFailure === null,
            $vehicleFailure === null,
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
    ): string {
        $savedDepartures = $previous !== null && ($previous->lastSuccessfulAt !== null || $previous->departures !== []);
        $savedVehicles = $previous !== null && ($previous->lastSuccessfulAt !== null || $previous->nearbyVehicles !== []);
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
                ? 'Nearby vehicle positions could not be refreshed; showing saved positions.'
                : 'Nearby vehicle positions are temporarily unavailable.';
        } else {
            $parts[] = 'Nearby vehicle positions were refreshed.';
        }
        if ($rateLimited !== null) {
            $parts[] = 'Entur will be retried after ' . $rateLimited->retryAt->format(DateTimeInterface::RFC3339_EXTENDED) . '.';
        }

        return implode(' ', $parts);
    }
}
