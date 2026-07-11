<?php

declare(strict_types=1);

namespace FjordPulse\Service;

use FjordPulse\Dto\BoundingBox;
use FjordPulse\Dto\Station;
use FjordPulse\Surreal\StationRepository;

final class StationClusterer
{
    private const int MAX_ITEMS = 2_000;
    private const int MAX_DIRECT_MARKERS = 300;
    private const float MINIMUM_DIRECT_MARKER_ZOOM = 9.0;
    private const int DIRECT_MARKER_PROBE_ITEMS = self::MAX_DIRECT_MARKERS + 1;
    private const int CELL_PROBE_ITEMS = self::MAX_ITEMS + 1;
    private const int MAX_CELL_ATTEMPTS = 24;

    /**
     * Build a complete, contract-bounded viewport without loading all matching
     * station records into PHP. Detailed viewports use projected markers only
     * when the repository proves they fit; every other viewport is aggregated
     * into database-side cells before crossing the SDK boundary.
     *
     * @return list<array<string, mixed>>
     */
    public function boundedItems(
        StationRepository $repository,
        BoundingBox $bounds,
        float $zoom,
    ): array {
        $stationCount = $repository->countWithinBounds(
            $bounds->minLongitude,
            $bounds->minLatitude,
            $bounds->maxLongitude,
            $bounds->maxLatitude,
        );
        if ($stationCount === 0) {
            return [];
        }

        if ($zoom >= self::MINIMUM_DIRECT_MARKER_ZOOM && $stationCount <= self::MAX_DIRECT_MARKERS) {
            $markers = $repository->projectedMarkersWithinBounds(
                $bounds->minLongitude,
                $bounds->minLatitude,
                $bounds->maxLongitude,
                $bounds->maxLatitude,
                self::DIRECT_MARKER_PROBE_ITEMS,
            );
            if (count($markers) <= self::MAX_DIRECT_MARKERS) {
                return $markers;
            }
        }

        $cellSize = self::cellSize($zoom);
        for ($attempt = 0; $attempt < self::MAX_CELL_ATTEMPTS; $attempt++) {
            $items = $repository->projectedCellsWithinBounds(
                $bounds->minLongitude,
                $bounds->minLatitude,
                $bounds->maxLongitude,
                $bounds->maxLatitude,
                $cellSize,
                self::CELL_PROBE_ITEMS,
            );
            if (count($items) <= self::MAX_ITEMS) {
                return $items;
            }
            $cellSize = min(360.0, $cellSize * 1.5);
        }

        throw new \RuntimeException('Unable to create a complete station map within the 2000-item contract limit.');
    }

    /**
     * @param list<Station> $stations
     * @return list<array<string, mixed>>
     */
    public function items(array $stations, float $zoom): array
    {
        if ($zoom >= self::MINIMUM_DIRECT_MARKER_ZOOM
            && count($stations) <= self::MAX_DIRECT_MARKERS
        ) {
            return array_map(self::marker(...), $stations);
        }
        $cellSize = self::cellSize($zoom);
        $cells = self::cells($stations, $cellSize);
        while (count($cells) > self::MAX_ITEMS) {
            $cellSize *= 1.5;
            $cells = self::cells($stations, $cellSize);
        }
        ksort($cells);
        $items = [];
        foreach ($cells as $key => $members) {
            if ($members === []) {
                continue;
            }
            if (count($members) === 1) {
                $items[] = self::marker($members[0]);
                continue;
            }
            $latitudes = array_map(static fn(Station $station): float => $station->coordinate->latitude, $members);
            $longitudes = array_map(static fn(Station $station): float => $station->coordinate->longitude, $members);
            $items[] = [
                'kind' => 'cluster',
                'id' => 'cluster_' . substr(hash('sha256', $key), 0, 16),
                'latitude' => array_sum($latitudes) / count($latitudes),
                'longitude' => array_sum($longitudes) / count($longitudes),
                'count' => count($members),
                'bounds' => [
                    'minLongitude' => min($longitudes),
                    'minLatitude' => min($latitudes),
                    'maxLongitude' => max($longitudes),
                    'maxLatitude' => max($latitudes),
                ],
            ];
        }

        return $items;
    }

    private static function cellSize(float $zoom): float
    {
        return max(0.2, 12.0 / (2 ** max(0.0, $zoom - 2.0)));
    }

    /**
     * @param list<Station> $stations
     * @return array<string, list<Station>>
     */
    private static function cells(array $stations, float $cellSize): array
    {
        $cells = [];
        foreach ($stations as $station) {
            $key = floor($station->coordinate->latitude / $cellSize) . ':' . floor($station->coordinate->longitude / $cellSize);
            $cells[$key][] = $station;
        }
        return $cells;
    }

    /** @return array<string, mixed> */
    private static function marker(Station $station): array
    {
        return [
            'kind' => 'station',
            'id' => $station->id,
            'name' => $station->name,
            'latitude' => $station->coordinate->latitude,
            'longitude' => $station->coordinate->longitude,
            'transportModes' => $station->transportModes,
        ];
    }
}
