<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum VehiclePassengerServiceState: string
{
    case Passenger = 'passenger';
    case NonPassenger = 'non_passenger';
    case Unknown = 'unknown';
}
