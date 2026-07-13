<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use FjordPulse\Domain\EnturService;

final readonly class EnturBudgetUsage
{
    /**
     * @param array<string, int> $services
     * @param array<string, DateTimeImmutable> $serviceAvailableAt
     */
    public function __construct(
        public int $global,
        public array $services,
        public ?DateTimeImmutable $globalAvailableAt,
        public array $serviceAvailableAt,
    ) {
    }

    public function service(EnturService $service): int
    {
        return $this->services[$service->value] ?? 0;
    }

    public function retryAt(EnturService $service, int $globalLimit, int $serviceLimit): ?DateTimeImmutable
    {
        $globalFull = $this->global >= $globalLimit;
        $serviceFull = $this->service($service) >= $serviceLimit;
        $globalAt = $globalFull ? $this->globalAvailableAt : null;
        $serviceAt = $serviceFull ? ($this->serviceAvailableAt[$service->value] ?? null) : null;

        if ($globalAt !== null && $serviceAt !== null) {
            return $globalAt > $serviceAt ? $globalAt : $serviceAt;
        }

        return $globalAt ?? $serviceAt;
    }
}
