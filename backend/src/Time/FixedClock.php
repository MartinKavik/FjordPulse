<?php

declare(strict_types=1);

namespace FjordPulse\Time;

use DateTimeImmutable;
use DateTimeZone;

final readonly class FixedClock implements ClockInterface
{
    private DateTimeImmutable $fixedAt;

    public function __construct(DateTimeImmutable $fixedAt)
    {
        $this->fixedAt = $fixedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixedAt;
    }
}
