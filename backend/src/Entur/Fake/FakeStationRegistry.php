<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use FjordPulse\Dto\Station;
use FjordPulse\Entur\StationPage;
use FjordPulse\Entur\StationRegistryInterface;

final readonly class FakeStationRegistry implements StationRegistryInterface
{
    public function page(int $offset, int $limit): StationPage
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Station page offset cannot be negative.');
        }
        if ($limit < 1 || $limit > 5_000) {
            throw new \InvalidArgumentException('Station page size must be between 1 and 5000.');
        }
        $stations = array_slice(FixtureFactory::stations(), $offset, $limit);

        return new StationPage($offset, count($stations), $stations);
    }

    /** @return list<Station> */
    public function stations(int $limit = 1_000): array
    {
        return array_slice(FixtureFactory::stations(), 0, max(0, $limit));
    }
}
