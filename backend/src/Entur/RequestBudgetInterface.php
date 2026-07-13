<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Domain\EnturService;

interface RequestBudgetInterface
{
    public function acquire(EnturService $service, ?string $requestId = null): void;

    /** @return array<string, array{limit: int, remaining: int}> */
    public function status(): array;
}
