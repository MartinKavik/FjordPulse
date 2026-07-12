<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;

final readonly class StationServiceCall
{
    public function __construct(
        public VehicleJourneyReference $journeyReference,
        public int $order,
        public ?string $quayId,
        public ?DateTimeImmutable $aimedArrivalAt,
        public ?DateTimeImmutable $expectedArrivalAt,
        public ?DateTimeImmutable $actualArrivalAt,
        public ?DateTimeImmutable $aimedDepartureAt,
        public ?DateTimeImmutable $expectedDepartureAt,
        public ?DateTimeImmutable $actualDepartureAt,
        public bool $cancellation,
    ) {
        if ($order < 0 || $order > 999) {
            throw new \InvalidArgumentException('Station service call order must be between zero and 999.');
        }
    }

    public function arrivalAt(): ?DateTimeImmutable
    {
        return $this->actualArrivalAt ?? $this->expectedArrivalAt ?? $this->aimedArrivalAt;
    }

    public function departureAt(): ?DateTimeImmutable
    {
        return $this->actualDepartureAt ?? $this->expectedDepartureAt ?? $this->aimedDepartureAt;
    }

    public function displayAt(): ?DateTimeImmutable
    {
        return $this->departureAt() ?? $this->arrivalAt();
    }
}
