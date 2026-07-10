<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum VehicleFreshness: string
{
    case Live = 'live';
    case Stale = 'stale';
    case Lost = 'lost';
}
