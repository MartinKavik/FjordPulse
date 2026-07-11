<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\VehicleState;
use Throwable;

final readonly class StationRefreshOutcome
{
    /**
     * @param list<Departure> $departures
     * @param list<VehicleState> $nearbyVehicles
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
    ) {
    }
}
