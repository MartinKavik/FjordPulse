<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Domain\Scenario;
use FjordPulse\Entur\ScenarioProviderInterface;
use FjordPulse\Surreal\SystemStatusRepository;

final readonly class SurrealScenarioProvider implements ScenarioProviderInterface
{
    public function __construct(
        private SystemStatusRepository $statuses,
        private Scenario $fallback = Scenario::Normal,
    ) {
    }

    public function current(): Scenario
    {
        $status = $this->statuses->find('dev_scenario');
        $value = $status?->metadata['scenario'] ?? null;

        return is_string($value) ? (Scenario::tryFrom($value) ?? $this->fallback) : $this->fallback;
    }
}
