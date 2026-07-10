<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\EnturService;

final class RequestBudget implements RequestBudgetInterface
{
    /** @var array<string, list<float>> */
    private array $requests = [];

    /** @param array<string, int> $perServiceLimits */
    public function __construct(private readonly int $globalLimit, private readonly array $perServiceLimits)
    {
    }

    public function acquire(EnturService $service): void
    {
        $now = microtime(true);
        $threshold = $now - 60.0;
        foreach ($this->requests as $key => $times) {
            $this->requests[$key] = array_values(array_filter($times, static fn(float $time): bool => $time > $threshold));
        }

        $global = array_merge(...array_values($this->requests ?: [[]]));
        $serviceTimes = $this->requests[$service->value] ?? [];
        $serviceLimit = $this->perServiceLimits[$service->value] ?? $this->globalLimit;
        if (count($global) >= $this->globalLimit || count($serviceTimes) >= $serviceLimit) {
            throw new RateLimited((new DateTimeImmutable())->add(new DateInterval('PT60S')), 'Internal Entur request budget exhausted.');
        }

        $this->requests[$service->value][] = $now;
    }

    /** @return array<string, array{limit: int, remaining: int}> */
    public function status(): array
    {
        $now = microtime(true);
        $threshold = $now - 60.0;
        foreach ($this->requests as $key => $times) {
            $this->requests[$key] = array_values(array_filter($times, static fn(float $time): bool => $time > $threshold));
        }
        $total = array_sum(array_map('count', $this->requests));
        $status = [
            'global' => [
                'limit' => $this->globalLimit,
                'remaining' => max(0, $this->globalLimit - $total),
            ],
        ];
        foreach (EnturService::cases() as $service) {
            $limit = $this->perServiceLimits[$service->value] ?? $this->globalLimit;
            $status[$service->value] = [
                'limit' => $limit,
                'remaining' => max(0, $limit - count($this->requests[$service->value] ?? [])),
            ];
        }

        return $status;
    }
}
