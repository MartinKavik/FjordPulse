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
        public ?VehicleJourneyReference $journeyReference = null,
        public ?MonitoredCallReference $monitoredCall = null,
        public ?ProgressBetweenStops $progressBetweenStops = null,
        public ?string $journeyVersion = null,
        public ?float $routeProgress = null,
        public ?DateTimeImmutable $refreshedAt = null,
    ) {
        if ($routeProgress !== null && ($routeProgress < 0.0 || $routeProgress > 1.0)) {
            throw new \InvalidArgumentException('Vehicle route progress must be between zero and one.');
        }
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
            'refreshedAt' => ($this->refreshedAt ?? $this->updatedAt)->format(DateTimeInterface::RFC3339_EXTENDED),
            'version' => $this->version,
            'nextStop' => $this->nextStop?->toArray(),
            'journeyReference' => $this->journeyReference?->toArray(),
            'monitoredCall' => $this->monitoredCall?->toArray(),
            'progressBetweenStops' => $this->progressBetweenStops?->toArray(),
            'journeyVersion' => $this->journeyVersion,
            'routeProgress' => $this->routeProgress,
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
