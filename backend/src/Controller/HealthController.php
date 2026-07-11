<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Service\HttpApiServiceFactory;

final class HealthController extends AppController
{
    public function health(): Response
    {
        return $this->report(false);
    }

    public function readiness(): Response
    {
        return $this->report(true);
    }

    private function report(bool $readiness): Response
    {
        try {
            $config = RuntimeConfig::fromEnvironment();
            $service = (new HttpApiServiceFactory($config))->create();
            try {
                return $this->success(
                    $service->health(),
                    status: $readiness && (
                        !$service->stationCatalogReadyForRuntime()
                        || ($config->environment === 'production' && !$config->mapTilesConfigured())
                    )
                        ? 503
                        : 200,
                );
            } finally {
                $service->close();
            }
        } catch (\Throwable) {
            $now = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED);

            return $this->success([
                'status' => 'unhealthy',
                'mode' => 'fallback_polling',
                'dataMode' => self::dataMode(),
                'checkedAt' => $now,
                'version' => getenv('APP_VERSION') ?: 'dev',
                'fallbackAvailable' => false,
                'dependencies' => [
                    'http' => ['status' => 'healthy', 'checkedAt' => $now],
                    'realtime' => ['status' => 'unavailable', 'checkedAt' => $now],
                    'surrealdb' => ['status' => 'unavailable', 'checkedAt' => $now, 'message' => 'Authoritative state is unavailable.'],
                    'entur' => ['status' => 'unknown', 'checkedAt' => $now],
                    'liveQueryBridge' => ['status' => 'unavailable', 'checkedAt' => $now],
                    'mapTiles' => [
                        'status' => self::mapTilesConfigured() ? 'configured' : 'misconfigured',
                        'checkedAt' => $now,
                        'message' => self::mapTilesConfigured()
                            ? 'MapTiler browser configuration is present; provider availability is verified by the browser at load time, not by this endpoint.'
                            : 'MAPTILER_API_KEY is not configured; browser maps are unavailable.',
                    ],
                ],
            ], status: 503);
        }
    }

    private static function mapTilesConfigured(): bool
    {
        $apiKey = getenv('MAPTILER_API_KEY');

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    private static function dataMode(): string
    {
        return getenv('DATA_MODE') === 'fake' ? 'fake' : 'real';
    }
}
