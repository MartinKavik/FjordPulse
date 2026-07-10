<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\SourceState;

final readonly class StationSnapshot
{
    /**
     * @param list<Departure> $departures
     * @param list<VehicleState> $nearbyVehicles
     */
    public function __construct(
        public string $stationId,
        public string $version,
        public string $contentHash,
        public DateTimeImmutable $updatedAt,
        public SourceState $state,
        public array $departures,
        public array $nearbyVehicles,
        public ?DateTimeImmutable $lastSuccessfulAt = null,
        public ?string $warning = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stationId' => $this->stationId,
            'version' => $this->version,
            'updatedAt' => $this->updatedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'state' => $this->state->value,
            'lastSuccessfulAt' => $this->lastSuccessfulAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'warning' => $this->warning,
            'departures' => array_map(static fn(Departure $departure): array => $departure->toArray(), $this->departures),
            'nearbyVehicles' => array_map(static fn(VehicleState $vehicle): array => $vehicle->toSummaryArray(), $this->nearbyVehicles),
        ];
    }

    /**
     * @param list<Departure> $departures
     * @param list<VehicleState> $nearbyVehicles
     */
    public static function semanticHash(
        SourceState $state,
        array $departures,
        array $nearbyVehicles,
        ?string $warning = null,
    ): string {
        return hash('sha256', json_encode([
            'state' => $state->value,
            'departures' => array_map(static fn(Departure $departure): array => $departure->toArray(), $departures),
            'nearbyVehicles' => array_map(static fn(VehicleState $vehicle): array => $vehicle->toSummaryArray(), $nearbyVehicles),
            'warning' => $warning,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
