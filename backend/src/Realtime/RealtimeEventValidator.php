<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\RealtimeType;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\StationVehicleCallRole;
use FjordPulse\Domain\StationVehicleProgress;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Domain\VehicleTransportMode;
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
        self::rfc3339($event->version, 'Realtime event version');

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
        self::object(
            $event->payload,
            ['stationId', 'state', 'version', 'updatedAt', 'departures', 'departureBoard', 'nearbyVehicles', 'servingVehicles', 'servingVehicleCoverage'],
            ['stationId', 'state', 'version', 'updatedAt', 'lastSuccessfulAt', 'warning', 'departures', 'departureBoard', 'nearbyVehicles', 'servingVehicles', 'servingVehicleCoverage'],
            'Station realtime event payload',
        );
        $this->stationBase($event);
        $departures = self::listField($event->payload, 'departures');
        if (count($departures) > 20) {
            throw new \InvalidArgumentException('Station realtime event departures must contain at most 20 items.');
        }
        foreach ($departures as $departure) {
            self::departure($departure);
        }
        $departureBoard = self::object(
            $event->payload['departureBoard'] ?? null,
            ['windowStart', 'windowEnd', 'limit', 'hasMore'],
            ['windowStart', 'windowEnd', 'limit', 'hasMore'],
            'Station departure-board coverage',
        );
        if (!is_int($departureBoard['limit'])
            || $departureBoard['limit'] < 1
            || $departureBoard['limit'] > 20
            || !is_bool($departureBoard['hasMore'])) {
            throw new \InvalidArgumentException('Station realtime event payload has invalid departure-board coverage.');
        }
        $departureWindowStart = self::rfc3339(
            $departureBoard['windowStart'] ?? null,
            'Station departure-board windowStart',
        );
        $departureWindowEnd = self::rfc3339(
            $departureBoard['windowEnd'] ?? null,
            'Station departure-board windowEnd',
        );
        if ($departureWindowEnd <= $departureWindowStart) {
            throw new \InvalidArgumentException('Station departure-board windowEnd must be after windowStart.');
        }
        foreach (self::listField($event->payload, 'nearbyVehicles') as $nearbyVehicle) {
            self::vehicleSummary($nearbyVehicle, false);
        }
        foreach (self::listField($event->payload, 'servingVehicles') as $servingVehicle) {
            self::servingVehicle($servingVehicle);
        }
        $coverage = self::object(
            $event->payload['servingVehicleCoverage'] ?? null,
            ['windowStart', 'windowEnd', 'candidateJourneyCount', 'queriedJourneyCount', 'truncated'],
            ['windowStart', 'windowEnd', 'candidateJourneyCount', 'queriedJourneyCount', 'truncated'],
            'Station serving-vehicle coverage',
        );
        if (!is_int($coverage['candidateJourneyCount'])
            || $coverage['candidateJourneyCount'] < 0
            || !is_int($coverage['queriedJourneyCount'])
            || $coverage['queriedJourneyCount'] < 0
            || $coverage['queriedJourneyCount'] > 200
            || !is_bool($coverage['truncated'])) {
            throw new \InvalidArgumentException('Station realtime event payload has invalid serving-vehicle coverage.');
        }
        $coverageWindowStart = self::nullableRfc3339(
            $coverage['windowStart'],
            'Station serving-vehicle coverage windowStart',
        );
        $coverageWindowEnd = self::nullableRfc3339(
            $coverage['windowEnd'],
            'Station serving-vehicle coverage windowEnd',
        );
        if ($coverageWindowStart !== null
            && $coverageWindowEnd !== null
            && $coverageWindowEnd <= $coverageWindowStart) {
            throw new \InvalidArgumentException('Station serving-vehicle coverage windowEnd must be after windowStart.');
        }
    }

    private function stationDepartures(RealtimeEvent $event): void
    {
        self::object(
            $event->payload,
            ['stationId', 'state', 'version', 'updatedAt', 'departures'],
            ['stationId', 'state', 'version', 'updatedAt', 'departures'],
            'Station departures realtime event payload',
        );
        $this->stationBase($event);
        $departures = self::listField($event->payload, 'departures');
        if (count($departures) > 20) {
            throw new \InvalidArgumentException('Station realtime event departures must contain at most 20 items.');
        }
        foreach ($departures as $departure) {
            self::departure($departure);
        }
    }

    private function nearbyVehicles(RealtimeEvent $event): void
    {
        self::object(
            $event->payload,
            ['stationId', 'state', 'version', 'updatedAt', 'vehicles'],
            ['stationId', 'state', 'version', 'updatedAt', 'vehicles'],
            'Nearby vehicles realtime event payload',
        );
        $this->stationBase($event);
        foreach (self::listField($event->payload, 'vehicles') as $vehicle) {
            self::vehicleSummary($vehicle, false);
        }
    }

    private function stationBase(RealtimeEvent $event): void
    {
        if (($event->payload['stationId'] ?? null) !== $event->entityId
            || ($event->payload['version'] ?? null) !== $event->version
            || !is_string($event->payload['state'] ?? null)
            || SourceState::tryFrom($event->payload['state']) === null) {
            throw new \InvalidArgumentException('Station realtime event payload does not match its envelope.');
        }
        self::rfc3339($event->payload['updatedAt'] ?? null, 'Station realtime event updatedAt');
        if (array_key_exists('lastSuccessfulAt', $event->payload)) {
            self::nullableRfc3339(
                $event->payload['lastSuccessfulAt'],
                'Station realtime event lastSuccessfulAt',
            );
        }
        if (array_key_exists('warning', $event->payload)) {
            self::nullableBoundedString($event->payload['warning'], 500, 'Station realtime event warning');
        }
    }

    private static function servingVehicle(mixed $servingVehicle): void
    {
        $servingVehicle = self::vehicleSummary($servingVehicle, true);
        $callRole = $servingVehicle['callRole'] ?? null;
        $progress = $servingVehicle['progress'] ?? null;
        if (!is_string($callRole)
            || StationVehicleCallRole::tryFrom($callRole) === null
            || !is_string($progress)
            || StationVehicleProgress::tryFrom($progress) === null
            || !in_array($servingVehicle['passengerServiceState'] ?? null, ['passenger', 'unknown'], true)
            || !array_key_exists('stationCallAt', $servingVehicle)) {
            throw new \InvalidArgumentException('Station serving vehicle has invalid canonical station-call semantics.');
        }
        self::nullableRfc3339($servingVehicle['stationCallAt'], 'Station serving vehicle stationCallAt');
    }

    private static function departure(mixed $departure): void
    {
        $departure = self::object(
            $departure,
            ['id', 'lineCode', 'destination', 'aimedDepartureAt', 'expectedDepartureAt', 'status', 'realtime'],
            ['id', 'serviceJourneyId', 'lineId', 'lineCode', 'destination', 'aimedDepartureAt', 'expectedDepartureAt', 'status', 'delaySeconds', 'platform', 'realtime'],
            'Station departure',
        );
        if (!is_string($departure['id']) || $departure['id'] === '' || self::stringLength($departure['id']) > 300) {
            throw new \InvalidArgumentException('Station departure id is invalid.');
        }
        foreach ([['serviceJourneyId', 300], ['lineId', 300], ['lineCode', 100], ['destination', 300], ['platform', 100]] as [$field, $maximum]) {
            if (array_key_exists($field, $departure)) {
                self::nullableBoundedString($departure[$field], $maximum, 'Station departure ' . $field);
            }
        }
        self::rfc3339($departure['aimedDepartureAt'], 'Station departure aimedDepartureAt');
        self::nullableRfc3339($departure['expectedDepartureAt'], 'Station departure expectedDepartureAt');
        if (!is_string($departure['status']) || DepartureStatus::tryFrom($departure['status']) === null) {
            throw new \InvalidArgumentException('Station departure status is invalid.');
        }
        if (array_key_exists('delaySeconds', $departure)
            && $departure['delaySeconds'] !== null
            && !is_int($departure['delaySeconds'])) {
            throw new \InvalidArgumentException('Station departure delaySeconds must be an integer or null.');
        }
        if (!is_bool($departure['realtime'])) {
            throw new \InvalidArgumentException('Station departure realtime must be boolean.');
        }
    }

    /** @return array<string, mixed> */
    private static function vehicleSummary(mixed $vehicle, bool $serving): array
    {
        $required = ['id', 'transportMode', 'passengerServiceState', 'lineCode', 'state', 'latitude', 'longitude', 'lastSeenAt', 'version'];
        $allowed = ['id', 'transportMode', 'passengerServiceState', 'lineCode', 'destination', 'state', 'latitude', 'longitude', 'bearing', 'delaySeconds', 'distanceMeters', 'lastSeenAt', 'version'];
        if ($serving) {
            $required = [...$required, 'callRole', 'progress', 'stationCallAt'];
            $allowed = [...$allowed, 'callRole', 'progress', 'stationCallAt'];
        }
        $vehicle = self::object($vehicle, $required, $allowed, $serving ? 'Station serving vehicle' : 'Nearby vehicle');

        if (!is_string($vehicle['id'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,199}$/D', $vehicle['id']) !== 1
            || !is_string($vehicle['transportMode'])
            || VehicleTransportMode::tryFrom($vehicle['transportMode']) === null
            || !is_string($vehicle['passengerServiceState'])
            || VehiclePassengerServiceState::tryFrom($vehicle['passengerServiceState']) === null
            || !is_string($vehicle['state'])
            || VehicleFreshness::tryFrom($vehicle['state']) === null) {
            throw new \InvalidArgumentException('Station vehicle summary has invalid identity or enum fields.');
        }
        self::nullableBoundedString($vehicle['lineCode'], 100, 'Station vehicle lineCode');
        if (array_key_exists('destination', $vehicle)) {
            self::nullableBoundedString($vehicle['destination'], 300, 'Station vehicle destination');
        }
        self::nullableNumberBetween($vehicle['latitude'], -90, 90, 'Station vehicle latitude');
        self::nullableNumberBetween($vehicle['longitude'], -180, 180, 'Station vehicle longitude');
        if (array_key_exists('bearing', $vehicle)) {
            self::nullableNumberBetween($vehicle['bearing'], 0, 360, 'Station vehicle bearing');
        }
        if (array_key_exists('delaySeconds', $vehicle)
            && $vehicle['delaySeconds'] !== null
            && !is_int($vehicle['delaySeconds'])) {
            throw new \InvalidArgumentException('Station vehicle delaySeconds must be an integer or null.');
        }
        if (array_key_exists('distanceMeters', $vehicle)) {
            self::nullableNumberBetween($vehicle['distanceMeters'], 0, null, 'Station vehicle distanceMeters');
        }
        self::rfc3339($vehicle['lastSeenAt'], 'Station vehicle lastSeenAt');
        self::rfc3339($vehicle['version'], 'Station vehicle version');

        return $vehicle;
    }

    private function vehicle(RealtimeEvent $event): void
    {
        $vehicle = $event->payload['vehicle'] ?? null;
        if (!is_array($vehicle) || array_is_list($vehicle)) {
            throw new \InvalidArgumentException('Vehicle realtime event payload must contain a vehicle object.');
        }
        foreach ([
            'id',
            'transportMode',
            'passengerServiceState',
            'lineCode',
            'routeName',
            'state',
            'latitude',
            'longitude',
            'lastSeenAt',
            'refreshedAt',
            'version',
            'nextStop',
            'journeyReference',
            'monitoredCall',
            'progressBetweenStops',
            'journeyVersion',
            'routeProgress',
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
            || !in_array($vehicle['transportMode'] ?? null, ['air', 'bus', 'coach', 'ferry', 'metro', 'taxi', 'tram', 'rail', 'unknown'], true)
            || !in_array($vehicle['passengerServiceState'] ?? null, ['passenger', 'non_passenger', 'unknown'], true)
            || !is_string($vehicle['lastSeenAt'] ?? null)
            || !is_string($vehicle['refreshedAt'] ?? null)) {
            throw new \InvalidArgumentException('Vehicle realtime event payload does not match its envelope.');
        }
        foreach (['journeyReference', 'monitoredCall', 'progressBetweenStops', 'nextStop'] as $field) {
            $value = $vehicle[$field];
            if ($value !== null && (!is_array($value) || array_is_list($value))) {
                throw new \InvalidArgumentException("Vehicle realtime field {$field} must be an object or null.");
            }
        }
        $observation = $event->payload['observation'] ?? null;
        if ($observation !== null && (!is_array($observation) || array_is_list($observation))) {
            throw new \InvalidArgumentException('Vehicle observation must be an object or null.');
        }
    }

    /**
     * @param list<string> $required
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private static function object(mixed $value, array $required, array $allowed, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("{$field} must be an object.");
        }
        $normalized = [];
        foreach ($value as $key => $nested) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("{$field} contains an unknown property.");
            }
            $normalized[$key] = $nested;
        }
        foreach ($required as $requiredField) {
            if (!array_key_exists($requiredField, $normalized)) {
                throw new \InvalidArgumentException("{$field} is missing {$requiredField}.");
            }
        }

        return $normalized;
    }

    private static function nullableBoundedString(mixed $value, int $maximum, string $field): void
    {
        if ($value !== null && (!is_string($value) || self::stringLength($value) > $maximum)) {
            throw new \InvalidArgumentException("{$field} must be a bounded string or null.");
        }
    }

    private static function nullableNumberBetween(
        mixed $value,
        float $minimum,
        ?float $maximum,
        string $field,
    ): void {
        if ($value === null) {
            return;
        }
        if ((!is_int($value) && !is_float($value))
            || !is_finite((float)$value)
            || $value < $minimum
            || ($maximum !== null && $value > $maximum)) {
            throw new \InvalidArgumentException("{$field} is outside its numeric bounds.");
        }
    }

    private static function stringLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private static function listField(array $payload, string $field): array
    {
        $value = $payload[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("Realtime event payload field {$field} must be a list.");
        }

        return $value;
    }

    private static function nullableRfc3339(mixed $value, string $field): ?DateTimeImmutable
    {
        return $value === null ? null : self::rfc3339($value, $field);
    }

    private static function rfc3339(mixed $value, string $field): DateTimeImmutable
    {
        if (!is_string($value)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',
                $value,
            ) !== 1
            || !checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4))) {
            throw new \InvalidArgumentException("{$field} must be RFC3339.");
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $error) {
            throw new \InvalidArgumentException("{$field} must be RFC3339.", previous: $error);
        }
    }
}
