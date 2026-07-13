<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\EnturService;

final class RequestBudget implements RequestBudgetInterface
{
    /** @var array<string, array{service: string, requestedAt: float}> */
    private array $requests = [];

    /** @param array<string, int> $perServiceLimits */
    public function __construct(private readonly int $globalLimit, private readonly array $perServiceLimits)
    {
    }

    public function acquire(EnturService $service, ?string $requestId = null): void
    {
        $now = microtime(true);
        $threshold = $now - 60.0;
        foreach ($this->requests as $key => $request) {
            if ($request['requestedAt'] <= $threshold) {
                unset($this->requests[$key]);
            }
        }

        $requestId ??= 'budget_' . bin2hex(random_bytes(8));
        if (isset($this->requests[$requestId])) {
            return;
        }

        $serviceCount = count(array_filter(
            $this->requests,
            static fn(array $request): bool => $request['service'] === $service->value,
        ));
        $serviceLimit = $this->perServiceLimits[$service->value] ?? $this->globalLimit;
        if (count($this->requests) >= $this->globalLimit || $serviceCount >= $serviceLimit) {
            throw new RateLimited((new DateTimeImmutable())->add(new DateInterval('PT60S')), 'Internal Entur request budget exhausted.');
        }

        $this->requests[$requestId] = ['service' => $service->value, 'requestedAt' => $now];
    }

    /** @return array<string, array{limit: int, remaining: int}> */
    public function status(): array
    {
        $now = microtime(true);
        $threshold = $now - 60.0;
        foreach ($this->requests as $key => $request) {
            if ($request['requestedAt'] <= $threshold) {
                unset($this->requests[$key]);
            }
        }
        $total = count($this->requests);
        $status = [
            'global' => [
                'limit' => $this->globalLimit,
                'remaining' => max(0, $this->globalLimit - $total),
            ],
        ];
        foreach (EnturService::cases() as $service) {
            $limit = $this->perServiceLimits[$service->value] ?? $this->globalLimit;
            $used = count(array_filter(
                $this->requests,
                static fn(array $request): bool => $request['service'] === $service->value,
            ));
            $status[$service->value] = [
                'limit' => $limit,
                'remaining' => max(0, $limit - $used),
            ];
        }

        return $status;
    }
}
