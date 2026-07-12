<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Http\TransportInterface;
use FjordPulse\Entur\Http\TransportResponse;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Entur\NullEnturRequestObserver;
use FjordPulse\Entur\Real\RealVehiclePositions;
use FjordPulse\Entur\RequestBudget;
use PHPUnit\Framework\TestCase;

final class RealVehiclePositionsCacheTest extends TestCase
{
    public function testNearbyUsesTrueCircleNearestOrderingAndLimitAfterFiltering(): void
    {
        $now = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $transport = new NearbyVehicleTransport($now);
        $limits = array_fill_keys(
            array_map(static fn(EnturService $service): string => $service->value, EnturService::cases()),
            10,
        );
        $positions = new RealVehiclePositions(
            new EnturApiClient(
                $transport,
                new RequestBudget(20, $limits),
                new NullEnturRequestObserver(),
                'martinkavik-fjordpulse',
            ),
            new VehicleMapper(clock: static fn(): DateTimeImmutable => $now),
        );

        $nearby = $positions->nearby(new Coordinate(0.0, 0.0), 5.0, 2);

        self::assertSame(
            ['near', 'middle'],
            array_map(static fn(VehicleState $vehicle): string => $vehicle->id, $nearby),
        );
        self::assertNull($nearby[0]->distanceMeters);
        self::assertStringContainsString('vehicles(boundingBox: $bbox)', $transport->query ?? '');
    }

    public function testDifferentVehicleLookupsShareOneShortLivedNationwideFetch(): void
    {
        $transport = new CountingVehicleTransport();
        $limits = array_fill_keys(
            array_map(static fn(EnturService $service): string => $service->value, EnturService::cases()),
            10,
        );
        $client = new EnturApiClient(
            $transport,
            new RequestBudget(20, $limits),
            new NullEnturRequestObserver(),
            'martinkavik-fjordpulse',
        );
        $now = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $positions = new RealVehiclePositions(
            $client,
            new VehicleMapper(clock: static fn(): DateTimeImmutable => $now),
            nationwideCacheSeconds: 2,
            clock: static fn(): DateTimeImmutable => $now,
        );

        self::assertCount(2, $positions->current());
        self::assertSame('vehicle-1', $positions->vehicle('vehicle-1')?->id);
        self::assertSame('vehicle-2', $positions->vehicle('vehicle-2')?->id);
        self::assertNull($positions->vehicle('not-present'));
        self::assertSame(1, $transport->requests);
    }

    public function testStationVehiclesUsesOneAliasedRequestAndKeepsServingVehiclesOutsideTheNearbyCircle(): void
    {
        $now = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $transport = new StationVehicleTransport($now);
        $limits = array_fill_keys(
            array_map(static fn(EnturService $service): string => $service->value, EnturService::cases()),
            10,
        );
        $positions = new RealVehiclePositions(
            new EnturApiClient($transport, new RequestBudget(20, $limits), new NullEnturRequestObserver(), 'martinkavik-fjordpulse'),
            new VehicleMapper(clock: static fn(): DateTimeImmutable => $now),
        );
        $reference = new VehicleJourneyReference('VYG:ServiceJourney:night-1', '2026-07-09');

        $result = $positions->stationVehicles(new Coordinate(0.0, 0.0), [$reference], 5.0, 2);

        self::assertSame(1, $transport->requests);
        self::assertStringContainsString('nearby: vehicles(boundingBox: $bbox)', $transport->query ?? '');
        self::assertStringContainsString('serving: vehicles(serviceJourneyIdAndDates: $journeys)', $transport->query ?? '');
        self::assertSame([['id' => 'VYG:ServiceJourney:night-1', 'date' => '2026-07-09']], $transport->variables['journeys'] ?? null);
        self::assertSame(['near', 'middle'], array_map(static fn(VehicleState $vehicle): string => $vehicle->id, $result->nearbyVehicles));
        self::assertSame(['serving-far-away'], array_map(static fn(VehicleState $vehicle): string => $vehicle->id, $result->servingVehicles));

        $positions->stationVehicles(new Coordinate(0.0, 0.0), []);
        self::assertStringNotContainsString('serving:', $transport->query ?? '');
        self::assertArrayNotHasKey('journeys', $transport->variables);
    }
}

final class NearbyVehicleTransport implements TransportInterface
{
    public ?string $query = null;

    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function request(string $method, string $url, array $headers, ?array $json = null): TransportResponse
    {
        unset($method, $url, $headers);
        $this->query = is_string($json['query'] ?? null) ? $json['query'] : null;
        $lastUpdated = $this->now->format(DATE_RFC3339);

        return new TransportResponse(200, [], json_encode(['data' => ['vehicles' => [[
            // This point is inside the candidate square but outside the 5 km circle.
            'vehicleId' => 'outside-circle-inside-box',
            'lastUpdated' => $lastUpdated,
            'location' => ['latitude' => 0.035, 'longitude' => 0.035],
        ], [
            'vehicleId' => 'middle',
            'lastUpdated' => $lastUpdated,
            'location' => ['latitude' => 0.027, 'longitude' => 0.0],
        ], [
            'vehicleId' => 'near',
            'lastUpdated' => $lastUpdated,
            'location' => ['latitude' => 0.009, 'longitude' => 0.0],
        ], [
            'vehicleId' => 'farther-but-inside',
            'lastUpdated' => $lastUpdated,
            'location' => ['latitude' => 0.036, 'longitude' => 0.0],
        ]]]], JSON_THROW_ON_ERROR));
    }
}

final class CountingVehicleTransport implements TransportInterface
{
    public int $requests = 0;

    public function request(string $method, string $url, array $headers, ?array $json = null): TransportResponse
    {
        unset($method, $url, $headers, $json);
        $this->requests++;

        return new TransportResponse(200, [], json_encode(['data' => ['vehicles' => [[
            'vehicleId' => 'vehicle-1',
            'lastUpdated' => '2026-07-10T09:59:59Z',
            'location' => ['latitude' => 61.0, 'longitude' => 5.0],
        ], [
            'vehicleId' => 'vehicle-2',
            'lastUpdated' => '2026-07-10T09:59:58Z',
            'location' => ['latitude' => 62.0, 'longitude' => 6.0],
        ]]]], JSON_THROW_ON_ERROR));
    }
}

final class StationVehicleTransport implements TransportInterface
{
    public int $requests = 0;
    public ?string $query = null;
    /** @var array<string, mixed> */
    public array $variables = [];

    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function request(string $method, string $url, array $headers, ?array $json = null): TransportResponse
    {
        unset($method, $url, $headers);
        $this->requests++;
        $this->query = is_string($json['query'] ?? null) ? $json['query'] : null;
        $variables = $json['variables'] ?? null;
        $this->variables = [];
        if (is_array($variables) && !array_is_list($variables)) {
            foreach ($variables as $key => $value) {
                if (is_string($key)) {
                    $this->variables[$key] = $value;
                }
            }
        }
        $lastUpdated = $this->now->format(DATE_RFC3339);

        return new TransportResponse(200, [], json_encode(['data' => [
            'nearby' => [[
                'vehicleId' => 'outside-circle-inside-box',
                'mode' => 'BUS',
                'lastUpdated' => $lastUpdated,
                'location' => ['latitude' => 0.035, 'longitude' => 0.035],
            ], [
                'vehicleId' => 'middle',
                'mode' => 'BUS',
                'lastUpdated' => $lastUpdated,
                'location' => ['latitude' => 0.027, 'longitude' => 0.0],
            ], [
                'vehicleId' => 'near',
                'mode' => 'BUS',
                'lastUpdated' => $lastUpdated,
                'location' => ['latitude' => 0.009, 'longitude' => 0.0],
            ]],
            'serving' => [[
                'vehicleId' => 'serving-far-away',
                'mode' => 'RAIL',
                'lastUpdated' => $lastUpdated,
                'location' => ['latitude' => 61.0, 'longitude' => 10.0],
                'serviceJourney' => ['id' => 'VYG:ServiceJourney:night-1', 'date' => '2026-07-09'],
            ]],
        ]], JSON_THROW_ON_ERROR));
    }
}
