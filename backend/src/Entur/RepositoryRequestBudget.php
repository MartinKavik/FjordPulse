<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\EnturService;
use FjordPulse\Surreal\EnturBudgetRepository;

final readonly class RepositoryRequestBudget implements RequestBudgetInterface
{
    /** @param array<string, int> $perServiceLimits */
    public function __construct(
        private EnturBudgetRepository $reservations,
        private int $globalLimit,
        private array $perServiceLimits,
    ) {
        if ($globalLimit < 1) {
            throw new \InvalidArgumentException('Global Entur request budget must be positive.');
        }
        foreach ($perServiceLimits as $service => $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException("Entur {$service} request budget must be positive.");
            }
        }
    }

    public function acquire(EnturService $service, ?string $requestId = null): void
    {
        $requestId ??= 'budget_' . bin2hex(random_bytes(8));
        $now = new DateTimeImmutable();
        $serviceLimit = $this->perServiceLimits[$service->value] ?? $this->globalLimit;
        if ($this->reservations->reserve($service, $requestId, $now, $this->globalLimit, $serviceLimit)) {
            return;
        }

        $retryAt = $this->reservations->usage($now)->retryAt($service, $this->globalLimit, $serviceLimit)
            ?? $now->add(new DateInterval('PT60S'));
        throw new RateLimited($retryAt, 'Shared Entur request budget exhausted.');
    }

    /** @return array<string, array{limit: int, remaining: int}> */
    public function status(): array
    {
        $usage = $this->reservations->usage(new DateTimeImmutable());
        $result = [
            'global' => [
                'limit' => $this->globalLimit,
                'remaining' => max(0, $this->globalLimit - $usage->global),
            ],
        ];
        foreach (EnturService::cases() as $service) {
            $limit = $this->perServiceLimits[$service->value] ?? $this->globalLimit;
            $result[$service->value] = [
                'limit' => $limit,
                'remaining' => max(0, $limit - $usage->service($service)),
            ];
        }

        return $result;
    }
}
