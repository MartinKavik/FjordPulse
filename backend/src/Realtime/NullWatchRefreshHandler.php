<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\Watch;

final class NullWatchRefreshHandler implements WatchRefreshHandler
{
    public function refresh(Watch $watch): void
    {
        unset($watch);
    }
}
