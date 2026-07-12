<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Entur\JourneyProgressMatcher;
use FjordPulse\Surreal\CurrentVehicleRepository;
use FjordPulse\Surreal\JourneySnapshotRepository;
use FjordPulse\Surreal\StationSnapshotRepository;
use FjordPulse\Surreal\VehicleObservationRepository;

final readonly class SurrealSnapshotProvider implements SnapshotProvider
{
    public function __construct(
        private StationSnapshotRepository $stationSnapshots,
        private CurrentVehicleRepository $currentVehicles,
        private VehicleObservationRepository $vehicleObservations,
        private JourneySnapshotRepository $journeySnapshots,
        private JourneyProgressMatcher $journeyProgress = new JourneyProgressMatcher(),
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
        $journey = $vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger
            || $vehicle->journeyReference === null
            ? null
            : $this->journeySnapshots->find(
                $vehicle->journeyReference->serviceJourneyId,
                $vehicle->journeyReference->operatingDate,
            );
        $upcomingStops = $journey === null ? [] : $this->journeyProgress->upcoming($journey, $vehicle);

        return new AuthoritativeSnapshot(
            'vehicle_snapshot',
            'vehicle:' . $vehicleId,
            $vehicleId,
            $vehicle->version,
            [
                'vehicle' => $vehicle->toArray(),
                'trail' => array_map(static fn(VehicleObservation $observation): array => $observation->toArray(), $trail),
                'journey' => $journey?->toArray(),
                'upcomingStops' => array_map(static fn($call): array => $call->toArray(), $upcomingStops),
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function vehicleSummary(VehicleState $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'transportMode' => $vehicle->transportMode->value,
            'passengerServiceState' => $vehicle->passengerServiceState->value,
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
