<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Real;

use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Departure;
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
}
