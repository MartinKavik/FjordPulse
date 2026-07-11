<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

final readonly class VehicleJourneyReference
{
    public function __construct(
        public string $serviceJourneyId,
        public string $operatingDate,
        public ?string $datedServiceJourneyId = null,
        public ?string $originRef = null,
        public ?string $originName = null,
        public ?string $destinationRef = null,
        public ?string $destinationName = null,
    ) {
        if ($serviceJourneyId === '') {
            throw new \InvalidArgumentException('Service journey id must not be empty.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $operatingDate);
        if ($date === false || $date->format('Y-m-d') !== $operatingDate) {
            throw new \InvalidArgumentException('Operating date must use YYYY-MM-DD.');
        }
    }

    public function key(): string
    {
        return $this->serviceJourneyId . '|' . $this->operatingDate;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'serviceJourneyId' => $this->serviceJourneyId,
            'operatingDate' => $this->operatingDate,
            'datedServiceJourneyId' => $this->datedServiceJourneyId,
            'originRef' => $this->originRef,
            'originName' => $this->originName,
            'destinationRef' => $this->destinationRef,
            'destinationName' => $this->destinationName,
        ];
    }
}
