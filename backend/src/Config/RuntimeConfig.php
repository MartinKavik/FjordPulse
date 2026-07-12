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
        public ?string $mapTilerApiKey,
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
        public int $stationImportPageSize,
        public int $stationImportWriteChunkSize,
        public int $enturStopPlaceRequestsPerMinute,
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
        if ($stationImportPageSize < 1 || $stationImportWriteChunkSize < 1 || $enturStopPlaceRequestsPerMinute < 1) {
            throw new InvalidArgumentException('Station import sizes and the Stop Place request budget must be positive.');
        }
        if ($stationImportPageSize > 5_000) {
            throw new InvalidArgumentException('STATION_IMPORT_PAGE_SIZE cannot exceed the verified Entur page size of 5000.');
        }
        if ($stationImportWriteChunkSize > $stationImportPageSize) {
            throw new InvalidArgumentException('STATION_IMPORT_WRITE_CHUNK_SIZE cannot exceed STATION_IMPORT_PAGE_SIZE.');
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
            self::env('DATA_MODE', 'real'),
            $scenario,
            self::env('APP_ORIGIN', 'http://127.0.0.1:8080'),
            $allowedOrigins,
            new SurrealConnectionConfig(
                $httpUrl,
                $webSocketUrl,
                self::env('SURREAL_NAMESPACE', 'fjordpulse'),
                self::env('SURREAL_DATABASE', 'fjordpulse_real'),
                self::env('SURREAL_USERNAME', 'fjordpulse_app'),
                self::env('SURREAL_PASSWORD', 'local-development-only'),
            ),
            self::env('ENTUR_CLIENT_NAME', 'martinkavik-fjordpulse'),
            self::env('ENTUR_GEOCODER_URL', 'https://api.entur.io/geocoder/v3'),
            self::env('ENTUR_JOURNEY_PLANNER_URL', 'https://api.entur.io/journey-planner/v3/graphql'),
            self::env('ENTUR_VEHICLE_POSITIONS_URL', 'https://api.entur.io/realtime/v2/vehicles/graphql'),
            self::env('ENTUR_VEHICLE_SUBSCRIPTIONS_URL', 'wss://api.entur.io/realtime/v2/vehicles/subscriptions'),
            self::env('ENTUR_STOP_PLACES_URL', 'https://api.entur.io/stop-places/v1/read'),
            self::optionalEnv('MAPTILER_API_KEY'),
            self::env('ADMIN_USERNAME', 'admin'),
            self::env('ADMIN_PASSWORD', 'local-development-only'),
            self::env('ADMIN_SESSION_SECRET', 'replace-in-production'),
            self::positiveInt('WATCH_TTL_SECONDS', 60),
            self::positiveInt('FALLBACK_POLL_SECONDS', 15),
            self::positiveInt('STATION_FRESH_SECONDS', 30),
            self::positiveInt('VEHICLE_FRESH_SECONDS', 10),
            self::positiveInt('VEHICLE_STALE_SECONDS', 30),
            self::positiveInt('VEHICLE_LOST_SECONDS', 300),
            self::positiveInt('VEHICLE_OBSERVATION_RETENTION_HOURS', 24),
            self::positiveInt('REALTIME_EVENT_RETENTION_HOURS', 24),
            self::positiveInt('STATION_IMPORT_PAGE_SIZE', 1_000),
            self::positiveInt('STATION_IMPORT_WRITE_CHUNK_SIZE', 1_000),
            self::positiveInt('ENTUR_STOP_PLACE_REQUESTS_PER_MINUTE', 60),
        );
    }

    public function isDevelopmentLike(): bool
    {
        return in_array($this->environment, ['local', 'development', 'test'], true);
    }

    public function mapTilesConfigured(): bool
    {
        return $this->mapTilerApiKey !== null;
    }

    /**
     * @return array{
     *   engine: 'surrealdb',
     *   endpointOrigin: string,
     *   namespace: string,
     *   name: string,
     *   warning: string|null
     * }
     */
    public function databaseDiagnostic(): array
    {
        $host = parse_url($this->surreal->webSocketUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \LogicException('Validated SurrealDB WebSocket URL must contain a host.');
        }

        $warning = null;
        if (in_array($this->environment, ['staging', 'production'], true) && self::isLoopbackHost($host)) {
            $warning = sprintf(
                'Loopback database target configured for %s; localhost resolves inside the running service.',
                $this->environment,
            );
        }

        return [
            'engine' => 'surrealdb',
            'endpointOrigin' => self::sanitizedOrigin($this->surreal->webSocketUrl),
            'namespace' => $this->surreal->namespace,
            'name' => $this->surreal->database,
            'warning' => $warning,
        ];
    }

    private static function sanitizedOrigin(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_string($scheme) || !is_string($host) || $host === '') {
            throw new \LogicException('Validated SurrealDB URL must contain a scheme and host.');
        }

        $normalizedHost = strtolower($host);
        if (str_contains($normalizedHost, ':') && !str_starts_with($normalizedHost, '[')) {
            $normalizedHost = '[' . $normalizedHost . ']';
        }

        return strtolower($scheme) . '://' . $normalizedHost . (is_int($port) ? ':' . $port : '');
    }

    private static function isLoopbackHost(string $host): bool
    {
        $normalized = strtolower(rtrim($host, '.'));
        if ($normalized === 'localhost' || str_ends_with($normalized, '.localhost')) {
            return true;
        }

        $address = @inet_pton($normalized);
        if (!is_string($address)) {
            return false;
        }

        $ipv6Loopback = inet_pton('::1');

        return $address === $ipv6Loopback || (strlen($address) === 4 && ord($address[0]) === 127);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function optionalEnv(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return positive-int */
    private static function positiveInt(string $name, int $default): int
    {
        $raw = self::env($name, (string)$default);
        if (!ctype_digit($raw) || (int)$raw < 1) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        return (int)$raw;
    }
}
