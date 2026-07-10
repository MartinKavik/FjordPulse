<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\Watch;

final class NullWatchStore implements WatchStore
{
    public function save(Watch $watch): void
    {
        unset($watch);
    }

    public function delete(string $watchId): void
    {
        unset($watchId);
    }
}
