<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Domain\Scenario;

final class MutableScenarioProvider implements ScenarioProviderInterface
{
    public function __construct(private Scenario $scenario = Scenario::Normal)
    {
    }

    public function current(): Scenario
    {
        return $this->scenario;
    }

    public function select(Scenario $scenario): void
    {
        $this->scenario = $scenario;
    }
}
