<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Domain\StationVehicleCallRole;
use FjordPulse\Domain\StationVehicleProgress;
use FjordPulse\Entur\Fake\FixtureFactory;
use FjordPulse\Surreal\SurrealDtoMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SurrealDtoMapperTest extends TestCase
{
    public function testReadsCanonicalStationVehicleSemantics(): void
    {
        $snapshot = SurrealDtoMapper::stationSnapshot(self::record([
            'callRole' => 'starts_here',
            'progress' => 'before_station',
        ]));

        self::assertCount(1, $snapshot->servingVehicles);
        self::assertSame(StationVehicleCallRole::StartsHere, $snapshot->servingVehicles[0]->callRole);
        self::assertSame(StationVehicleProgress::BeforeStation, $snapshot->servingVehicles[0]->progress);
        self::assertArrayNotHasKey('relation', $snapshot->servingVehicles[0]->toArray());
    }

    public function testConservativelyMapsLegacyRelationOnlyStationVehicles(): void
    {
        $cases = [
            'starting_here' => [StationVehicleCallRole::StartsHere, StationVehicleProgress::Unknown],
            'at_station' => [StationVehicleCallRole::CallsHere, StationVehicleProgress::AtStation],
            'approaching' => [StationVehicleCallRole::CallsHere, StationVehicleProgress::BeforeStation],
            'departed' => [StationVehicleCallRole::CallsHere, StationVehicleProgress::AfterStation],
            'serves_station' => [StationVehicleCallRole::CallsHere, StationVehicleProgress::Unknown],
        ];

        foreach ($cases as $legacy => [$expectedRole, $expectedProgress]) {
            $snapshot = SurrealDtoMapper::stationSnapshot(self::record(['relation' => $legacy]));
            $stationVehicle = $snapshot->servingVehicles[0];

            self::assertSame($expectedRole, $stationVehicle->callRole, $legacy);
            self::assertSame($expectedProgress, $stationVehicle->progress, $legacy);
        }
    }

    public function testRejectsPartiallyWrittenCanonicalStationVehicleSemantics(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('callRole and progress must be present together');

        SurrealDtoMapper::stationSnapshot(self::record(['callRole' => 'calls_here']));
    }

    /**
     * @param array<string, mixed> $semantics
     * @return array<string, mixed>
     */
    private static function record(array $semantics): array
    {
        $vehicle = FixtureFactory::vehicles(1)[0]->toSummaryArray();

        return [
            'station_id' => 'NSR:StopPlace:36025',
            'version' => '2026-07-12T10:00:00.000Z',
            'content_hash' => hash('sha256', 'station-snapshot'),
            'updated_at' => '2026-07-12T10:00:00Z',
            'state' => 'fresh',
            'departures' => [],
            'nearby_vehicles' => [],
            'serving_vehicles' => [[
                ...$vehicle,
                ...$semantics,
                'stationCallAt' => '2026-07-12T10:30:00Z',
            ]],
        ];
    }
}
