<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum StationVehicleProgress: string
{
    case AtStation = 'at_station';
    case BeforeStation = 'before_station';
    case AfterStation = 'after_station';
    case Unknown = 'unknown';
}
