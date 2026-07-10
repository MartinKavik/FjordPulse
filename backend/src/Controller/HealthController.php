<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use DateTimeImmutable;
use DateTimeInterface;

final class HealthController extends AppController
{
    public function health(): Response
    {
        try {
            $service = $this->openService();
            try {
                return $this->success($service->health());
            } finally {
                $service->close();
            }
        } catch (\Throwable) {
            $now = (new DateTimeImmutable())->format(DateTimeInterface::RFC3339_EXTENDED);

            return $this->success([
                'status' => 'unhealthy',
                'mode' => 'fallback_polling',
                'checkedAt' => $now,
                'version' => getenv('APP_VERSION') ?: 'dev',
                'fallbackAvailable' => false,
                'dependencies' => [
                    'http' => ['status' => 'healthy', 'checkedAt' => $now],
                    'realtime' => ['status' => 'unavailable', 'checkedAt' => $now],
                    'surrealdb' => ['status' => 'unavailable', 'checkedAt' => $now, 'message' => 'Authoritative state is unavailable.'],
                    'entur' => ['status' => 'unknown', 'checkedAt' => $now],
                    'liveQueryBridge' => ['status' => 'unavailable', 'checkedAt' => $now],
                ],
            ], status: 503);
        }
    }

    public function readiness(): Response
    {
        return $this->health();
    }
}
