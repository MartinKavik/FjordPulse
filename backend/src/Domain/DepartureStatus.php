<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum DepartureStatus: string
{
    case Scheduled = 'scheduled';
    case Realtime = 'realtime';
    case Delayed = 'delayed';
    case Cancelled = 'cancelled';
    case Departed = 'departed';
    case Unknown = 'unknown';
}
