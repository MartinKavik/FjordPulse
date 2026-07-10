<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\EnturService;
use FjordPulse\Surreal\EnturRequestLogRepository;

final readonly class RepositoryRequestBudget implements RequestBudgetInterface
{
    /** @param array<string, int> $perServiceLimits */
    public function __construct(
        private EnturRequestLogRepository $logs,
        private int $globalLimit,
        private array $perServiceLimits,
    ) {
        if ($globalLimit < 1) {
            throw new \InvalidArgumentException('Global Entur request budget must be positive.');
        }
    }

    public function acquire(EnturService $service): void
    {
        $usage = $this->usage();
        $serviceLimit = $this->perServiceLimits[$service->value] ?? $this->globalLimit;
        $globalUsed = array_sum($usage);
        if ($globalUsed >= $this->globalLimit || ($usage[$service->value] ?? 0) >= $serviceLimit) {
            throw new RateLimited((new DateTimeImmutable())->add(new DateInterval('PT60S')), 'Shared Entur request budget exhausted.');
        }
    }

    /** @return array<string, array{limit: int, remaining: int}> */
    public function status(): array
    {
        $usage = $this->usage();
        $result = [
            'global' => [
                'limit' => $this->globalLimit,
                'remaining' => max(0, $this->globalLimit - array_sum($usage)),
            ],
        ];
        foreach (EnturService::cases() as $service) {
            $limit = $this->perServiceLimits[$service->value] ?? $this->globalLimit;
            $result[$service->value] = [
                'limit' => $limit,
                'remaining' => max(0, $limit - ($usage[$service->value] ?? 0)),
            ];
        }

        return $result;
    }

    /** @return array<string, int> */
    private function usage(): array
    {
        return $this->logs->usageSince((new DateTimeImmutable())->sub(new DateInterval('PT60S')));
    }
}
