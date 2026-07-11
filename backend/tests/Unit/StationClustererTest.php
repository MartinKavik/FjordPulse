<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\StationKind;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Station;
use FjordPulse\Service\StationClusterer;
use PHPUnit\Framework\TestCase;

final class StationClustererTest extends TestCase
{
    public function testZoomEightAggregatesAndZoomNineShowsExactSmallViewport(): void
    {
        $stations = self::stations(64);
        $clusterer = new StationClusterer();

        $zoomEight = $clusterer->items($stations, 8.0);
        self::assertSame(64, self::coverage($zoomEight));
        self::assertLessThan(64, count($zoomEight));
        self::assertContains('cluster', array_column($zoomEight, 'kind'));

        $zoomNine = $clusterer->items($stations, 9.0);
        self::assertSame(64, self::coverage($zoomNine));
        self::assertCount(64, $zoomNine);
        foreach ($zoomNine as $item) {
            self::assertSame('station', $item['kind'] ?? null);
        }
    }

    public function testZoomNineAggregatesViewportAboveThreeHundredStationBudget(): void
    {
        $items = (new StationClusterer())->items(self::stations(301), 9.0);

        self::assertSame(301, self::coverage($items));
        self::assertLessThan(301, count($items));
        self::assertContains('cluster', array_column($items, 'kind'));
    }

    /** @return list<Station> */
    private static function stations(int $count): array
    {
        $importedAt = new DateTimeImmutable('2026-07-10T09:00:00Z');
        $stations = [];
        for ($index = 0; $index < $count; $index++) {
            $stations[] = new Station(
                sprintf('NSR:StopPlace:cluster-%03d', $index),
                sprintf('Cluster station %03d', $index),
                StationKind::StopPlace,
                new Coordinate(
                    61.01 + (($index % 20) * 0.0001),
                    5.01 + (intdiv($index, 20) * 0.0001),
                ),
                'Test locality',
                'Test municipality',
                ['bus'],
                $importedAt,
            );
        }

        return $stations;
    }

    /** @param list<array<string, mixed>> $items */
    private static function coverage(array $items): int
    {
        $coverage = 0;
        foreach ($items as $item) {
            if (($item['kind'] ?? null) === 'station') {
                $coverage++;
                continue;
            }
            self::assertSame('cluster', $item['kind'] ?? null);
            self::assertIsInt($item['count'] ?? null);
            $coverage += $item['count'];
        }

        return $coverage;
    }
}
