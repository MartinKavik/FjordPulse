<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class StopCall
{
    public function __construct(
        public ?string $stopPlaceId,
        public string $name,
        public ?DateTimeImmutable $aimedArrivalAt,
        public ?DateTimeImmutable $expectedArrivalAt,
        public int $order = 0,
        public ?string $quayId = null,
        public ?Coordinate $coordinate = null,
        public ?DateTimeImmutable $aimedDepartureAt = null,
        public ?DateTimeImmutable $expectedDepartureAt = null,
        public bool $realtime = false,
        public bool $cancellation = false,
    ) {
        if ($name === '' || $order < 0) {
            throw new \InvalidArgumentException('Stop call name is required and order must be non-negative.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stopPlaceId' => $this->stopPlaceId,
            'quayId' => $this->quayId,
            'name' => $this->name,
            'order' => $this->order,
            'latitude' => $this->coordinate?->latitude,
            'longitude' => $this->coordinate?->longitude,
            'aimedArrivalAt' => $this->aimedArrivalAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'expectedArrivalAt' => $this->expectedArrivalAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'aimedDepartureAt' => $this->aimedDepartureAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'expectedDepartureAt' => $this->expectedDepartureAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'realtime' => $this->realtime,
            'cancellation' => $this->cancellation,
        ];
    }
}
