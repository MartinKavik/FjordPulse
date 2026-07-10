<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum EnturService: string
{
    case StopPlaceRegister = 'stop_place_register';
    case Geocoder = 'geocoder';
    case JourneyPlanner = 'journey_planner';
    case VehiclePositions = 'vehicle_positions';
}
