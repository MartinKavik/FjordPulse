<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Station;

interface GeocoderInterface
{
    /** @return list<Station> */
    public function search(string $query, int $limit = 10): array;
}
