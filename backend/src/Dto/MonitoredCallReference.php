<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

final readonly class MonitoredCallReference
{
    public function __construct(
        public ?string $stopPointRef,
        public int $order,
        public bool $vehicleAtStop,
    ) {
        if ($order < 0) {
            throw new \InvalidArgumentException('Monitored call order must be zero-based and non-negative.');
        }
    }

    /** @return array{stopPointRef: ?string, order: int, vehicleAtStop: bool} */
    public function toArray(): array
    {
        return [
            'stopPointRef' => $this->stopPointRef,
            'order' => $this->order,
            'vehicleAtStop' => $this->vehicleAtStop,
        ];
    }
}
