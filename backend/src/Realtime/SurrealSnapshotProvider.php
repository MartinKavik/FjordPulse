<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Surreal\CurrentVehicleRepository;
use FjordPulse\Surreal\StationSnapshotRepository;
use FjordPulse\Surreal\VehicleObservationRepository;

final readonly class SurrealSnapshotProvider implements SnapshotProvider
{
    public function __construct(
        private StationSnapshotRepository $stationSnapshots,
        private CurrentVehicleRepository $currentVehicles,
        private VehicleObservationRepository $vehicleObservations,
    ) {
    }

    public function station(string $stationId): ?AuthoritativeSnapshot
    {
        $snapshot = $this->stationSnapshots->find($stationId);
        if ($snapshot === null) {
            return null;
        }
        $payload = $snapshot->toArray();
        $payload['nearbyVehicles'] = array_map(self::vehicleSummary(...), $snapshot->nearbyVehicles);

        return new AuthoritativeSnapshot(
            'station_snapshot',
            'station:' . $stationId,
            $stationId,
            $snapshot->version,
            $payload,
        );
    }

    public function vehicle(string $vehicleId): ?AuthoritativeSnapshot
    {
        $vehicle = $this->currentVehicles->find($vehicleId);
        if ($vehicle === null) {
            return null;
        }
        $trail = $this->vehicleObservations->recent($vehicleId, 100);

        return new AuthoritativeSnapshot(
            'vehicle_snapshot',
            'vehicle:' . $vehicleId,
            $vehicleId,
            $vehicle->version,
            [
                'vehicle' => $vehicle->toArray(),
                'trail' => array_map(static fn(VehicleObservation $observation): array => $observation->toArray(), $trail),
                'upcomingStops' => $vehicle->nextStop === null ? [] : [$vehicle->nextStop->toArray()],
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function vehicleSummary(VehicleState $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'lineCode' => $vehicle->lineCode,
            'destination' => $vehicle->destination,
            'state' => $vehicle->state->value,
            'latitude' => $vehicle->coordinate?->latitude,
            'longitude' => $vehicle->coordinate?->longitude,
            'bearing' => $vehicle->bearing,
            'delaySeconds' => $vehicle->delaySeconds,
            'distanceMeters' => $vehicle->distanceMeters,
            'lastSeenAt' => $vehicle->lastSeenAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'version' => $vehicle->version,
        ];
    }
}
