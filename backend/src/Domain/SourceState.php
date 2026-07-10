<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum SourceState: string
{
    case Loading = 'loading';
    case Fresh = 'fresh';
    case Refreshing = 'refreshing';
    case Empty = 'empty';
    case Stale = 'stale';
    case Unavailable = 'unavailable';
    case Error = 'error';
    case Backoff = 'backoff';
    case RateLimited = 'rate_limited';
}
