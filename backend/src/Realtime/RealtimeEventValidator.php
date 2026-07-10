<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use FjordPulse\Domain\RealtimeType;
use FjordPulse\Dto\RealtimeEvent;

final class RealtimeEventValidator
{
    public function validate(RealtimeEvent $event): void
    {
        $expectedPrefix = match ($event->type) {
            RealtimeType::StationSnapshotChanged,
            RealtimeType::StationDeparturesChanged,
            RealtimeType::NearbyVehiclesChanged => 'station:',
            RealtimeType::VehicleMoved,
            RealtimeType::VehicleStale,
            RealtimeType::VehicleLost => 'vehicle:',
            default => throw new \InvalidArgumentException('Only database-originated state events may use the room event sink.'),
        };
        if ($event->scope !== $expectedPrefix . $event->entityId) {
            throw new \InvalidArgumentException('Realtime event scope does not match its entity and type.');
        }
        try {
            new DateTimeImmutable($event->version);
        } catch (\Exception $error) {
            throw new \InvalidArgumentException('Realtime event version must be RFC3339.', previous: $error);
        }

        match ($event->type) {
            RealtimeType::StationSnapshotChanged => $this->stationSnapshot($event),
            RealtimeType::StationDeparturesChanged => $this->stationDepartures($event),
            RealtimeType::NearbyVehiclesChanged => $this->nearbyVehicles($event),
            RealtimeType::VehicleMoved,
            RealtimeType::VehicleStale,
            RealtimeType::VehicleLost => $this->vehicle($event),
        };
    }

    private function stationSnapshot(RealtimeEvent $event): void
    {
        $this->stationBase($event);
        self::listField($event->payload, 'departures');
        self::listField($event->payload, 'nearbyVehicles');
    }

    private function stationDepartures(RealtimeEvent $event): void
    {
        $this->stationBase($event);
        self::listField($event->payload, 'departures');
    }

    private function nearbyVehicles(RealtimeEvent $event): void
    {
        $this->stationBase($event);
        self::listField($event->payload, 'vehicles');
    }

    private function stationBase(RealtimeEvent $event): void
    {
        if (($event->payload['stationId'] ?? null) !== $event->entityId
            || ($event->payload['version'] ?? null) !== $event->version
            || !is_string($event->payload['state'] ?? null)
            || !is_string($event->payload['updatedAt'] ?? null)) {
            throw new \InvalidArgumentException('Station realtime event payload does not match its envelope.');
        }
    }

    private function vehicle(RealtimeEvent $event): void
    {
        $vehicle = $event->payload['vehicle'] ?? null;
        if (!is_array($vehicle) || array_is_list($vehicle)) {
            throw new \InvalidArgumentException('Vehicle realtime event payload must contain a vehicle object.');
        }
        foreach ([
            'id',
            'lineCode',
            'routeName',
            'state',
            'latitude',
            'longitude',
            'lastSeenAt',
            'version',
            'nextStop',
        ] as $field) {
            if (!array_key_exists($field, $vehicle)) {
                throw new \InvalidArgumentException("Vehicle realtime event payload is missing {$field}.");
            }
        }
        $expectedState = match ($event->type) {
            RealtimeType::VehicleMoved => 'live',
            RealtimeType::VehicleStale => 'stale',
            RealtimeType::VehicleLost => 'lost',
            default => throw new \LogicException('Unexpected vehicle realtime type.'),
        };
        if (($vehicle['id'] ?? null) !== $event->entityId
            || ($vehicle['version'] ?? null) !== $event->version
            || ($vehicle['state'] ?? null) !== $expectedState
            || !is_string($vehicle['lastSeenAt'] ?? null)) {
            throw new \InvalidArgumentException('Vehicle realtime event payload does not match its envelope.');
        }
        $observation = $event->payload['observation'] ?? null;
        if ($observation !== null && (!is_array($observation) || array_is_list($observation))) {
            throw new \InvalidArgumentException('Vehicle observation must be an object or null.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function listField(array $payload, string $field): void
    {
        $value = $payload[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("Realtime event payload field {$field} must be a list.");
        }
    }
}
