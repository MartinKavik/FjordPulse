<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum WatchState: string
{
    case Active = 'active';
    case Stale = 'stale';
    case Backoff = 'backoff';
    case Failed = 'failed';
    case Expired = 'expired';
}
