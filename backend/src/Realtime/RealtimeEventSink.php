<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\RealtimeEvent;

interface RealtimeEventSink
{
    public function publish(RealtimeEvent $event): void;
}
