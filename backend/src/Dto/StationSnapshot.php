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
     * @param list<StationVehicle> $servingVehicles
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
        public array $servingVehicles = [],
        public ?DateTimeImmutable $servingWindowStartedAt = null,
        public ?DateTimeImmutable $servingWindowEndsAt = null,
        public int $servingCandidateJourneyCount = 0,
        public int $servingQueriedJourneyCount = 0,
        public bool $servingVehiclesTruncated = false,
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
            'servingVehicles' => array_map(static fn(StationVehicle $vehicle): array => $vehicle->toArray(), $this->servingVehicles),
            'servingVehicleCoverage' => [
                'windowStart' => $this->servingWindowStartedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
                'windowEnd' => $this->servingWindowEndsAt?->format(DateTimeInterface::RFC3339_EXTENDED),
                'candidateJourneyCount' => $this->servingCandidateJourneyCount,
                'queriedJourneyCount' => $this->servingQueriedJourneyCount,
                'truncated' => $this->servingVehiclesTruncated,
            ],
        ];
    }

    /**
     * @param list<Departure> $departures
     * @param list<VehicleState> $nearbyVehicles
     * @param list<StationVehicle> $servingVehicles
     */
    public static function semanticHash(
        SourceState $state,
        array $departures,
        array $nearbyVehicles,
        ?string $warning = null,
        array $servingVehicles = [],
        int $servingCandidateJourneyCount = 0,
        int $servingQueriedJourneyCount = 0,
        bool $servingVehiclesTruncated = false,
    ): string {
        return hash('sha256', json_encode([
            'state' => $state->value,
            'departures' => array_map(static fn(Departure $departure): array => $departure->toArray(), $departures),
            'nearbyVehicles' => array_map(self::vehicleSemanticSummary(...), $nearbyVehicles),
            'servingVehicles' => array_map(self::stationVehicleSemanticSummary(...), $servingVehicles),
            'servingVehicleCoverage' => [
                'candidateJourneyCount' => $servingCandidateJourneyCount,
                'queriedJourneyCount' => $servingQueriedJourneyCount,
                'truncated' => $servingVehiclesTruncated,
            ],
            'warning' => $warning,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private static function vehicleSemanticSummary(VehicleState $vehicle): array
    {
        $summary = $vehicle->toSummaryArray();
        unset($summary['version']);

        return $summary;
    }

    /** @return array<string, mixed> */
    private static function stationVehicleSemanticSummary(StationVehicle $stationVehicle): array
    {
        return [
            ...self::vehicleSemanticSummary($stationVehicle->vehicle),
            'relation' => $stationVehicle->relation->value,
            'stationCallAt' => $stationVehicle->stationCallAt?->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
