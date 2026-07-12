<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum StationVehicleRelation: string
{
    case StartingHere = 'starting_here';
    case Approaching = 'approaching';
    case AtStation = 'at_station';
    case Departed = 'departed';
    case ServesStation = 'serves_station';
}
