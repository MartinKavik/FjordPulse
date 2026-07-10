<?php

declare(strict_types=1);

namespace FjordPulse\Config;

use FjordPulse\Domain\Scenario;
use FjordPulse\Surreal\SurrealConnectionConfig;
use InvalidArgumentException;

final readonly class RuntimeConfig
{
    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        public string $environment,
        public bool $debug,
        public string $dataMode,
        public Scenario $defaultScenario,
        public string $appOrigin,
        public array $allowedOrigins,
        public SurrealConnectionConfig $surreal,
        public string $enturClientName,
        public string $enturGeocoderUrl,
        public string $enturJourneyPlannerUrl,
        public string $enturVehiclePositionsUrl,
        public string $enturVehicleSubscriptionsUrl,
        public string $enturStopPlacesUrl,
        public string $adminUsername,
        public string $adminPassword,
        public string $adminSessionSecret,
        public int $watchTtlSeconds,
        public int $fallbackPollSeconds,
        public int $stationFreshSeconds,
        public int $vehicleFreshSeconds,
        public int $vehicleStaleSeconds,
        public int $vehicleLostSeconds,
        public int $observationRetentionHours,
        public int $eventRetentionHours,
    ) {
        if (!in_array($dataMode, ['fake', 'real'], true)) {
            throw new InvalidArgumentException('DATA_MODE must be fake or real.');
        }
        if ($environment === 'production' && $dataMode !== 'real') {
            throw new InvalidArgumentException('Production requires DATA_MODE=real; fake transport data is forbidden.');
        }
        if ($allowedOrigins === []) {
            throw new InvalidArgumentException('At least one ALLOWED_ORIGINS value is required.');
        }
        if ($enturClientName === '' || preg_match('/^[A-Za-z0-9_]+-[A-Za-z0-9_-]+$/D', $enturClientName) !== 1) {
            throw new InvalidArgumentException('ENTUR_CLIENT_NAME must use company-application format.');
        }
        if ($environment === 'production' && (
            $adminPassword === 'local-development-only'
            || $adminSessionSecret === 'replace-in-production'
            || strlen($adminSessionSecret) < 32
        )) {
            throw new InvalidArgumentException('Production admin credentials/session secret are not configured safely.');
        }
        if ($vehicleStaleSeconds <= $vehicleFreshSeconds || $vehicleLostSeconds <= $vehicleStaleSeconds) {
            throw new InvalidArgumentException('Vehicle freshness thresholds must increase from fresh to stale to lost.');
        }
    }

    public static function fromEnvironment(): self
    {
        $environment = self::env('APP_ENV', 'development');
        $scenarioValue = self::env('SCENARIO', Scenario::Normal->value);
        $scenario = Scenario::tryFrom($scenarioValue);
        if ($scenario === null) {
            throw new InvalidArgumentException('SCENARIO is not supported.');
        }
        $httpUrl = self::env('SURREAL_HTTP_URL', 'http://127.0.0.1:8000');
        $webSocketUrl = self::env('SURREAL_URL', 'ws://127.0.0.1:8000/rpc');
        $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', self::env(
            'ALLOWED_ORIGINS',
            'http://127.0.0.1:8080,http://localhost:8080,http://127.0.0.1:5173,http://localhost:5173',
        ))), static fn(string $origin): bool => $origin !== ''));

        return new self(
            $environment,
            filter_var(self::env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
            self::env('DATA_MODE', 'fake'),
            $scenario,
            self::env('APP_ORIGIN', 'http://127.0.0.1:8080'),
            $allowedOrigins,
            new SurrealConnectionConfig(
                $httpUrl,
                $webSocketUrl,
                self::env('SURREAL_NAMESPACE', 'fjordpulse'),
                self::env('SURREAL_DATABASE', 'fjordpulse'),
                self::env('SURREAL_USERNAME', 'fjordpulse_app'),
                self::env('SURREAL_PASSWORD', 'local-development-only'),
            ),
            self::env('ENTUR_CLIENT_NAME', 'martinkavik-fjordpulse'),
            self::env('ENTUR_GEOCODER_URL', 'https://api.entur.io/geocoder/v3'),
            self::env('ENTUR_JOURNEY_PLANNER_URL', 'https://api.entur.io/journey-planner/v3/graphql'),
            self::env('ENTUR_VEHICLE_POSITIONS_URL', 'https://api.entur.io/realtime/v2/vehicles/graphql'),
            self::env('ENTUR_VEHICLE_SUBSCRIPTIONS_URL', 'wss://api.entur.io/realtime/v2/vehicles/subscriptions'),
            self::env('ENTUR_STOP_PLACES_URL', 'https://api.entur.io/stop-places/v1/read'),
            self::env('ADMIN_USERNAME', 'admin'),
            self::env('ADMIN_PASSWORD', 'local-development-only'),
            self::env('ADMIN_SESSION_SECRET', 'replace-in-production'),
            self::positiveInt('WATCH_TTL_SECONDS', 60),
            self::positiveInt('FALLBACK_POLL_SECONDS', 15),
            self::positiveInt('STATION_FRESH_SECONDS', 30),
            self::positiveInt('VEHICLE_FRESH_SECONDS', 10),
            self::positiveInt('VEHICLE_STALE_SECONDS', 30),
            self::positiveInt('VEHICLE_LOST_SECONDS', 120),
            self::positiveInt('VEHICLE_OBSERVATION_RETENTION_HOURS', 24),
            self::positiveInt('REALTIME_EVENT_RETENTION_HOURS', 24),
        );
    }

    public function isDevelopmentLike(): bool
    {
        return in_array($this->environment, ['local', 'development', 'test'], true);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function positiveInt(string $name, int $default): int
    {
        $raw = self::env($name, (string)$default);
        if (!ctype_digit($raw) || (int)$raw < 1) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        return (int)$raw;
    }
}
