<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\StationKind;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Domain\VehicleTransportMode;
use FjordPulse\Entur\Mapper\GeocoderMapper;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use FjordPulse\Entur\Mapper\StopPlaceMapper;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Dto\VehicleJourneyReference;
use PHPUnit\Framework\TestCase;

final class EnturMapperTest extends TestCase
{
    public function testGeocoderV3StructuredResponse(): void
    {
        $stations = (new GeocoderMapper())->map([
            'type' => 'FeatureCollection',
            'features' => [[
                'geometry' => ['type' => 'Point', 'coordinates' => [5.8572, 61.4522]],
                'properties' => [
                    'id' => 'NSR:StopPlace:36025',
                    'names' => ['default' => 'Førde rutebilstasjon'],
                    'address' => ['locality' => 'Sunnfjord'],
                    'stopPlaceTypes' => ['busStation'],
                    'transportModes' => [['mode' => 'bus']],
                ],
            ]],
            'metadata' => ['timestamp' => '2026-07-10T04:00:00Z'],
        ]);

        self::assertCount(1, $stations);
        self::assertSame(StationKind::BusStation, $stations[0]->kind);
        self::assertSame(['bus'], $stations[0]->transportModes);
    }

    public function testGeocoderV3MapsPoiResultsAsPlaces(): void
    {
        $places = (new GeocoderMapper())->map([
            'features' => [[
                'geometry' => ['coordinates' => [10.7336, 59.9119]],
                'properties' => [
                    'id' => 'OSM:TopographicPlace:24900009',
                    'names' => ['default' => 'Oslo rådhus'],
                    'address' => ['locality' => 'Oslo'],
                    'layer' => 'poi',
                ],
            ]],
        ]);

        self::assertCount(1, $places);
        self::assertSame(StationKind::Unknown, $places[0]->kind);
        self::assertSame('Oslo rådhus', $places[0]->name);
    }

    public function testStopPlaceMapperHandlesNetexJsonShape(): void
    {
        $stations = (new StopPlaceMapper())->map([[
            'id' => 'NSR:StopPlace:1',
            'name' => ['lang' => 'nor', 'value' => 'Drangedal stasjon'],
            'centroid' => ['location' => ['latitude' => 59.096262, 'longitude' => 9.064246]],
            'changed' => '2026-02-04T15:33:07+01:00',
            'stopPlaceType' => 'RAIL_STATION',
            'transportMode' => 'RAIL',
        ]]);

        self::assertSame('Drangedal stasjon', $stations[0]->name);
        self::assertSame(StationKind::RailStation, $stations[0]->kind);
    }

    public function testJourneyPlannerMapsRealtimeDelayedCancelledAndMissingOptionalFields(): void
    {
        $payload = ['data' => ['stopPlace' => ['estimatedCalls' => [
            [
                'aimedDepartureTime' => '2026-07-10T06:36:00+02:00',
                'expectedDepartureTime' => '2026-07-10T06:39:00+02:00',
                'actualDepartureTime' => null,
                'cancellation' => false,
                'quay' => ['publicCode' => '16'],
                'destinationDisplay' => ['frontText' => 'Ski'],
                'serviceJourney' => ['id' => 'VYG:ServiceJourney:1', 'journeyPattern' => ['line' => ['id' => 'VYG:Line:R23', 'publicCode' => 'R23']]],
            ],
            [
                'aimedDepartureTime' => '2026-07-10T06:40:00+02:00',
                'expectedDepartureTime' => null,
                'actualDepartureTime' => null,
                'cancellation' => true,
                'serviceJourney' => [],
            ],
            [
                'aimedDepartureTime' => '2026-07-10T06:45:00+02:00',
                'expectedDepartureTime' => null,
                'actualDepartureTime' => null,
                'cancellation' => false,
            ],
        ]]]];
        $departures = (new JourneyPlannerMapper())->map($payload);

        self::assertSame(DepartureStatus::Delayed, $departures[0]->status);
        self::assertSame(180, $departures[0]->delaySeconds);
        self::assertSame(DepartureStatus::Cancelled, $departures[1]->status);
        self::assertSame(DepartureStatus::Scheduled, $departures[2]->status);
        self::assertNull($departures[2]->lineCode);
    }

    public function testVehicleMapperNormalizesPositionAndMissingOptionals(): void
    {
        $vehicles = (new VehicleMapper(
            clock: static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-07-10T04:06:00Z'),
        ))->map(['data' => ['vehicles' => [[
            'vehicleId' => '3350387148',
            'mode' => 'BUS',
            'lastUpdated' => '2026-07-10T04:05:44Z',
            'location' => ['latitude' => 60.35, 'longitude' => 5.33728],
            'bearing' => -58.34256362915039,
            'delay' => 60.0,
            'line' => ['lineRef' => 'SKY:Line:100', 'lineName' => 'Førde–Florø', 'publicCode' => '100'],
            'destinationName' => 'Florø',
            'serviceJourney' => ['id' => 'SKY:ServiceJourney:100-1', 'date' => '2026-07-10'],
            'monitoredCall' => ['stopPointRef' => 'NSR:Quay:1', 'order' => 2, 'vehicleAtStop' => false],
            'progressBetweenStops' => ['linkDistance' => 1200, 'percentage' => 35.0],
        ], [
            'vehicleId' => 'minimal',
            'lastUpdated' => '2026-07-10T04:05:44Z',
            'location' => ['latitude' => 60.0, 'longitude' => 5.0],
        ]]]]);

        self::assertCount(2, $vehicles);
        self::assertSame(VehicleFreshness::Live, $vehicles[0]->state);
        self::assertSame(VehicleTransportMode::Bus, $vehicles[0]->transportMode);
        self::assertSame(VehiclePassengerServiceState::Passenger, $vehicles[0]->passengerServiceState);
        self::assertSame('100', $vehicles[0]->lineCode);
        self::assertSame('2026-07-10T04:05:44+00:00', $vehicles[0]->lastSeenAt->format(DATE_RFC3339));
        self::assertSame('2026-07-10T04:06:00+00:00', $vehicles[0]->refreshedAt?->format(DATE_RFC3339));
        self::assertSame('SKY:ServiceJourney:100-1', $vehicles[0]->journeyReference?->serviceJourneyId);
        self::assertSame(1, $vehicles[0]->monitoredCall?->order);
        self::assertSame(0.35, $vehicles[0]->progressBetweenStops?->percentage);
        self::assertEqualsWithDelta(301.6574363708496, $vehicles[0]->bearing ?? -1, 0.000001);
        self::assertNull($vehicles[1]->lineCode);
        self::assertSame(VehicleTransportMode::Unknown, $vehicles[1]->transportMode);
        self::assertSame(VehiclePassengerServiceState::Unknown, $vehicles[1]->passengerServiceState);
        self::assertCount(1, $vehicles[0]->observations);
    }

    public function testVehicleMapperKeepsNewestDuplicatePhysicalVehicle(): void
    {
        $records = [[
            'vehicleId' => 'duplicate',
            'lastUpdated' => '2026-07-10T04:05:20Z',
            'location' => ['latitude' => 60.0, 'longitude' => 5.0],
            'line' => ['lineName' => 'Flaktveit - Hesjaholtet', 'publicCode' => '4'],
            'destinationName' => 'Flaktveit',
            'serviceJourney' => ['id' => 'SKY:ServiceJourney:4-1', 'date' => '2026-07-10'],
        ], [
            'vehicleId' => 'duplicate',
            'lastUpdated' => '2026-07-10T04:05:50Z',
            'location' => ['latitude' => 61.0, 'longitude' => 6.0],
            'delay' => 1_080,
            'line' => ['lineName' => 'Flaktveit - Hesjaholtet', 'publicCode' => '4'],
            'destinationName' => 'skyss.no',
            'serviceJourney' => ['id' => '21255797_200969', 'date' => '2026-07-10'],
            'originRef' => 'NSR:Quay:53799',
            'destinationRef' => 'GAR4.402',
            'monitoredCall' => ['stopPointRef' => 'GAR4.402', 'order' => 2, 'vehicleAtStop' => false],
        ]];

        foreach ([$records, array_reverse($records)] as $order) {
            $vehicles = (new VehicleMapper(
                clock: static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-07-10T04:06:00Z'),
            ))->map(['data' => ['vehicles' => $order]]);

            self::assertCount(1, $vehicles);
            self::assertSame(61.0, $vehicles[0]->coordinate?->latitude);
            self::assertSame('2026-07-10T04:05:50+00:00', $vehicles[0]->lastSeenAt->format(DATE_RFC3339));
            self::assertSame(VehiclePassengerServiceState::NonPassenger, $vehicles[0]->passengerServiceState);
            self::assertSame('21255797_200969', $vehicles[0]->journeyReference?->serviceJourneyId);
            self::assertSame('4', $vehicles[0]->lineCode, 'Raw public-code metadata remains available for diagnostics.');
            self::assertSame(1_080, $vehicles[0]->delaySeconds, 'Raw delay metadata remains available for diagnostics.');
        }
    }

    public function testJourneyPlannerMapsPolylineAndOrderedRealtimeCalls(): void
    {
        $reference = new VehicleJourneyReference('SKY:ServiceJourney:1', '2026-07-10');
        $journey = (new JourneyPlannerMapper())->mapJourney(['data' => ['serviceJourney' => [
            'id' => 'SKY:ServiceJourney:1',
            'pointsOnLink' => [
                'points' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
                'distance' => 788_000,
            ],
            'estimatedCalls' => [[
                'stopPositionInPattern' => 0,
                'aimedArrivalTime' => '2026-07-10T06:00:00+02:00',
                'expectedArrivalTime' => '2026-07-10T06:01:00+02:00',
                'aimedDepartureTime' => '2026-07-10T06:02:00+02:00',
                'expectedDepartureTime' => '2026-07-10T06:03:00+02:00',
                'realtime' => true,
                'cancellation' => false,
                'quay' => [
                    'id' => 'NSR:Quay:1',
                    'name' => 'Platform A',
                    'latitude' => 38.5,
                    'longitude' => -120.2,
                    'stopPlace' => ['id' => 'NSR:StopPlace:1', 'name' => 'First stop'],
                ],
            ]],
        ]]], $reference, new \DateTimeImmutable('2026-07-10T04:00:00Z'));

        self::assertNotNull($journey);
        self::assertNotNull($journey->route);
        self::assertCount(3, $journey->route->coordinates);
        self::assertSame(788_000.0, $journey->route->distanceMeters);
        self::assertSame('First stop', $journey->calls[0]->name);
        self::assertSame('2026-07-10T04:01:00+00:00', $journey->calls[0]->expectedArrivalAt?->format(DATE_RFC3339));
        self::assertTrue($journey->calls[0]->realtime);
    }
}
