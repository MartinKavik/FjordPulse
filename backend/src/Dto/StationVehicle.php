<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\StationVehicleRelation;

final readonly class StationVehicle
{
    public function __construct(
        public VehicleState $vehicle,
        public StationVehicleRelation $relation,
        public ?DateTimeImmutable $stationCallAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->vehicle->toSummaryArray(),
            'relation' => $this->relation->value,
            'stationCallAt' => $this->stationCallAt?->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
