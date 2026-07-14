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
use FjordPulse\Dto\StationTimetable;
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
        $windowStart = $now->setTimezone(new \DateTimeZone('Europe/Oslo'));
        $windowEnd = $windowStart->setTime(0, 0)->modify('+1 day');
        $eligible = array_values(array_filter(
            $this->departuresForServiceDay($stationId, $windowStart),
            static fn(Departure $departure): bool =>
                $departure->aimedDepartureAt >= $windowStart && $departure->aimedDepartureAt < $windowEnd,
        ));
        $departures = array_slice($eligible, 0, max(0, $limit));
        $operatingDates = [];
        foreach (FixtureFactory::vehicles() as $vehicle) {
            if ($vehicle->journeyReference !== null) {
                $operatingDates[$vehicle->journeyReference->serviceJourneyId] = $vehicle->journeyReference->operatingDate;
            }
        }
        $calls = [];
        foreach ($departures as $departure) {
            if ($departure->serviceJourneyId === null) {
                continue;
            }
            $calls[] = new StationServiceCall(
                new VehicleJourneyReference(
                    $departure->serviceJourneyId,
                    $operatingDates[$departure->serviceJourneyId] ?? $departure->aimedDepartureAt->format('Y-m-d'),
                ),
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
            $windowStart,
            $windowEnd,
            $limit,
            count($eligible) > $limit,
        );
    }

    public function dailyTimetable(string $stationId, DateTimeImmutable $serviceDay): StationTimetable
    {
        $windowStart = $serviceDay->setTime(0, 0);
        $windowEnd = $windowStart->modify('+1 day');
        $departures = $this->departuresForServiceDay($stationId, $windowStart);

        return StationTimetable::create(
            $stationId,
            $windowStart->format('Y-m-d'),
            $windowStart->getTimezone()->getName(),
            $windowStart,
            $windowEnd,
            $departures,
            true,
        );
    }

    /** @return list<Departure> */
    private function departuresForServiceDay(string $stationId, DateTimeImmutable $windowStart): array
    {
        return array_map(static function (Departure $departure) use ($windowStart): Departure {
            $sourceAimed = $departure->aimedDepartureAt->setTimezone($windowStart->getTimezone());
            $aimed = $windowStart->setTime(
                (int)$sourceAimed->format('H'),
                (int)$sourceAimed->format('i'),
                (int)$sourceAimed->format('s'),
            );
            $expected = $departure->expectedDepartureAt === null
                ? null
                : $aimed->modify(sprintf(
                    '%+d seconds',
                    $departure->expectedDepartureAt->getTimestamp() - $departure->aimedDepartureAt->getTimestamp(),
                ));

            return new Departure(
                $departure->id,
                $departure->serviceJourneyId,
                $departure->lineId,
                $departure->lineCode,
                $departure->destination,
                $aimed,
                $expected,
                $departure->status,
                $departure->delaySeconds,
                $departure->platform,
                $departure->realtime,
            );
        }, $this->departures($stationId, 50));
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
