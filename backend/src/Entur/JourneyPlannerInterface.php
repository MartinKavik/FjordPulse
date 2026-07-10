<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Departure;

interface JourneyPlannerInterface
{
    /** @return list<Departure> */
    public function departures(string $stationId, int $limit = 20): array;
}
