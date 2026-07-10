<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

final class NullSnapshotProvider implements SnapshotProvider
{
    public function station(string $stationId): ?AuthoritativeSnapshot
    {
        unset($stationId);

        return null;
    }

    public function vehicle(string $vehicleId): ?AuthoritativeSnapshot
    {
        unset($vehicleId);

        return null;
    }
}
