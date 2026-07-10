<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Mapper;

use DateTimeImmutable;
use FjordPulse\Domain\StationKind;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Station;

final class GeocoderMapper
{
    /**
     * @param array<mixed> $payload
     * @return list<Station>
     */
    public function map(array $payload): array
    {
        $features = $payload['features'] ?? [];
        if (!is_array($features)) {
            return [];
        }
        $importedAt = $this->date(ArrayValue::get($payload, ['metadata', 'timestamp'])) ?? new DateTimeImmutable();
        $stations = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $properties = $feature['properties'] ?? null;
            $coordinates = ArrayValue::get($feature, ['geometry', 'coordinates']);
            if (!is_array($properties) || !is_array($coordinates)) {
                continue;
            }
            $id = $properties['id'] ?? null;
            $name = ArrayValue::get($properties, ['names', 'default'])
                ?? ($properties['label'] ?? null)
                ?? ($properties['name'] ?? null);
            $longitude = $coordinates[0] ?? null;
            $latitude = $coordinates[1] ?? null;
            if (!is_string($id) || !is_string($name) || !is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            $locality = ArrayValue::get($properties, ['address', 'locality']);
            $municipality = ArrayValue::get($properties, ['address', 'municipality']) ?? $locality;
            $stations[] = new Station(
                $id,
                $name,
                str_starts_with($id, 'NSR:StopPlace:')
                    ? $this->kind($properties['stopPlaceTypes'] ?? null)
                    : StationKind::Unknown,
                new Coordinate((float)$latitude, (float)$longitude),
                is_string($locality) ? $locality : null,
                is_string($municipality) ? $municipality : null,
                $this->modes($properties['transportModes'] ?? null),
                $importedAt,
            );
        }

        return $stations;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? new DateTimeImmutable($value) : null;
    }

    private function kind(mixed $values): StationKind
    {
        if (!is_array($values)) {
            return StationKind::StopPlace;
        }
        $normalized = array_map(static fn(mixed $value): string => is_string($value) ? strtolower($value) : '', $values);

        return match (true) {
            in_array('railstation', $normalized, true) => StationKind::RailStation,
            in_array('busstation', $normalized, true) => StationKind::BusStation,
            in_array('ferrystop', $normalized, true), in_array('ferryterminal', $normalized, true) => StationKind::FerryTerminal,
            in_array('tramstation', $normalized, true) => StationKind::TramStop,
            in_array('metrostation', $normalized, true) => StationKind::MetroStation,
            in_array('airport', $normalized, true) => StationKind::Airport,
            default => StationKind::StopPlace,
        };
    }

    /** @return list<string> */
    private function modes(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $modes = [];
        foreach ($values as $value) {
            if (is_array($value) && is_string($value['mode'] ?? null)) {
                $modes[] = strtolower($value['mode']);
            }
        }

        return array_values(array_unique($modes));
    }
}
