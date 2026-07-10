<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class StopCall
{
    public function __construct(
        public string $stopPlaceId,
        public string $name,
        public DateTimeImmutable $aimedArrivalAt,
        public ?DateTimeImmutable $expectedArrivalAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stopPlaceId' => $this->stopPlaceId,
            'name' => $this->name,
            'aimedArrivalAt' => $this->aimedArrivalAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'expectedArrivalAt' => $this->expectedArrivalAt?->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
