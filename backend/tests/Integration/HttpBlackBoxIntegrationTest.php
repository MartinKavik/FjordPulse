<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use FjordPulse\Entur\Fake\FixtureFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

#[CoversNothing]
final class HttpBlackBoxIntegrationTest extends TestCase
{
    private static ?HttpBlackBoxServer $server = null;
    private static ?Client $client = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$server = HttpBlackBoxServer::start();
        self::$client = new Client([
            'base_uri' => self::$server->baseUrl(),
            'connect_timeout' => 3.0,
            'timeout' => 15.0,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$client = null;
        self::$server?->stop();
        self::$server = null;
        parent::tearDownAfterClass();
    }

    public function testHealthReadinessRequestIdsAndSecurityHeaders(): void
    {
        $health = self::request('GET', '/api/health', [
            'headers' => ['X-Request-Id' => 'blackbox-health-request'],
        ]);

        self::assertSame(200, $health->getStatusCode());
        self::assertSame('blackbox-health-request', $health->getHeaderLine('X-Request-Id'));
        self::assertStringStartsWith('application/json', $health->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $health->getHeaderLine('Cache-Control'));
        self::assertSecurityHeaders($health);
        self::assertOpenApiResponse('getHealth', 200, $health);
        $healthData = self::data($health);
        self::assertContains($healthData['status'] ?? null, ['healthy', 'degraded']);
        self::assertSame('healthy', self::nestedString($healthData, 'dependencies', 'surrealdb', 'status'));

        $readiness = self::request('GET', '/api/readiness', [
            'headers' => ['X-Request-Id' => 'invalid request id'],
        ]);
        self::assertSame(200, $readiness->getStatusCode());
        self::assertMatchesRegularExpression('/^req_[a-f0-9]{16}$/D', $readiness->getHeaderLine('X-Request-Id'));
        self::assertOpenApiResponse('getReadiness', 200, $readiness);
    }

    public function testStationSearchRefreshAndVehicleResourcesMatchOpenApi(): void
    {
        $map = self::request('GET', '/api/stations?bbox=4,58,20,70&zoom=9');
        self::assertSame(200, $map->getStatusCode(), $map->getBody()->getContents());
        self::assertOpenApiResponse('getStationMap', 200, $map);
        $mapData = self::data($map);
        self::assertSame('surrealdb', $mapData['dataSource'] ?? null);
        self::assertCount(count(FixtureFactory::stations()), self::listValue($mapData, 'items'));

        $search = self::request('GET', '/api/search?q=Oslo&limit=5');
        self::assertSame(200, $search->getStatusCode());
        self::assertOpenApiResponse('search', 200, $search);
        $searchData = self::data($search);
        self::assertSame('Oslo', $searchData['query'] ?? null);
        self::assertNotEmpty(self::listValue($searchData, 'results'));

        $station = self::request('GET', '/api/stations/NSR:StopPlace:337?refresh=true');
        self::assertSame(200, $station->getStatusCode(), $station->getBody()->getContents());
        self::assertOpenApiResponse('getStation', 200, $station);
        $stationData = self::data($station);
        $snapshot = self::objectValue($stationData, 'snapshot');
        self::assertSame('NSR:StopPlace:337', $snapshot['stationId'] ?? null);
        self::assertCount(4, self::listValue($snapshot, 'departures'));
        $firstVersion = self::stringValue($snapshot, 'version');

        $sameSemanticRefresh = self::request('GET', '/api/stations/NSR:StopPlace:337?refresh=true');
        self::assertSame(200, $sameSemanticRefresh->getStatusCode());
        self::assertSame($firstVersion, self::stringValue(
            self::objectValue(self::data($sameSemanticRefresh), 'snapshot'),
            'version',
        ), 'A semantic no-op refresh must preserve the authoritative entity version.');

        $departures = self::request('GET', '/api/stations/NSR:StopPlace:337/departures');
        self::assertSame(200, $departures->getStatusCode());
        self::assertOpenApiResponse('getStationDepartures', 200, $departures);

        $nearby = self::request('GET', '/api/stations/NSR:StopPlace:337/nearby-vehicles');
        self::assertSame(200, $nearby->getStatusCode());
        self::assertOpenApiResponse('getStationNearbyVehicles', 200, $nearby);
        self::assertCount(3, self::listValue(self::data($nearby), 'vehicles'));

        $vehicle = self::request('GET', '/api/vehicles/SKY:Vehicle:1001');
        self::assertSame(200, $vehicle->getStatusCode(), $vehicle->getBody()->getContents());
        self::assertOpenApiResponse('getVehicle', 200, $vehicle);
        self::assertSame(
            'SKY:Vehicle:1001',
            self::objectValue(self::data($vehicle), 'vehicle')['id'] ?? null,
        );
    }

    public function testAdminSessionProtectionDiagnosticsLogoutAndSpaDeepLinks(): void
    {
        foreach (['/api/admin/session', '/api/admin/status', '/api/admin/events'] as $path) {
            $protected = self::request('GET', $path);
            self::assertSame(401, $protected->getStatusCode(), $path);
            self::assertErrorEnvelope($protected, 'admin_unauthorized');
        }

        $wrongPassword = self::request('POST', '/api/admin/session', [
            'json' => ['username' => HttpBlackBoxServer::ADMIN_USERNAME, 'password' => 'wrong'],
        ]);
        self::assertSame(401, $wrongPassword->getStatusCode());
        self::assertOpenApiResponse('createAdminSession', 401, $wrongPassword);

        $cookies = new CookieJar();
        $login = self::request('POST', '/api/admin/session', [
            'cookies' => $cookies,
            'json' => [
                'username' => HttpBlackBoxServer::ADMIN_USERNAME,
                'password' => HttpBlackBoxServer::ADMIN_PASSWORD,
            ],
        ]);
        self::assertSame(200, $login->getStatusCode());
        self::assertOpenApiResponse('createAdminSession', 200, $login);
        self::assertStringContainsString('HttpOnly', $login->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('SameSite=Strict', $login->getHeaderLine('Set-Cookie'));
        self::assertStringNotContainsString(HttpBlackBoxServer::ADMIN_PASSWORD, (string)$login->getBody());

        $session = self::request('GET', '/api/admin/session', ['cookies' => $cookies]);
        self::assertSame(200, $session->getStatusCode());
        self::assertOpenApiResponse('getAdminSession', 200, $session);

        foreach ([
            ['getAdminStatus', '/api/admin/status'],
            ['getAdminWatches', '/api/admin/watches?limit=10'],
            ['getAdminEnturLog', '/api/admin/entur-log?limit=10'],
            ['getAdminRealtime', '/api/admin/realtime'],
            ['getAdminEvents', '/api/admin/events?limit=10'],
            ['getAdminMigrations', '/api/admin/migrations'],
        ] as [$operation, $path]) {
            $diagnostic = self::request('GET', $path, ['cookies' => $cookies]);
            self::assertSame(200, $diagnostic->getStatusCode(), $path . ': ' . $diagnostic->getBody()->getContents());
            self::assertOpenApiResponse($operation, 200, $diagnostic);
        }

        $invalidFilter = self::request('GET', '/api/admin/events?type=not-an-event', ['cookies' => $cookies]);
        self::assertSame(400, $invalidFilter->getStatusCode());
        self::assertOpenApiResponse('getAdminEvents', 400, $invalidFilter);

        $logout = self::request('DELETE', '/api/admin/session', ['cookies' => $cookies]);
        self::assertSame(204, $logout->getStatusCode());
        self::assertSame('', (string)$logout->getBody());

        $afterLogout = self::request('GET', '/api/admin/status', ['cookies' => $cookies]);
        self::assertSame(401, $afterLogout->getStatusCode());

        foreach (['/admin/status', '/stations/NSR:StopPlace:337', '/vehicles/SKY:Vehicle:1001'] as $path) {
            $deepLink = self::request('GET', $path, ['headers' => ['Accept' => 'text/html']]);
            self::assertSame(200, $deepLink->getStatusCode(), $path);
            self::assertStringStartsWith('text/html', $deepLink->getHeaderLine('Content-Type'));
            self::assertStringContainsString('data-test="fjordpulse-spa"', (string)$deepLink->getBody());
        }
    }

    public function testCorsAllowPreflightAndDenyAreExact(): void
    {
        $allowed = self::request('GET', '/api/health', [
            'headers' => ['Origin' => HttpBlackBoxServer::ALLOWED_ORIGIN],
        ]);
        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame(HttpBlackBoxServer::ALLOWED_ORIGIN, $allowed->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $allowed->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $allowed->getHeaderLine('Vary'));

        $preflight = self::request('OPTIONS', '/api/stations', [
            'headers' => [
                'Origin' => HttpBlackBoxServer::ALLOWED_ORIGIN,
                'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'Content-Type, X-Request-Id',
            ],
        ]);
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('GET, POST, DELETE, OPTIONS', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, X-Request-Id', $preflight->getHeaderLine('Access-Control-Allow-Headers'));

        $denied = self::request('GET', '/api/health', [
            'headers' => ['Origin' => 'https://evil.example'],
        ]);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('', $denied->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertErrorEnvelope($denied, 'origin_forbidden');
        self::assertSecurityHeaders($denied);
    }

    public function testMalformedJsonUnknownFieldsAndParameterValidationReturnJsonErrors(): void
    {
        $malformed = self::request('POST', '/api/admin/session', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"username":',
        ]);
        self::assertSame(400, $malformed->getStatusCode(), (string)$malformed->getBody());
        self::assertOpenApiResponse('createAdminSession', 400, $malformed);

        $unknownField = self::request('POST', '/api/admin/session', [
            'json' => [
                'username' => HttpBlackBoxServer::ADMIN_USERNAME,
                'password' => HttpBlackBoxServer::ADMIN_PASSWORD,
                'unexpected' => true,
            ],
        ]);
        self::assertSame(400, $unknownField->getStatusCode(), 'OpenAPI forbids unknown login request properties.');
        self::assertOpenApiResponse('createAdminSession', 400, $unknownField);

        foreach ([
            ['getStationMap', '/api/stations?bbox=invalid&zoom=9'],
            ['getStationMap', '/api/stations?bbox=4,58,20,70&zoom=99'],
            ['search', '/api/search'],
            ['search', '/api/search?q=Oslo&limit=51'],
            ['getStation', '/api/stations/not-a-station'],
            ['getVehicle', '/api/vehicles/%20'],
        ] as [$operation, $path]) {
            $invalid = self::request('GET', $path);
            self::assertSame(400, $invalid->getStatusCode(), $path . ': ' . (string)$invalid->getBody());
            self::assertOpenApiResponse($operation, 400, $invalid);
        }
    }

    public function testHealthAndReadinessReturnContractual503WhileDatabaseIsDown(): void
    {
        $server = self::server();
        $server->stopSurreal();
        try {
            foreach ([['getHealth', '/api/health'], ['getReadiness', '/api/readiness']] as [$operation, $path]) {
                $response = self::request('GET', $path);
                self::assertSame(503, $response->getStatusCode(), $path . ': ' . (string)$response->getBody());
                self::assertOpenApiResponse($operation, 503, $response);
                $data = self::data($response);
                self::assertSame('unhealthy', $data['status'] ?? null);
                self::assertSame('unavailable', self::nestedString($data, 'dependencies', 'surrealdb', 'status'));
            }
        } finally {
            $server->restartSurreal();
        }

        $recovered = self::request('GET', '/api/health');
        self::assertSame(200, $recovered->getStatusCode());
        self::assertSame('healthy', self::nestedString(self::data($recovered), 'dependencies', 'surrealdb', 'status'));
    }

    /** @param array<string, mixed> $options */
    private static function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $client = self::$client;
        if (!$client instanceof Client) {
            throw new RuntimeException('HTTP black-box client is not running.');
        }

        return $client->request($method, $path, $options);
    }

    private static function server(): HttpBlackBoxServer
    {
        $server = self::$server;
        if (!$server instanceof HttpBlackBoxServer) {
            throw new RuntimeException('HTTP black-box server is not running.');
        }

        return $server;
    }

    private static function assertSecurityHeaders(ResponseInterface $response): void
    {
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('geolocation=(), camera=(), microphone=()', $response->getHeaderLine('Permissions-Policy'));
        self::assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('', $response->getHeaderLine('Server'));
    }

    private static function assertErrorEnvelope(ResponseInterface $response, string $expectedCode): void
    {
        $payload = self::json($response);
        self::assertFalse($payload['ok'] ?? null);
        $error = self::objectValue($payload, 'error');
        self::assertSame($expectedCode, $error['code'] ?? null);
        self::assertNotSame('', $error['message'] ?? '');
        self::assertArrayHasKey('details', $error);
        self::assertArrayHasKey('requestId', self::objectValue($payload, 'meta'));
    }

    private static function assertOpenApiResponse(string $operationId, int $status, ResponseInterface $response): void
    {
        self::json($response);
        $rawPayload = (string)$response->getBody();
        $root = dirname(__DIR__, 3);
        $process = proc_open([
            'node',
            __DIR__ . '/validate-openapi-response.mjs',
            $operationId,
            (string)$status,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);
        if (!is_resource($process)) {
            self::fail('Unable to start the OpenAPI response validator.');
        }

        fwrite($pipes[0], $rawPayload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(
            0,
            $exitCode,
            trim(
                ($stderr === false ? '' : $stderr)
                . "\n"
                . ($stdout === false ? '' : $stdout)
                . "\nPayload: "
                . $rawPayload,
            ),
        );
    }

    /** @return array<string, mixed> */
    private static function json(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            self::fail('Response is not valid JSON: ' . $error->getMessage());
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            self::fail('Response JSON must be an object.');
        }

        return self::stringKeyed($decoded);
    }

    /** @return array<string, mixed> */
    private static function data(ResponseInterface $response): array
    {
        return self::objectValue(self::json($response), 'data');
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function objectValue(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            self::fail("Expected {$key} to be an object.");
        }

        return self::stringKeyed($value);
    }

    /**
     * @param array<string, mixed> $source
     * @return list<mixed>
     */
    private static function listValue(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            self::fail("Expected {$key} to be a list.");
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private static function stringValue(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            self::fail("Expected {$key} to be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private static function nestedString(array $source, string ...$keys): string
    {
        $value = $source;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                self::fail('Missing nested response value: ' . implode('.', $keys));
            }
            $value = $value[$key];
        }
        if (!is_string($value)) {
            self::fail('Nested response value is not a string: ' . implode('.', $keys));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('Expected response object keys to be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
