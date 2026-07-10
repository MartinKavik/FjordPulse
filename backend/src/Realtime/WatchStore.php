<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\Watch;

interface WatchStore
{
    public function save(Watch $watch): void;

    public function delete(string $watchId): void;
}
