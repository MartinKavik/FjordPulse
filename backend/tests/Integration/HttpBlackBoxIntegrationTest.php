<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Watch;
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
        self::assertSame('configured', self::nestedString($healthData, 'dependencies', 'mapTiles', 'status'));

        $readiness = self::request('GET', '/api/readiness', [
            'headers' => ['X-Request-Id' => 'invalid request id'],
        ]);
        self::assertSame(200, $readiness->getStatusCode());
        self::assertMatchesRegularExpression('/^req_[a-f0-9]{16}$/D', $readiness->getHeaderLine('X-Request-Id'));
        self::assertOpenApiResponse('getReadiness', 200, $readiness);

        $proxiedReadiness = self::request('GET', '/api/readiness', [
            'headers' => ['Host' => 'fjordpulse.kavik.cz'],
        ]);
        self::assertSame(200, $proxiedReadiness->getStatusCode());
        self::assertStringStartsWith('application/json', $proxiedReadiness->getHeaderLine('Content-Type'));
        self::assertTrue(self::json($proxiedReadiness)['ok'] ?? false);
        self::assertSame('http-blackbox-test', self::data($proxiedReadiness)['version'] ?? null);
        self::assertOpenApiResponse('getReadiness', 200, $proxiedReadiness);
    }

    public function testPublicMapConfigurationDefaultsToFixedSatelliteAndStreetStyles(): void
    {
        $response = self::request('GET', '/api/map/config');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSecurityHeaders($response);
        self::assertOpenApiResponse('getMapConfig', 200, $response);
        $data = self::data($response);
        self::assertSame('maptiler', $data['provider'] ?? null);
        self::assertSame('satellite', $data['defaultBasemap'] ?? null);
        self::assertSame([
            [
                'id' => 'satellite',
                'label' => 'Satellite',
                'styleUrl' => 'https://api.maptiler.com/maps/hybrid-v4/style.json?key=' . HttpBlackBoxServer::MAPTILER_API_KEY,
            ],
            [
                'id' => 'streets',
                'label' => 'Map',
                'styleUrl' => 'https://api.maptiler.com/maps/streets-v4/style.json?key=' . HttpBlackBoxServer::MAPTILER_API_KEY,
            ],
        ], self::listValue($data, 'basemaps'));
    }

    public function testMissingMapConfigurationDegradesHealthAndFailsProductionReadiness(): void
    {
        $server = HttpBlackBoxServer::start(
            mapTilesConfigured: false,
            environment: 'production',
            adminDemoAccess: false,
        );
        $client = new Client([
            'base_uri' => $server->baseUrl(),
            'connect_timeout' => 3.0,
            'timeout' => 15.0,
            'http_errors' => false,
        ]);
        try {
            $mapConfig = $client->get('/api/map/config');
            self::assertSame(503, $mapConfig->getStatusCode());
            self::assertOpenApiResponse('getMapConfig', 503, $mapConfig);
            self::assertErrorEnvelope($mapConfig, 'map_provider_misconfigured');

            $demoCredentials = $client->get('/api/admin/demo-credentials');
            self::assertSame(200, $demoCredentials->getStatusCode());
            self::assertOpenApiResponse('getAdminDemoCredentials', 200, $demoCredentials);
            self::assertSame(['enabled' => false], self::data($demoCredentials));
            $disabledDemoLogin = $client->post('/api/admin/session', [
                'json' => [
                    'username' => HttpBlackBoxServer::ADMIN_DEMO_USERNAME,
                    'password' => HttpBlackBoxServer::ADMIN_DEMO_PASSWORD,
                ],
            ]);
            self::assertSame(401, $disabledDemoLogin->getStatusCode());
            self::assertOpenApiResponse('createAdminSession', 401, $disabledDemoLogin);

            $health = $client->get('/api/health');
            self::assertSame(200, $health->getStatusCode());
            self::assertOpenApiResponse('getHealth', 200, $health);
            self::assertSame('degraded', self::data($health)['status'] ?? null);
            self::assertSame('misconfigured', self::nestedString(self::data($health), 'dependencies', 'mapTiles', 'status'));

            $readiness = $client->get('/api/readiness');
            self::assertSame(503, $readiness->getStatusCode());
            self::assertOpenApiResponse('getReadiness', 503, $readiness);
            self::assertSame('misconfigured', self::nestedString(self::data($readiness), 'dependencies', 'mapTiles', 'status'));
        } finally {
            $server->stop();
        }
    }

    public function testRealModeRefusesMissingOrUnprovenStationCatalog(): void
    {
        $server = HttpBlackBoxServer::start(mapTilesConfigured: true, environment: 'production');
        $client = new Client([
            'base_uri' => $server->baseUrl(),
            'connect_timeout' => 3.0,
            'timeout' => 15.0,
            'http_errors' => false,
        ]);
        try {
            $demoCredentials = $client->get('/api/admin/demo-credentials');
            self::assertSame(200, $demoCredentials->getStatusCode());
            self::assertSame([
                'enabled' => true,
                'username' => HttpBlackBoxServer::ADMIN_DEMO_USERNAME,
                'password' => HttpBlackBoxServer::ADMIN_DEMO_PASSWORD,
            ], self::data($demoCredentials));
            $demoLogin = $client->post('/api/admin/session', [
                'json' => [
                    'username' => HttpBlackBoxServer::ADMIN_DEMO_USERNAME,
                    'password' => HttpBlackBoxServer::ADMIN_DEMO_PASSWORD,
                ],
            ]);
            self::assertSame(200, $demoLogin->getStatusCode(), (string)$demoLogin->getBody());
            self::assertSame('demo', self::data($demoLogin)['access'] ?? null);

            $health = $client->get('/api/health');
            self::assertSame(200, $health->getStatusCode());
            self::assertSame('real', self::data($health)['dataMode'] ?? null);
            self::assertSame('degraded', self::nestedString(self::data($health), 'dependencies', 'surrealdb', 'status'));

            $readiness = $client->get('/api/readiness');
            self::assertSame(503, $readiness->getStatusCode());
            self::assertOpenApiResponse('getReadiness', 503, $readiness);

            $stations = $client->get('/api/stations?bbox=4,58,20,70&zoom=9');
            self::assertSame(503, $stations->getStatusCode());
            self::assertErrorEnvelope($stations, 'service_unavailable');
        } finally {
            $server->stop();
        }
    }

    public function testAdminLoginRateLimitPersistsAcrossClassicHttpRequests(): void
    {
        $server = HttpBlackBoxServer::start();
        $client = new Client([
            'base_uri' => $server->baseUrl(),
            'connect_timeout' => 3.0,
            'timeout' => 15.0,
            'http_errors' => false,
        ]);
        try {
            for ($attempt = 1; $attempt <= 60; $attempt++) {
                $response = $client->post('/api/admin/session', [
                    'json' => [
                        'username' => HttpBlackBoxServer::ADMIN_USERNAME,
                        'password' => 'intentionally-wrong-' . $attempt,
                    ],
                ]);
                self::assertSame(401, $response->getStatusCode(), 'Attempt ' . $attempt);
            }

            $blocked = $client->post('/api/admin/session', [
                'json' => [
                    'username' => HttpBlackBoxServer::ADMIN_USERNAME,
                    'password' => HttpBlackBoxServer::ADMIN_PASSWORD,
                ],
            ]);
            self::assertSame(429, $blocked->getStatusCode(), (string)$blocked->getBody());
            self::assertMatchesRegularExpression('/^[1-9][0-9]*$/D', $blocked->getHeaderLine('Retry-After'));
            self::assertErrorEnvelope($blocked, 'rate_limited');
        } finally {
            $server->stop();
        }
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

        foreach (['Forde', 'Fo', 'Frode'] as $query) {
            $tolerantSearch = self::request('GET', '/api/search?q=' . rawurlencode($query) . '&limit=5');
            self::assertSame(200, $tolerantSearch->getStatusCode(), (string)$tolerantSearch->getBody());
            self::assertOpenApiResponse('search', 200, $tolerantSearch);
            $results = self::listValue(self::data($tolerantSearch), 'results');
            self::assertNotEmpty($results, $query);
            self::assertNotEmpty(
                array_filter(
                    $results,
                    static fn(mixed $result): bool => is_array($result)
                        && ($result['stationId'] ?? null) === 'NSR:StopPlace:36025',
                ),
                $query . ': ' . json_encode($results, JSON_THROW_ON_ERROR),
            );
            if ($query === 'Forde') {
                self::assertNotEmpty(
                    array_filter(
                        $results,
                        static fn(mixed $result): bool => is_array($result)
                            && ($result['type'] ?? null) === 'line'
                            && ($result['lineCode'] ?? null) === '100',
                    ),
                    'Førde discovery must retain the documented Line 100 result after circular nearby filtering.',
                );
            }
        }
        $lineSearch = self::request('GET', '/api/search?q=Line%20100&limit=5');
        self::assertSame(200, $lineSearch->getStatusCode(), (string)$lineSearch->getBody());
        self::assertOpenApiResponse('search', 200, $lineSearch);
        $lineResults = self::listValue(self::data($lineSearch), 'results');
        self::assertNotEmpty(array_filter($lineResults, static fn(mixed $result): bool => is_array($result) && ($result['lineCode'] ?? null) === '100'));
        $vehicleSearchResults = array_values(array_filter($lineResults, static fn(mixed $result): bool => is_array($result) && ($result['type'] ?? null) === 'vehicle' && ($result['lineCode'] ?? null) === '100'));
        self::assertNotEmpty($vehicleSearchResults);
        self::assertSame('bus', $vehicleSearchResults[0]['transportMode'] ?? null);

        $station = self::request('GET', '/api/stations/NSR:StopPlace:36025?refresh=true');
        self::assertSame(200, $station->getStatusCode(), $station->getBody()->getContents());
        self::assertOpenApiResponse('getStation', 200, $station);
        $stationData = self::data($station);
        $snapshot = self::objectValue($stationData, 'snapshot');
        self::assertSame('NSR:StopPlace:36025', $snapshot['stationId'] ?? null);
        self::assertArrayNotHasKey('searchRadiusMeters', $snapshot, 'Radius metadata belongs only to the dedicated nearby-vehicles resource.');
        self::assertCount(4, self::listValue($snapshot, 'departures'));
        $departureBoard = self::objectValue($snapshot, 'departureBoard');
        self::assertSame(20, $departureBoard['limit'] ?? null);
        self::assertFalse($departureBoard['hasMore'] ?? true);
        $servingVehicles = self::listValue($snapshot, 'servingVehicles');
        self::assertNotEmpty($servingVehicles);
        $firstServingVehicle = self::objectItem($servingVehicles[0], 'serving vehicle');
        self::assertSame('bus', $firstServingVehicle['transportMode'] ?? null);
        self::assertSame('passenger', $firstServingVehicle['passengerServiceState'] ?? null);
        self::assertContains($firstServingVehicle['callRole'] ?? null, ['starts_here', 'calls_here']);
        self::assertContains($firstServingVehicle['progress'] ?? null, ['at_station', 'before_station', 'after_station', 'unknown']);
        self::assertArrayNotHasKey('relation', $firstServingVehicle);
        $servingCoverage = self::objectValue($snapshot, 'servingVehicleCoverage');
        self::assertSame(4, $servingCoverage['queriedJourneyCount'] ?? null);
        self::assertFalse($servingCoverage['truncated'] ?? true);
        $firstVersion = self::stringValue($snapshot, 'version');

        $sameSemanticRefresh = self::request('GET', '/api/stations/NSR:StopPlace:36025?refresh=true');
        self::assertSame(200, $sameSemanticRefresh->getStatusCode());
        self::assertSame($firstVersion, self::stringValue(
            self::objectValue(self::data($sameSemanticRefresh), 'snapshot'),
            'version',
        ), 'A semantic no-op refresh must preserve the authoritative entity version.');

        $departures = self::request('GET', '/api/stations/NSR:StopPlace:36025/departures');
        self::assertSame(200, $departures->getStatusCode());
        self::assertOpenApiResponse('getStationDepartures', 200, $departures);
        $previewData = self::data($departures);
        self::assertSame('preview', $previewData['mode'] ?? null);
        self::assertTrue($previewData['complete'] ?? false);
        self::assertSame(4, $previewData['totalCount'] ?? null);
        self::assertFalse(self::objectValue($previewData, 'page')['hasMore'] ?? true);
        $previewWithDailyLimit = self::request(
            'GET',
            '/api/stations/NSR:StopPlace:36025/departures?limit=2',
        );
        self::assertSame(400, $previewWithDailyLimit->getStatusCode());
        self::assertErrorEnvelope($previewWithDailyLimit, 'invalid_timetable_query');

        $serviceDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        $dailyFirst = self::request(
            'GET',
            '/api/stations/NSR:StopPlace:36025/departures?date=' . $serviceDate . '&limit=2',
        );
        self::assertSame(200, $dailyFirst->getStatusCode(), (string)$dailyFirst->getBody());
        self::assertOpenApiResponse('getStationDepartures', 200, $dailyFirst);
        $dailyFirstData = self::data($dailyFirst);
        self::assertSame('day', $dailyFirstData['mode'] ?? null);
        self::assertSame('Europe/Oslo', $dailyFirstData['timeZone'] ?? null);
        self::assertTrue($dailyFirstData['complete'] ?? false);
        self::assertSame(4, $dailyFirstData['totalCount'] ?? null);
        self::assertCount(2, self::listValue($dailyFirstData, 'departures'));
        $firstPage = self::objectValue($dailyFirstData, 'page');
        self::assertTrue($firstPage['hasMore'] ?? false);
        $nextCursor = self::stringValue($firstPage, 'nextCursor');

        $dailySecond = self::request(
            'GET',
            '/api/stations/NSR:StopPlace:36025/departures?date=' . $serviceDate
                . '&limit=2&cursor=' . rawurlencode($nextCursor),
        );
        self::assertSame(200, $dailySecond->getStatusCode(), (string)$dailySecond->getBody());
        self::assertOpenApiResponse('getStationDepartures', 200, $dailySecond);
        $dailySecondData = self::data($dailySecond);
        self::assertCount(2, self::listValue($dailySecondData, 'departures'));
        self::assertFalse(self::objectValue($dailySecondData, 'page')['hasMore'] ?? true);

        $cachedDaily = self::request(
            'GET',
            '/api/stations/NSR:StopPlace:36025/departures?date=' . $serviceDate . '&limit=2',
        );
        self::assertSame(200, $cachedDaily->getStatusCode(), (string)$cachedDaily->getBody());
        self::assertSame($dailyFirstData['version'] ?? null, self::data($cachedDaily)['version'] ?? null);
        usleep(2_000);
        $refreshedDaily = self::request(
            'GET',
            '/api/stations/NSR:StopPlace:36025/departures?date=' . $serviceDate . '&limit=2&refresh=true',
        );
        self::assertSame(200, $refreshedDaily->getStatusCode(), (string)$refreshedDaily->getBody());
        self::assertOpenApiResponse('getStationDepartures', 200, $refreshedDaily);
        self::assertNotSame(
            $dailyFirstData['version'] ?? null,
            self::data($refreshedDaily)['version'] ?? null,
            'An explicit daily retry must bypass the first-page timetable cache.',
        );
        $refreshWithCursor = self::request(
            'GET',
            '/api/stations/NSR:StopPlace:36025/departures?date=' . $serviceDate
                . '&limit=2&cursor=' . rawurlencode($nextCursor) . '&refresh=true',
        );
        self::assertSame(400, $refreshWithCursor->getStatusCode());
        self::assertErrorEnvelope($refreshWithCursor, 'invalid_timetable_query');

        $oversizedDailyPage = self::request(
            'GET',
            '/api/stations/NSR:StopPlace:36025/departures?date=' . $serviceDate . '&limit=51',
        );
        self::assertSame(400, $oversizedDailyPage->getStatusCode());
        self::assertErrorEnvelope($oversizedDailyPage, 'invalid_limit');

        $nearby = self::request('GET', '/api/stations/NSR:StopPlace:36025/nearby-vehicles');
        self::assertSame(200, $nearby->getStatusCode());
        self::assertOpenApiResponse('getStationNearbyVehicles', 200, $nearby);
        $nearbyData = self::data($nearby);
        self::assertSame(5_000, $nearbyData['searchRadiusMeters'] ?? null);
        self::assertSame(
            ['SKY:Vehicle:1001', 'SKY:Vehicle:1102', 'SKY:Vehicle:5903'],
            array_column(self::listValue($nearbyData, 'vehicles'), 'id'),
            'Nearby vehicles must be ordered nearest-first after circular filtering.',
        );
        foreach (self::listValue($nearbyData, 'vehicles') as $nearbyVehicle) {
            self::assertSame('bus', self::stringValue(self::objectItem($nearbyVehicle, 'nearby vehicle'), 'transportMode'));
            self::assertSame('passenger', self::stringValue(self::objectItem($nearbyVehicle, 'nearby vehicle'), 'passengerServiceState'));
        }

        $vehicle = self::request('GET', '/api/vehicles/SKY:Vehicle:1001');
        self::assertSame(200, $vehicle->getStatusCode(), $vehicle->getBody()->getContents());
        self::assertOpenApiResponse('getVehicle', 200, $vehicle);
        self::assertSame(
            'SKY:Vehicle:1001',
            self::objectValue(self::data($vehicle), 'vehicle')['id'] ?? null,
        );
        self::assertSame('bus', self::objectValue(self::data($vehicle), 'vehicle')['transportMode'] ?? null);
        self::assertSame('passenger', self::objectValue(self::data($vehicle), 'vehicle')['passengerServiceState'] ?? null);
        $journey = self::objectValue(self::data($vehicle), 'journey');
        self::assertNotEmpty(self::objectValue($journey, 'route')['coordinates'] ?? []);
        self::assertNotEmpty(self::listValue($journey, 'calls'));
        self::assertNotEmpty(self::listValue(self::data($vehicle), 'upcomingStops'));
    }

    public function testExactVehicleIdSearchKeepsALostVehicleDiscoverable(): void
    {
        $vehicleId = FixtureFactory::vehicles()[0]->id;
        $server = HttpBlackBoxServer::start();
        $client = new Client([
            'base_uri' => $server->baseUrl(),
            'connect_timeout' => 3.0,
            'timeout' => 15.0,
            'http_errors' => false,
        ]);
        try {
            $scenario = $client->post('/api/dev/scenario', [
                'json' => ['scenario' => 'vehicle_lost'],
            ]);
            self::assertSame(200, $scenario->getStatusCode(), (string)$scenario->getBody());

            $search = $client->get('/api/search?q=' . rawurlencode($vehicleId) . '&limit=5');
            self::assertSame(200, $search->getStatusCode(), (string)$search->getBody());
            self::assertOpenApiResponse('search', 200, $search);
            $matches = array_values(array_filter(
                self::listValue(self::data($search), 'results'),
                static fn(mixed $result): bool => is_array($result)
                    && ($result['type'] ?? null) === 'vehicle'
                    && ($result['id'] ?? null) === $vehicleId,
            ));
            self::assertCount(1, $matches);

            $vehicle = $client->get('/api/vehicles/' . rawurlencode($vehicleId));
            self::assertSame(200, $vehicle->getStatusCode(), (string)$vehicle->getBody());
            self::assertSame('lost', self::objectValue(self::data($vehicle), 'vehicle')['state'] ?? null);
        } finally {
            $server->stop();
        }
    }

    public function testLargeCatalogStationMapIsJsonBoundedCompleteAndMemorySafe(): void
    {
        $stationCount = 58_500;
        $server = self::server();
        self::assertSame($stationCount, $server->replaceStationCatalog($stationCount));

        try {
            $lowZoomClusterIds = null;
            $highZoomClusterIds = null;
            foreach ([4, 10, 4] as $requestIndex => $zoom) {
                $response = self::request('GET', '/api/stations?bbox=4,57,32,72&zoom=' . $zoom);
                self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
                self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
                if ($requestIndex === 0) {
                    self::assertOpenApiResponse('getStationMap', 200, $response);
                }

                $items = self::listValue(self::data($response), 'items');
                self::assertNotEmpty($items);
                self::assertLessThanOrEqual(2_000, count($items));
                self::assertSame($stationCount, self::stationCoverage($items));
                self::assertNotEmpty(array_filter(
                    $items,
                    static fn(mixed $item): bool => is_array($item) && ($item['kind'] ?? null) === 'cluster',
                ));
                $clusterIds = [];
                foreach ($items as $item) {
                    if (is_array($item) && !array_is_list($item) && ($item['kind'] ?? null) === 'cluster') {
                        $clusterIds[] = self::stringValue(self::stringKeyed($item), 'id');
                    }
                }
                self::assertSame($clusterIds, array_values(array_unique($clusterIds)));
                if ($zoom === 4 && $lowZoomClusterIds === null) {
                    $lowZoomClusterIds = $clusterIds;
                } elseif ($zoom === 4) {
                    self::assertSame($lowZoomClusterIds, $clusterIds, 'Cluster IDs must be stable for the same cell size.');
                } else {
                    $highZoomClusterIds = $clusterIds;
                }
            }
            self::assertSame([], array_values(array_intersect(
                $lowZoomClusterIds,
                $highZoomClusterIds,
            )), 'Cluster IDs from different cell sizes must not collide.');

            $zoomEight = self::request('GET', '/api/stations?bbox=4,57,4.5,58&zoom=8');
            self::assertSame(200, $zoomEight->getStatusCode(), (string)$zoomEight->getBody());
            self::assertStringStartsWith('application/json', $zoomEight->getHeaderLine('Content-Type'));
            $zoomEightItems = self::listValue(self::data($zoomEight), 'items');
            $viewportStationCount = self::stationCoverage($zoomEightItems);
            self::assertGreaterThan(1, $viewportStationCount);
            self::assertLessThanOrEqual(300, $viewportStationCount);
            self::assertLessThan($viewportStationCount, count($zoomEightItems));
            self::assertNotEmpty(array_filter(
                $zoomEightItems,
                static fn(mixed $item): bool => is_array($item) && ($item['kind'] ?? null) === 'cluster',
            ), 'Zoom 8 must aggregate even a viewport that fits the direct-marker budget.');

            $zoomNine = self::request('GET', '/api/stations?bbox=4,57,4.5,58&zoom=9');
            self::assertSame(200, $zoomNine->getStatusCode(), (string)$zoomNine->getBody());
            self::assertStringStartsWith('application/json', $zoomNine->getHeaderLine('Content-Type'));
            $zoomNineItems = self::listValue(self::data($zoomNine), 'items');
            self::assertSame($viewportStationCount, self::stationCoverage($zoomNineItems));
            self::assertCount($viewportStationCount, $zoomNineItems);
            self::assertSame([], array_values(array_filter(
                $zoomNineItems,
                static fn(mixed $item): bool => !is_array($item) || ($item['kind'] ?? null) !== 'station',
            )), 'Zoom 9 must expose exact station markers when the viewport contains at most 300 stations.');
        } finally {
            self::assertSame(count(FixtureFactory::stations()), $server->replaceStationCatalog(0));
        }
    }

    public function testAdminSessionProtectionDiagnosticsLogoutAndSpaDeepLinks(): void
    {
        $encodedAdminRoutes = [
            ['getAdminStatus', '/%61pi/admin/status'],
            ['getAdminDatabaseSchema', '/api/%61dmin/database/schema'],
            ['getAdminDatabaseMigrations', '/api/a%64min/database/migrations'],
            ['getAdminStatus', '/api/ad%6din/status'],
            ['getAdminStatus', '/api/adm%69n/status'],
            ['getAdminStatus', '/api/admin/%73tatus'],
        ];
        foreach ([
            '/api/admin/session',
            '/api/admin/status',
            '/api/admin/events',
            '/api/admin/database/schema',
            '/api/admin/database/migrations',
            '/api/admin/migrations',
        ] as $path) {
            $protected = self::request('GET', $path);
            self::assertSame(401, $protected->getStatusCode(), $path);
            self::assertErrorEnvelope($protected, 'admin_unauthorized');
        }
        foreach ($encodedAdminRoutes as [$operation, $path]) {
            $protected = self::request('GET', $path);
            self::assertSame(401, $protected->getStatusCode(), $path . ': ' . (string)$protected->getBody());
            self::assertErrorEnvelope($protected, 'admin_unauthorized');
            self::assertOpenApiResponse($operation, 401, $protected);
        }
        foreach ([
            '/api/%2561dmin/status',
            '/api%2Fadmin/status',
            '/api/admin%2Fstatus',
            '/api%5Cadmin/status',
            '/api/admin%5Cstatus',
            '/api/%25ZZadmin/status',
        ] as $ambiguousPath) {
            $response = self::request('GET', $ambiguousPath);
            if ($response->getStatusCode() === 200) {
                self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'), $ambiguousPath);
                self::assertStringContainsString(
                    'data-test="fjordpulse-spa"',
                    (string)$response->getBody(),
                    $ambiguousPath,
                );
                continue;
            }
            self::assertContains(
                $response->getStatusCode(),
                [400, 404],
                $ambiguousPath . ': ' . $response->getHeaderLine('Content-Type') . ' ' . (string)$response->getBody(),
            );
        }

        $publicDemo = self::request('GET', '/api/admin/demo-credentials');
        self::assertSame(200, $publicDemo->getStatusCode());
        self::assertSame('no-store', $publicDemo->getHeaderLine('Cache-Control'));
        self::assertOpenApiResponse('getAdminDemoCredentials', 200, $publicDemo);
        self::assertSame([
            'enabled' => true,
            'username' => HttpBlackBoxServer::ADMIN_DEMO_USERNAME,
            'password' => HttpBlackBoxServer::ADMIN_DEMO_PASSWORD,
        ], self::data($publicDemo));
        self::assertStringNotContainsString(HttpBlackBoxServer::ADMIN_PASSWORD, (string)$publicDemo->getBody());

        $demoCookies = new CookieJar();
        $demoLogin = self::request('POST', '/api/admin/session', [
            'cookies' => $demoCookies,
            'json' => [
                'username' => HttpBlackBoxServer::ADMIN_DEMO_USERNAME,
                'password' => HttpBlackBoxServer::ADMIN_DEMO_PASSWORD,
            ],
        ]);
        self::assertSame(200, $demoLogin->getStatusCode());
        self::assertOpenApiResponse('createAdminSession', 200, $demoLogin);
        self::assertSame(
            HttpBlackBoxServer::ADMIN_DEMO_USERNAME,
            self::data($demoLogin)['username'] ?? null,
        );
        self::assertSame('demo', self::data($demoLogin)['access'] ?? null);
        $demoSession = self::request('GET', '/api/admin/session', ['cookies' => $demoCookies]);
        self::assertSame(200, $demoSession->getStatusCode());
        self::assertOpenApiResponse('getAdminSession', 200, $demoSession);
        self::assertSame('demo', self::data($demoSession)['access'] ?? null);
        $demoStatus = self::request('GET', '/api/admin/status', ['cookies' => $demoCookies]);
        self::assertSame(200, $demoStatus->getStatusCode());
        self::assertOpenApiResponse('getAdminStatus', 200, $demoStatus);
        $demoLogout = self::request('DELETE', '/api/admin/session', ['cookies' => $demoCookies]);
        self::assertSame(204, $demoLogout->getStatusCode());

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
        self::assertSame('operator', self::data($login)['access'] ?? null);

        $session = self::request('GET', '/api/admin/session', ['cookies' => $cookies]);
        self::assertSame(200, $session->getStatusCode());
        self::assertOpenApiResponse('getAdminSession', 200, $session);
        self::assertSame('operator', self::data($session)['access'] ?? null);

        foreach ($encodedAdminRoutes as [$operation, $path]) {
            $diagnostic = self::request('GET', $path, ['cookies' => $cookies]);
            self::assertSame(200, $diagnostic->getStatusCode(), $path . ': ' . (string)$diagnostic->getBody());
            self::assertOpenApiResponse($operation, 200, $diagnostic);
        }

        $statusDiagnostic = self::request('GET', '/api/admin/status', ['cookies' => $cookies]);
        self::assertSame(200, $statusDiagnostic->getStatusCode());
        self::assertOpenApiResponse('getAdminStatus', 200, $statusDiagnostic);
        $statusData = self::data($statusDiagnostic);
        self::assertSame('surrealdb', self::nestedString($statusData, 'database', 'engine'));
        $databaseEndpoint = self::nestedString($statusData, 'database', 'endpointOrigin');
        self::assertMatchesRegularExpression(
            '#^ws://127\.0\.0\.1:[0-9]+$#D',
            $databaseEndpoint,
        );
        self::assertSame('fjordpulse_http_test', self::nestedString($statusData, 'database', 'namespace'));
        self::assertStringStartsWith('http_blackbox_', self::nestedString($statusData, 'database', 'name'));
        $databaseDiagnostic = $statusData['database'] ?? null;
        self::assertIsArray($databaseDiagnostic);
        self::assertArrayHasKey('warning', $databaseDiagnostic);
        self::assertNull($databaseDiagnostic['warning']);
        self::assertStringNotContainsString('/rpc', $databaseEndpoint);
        self::assertStringNotContainsString('@', $databaseEndpoint);
        self::assertStringNotContainsString('?', $databaseEndpoint);
        self::assertStringNotContainsString('#', $databaseEndpoint);
        self::assertStringNotContainsString(HttpBlackBoxServer::ADMIN_PASSWORD, (string)$statusDiagnostic->getBody());

        $enturBudgets = $statusData['enturBudgets'] ?? null;
        self::assertIsArray($enturBudgets);
        self::assertCount(5, $enturBudgets);
        self::assertSame([
            'global',
            'stop_place_register',
            'geocoder',
            'journey_planner',
            'vehicle_positions',
        ], array_column($enturBudgets, 'service'));
        foreach ($enturBudgets as $budget) {
            self::assertIsArray($budget);
            self::assertArrayNotHasKey('resetsAt', $budget);
            self::assertSame(60, $budget['windowSeconds'] ?? null);
            self::assertIsInt($budget['limit'] ?? null);
            self::assertIsInt($budget['remaining'] ?? null);
            self::assertLessThanOrEqual($budget['limit'], $budget['remaining']);
        }

        $recentEvents = $statusData['recentEvents'] ?? null;
        self::assertIsArray($recentEvents);
        self::assertLessThanOrEqual(5, count($recentEvents));

        $resources = $statusData['resources'] ?? null;
        self::assertIsArray($resources);
        self::assertIsString($resources['checkedAt'] ?? null);
        self::assertNotFalse(DateTimeImmutable::createFromFormat(
            DateTimeInterface::RFC3339_EXTENDED,
            (string)$resources['checkedAt'],
        ));
        $cpu = $resources['cpu'] ?? null;
        self::assertIsArray($cpu);
        foreach (['usagePercent', 'load1', 'load5', 'load15'] as $measurement) {
            $value = $cpu[$measurement] ?? null;
            self::assertTrue($value === null || is_int($value) || is_float($value), $measurement);
            if ($value !== null) {
                self::assertGreaterThanOrEqual(0, $value, $measurement);
            }
        }
        if (($cpu['usagePercent'] ?? null) !== null) {
            self::assertLessThanOrEqual(100, $cpu['usagePercent']);
        }
        self::assertTrue(($cpu['logicalCores'] ?? null) === null || is_int($cpu['logicalCores']));
        if (($cpu['logicalCores'] ?? null) !== null) {
            self::assertGreaterThanOrEqual(1, $cpu['logicalCores']);
        }

        $memory = $resources['memory'] ?? null;
        self::assertIsArray($memory);
        self::assertContains($memory['scope'] ?? null, ['host', 'cgroup']);
        foreach (['totalBytes', 'availableBytes', 'usedBytes'] as $measurement) {
            $value = $memory[$measurement] ?? null;
            self::assertTrue($value === null || is_int($value), $measurement);
            if ($value !== null) {
                self::assertGreaterThanOrEqual(0, $value, $measurement);
            }
        }
        if (is_int($memory['totalBytes'] ?? null) && is_int($memory['availableBytes'] ?? null) && is_int($memory['usedBytes'] ?? null)) {
            self::assertSame($memory['totalBytes'], $memory['availableBytes'] + $memory['usedBytes']);
        }

        $disk = $resources['disk'] ?? null;
        self::assertIsArray($disk);
        self::assertSame(realpath(dirname(__DIR__, 2)), $disk['path'] ?? null);
        foreach (['totalBytes', 'freeBytes', 'usedBytes'] as $measurement) {
            $value = $disk[$measurement] ?? null;
            self::assertTrue($value === null || is_int($value), $measurement);
            if ($value !== null) {
                self::assertGreaterThanOrEqual(0, $value, $measurement);
            }
        }
        if (is_int($disk['totalBytes'] ?? null) && is_int($disk['freeBytes'] ?? null) && is_int($disk['usedBytes'] ?? null)) {
            self::assertSame($disk['totalBytes'], $disk['freeBytes'] + $disk['usedBytes']);
        }

        foreach ([
            ['getAdminWatches', '/api/admin/watches?limit=10'],
            ['getAdminEnturLog', '/api/admin/entur-log?limit=10'],
            ['getAdminRealtime', '/api/admin/realtime'],
            ['getAdminEvents', '/api/admin/events?limit=10'],
        ] as [$operation, $path]) {
            $diagnostic = self::request('GET', $path, ['cookies' => $cookies]);
            self::assertSame(200, $diagnostic->getStatusCode(), $path . ': ' . $diagnostic->getBody()->getContents());
            self::assertOpenApiResponse($operation, 200, $diagnostic);
        }

        $schemaDiagnostic = self::request('GET', '/api/admin/database/schema', ['cookies' => $cookies]);
        self::assertSame(200, $schemaDiagnostic->getStatusCode(), (string)$schemaDiagnostic->getBody());
        self::assertOpenApiResponse('getAdminDatabaseSchema', 200, $schemaDiagnostic);
        $schemaData = self::data($schemaDiagnostic);
        self::assertSame(true, $schemaData['readOnly'] ?? null);
        $schemaTables = self::listValue($schemaData, 'tables');
        self::assertNotEmpty($schemaTables);
        self::assertContains('schema_migration', array_column($schemaTables, 'name'));
        self::assertContains('schema_migration_attempt', array_column($schemaTables, 'name'));
        $schemaJson = json_encode($schemaData, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PASSHASH', $schemaJson);
        self::assertStringNotContainsString('users', $schemaJson);
        self::assertStringNotContainsString(HttpBlackBoxServer::ADMIN_PASSWORD, $schemaJson);
        self::assertStringNotContainsString('blackbox-database-password', $schemaJson);

        $migrationDiagnostic = self::request('GET', '/api/admin/database/migrations', ['cookies' => $cookies]);
        self::assertSame(200, $migrationDiagnostic->getStatusCode(), (string)$migrationDiagnostic->getBody());
        self::assertOpenApiResponse('getAdminDatabaseMigrations', 200, $migrationDiagnostic);
        $migrationData = self::data($migrationDiagnostic);
        self::assertSame(true, $migrationData['readOnly'] ?? null);
        self::assertSame('in_sync', $migrationData['state'] ?? null);
        $migrationCounts = $migrationData['counts'] ?? null;
        self::assertIsArray($migrationCounts);
        self::assertSame(11, $migrationCounts['applied'] ?? null);
        $migrationRows = self::listValue($migrationData, 'migrations');
        self::assertCount(11, $migrationRows);
        self::assertContains('010_migration_attempt_history.surql', array_column($migrationRows, 'name'));
        self::assertContains('011_station_timetable_cache.surql', array_column($migrationRows, 'name'));
        foreach ($migrationRows as $migrationRow) {
            self::assertIsArray($migrationRow);
            self::assertSame('applied', $migrationRow['state'] ?? null);
            self::assertIsString($migrationRow['source'] ?? null);
            self::assertNotSame('', $migrationRow['source']);
            self::assertIsArray($migrationRow['affectedObjects'] ?? null);
            self::assertNotSame([], $migrationRow['affectedObjects']);
        }

        $legacyMigrations = self::request('GET', '/api/admin/migrations', ['cookies' => $cookies]);
        self::assertSame(200, $legacyMigrations->getStatusCode(), (string)$legacyMigrations->getBody());
        self::assertOpenApiResponse('getAdminMigrations', 200, $legacyMigrations);
        $legacyData = self::data($legacyMigrations);
        unset($legacyData['checkedAt'], $migrationData['checkedAt']);
        self::assertSame($migrationData, $legacyData);

        foreach (['/api/admin/database/schema', '/api/admin/database/migrations'] as $readOnlyPath) {
            $mutation = self::request('POST', $readOnlyPath, ['cookies' => $cookies, 'json' => []]);
            self::assertContains($mutation->getStatusCode(), [404, 405], $readOnlyPath);
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

    public function testAdminWatchMetricsExcludeDisconnectGraceAndPastExpiry(): void
    {
        $now = new DateTimeImmutable();
        $future = $now->add(new DateInterval('PT5M'));
        $past = $now->sub(new DateInterval('PT5M'));
        $server = self::server();
        self::assertSame(4, $server->replaceWatches([
            new Watch('live-vehicle', WatchType::Vehicle, 'vehicle:live', 'live', 1, WatchPriority::Vehicle, null, $now, $future, WatchState::Active),
            new Watch('live-focus', WatchType::Focus, 'focus:live-session:live', 'live', 1, WatchPriority::Focus, null, $now, $future, WatchState::Active),
            new Watch('grace-focus', WatchType::Focus, 'focus:closed-session:grace', 'grace', 0, WatchPriority::Focus, null, $now, $future, WatchState::Active),
            new Watch('past-orphan', WatchType::Vehicle, 'vehicle:past', 'past', 1, WatchPriority::Vehicle, null, $past, $past, WatchState::Active),
        ]));

        try {
            $cookies = new CookieJar();
            $login = self::request('POST', '/api/admin/session', [
                'cookies' => $cookies,
                'json' => [
                    'username' => HttpBlackBoxServer::ADMIN_USERNAME,
                    'password' => HttpBlackBoxServer::ADMIN_PASSWORD,
                ],
            ]);
            self::assertSame(200, $login->getStatusCode());

            $status = self::request('GET', '/api/admin/status', ['cookies' => $cookies]);
            self::assertSame(200, $status->getStatusCode(), (string)$status->getBody());
            self::assertOpenApiResponse('getAdminStatus', 200, $status);
            $metrics = self::objectValue(self::data($status), 'metrics');
            self::assertSame(1, $metrics['vehicleWatches'] ?? null);
            self::assertSame(1, $metrics['focusWatches'] ?? null);

            $watches = self::request('GET', '/api/admin/watches', ['cookies' => $cookies]);
            self::assertSame(200, $watches->getStatusCode(), (string)$watches->getBody());
            self::assertOpenApiResponse('getAdminWatches', 200, $watches);
            $watchData = self::data($watches);
            $rows = self::listValue($watchData, 'watches');
            self::assertCount(3, $rows);
            $watchRows = array_map(
                static fn(mixed $row): array => self::objectItem($row, 'watch row'),
                $rows,
            );
            self::assertSame(
                ['grace-focus', 'live-focus', 'live-vehicle'],
                array_map(static fn(array $row): mixed => $row['id'] ?? null, $watchRows),
            );
            $grace = $watchRows[0];
            self::assertSame(0, $grace['clientCount'] ?? null);
            self::assertSame('expired', $grace['state'] ?? null);

            $expired = self::request('GET', '/api/admin/watches?state=expired', ['cookies' => $cookies]);
            self::assertSame(200, $expired->getStatusCode(), (string)$expired->getBody());
            $expiredRows = array_map(
                static fn(mixed $row): array => self::objectItem($row, 'expired watch row'),
                self::listValue(self::data($expired), 'watches'),
            );
            self::assertSame(
                ['grace-focus'],
                array_map(static fn(array $row): mixed => $row['id'] ?? null, $expiredRows),
            );
        } finally {
            self::assertSame(0, $server->replaceWatches([]));
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

    /** @param list<mixed> $items */
    private static function stationCoverage(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item)) {
                self::fail('Station map item must be an object.');
            }
            $kind = $item['kind'] ?? null;
            if ($kind === 'station') {
                $total++;
                continue;
            }
            if ($kind !== 'cluster' || !is_int($item['count'] ?? null)) {
                self::fail('Station map item must be a station or counted cluster.');
            }
            $total += $item['count'];
        }

        return $total;
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

    /** @return array<string, mixed> */
    private static function objectItem(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            self::fail("Expected {$context} to be an object.");
        }

        return self::stringKeyed($value);
    }
}
