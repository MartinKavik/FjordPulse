<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum Scenario: string
{
    case Normal = 'normal';
    case StationEmpty = 'station_empty';
    case StationStale = 'station_stale';
    case StationError = 'station_error';
    case VehicleLive = 'vehicle_live';
    case VehicleStale = 'vehicle_stale';
    case VehicleLost = 'vehicle_lost';
    case Fallback = 'fallback';
    case EnturBackoff = 'entur_backoff';
    case RealtimeReconnect = 'realtime_reconnect';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $scenario): string => $scenario->value, self::cases());
    }
}
