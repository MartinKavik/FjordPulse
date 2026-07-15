<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;

final readonly class PersistenceDiagnostics
{
    /** @param list<AppliedMigration> $recentMigrations */
    public function __construct(
        public int $stations,
        public int $stationSnapshots,
        public int $stationTimetables,
        public int $currentVehicles,
        public int $journeySnapshots,
        public int $vehicleObservations,
        public int $watches,
        public int $realtimeEvents,
        public int $enturRequestLogs,
        public ?DateTimeImmutable $lastStationImportedAt,
        public ?string $stationSource,
        public ?string $stationSourceVersion,
        public array $recentMigrations,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'counts' => [
                'stations' => $this->stations,
                'stationSnapshots' => $this->stationSnapshots,
                'stationTimetables' => $this->stationTimetables,
                'currentVehicles' => $this->currentVehicles,
                'journeySnapshots' => $this->journeySnapshots,
                'vehicleObservations' => $this->vehicleObservations,
                'watches' => $this->watches,
                'realtimeEvents' => $this->realtimeEvents,
                'enturRequestLogs' => $this->enturRequestLogs,
            ],
            'stationImport' => [
                'lastImportedAt' => $this->lastStationImportedAt?->format(DATE_RFC3339_EXTENDED),
                'source' => $this->stationSource,
                'sourceVersion' => $this->stationSourceVersion,
            ],
            'recentMigrations' => array_map(static fn(AppliedMigration $migration): array => [
                'name' => $migration->name,
                'checksum' => $migration->checksum,
                'appliedAt' => $migration->appliedAt->format(DATE_RFC3339_EXTENDED),
            ], $this->recentMigrations),
        ];
    }
}
