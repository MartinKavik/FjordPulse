<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use FjordPulse\Dto\Station;
use FjordPulse\Entur\GeocoderInterface;

final readonly class FakeGeocoder implements GeocoderInterface
{
    /** @return list<Station> */
    public function search(string $query, int $limit = 10): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        $matches = array_values(array_filter(
            [...FixtureFactory::stations(), ...FixtureFactory::places()],
            static fn(Station $station): bool => str_contains(mb_strtolower($station->name), $needle)
                || ($station->locality !== null && str_contains(mb_strtolower($station->locality), $needle)),
        ));

        return array_slice($matches, 0, max(0, $limit));
    }
}
