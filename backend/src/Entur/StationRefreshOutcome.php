<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\DepartureBoard;
use FjordPulse\Dto\StationVehicle;
use FjordPulse\Dto\VehicleState;
use Throwable;

final readonly class StationRefreshOutcome
{
    /**
     * @param list<Departure> $departures
     * @param list<VehicleState> $nearbyVehicles
     * @param list<StationVehicle> $servingVehicles
     */
    public function __construct(
        public array $departures,
        public array $nearbyVehicles,
        public SourceState $state,
        public ?DateTimeImmutable $lastSuccessfulAt,
        public ?string $warning,
        public ?Throwable $retryFailure,
        public bool $departuresRefreshed,
        public bool $nearbyVehiclesRefreshed,
        public array $servingVehicles = [],
        public ?DateTimeImmutable $servingWindowStartedAt = null,
        public ?DateTimeImmutable $servingWindowEndsAt = null,
        public int $servingCandidateJourneyCount = 0,
        public int $servingQueriedJourneyCount = 0,
        public bool $servingVehiclesTruncated = false,
        public bool $servingVehiclesRefreshed = false,
        public ?DepartureBoard $departureBoard = null,
    ) {
    }
}
