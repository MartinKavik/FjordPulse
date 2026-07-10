<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Real;

use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Station;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Mapper\StopPlaceMapper;
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
        if ($limit < 1 || $limit > 50_000) {
            throw new \InvalidArgumentException('Station import limit must be between 1 and 50000.');
        }
        $stations = [];
        $skip = 0;
        while (count($stations) < $limit) {
            $pageSize = min(1_000, $limit - count($stations));
            $url = rtrim($this->baseUrl, '/') . '/stop-places?' . http_build_query([
                'count' => $pageSize,
                'skip' => $skip,
            ]);
            $payload = $this->client->json(
                EnturService::StopPlaceRegister,
                'GET',
                $url,
                "stations:import:{$skip}",
            );
            $page = $this->mapper->map($payload);
            foreach ($page as $station) {
                $stations[$station->id] = $station;
            }
            if (count($page) < $pageSize) {
                break;
            }
            $skip += $pageSize;
        }

        return array_slice(array_values($stations), 0, $limit);
    }
}
