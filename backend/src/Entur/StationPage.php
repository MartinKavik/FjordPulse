<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Station;
use InvalidArgumentException;

final readonly class StationPage
{
    /** @param list<Station> $stations */
    public function __construct(
        public int $offset,
        public int $sourceItemCount,
        public array $stations,
    ) {
        if ($offset < 0 || $sourceItemCount < 0) {
            throw new InvalidArgumentException('Station page offsets and counts cannot be negative.');
        }
    }

    public function terminal(int $requestedPageSize): bool
    {
        return $this->sourceItemCount < $requestedPageSize;
    }
}
