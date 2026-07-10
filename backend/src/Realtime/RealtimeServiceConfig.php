<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

final readonly class RealtimeServiceConfig
{
    /** @param list<string> $allowedOrigins */
    public function __construct(
        public string $host,
        public int $port,
        public array $allowedOrigins,
        public int $maximumMessageBytes = 65_536,
        public int $messagesPerWindow = 30,
        public float $rateWindowSeconds = 10.0,
        public float $schedulerIntervalSeconds = 0.5,
        public float $telemetryIntervalSeconds = 10.0,
    ) {
        if ($host === '' || $port < 1 || $port > 65_535) {
            throw new \InvalidArgumentException('Realtime bind address is invalid.');
        }
        if ($allowedOrigins === [] || $maximumMessageBytes < 256 || $messagesPerWindow < 1
            || $rateWindowSeconds <= 0 || $schedulerIntervalSeconds <= 0 || $telemetryIntervalSeconds <= 0) {
            throw new \InvalidArgumentException('Realtime service limits are invalid.');
        }
    }
}
