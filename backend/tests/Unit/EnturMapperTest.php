<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\StationKind;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Entur\Mapper\GeocoderMapper;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use FjordPulse\Entur\Mapper\StopPlaceMapper;
use FjordPulse\Entur\Mapper\VehicleMapper;
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
            'lastUpdated' => '2026-07-10T04:05:44Z',
            'location' => ['latitude' => 60.35, 'longitude' => 5.33728],
            'bearing' => 43.5,
            'delay' => 60.0,
            'line' => ['lineRef' => 'SKY:Line:100', 'lineName' => 'Førde–Florø', 'publicCode' => '100'],
            'destinationName' => 'Florø',
        ], [
            'vehicleId' => 'minimal',
            'lastUpdated' => '2026-07-10T04:05:44Z',
            'location' => ['latitude' => 60.0, 'longitude' => 5.0],
        ]]]]);

        self::assertCount(2, $vehicles);
        self::assertSame(VehicleFreshness::Live, $vehicles[0]->state);
        self::assertSame('100', $vehicles[0]->lineCode);
        self::assertNull($vehicles[1]->lineCode);
        self::assertCount(1, $vehicles[0]->observations);
    }
}
