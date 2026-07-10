<?php

declare(strict_types=1);

namespace FjordPulse\Service;

use FjordPulse\Dto\Station;

final class StationClusterer
{
    /**
     * @param list<Station> $stations
     * @return list<array<string, mixed>>
     */
    public function items(array $stations, float $zoom): array
    {
        if ($zoom >= 8.0) {
            return array_map(self::marker(...), $stations);
        }
        $cellSize = max(0.2, 12.0 / (2 ** max(0.0, $zoom - 2.0)));
        $cells = [];
        foreach ($stations as $station) {
            $key = floor($station->coordinate->latitude / $cellSize) . ':' . floor($station->coordinate->longitude / $cellSize);
            $cells[$key][] = $station;
        }
        ksort($cells);
        $items = [];
        foreach ($cells as $key => $members) {
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
