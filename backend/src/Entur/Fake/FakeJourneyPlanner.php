<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\Scenario;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\StationBoard;
use FjordPulse\Dto\StationServiceCall;
use FjordPulse\Dto\VehicleJourneyReference;
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

    public function stationBoard(string $stationId, DateTimeImmutable $now, int $limit = 20): StationBoard
    {
        $departures = $this->departures($stationId, $limit);
        $calls = [];
        foreach ($departures as $departure) {
            if ($departure->serviceJourneyId === null) {
                continue;
            }
            $calls[] = new StationServiceCall(
                new VehicleJourneyReference($departure->serviceJourneyId, $departure->aimedDepartureAt->format('Y-m-d')),
                0,
                null,
                $departure->aimedDepartureAt,
                $departure->expectedDepartureAt,
                null,
                $departure->aimedDepartureAt,
                $departure->expectedDepartureAt,
                null,
                $departure->status === \FjordPulse\Domain\DepartureStatus::Cancelled,
            );
        }

        return new StationBoard(
            $departures,
            $calls,
            $now->modify('-6 hours'),
            $now->modify('+6 hours'),
            count($calls),
            count($calls),
            false,
        );
    }

    public function journey(VehicleJourneyReference $reference): JourneySnapshot
    {
        return match ($this->scenarios->current()) {
            Scenario::StationError => throw new SourceUnavailable('Deterministic journey source failure.'),
            Scenario::EnturBackoff => throw new RateLimited((new DateTimeImmutable())->add(new DateInterval('PT30S'))),
            default => FixtureFactory::journey($reference),
        };
    }
}
