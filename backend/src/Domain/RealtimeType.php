<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum RealtimeType: string
{
    case StationSnapshotChanged = 'station_snapshot_changed';
    case StationDeparturesChanged = 'station_departures_changed';
    case NearbyVehiclesChanged = 'nearby_vehicles_changed';
    case VehicleMoved = 'vehicle_moved';
    case VehicleStale = 'vehicle_stale';
    case VehicleLost = 'vehicle_lost';
    case SourceBackoff = 'source_backoff';
    case RateLimited = 'rate_limited';
    case RealtimeDegraded = 'realtime_degraded';
    case ResyncRequired = 'resync_required';
}
