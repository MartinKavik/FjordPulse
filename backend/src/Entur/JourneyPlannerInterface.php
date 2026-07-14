<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\StationBoard;
use FjordPulse\Dto\StationTimetable;
use FjordPulse\Dto\VehicleJourneyReference;

interface JourneyPlannerInterface
{
    /** @return list<Departure> */
    public function departures(string $stationId, int $limit = 20): array;

    public function stationBoard(string $stationId, DateTimeImmutable $now, int $limit = 20): StationBoard;

    public function dailyTimetable(string $stationId, DateTimeImmutable $serviceDay): StationTimetable;

    public function journey(VehicleJourneyReference $reference): ?JourneySnapshot;
}
