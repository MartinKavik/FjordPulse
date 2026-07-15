<?php

declare(strict_types=1);

namespace FjordPulse\Time;

use DateTimeImmutable;

interface ClockInterface
{
    /** Return the current instant normalized to UTC. */
    public function now(): DateTimeImmutable;
}
