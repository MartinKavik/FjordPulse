<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\Watch;

interface WatchRefreshHandler
{
    public function refresh(Watch $watch): void;
}
