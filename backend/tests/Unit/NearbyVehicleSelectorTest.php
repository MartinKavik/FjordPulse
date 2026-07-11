<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\NearbyVehicleSelector;
use PHPUnit\Framework\TestCase;

final class NearbyVehicleSelectorTest extends TestCase
{
    public function testTrueCircleIsNearestFirstAndLimitedAfterFiltering(): void
    {
        $center = new Coordinate(0.0, 0.0);
        $vehicles = [
            self::vehicle('outside-circle-inside-box', new Coordinate(0.035, 0.035)),
            self::vehicle('four-kilometres', new Coordinate(0.036, 0.0)),
            self::vehicle('one-kilometre', new Coordinate(0.009, 0.0)),
            self::vehicle('three-kilometres', new Coordinate(0.0, 0.027)),
            self::vehicle('position-unavailable', null),
        ];

        $selected = NearbyVehicleSelector::select($center, $vehicles, 5.0, 2);

        self::assertSame(
            ['one-kilometre', 'three-kilometres'],
            array_map(static fn(VehicleState $vehicle): string => $vehicle->id, $selected),
        );
        self::assertSame(5_000, NearbyVehicleSelector::DEFAULT_RADIUS_METERS);
        self::assertSame(20, NearbyVehicleSelector::DEFAULT_LIMIT);
        self::assertNull($selected[0]->distanceMeters, 'Selection distance must not overload VehicleSummary.distanceMeters.');
    }

    public function testZeroLimitIsAnEmptySelection(): void
    {
        self::assertSame([], NearbyVehicleSelector::select(
            new Coordinate(0.0, 0.0),
            [self::vehicle('at-center', new Coordinate(0.0, 0.0))],
            limit: 0,
        ));
    }

    public function testInvalidRadiusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NearbyVehicleSelector::select(new Coordinate(0.0, 0.0), [], 0.0);
    }

    private static function vehicle(string $id, ?Coordinate $coordinate): VehicleState
    {
        $at = new DateTimeImmutable('2026-07-10T10:00:00Z');

        return new VehicleState(
            $id,
            '2026-07-10T10:00:00.000Z',
            hash('sha256', $id),
            VehicleFreshness::Live,
            $coordinate,
            null,
            null,
            null,
            null,
            null,
            null,
            $at,
            $at,
            null,
        );
    }
}
