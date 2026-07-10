<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Domain\Scenario;

interface ScenarioProviderInterface
{
    public function current(): Scenario;
}
