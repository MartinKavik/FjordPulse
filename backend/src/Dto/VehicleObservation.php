<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class VehicleObservation
{
    public function __construct(
        public string $id,
        public string $vehicleId,
        public Coordinate $coordinate,
        public DateTimeImmutable $observedAt,
        public ?float $bearing,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'latitude' => $this->coordinate->latitude,
            'longitude' => $this->coordinate->longitude,
            'bearing' => $this->bearing,
            'observedAt' => $this->observedAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
