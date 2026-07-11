<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Departure;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\VehicleJourneyReference;

interface JourneyPlannerInterface
{
    /** @return list<Departure> */
    public function departures(string $stationId, int $limit = 20): array;

    public function journey(VehicleJourneyReference $reference): ?JourneySnapshot;
}
