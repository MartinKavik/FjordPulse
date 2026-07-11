<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Dto\MapConfig;

final class MapController extends AppController
{
    public function config(): Response
    {
        $apiKey = RuntimeConfig::fromEnvironment()->mapTilerApiKey;
        if ($apiKey === null) {
            return $this->failure(
                'map_provider_misconfigured',
                'Map tiles are not configured. This is a FjordPulse service problem; users do not need to provide an API key.',
                [],
                503,
            );
        }

        return $this->success(MapConfig::mapTiler($apiKey)->toArray());
    }
}
