<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Real;

use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\StationBoard;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;

final readonly class RealJourneyPlanner implements JourneyPlannerInterface
{
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

    private const string STATION_BOARD_QUERY = <<<'GRAPHQL'
query StationBoard($id: String!, $limit: Int!, $vehicleWindowStart: DateTime!, $vehicleWindowNow: DateTime!) {
  stopPlace(id: $id) {
    departureCalls: estimatedCalls(timeRange: 7200, numberOfDepartures: $limit) {
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
        $payload = $this->client->json(
            EnturService::JourneyPlanner,
            'POST',
            $this->url,
            'station:' . $stationId,
            [
                'query' => self::STATION_BOARD_QUERY,
                'variables' => [
                    'id' => $stationId,
                    'limit' => max(1, min(50, $limit)),
                    'vehicleWindowStart' => $now->modify('-6 hours')->format(DATE_RFC3339),
                    'vehicleWindowNow' => $now->format(DATE_RFC3339),
                ],
            ],
        );

        return $this->mapper->mapStationBoard($payload, $now);
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
