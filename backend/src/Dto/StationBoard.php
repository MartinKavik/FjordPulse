<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;

final readonly class StationBoard
{
    /**
     * @param list<Departure> $departures
     * @param list<StationServiceCall> $serviceCalls
     */
    public function __construct(
        public array $departures,
        public array $serviceCalls,
        public DateTimeImmutable $serviceWindowStartedAt,
        public DateTimeImmutable $serviceWindowEndsAt,
        public int $candidateJourneyCount,
        public int $queriedJourneyCount,
        public bool $serviceCallsTruncated,
    ) {
    }
}
