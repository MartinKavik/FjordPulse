<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

interface SnapshotProvider
{
    public function station(string $stationId): ?AuthoritativeSnapshot;

    public function vehicle(string $vehicleId): ?AuthoritativeSnapshot;
}
