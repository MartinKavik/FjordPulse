<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Domain\Scenario;

final class DevScenarioController extends AppController
{
    public function view(): Response
    {
        if (!RuntimeConfig::fromEnvironment()->isDevelopmentLike()) {
            return $this->failure('not_found', 'Development scenarios are disabled.', [], 404);
        }
        $service = $this->openService();
        try {
            return $this->success($service->scenario());
        } finally {
            $service->close();
        }
    }

    public function update(): Response
    {
        if (!RuntimeConfig::fromEnvironment()->isDevelopmentLike()) {
            return $this->failure('not_found', 'Development scenarios are disabled.', [], 404);
        }
        $data = $this->getRequest()->getData();
        if (!is_array($data)) {
            $data = [];
        }
        $scenario = is_string($data['scenario'] ?? null) ? Scenario::tryFrom($data['scenario']) : null;
        if ($scenario === null) {
            return $this->failure('invalid_scenario', 'Scenario is not supported.', ['scenarios' => Scenario::values()], 400);
        }
        $service = $this->openService();
        try {
            return $this->success($service->selectScenario($scenario));
        } finally {
            $service->close();
        }
    }

    public function index(): Response
    {
        if (!RuntimeConfig::fromEnvironment()->isDevelopmentLike()) {
            return $this->failure('not_found', 'Development scenarios are disabled.', [], 404);
        }

        return $this->success(['scenarios' => Scenario::values()]);
    }
}
