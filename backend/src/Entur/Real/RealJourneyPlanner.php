<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Real;

use DateTimeZone;
use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\StationBoard;
use FjordPulse\Dto\StationTimetable;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;

final readonly class RealJourneyPlanner implements JourneyPlannerInterface
{
    private const int DAILY_CALL_LIMIT = 1_000;
    private const int MIN_DAILY_WINDOW_SECONDS = 900;

    private const string QUERY = <<<'GRAPHQL'
query Departures($id: String!, $limit: Int!) {
  stopPlace(id: $id) {
    estimatedCalls(timeRange: 7200, numberOfDepartures: $limit) {
      aimedDepartureTime
      expectedDepartureTime
      actualDepartureTime
      cancellation
      date
      quay { id publicCode }
      destinationDisplay { frontText }
      serviceJourney {
        id
        journeyPattern { line { id publicCode name } }
      }
    }
  }
}
GRAPHQL;

    private const string DAILY_TIMETABLE_QUERY = <<<'GRAPHQL'
query DailyTimetable($id: String!, $startTime: DateTime!, $timeRange: Int!, $limit: Int!) {
  stopPlace(id: $id) {
    estimatedCalls(
      startTime: $startTime
      timeRange: $timeRange
      numberOfDepartures: $limit
      includeCancelledTrips: true
    ) {
      aimedDepartureTime
      expectedDepartureTime
      actualDepartureTime
      cancellation
      date
      quay { id publicCode }
      destinationDisplay { frontText }
      serviceJourney {
        id
        journeyPattern { line { id publicCode name } }
      }
    }
  }
}
GRAPHQL;

    private const string STATION_BOARD_QUERY = <<<'GRAPHQL'
query StationBoard(
  $id: String!
  $limit: Int!
  $departureWindowStart: DateTime!
  $departureTimeRange: Int!
  $vehicleWindowStart: DateTime!
  $vehicleWindowNow: DateTime!
) {
  stopPlace(id: $id) {
    departureCalls: estimatedCalls(
      startTime: $departureWindowStart
      timeRange: $departureTimeRange
      numberOfDepartures: $limit
      includeCancelledTrips: true
    ) {
      aimedDepartureTime
      expectedDepartureTime
      actualDepartureTime
      cancellation
      date
      quay { id publicCode }
      destinationDisplay { frontText }
      serviceJourney {
        id
        journeyPattern { line { id publicCode name } }
      }
    }
    recentVehicleCalls: estimatedCalls(
      startTime: $vehicleWindowStart
      timeRange: 21600
      numberOfDepartures: 200
      arrivalDeparture: both
    ) {
      date
      stopPositionInPattern
      aimedArrivalTime
      expectedArrivalTime
      actualArrivalTime
      aimedDepartureTime
      expectedDepartureTime
      actualDepartureTime
      cancellation
      quay { id stopPlace { id } }
      serviceJourney { id }
    }
    upcomingVehicleCalls: estimatedCalls(
      startTime: $vehicleWindowNow
      timeRange: 21600
      numberOfDepartures: 200
      arrivalDeparture: both
    ) {
      date
      stopPositionInPattern
      aimedArrivalTime
      expectedArrivalTime
      actualArrivalTime
      aimedDepartureTime
      expectedDepartureTime
      actualDepartureTime
      cancellation
      quay { id stopPlace { id } }
      serviceJourney { id }
    }
  }
}
GRAPHQL;

    private const string JOURNEY_QUERY = <<<'GRAPHQL'
query ServiceJourney($id: String!, $date: Date!) {
  serviceJourney(id: $id) {
    id
    pointsOnLink { length points distance }
    estimatedCalls(date: $date) {
      stopPositionInPattern
      aimedArrivalTime
      expectedArrivalTime
      aimedDepartureTime
      expectedDepartureTime
      realtime
      cancellation
      quay {
        id
        name
        latitude
        longitude
        stopPlace { id name }
      }
    }
  }
}
GRAPHQL;

    public function __construct(
        private EnturApiClient $client,
        private JourneyPlannerMapper $mapper,
        private string $url = 'https://api.entur.io/journey-planner/v3/graphql',
    ) {
    }

    /** @return list<Departure> */
    public function departures(string $stationId, int $limit = 20): array
    {
        $payload = $this->client->json(
            EnturService::JourneyPlanner,
            'POST',
            $this->url,
            'station:' . $stationId,
            ['query' => self::QUERY, 'variables' => ['id' => $stationId, 'limit' => max(1, min(50, $limit))]],
        );

        return $this->mapper->map($payload);
    }

    public function stationBoard(string $stationId, \DateTimeImmutable $now, int $limit = 20): StationBoard
    {
        $limit = max(1, min(50, $limit));
        $departureWindowStart = $now->setTimezone(new DateTimeZone('Europe/Oslo'));
        $departureWindowEnd = $departureWindowStart->setTime(0, 0)->modify('+1 day');
        $departureTimeRange = max(1, $departureWindowEnd->getTimestamp() - $departureWindowStart->getTimestamp());
        $payload = $this->client->json(
            EnturService::JourneyPlanner,
            'POST',
            $this->url,
            'station:' . $stationId,
            [
                'query' => self::STATION_BOARD_QUERY,
                'variables' => [
                    'id' => $stationId,
                    'limit' => $limit + 1,
                    'departureWindowStart' => $departureWindowStart->format(DATE_RFC3339),
                    'departureTimeRange' => $departureTimeRange,
                    'vehicleWindowStart' => $now->modify('-6 hours')->format(DATE_RFC3339),
                    'vehicleWindowNow' => $now->format(DATE_RFC3339),
                ],
            ],
        );

        return $this->mapper->mapStationBoard(
            $payload,
            $now,
            $limit,
            $departureWindowStart,
            $departureWindowEnd,
        );
    }

    public function dailyTimetable(string $stationId, \DateTimeImmutable $serviceDay): StationTimetable
    {
        $windowStart = $serviceDay->setTime(0, 0);
        $windowEnd = $windowStart->modify('+1 day');
        [$departures, $complete] = $this->dailyWindow($stationId, $windowStart, $windowEnd);
        $deduplicated = [];
        foreach ($departures as $departure) {
            $key = implode('|', [
                $departure->id,
                $departure->aimedDepartureAt->format('U.u'),
                $departure->platform ?? '',
                $departure->lineId ?? '',
                $departure->destination ?? '',
            ]);
            $deduplicated[$key] = $departure;
        }
        $departures = array_values($deduplicated);
        usort($departures, static function (Departure $left, Departure $right): int {
            return [
                $left->aimedDepartureAt->format('U.u'),
                $left->id,
                $left->platform ?? '',
            ] <=> [
                $right->aimedDepartureAt->format('U.u'),
                $right->id,
                $right->platform ?? '',
            ];
        });

        return StationTimetable::create(
            $stationId,
            $windowStart->format('Y-m-d'),
            $windowStart->getTimezone()->getName(),
            $windowStart,
            $windowEnd,
            $departures,
            $complete,
        );
    }

    /**
     * Entur does not expose a cursor for estimated calls. Fetch a bounded
     * window and recursively split only windows that hit the requested cap.
     *
     * @return array{list<Departure>, bool}
     */
    private function dailyWindow(
        string $stationId,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
    ): array {
        $timeRange = $windowEnd->getTimestamp() - $windowStart->getTimestamp();
        if ($timeRange <= 0) {
            return [[], true];
        }
        $payload = $this->client->json(
            EnturService::JourneyPlanner,
            'POST',
            $this->url,
            'station-timetable:' . $stationId . ':' . $windowStart->format('Y-m-d\TH:i:sP'),
            [
                'query' => self::DAILY_TIMETABLE_QUERY,
                'variables' => [
                    'id' => $stationId,
                    'startTime' => $windowStart->format(DATE_RFC3339),
                    'timeRange' => $timeRange,
                    'limit' => self::DAILY_CALL_LIMIT,
                ],
            ],
        );
        $rawCallCount = $this->mapper->estimatedCallCount($payload);
        $departures = array_values(array_filter(
            $this->mapper->map($payload),
            static fn(Departure $departure): bool =>
                $departure->aimedDepartureAt >= $windowStart && $departure->aimedDepartureAt < $windowEnd,
        ));
        if ($rawCallCount < self::DAILY_CALL_LIMIT) {
            return [$departures, true];
        }
        if ($timeRange <= self::MIN_DAILY_WINDOW_SECONDS) {
            return [$departures, false];
        }

        $midpoint = $windowStart->setTimestamp($windowStart->getTimestamp() + intdiv($timeRange, 2));
        [$left, $leftComplete] = $this->dailyWindow($stationId, $windowStart, $midpoint);
        [$right, $rightComplete] = $this->dailyWindow($stationId, $midpoint, $windowEnd);

        return [[...$left, ...$right], $leftComplete && $rightComplete];
    }

    public function journey(VehicleJourneyReference $reference): ?JourneySnapshot
    {
        $payload = $this->client->json(
            EnturService::JourneyPlanner,
            'POST',
            $this->url,
            'journey:' . $reference->key(),
            [
                'query' => self::JOURNEY_QUERY,
                'variables' => [
                    'id' => $reference->serviceJourneyId,
                    'date' => $reference->operatingDate,
                ],
            ],
        );

        return $this->mapper->mapJourney($payload, $reference);
    }
}
