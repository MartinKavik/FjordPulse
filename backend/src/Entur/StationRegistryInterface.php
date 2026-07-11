<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Station;

interface StationRegistryInterface
{
    public function page(int $offset, int $limit): StationPage;

    /** @return list<Station> */
    public function stations(int $limit = 1_000): array;
}
