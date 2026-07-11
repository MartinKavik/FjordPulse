<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Real;

use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Station;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Mapper\StopPlaceMapper;
use FjordPulse\Entur\StationPage;
use FjordPulse\Entur\StationRegistryInterface;

final readonly class RealStationRegistry implements StationRegistryInterface
{
    public function __construct(
        private EnturApiClient $client,
        private StopPlaceMapper $mapper,
        private string $baseUrl = 'https://api.entur.io/stop-places/v1/read',
    ) {
    }

    /** @return list<Station> */
    public function stations(int $limit = 1_000): array
    {
        if ($limit < 1 || $limit > 250_000) {
            throw new \InvalidArgumentException('Station import limit must be between 1 and 250000.');
        }
        $stations = [];
        $skip = 0;
        while (count($stations) < $limit) {
            $pageSize = min(5_000, $limit - count($stations));
            $page = $this->page($skip, $pageSize);
            foreach ($page->stations as $station) {
                $stations[$station->id] = $station;
            }
            if ($page->terminal($pageSize)) {
                break;
            }
            $skip += $page->sourceItemCount;
        }

        return array_slice(array_values($stations), 0, $limit);
    }

    public function page(int $offset, int $limit): StationPage
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Station page offset cannot be negative.');
        }
        if ($limit < 1 || $limit > 5_000) {
            throw new \InvalidArgumentException('Station page size must be between 1 and the verified Entur page size of 5000.');
        }
        $url = rtrim($this->baseUrl, '/') . '/stop-places?' . http_build_query([
            'count' => $limit,
            'skip' => $offset,
        ]);
        $payload = $this->client->json(
            EnturService::StopPlaceRegister,
            'GET',
            $url,
            "stations:import:{$offset}",
        );

        return new StationPage($offset, count($payload), $this->mapper->map($payload));
    }
}
