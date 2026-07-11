<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\EnturService;
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
