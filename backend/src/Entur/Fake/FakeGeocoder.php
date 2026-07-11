<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use FjordPulse\Dto\Station;
use FjordPulse\Entur\GeocoderInterface;
use FjordPulse\Service\SearchNormalizer;

final readonly class FakeGeocoder implements GeocoderInterface
{
    public function __construct(private SearchNormalizer $normalizer = new SearchNormalizer())
    {
    }

    /** @return list<Station> */
    public function search(string $query, int $limit = 10): array
    {
        $needle = $this->normalizer->normalize($query);
        if ($needle === '') {
            return [];
        }

        $matches = array_values(array_filter(
            [...FixtureFactory::stations(), ...FixtureFactory::places()],
            fn(Station $station): bool => str_contains($this->normalizer->normalize($station->name), $needle)
                || ($station->locality !== null && str_contains($this->normalizer->normalize($station->locality), $needle)),
        ));

        return array_slice($matches, 0, max(0, $limit));
    }
}
