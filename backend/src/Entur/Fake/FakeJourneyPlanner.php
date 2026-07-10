<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\Scenario;
use FjordPulse\Dto\Departure;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\ScenarioProviderInterface;
use FjordPulse\Entur\SourceUnavailable;

final readonly class FakeJourneyPlanner implements JourneyPlannerInterface
{
    public function __construct(private ScenarioProviderInterface $scenarios)
    {
    }

    /** @return list<Departure> */
    public function departures(string $stationId, int $limit = 20): array
    {
        return match ($this->scenarios->current()) {
            Scenario::StationEmpty => [],
            Scenario::StationError => throw new SourceUnavailable('Deterministic station source failure.'),
            Scenario::EnturBackoff => throw new RateLimited((new DateTimeImmutable())->add(new DateInterval('PT30S'))),
            default => array_slice(FixtureFactory::departures($stationId), 0, max(0, $limit)),
        };
    }
}
