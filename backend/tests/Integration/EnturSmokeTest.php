<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Http\GuzzleTransport;
use FjordPulse\Entur\Mapper\GeocoderMapper;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use FjordPulse\Entur\Mapper\StopPlaceMapper;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Entur\NullEnturRequestObserver;
use FjordPulse\Entur\Real\RealGeocoder;
use FjordPulse\Entur\Real\RealJourneyPlanner;
use FjordPulse\Entur\Real\RealStationRegistry;
use FjordPulse\Entur\Real\RealVehiclePositions;
use FjordPulse\Entur\RequestBudget;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('external')]
final class EnturSmokeTest extends TestCase
{
    public function testAllBackendOnlyEnturAdapters(): void
    {
        if (getenv('RUN_ENTUR_SMOKE') !== '1') {
            self::markTestSkipped('Set RUN_ENTUR_SMOKE=1 to call Entur open services.');
        }
        $clientName = getenv('ENTUR_CLIENT_NAME') ?: 'martinkavik-fjordpulse';
        $limits = array_fill_keys(array_map(static fn(EnturService $service): string => $service->value, EnturService::cases()), 10);
        $client = new EnturApiClient(new GuzzleTransport(), new RequestBudget(30, $limits), new NullEnturRequestObserver(), $clientName);

        $search = (new RealGeocoder($client, new GeocoderMapper()))->search('Førde', 2);
        self::assertNotEmpty($search);
        self::assertStringStartsWith('NSR:StopPlace:', $search[0]->id);

        $stops = (new RealStationRegistry($client, new StopPlaceMapper()))->stations(1);
        self::assertCount(1, $stops);

        $journeys = new RealJourneyPlanner($client, new JourneyPlannerMapper());
        $departures = $journeys->departures('NSR:StopPlace:337', 1);
        self::assertCount(1, $departures);

        $vehiclePositions = new RealVehiclePositions($client, new VehicleMapper());
        $vehicles = [];
        foreach ([
            new Coordinate(59.91, 10.75),
            new Coordinate(61.45, 5.86),
            new Coordinate(60.39, 5.33),
        ] as $center) {
            $vehicles = array_values(array_filter(
                $vehiclePositions->nearby($center, 50.0, 50),
                static fn($vehicle): bool => $vehicle->journeyReference !== null,
            ));
            if ($vehicles !== []) {
                break;
            }
        }
        self::assertNotEmpty($vehicles, 'No current Entur vehicle with a service journey was found in the smoke regions.');
        $lookedUpVehicle = $vehiclePositions->vehicle($vehicles[0]->id);
        self::assertNotNull($lookedUpVehicle);
        self::assertSame($vehicles[0]->id, $lookedUpVehicle->id);
        $reference = $lookedUpVehicle->journeyReference;
        self::assertNotNull($reference);
        $journey = $journeys->journey($reference);
        self::assertNotNull($journey);
        self::assertNotNull($journey->route);
        self::assertGreaterThan(1, count($journey->route->coordinates));
        self::assertNotEmpty($journey->calls);
    }
}
