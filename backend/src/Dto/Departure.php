<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\DepartureStatus;

final readonly class Departure
{
    public function __construct(
        public string $id,
        public ?string $serviceJourneyId,
        public ?string $lineId,
        public ?string $lineCode,
        public ?string $destination,
        public DateTimeImmutable $aimedDepartureAt,
        public ?DateTimeImmutable $expectedDepartureAt,
        public DepartureStatus $status,
        public ?int $delaySeconds,
        public ?string $platform,
        public bool $realtime,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'serviceJourneyId' => $this->serviceJourneyId,
            'lineId' => $this->lineId,
            'lineCode' => $this->lineCode,
            'destination' => $this->destination,
            'aimedDepartureAt' => $this->aimedDepartureAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'expectedDepartureAt' => $this->expectedDepartureAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'status' => $this->status->value,
            'delaySeconds' => $this->delaySeconds,
            'platform' => $this->platform,
            'realtime' => $this->realtime,
        ];
    }
}
