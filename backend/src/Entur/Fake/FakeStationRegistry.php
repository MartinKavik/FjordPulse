<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use FjordPulse\Dto\Station;
use FjordPulse\Entur\StationRegistryInterface;

final readonly class FakeStationRegistry implements StationRegistryInterface
{
    /** @return list<Station> */
    public function stations(int $limit = 1_000): array
    {
        return array_slice(FixtureFactory::stations(), 0, max(0, $limit));
    }
}
