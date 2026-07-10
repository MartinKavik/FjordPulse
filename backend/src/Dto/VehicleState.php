<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\VehicleFreshness;

final readonly class VehicleState
{
    /**
     * @param list<VehicleObservation> $observations
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $contentHash,
        public VehicleFreshness $state,
        public ?Coordinate $coordinate,
        public ?string $lineCode,
        public ?string $routeName,
        public ?string $destination,
        public ?float $bearing,
        public ?int $delaySeconds,
        public ?float $distanceMeters,
        public DateTimeImmutable $lastSeenAt,
        public DateTimeImmutable $updatedAt,
        public ?StopCall $nextStop,
        public array $observations = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lineCode' => $this->lineCode,
            'routeName' => $this->routeName,
            'destination' => $this->destination,
            'state' => $this->state->value,
            'latitude' => $this->coordinate?->latitude,
            'longitude' => $this->coordinate?->longitude,
            'bearing' => $this->bearing,
            'delaySeconds' => $this->delaySeconds,
            'distanceMeters' => $this->distanceMeters,
            'lastSeenAt' => $this->lastSeenAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'version' => $this->version,
            'nextStop' => $this->nextStop?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'lineCode' => $this->lineCode,
            'destination' => $this->destination,
            'state' => $this->state->value,
            'latitude' => $this->coordinate?->latitude,
            'longitude' => $this->coordinate?->longitude,
            'bearing' => $this->bearing,
            'delaySeconds' => $this->delaySeconds,
            'distanceMeters' => $this->distanceMeters,
            'lastSeenAt' => $this->lastSeenAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'version' => $this->version,
        ];
    }
}
