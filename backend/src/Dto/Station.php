<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\StationKind;

final readonly class Station
{
    /**
     * @param list<string> $transportModes
     */
    public function __construct(
        public string $id,
        public string $name,
        public StationKind $kind,
        public Coordinate $coordinate,
        public ?string $locality,
        public ?string $municipality,
        public array $transportModes,
        public DateTimeImmutable $importedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'latitude' => $this->coordinate->latitude,
            'longitude' => $this->coordinate->longitude,
            'locality' => $this->locality,
            'municipality' => $this->municipality,
            'transportModes' => $this->transportModes,
            'importedAt' => $this->importedAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
