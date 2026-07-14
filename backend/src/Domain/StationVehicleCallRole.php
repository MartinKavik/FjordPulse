<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum StationVehicleCallRole: string
{
    case StartsHere = 'starts_here';
    case CallsHere = 'calls_here';
}
