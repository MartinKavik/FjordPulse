<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\StationVehicleCallRole;
use FjordPulse\Domain\StationVehicleProgress;

final readonly class StationVehicle
{
    public function __construct(
        public VehicleState $vehicle,
        public StationVehicleCallRole $callRole,
        public StationVehicleProgress $progress,
        public ?DateTimeImmutable $stationCallAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->vehicle->toSummaryArray(),
            'callRole' => $this->callRole->value,
            'progress' => $this->progress->value,
            'stationCallAt' => $this->stationCallAt?->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
