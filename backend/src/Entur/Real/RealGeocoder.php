<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Real;

use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Station;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\GeocoderInterface;
use FjordPulse\Entur\Mapper\GeocoderMapper;

final readonly class RealGeocoder implements GeocoderInterface
{
    public function __construct(
        private EnturApiClient $client,
        private GeocoderMapper $mapper,
        private string $baseUrl = 'https://api.entur.io/geocoder/v3',
    ) {
    }

    /** @return list<Station> */
    public function search(string $query, int $limit = 10): array
    {
        // The unfiltered endpoint returns stop places, addresses, and POIs. Limiting this
        // to stopPlace would make the public "station or place" search contract false.
        $parameters = ['q' => $query, 'lang' => 'no', 'limit' => max(1, min(50, $limit))];
        $url = rtrim($this->baseUrl, '/') . '/autocomplete?' . http_build_query($parameters, encoding_type: PHP_QUERY_RFC3986);
        $payload = $this->client->json(EnturService::Geocoder, 'GET', $url, 'search:' . hash('sha256', mb_strtolower($query)));

        return $this->mapper->map($payload);
    }
}
