<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class CleanupReport
{
    public function __construct(
        public int $vehicleObservations,
        public int $realtimeEvents,
        public int $expiredWatches,
        public int $enturRequestLogs,
    ) {
    }

    public function total(): int
    {
        return $this->vehicleObservations
            + $this->realtimeEvents
            + $this->expiredWatches
            + $this->enturRequestLogs;
    }
}
