<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Mapper;

use DateTimeImmutable;
use FjordPulse\Domain\StationKind;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Station;

final class StopPlaceMapper
{
    /**
     * @param array<mixed> $payload
     * @return list<Station>
     */
    public function map(array $payload): array
    {
        $stations = [];
        foreach ($payload as $record) {
            if (!is_array($record)) {
                continue;
            }
            $id = $record['id'] ?? null;
            $name = ArrayValue::get($record, ['name', 'value']);
            $latitude = ArrayValue::get($record, ['centroid', 'location', 'latitude']);
            $longitude = ArrayValue::get($record, ['centroid', 'location', 'longitude']);
            if (!is_string($id) || !is_string($name) || !is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            $mode = is_string($record['transportMode'] ?? null) ? strtolower($record['transportMode']) : null;
            $changed = is_string($record['changed'] ?? null) ? new DateTimeImmutable($record['changed']) : new DateTimeImmutable();
            $stations[] = new Station(
                $id,
                $name,
                $this->kind($record['stopPlaceType'] ?? null),
                new Coordinate((float)$latitude, (float)$longitude),
                null,
                null,
                $mode === null ? [] : [$mode],
                $changed,
            );
        }

        return $stations;
    }

    private function kind(mixed $value): StationKind
    {
        return match (is_string($value) ? strtoupper($value) : '') {
            'RAIL_STATION' => StationKind::RailStation,
            'BUS_STATION' => StationKind::BusStation,
            'FERRY_STOP', 'FERRY_TERMINAL' => StationKind::FerryTerminal,
            'TRAM_STATION' => StationKind::TramStop,
            'METRO_STATION' => StationKind::MetroStation,
            'AIRPORT' => StationKind::Airport,
            default => StationKind::StopPlace,
        };
    }
}
