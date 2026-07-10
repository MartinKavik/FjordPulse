<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\StationKind;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\RealtimeEvent;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Dto\Watch;
use InvalidArgumentException;

final class SurrealDtoMapper
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $record */
    public static function station(array $record): Station
    {
        return new Station(
            DatabaseRecord::string($record['station_id'] ?? null, 'station.station_id'),
            DatabaseRecord::string($record['name'] ?? null, 'station.name'),
            StationKind::from(DatabaseRecord::string($record['kind'] ?? null, 'station.kind')),
            new Coordinate(
                DatabaseRecord::float($record['latitude'] ?? null, 'station.latitude'),
                DatabaseRecord::float($record['longitude'] ?? null, 'station.longitude'),
            ),
            DatabaseRecord::nullableString($record['locality'] ?? null, 'station.locality'),
            DatabaseRecord::nullableString($record['municipality'] ?? null, 'station.municipality'),
            self::stringList($record['transport_modes'] ?? [], 'station.transport_modes'),
            DatabaseRecord::dateTime($record['imported_at'] ?? null, 'station.imported_at'),
        );
    }

    /** @param array<string, mixed> $record */
    public static function stationSnapshot(array $record): StationSnapshot
    {
        $departures = [];
        foreach (self::objectList($record['departures'] ?? [], 'station_snapshot.departures') as $departure) {
            $departures[] = self::departure($departure);
        }

        $vehicles = [];
        foreach (self::objectList($record['nearby_vehicles'] ?? [], 'station_snapshot.nearby_vehicles') as $vehicle) {
            $vehicles[] = self::vehiclePayload($vehicle);
        }

        return new StationSnapshot(
            DatabaseRecord::string($record['station_id'] ?? null, 'station_snapshot.station_id'),
            DatabaseRecord::string($record['version'] ?? null, 'station_snapshot.version'),
            DatabaseRecord::string($record['content_hash'] ?? null, 'station_snapshot.content_hash'),
            DatabaseRecord::dateTime($record['updated_at'] ?? null, 'station_snapshot.updated_at'),
            SourceState::from(DatabaseRecord::string($record['state'] ?? null, 'station_snapshot.state')),
            $departures,
            $vehicles,
            DatabaseRecord::nullableDateTime($record['last_successful_at'] ?? null, 'station_snapshot.last_successful_at'),
            DatabaseRecord::nullableString($record['warning'] ?? null, 'station_snapshot.warning'),
        );
    }

    /**
     * @param array<string, mixed> $record
     * @param list<VehicleObservation> $observations
     */
    public static function currentVehicle(array $record, array $observations = []): VehicleState
    {
        $latitude = DatabaseRecord::nullableFloat($record['latitude'] ?? null, 'current_vehicle.latitude');
        $longitude = DatabaseRecord::nullableFloat($record['longitude'] ?? null, 'current_vehicle.longitude');

        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException('Current vehicle coordinates must both be present or absent.');
        }

        $nextStop = self::nullableObject($record['next_stop'] ?? null, 'current_vehicle.next_stop');

        return new VehicleState(
            DatabaseRecord::string($record['vehicle_id'] ?? null, 'current_vehicle.vehicle_id'),
            DatabaseRecord::string($record['version'] ?? null, 'current_vehicle.version'),
            DatabaseRecord::string($record['content_hash'] ?? null, 'current_vehicle.content_hash'),
            VehicleFreshness::from(DatabaseRecord::string($record['state'] ?? null, 'current_vehicle.state')),
            $latitude === null ? null : new Coordinate($latitude, (float) $longitude),
            DatabaseRecord::nullableString($record['line_code'] ?? null, 'current_vehicle.line_code'),
            DatabaseRecord::nullableString($record['route_name'] ?? null, 'current_vehicle.route_name'),
            DatabaseRecord::nullableString($record['destination'] ?? null, 'current_vehicle.destination'),
            DatabaseRecord::nullableFloat($record['bearing'] ?? null, 'current_vehicle.bearing'),
            DatabaseRecord::nullableInt($record['delay_seconds'] ?? null, 'current_vehicle.delay_seconds'),
            DatabaseRecord::nullableFloat($record['distance_meters'] ?? null, 'current_vehicle.distance_meters'),
            DatabaseRecord::dateTime($record['last_seen_at'] ?? null, 'current_vehicle.last_seen_at'),
            DatabaseRecord::dateTime($record['updated_at'] ?? null, 'current_vehicle.updated_at'),
            $nextStop === null ? null : self::stopCall($nextStop),
            $observations,
        );
    }

    /** @param array<string, mixed> $record */
    public static function observation(array $record): VehicleObservation
    {
        return new VehicleObservation(
            DatabaseRecord::string($record['observation_id'] ?? null, 'vehicle_observation.observation_id'),
            DatabaseRecord::string($record['vehicle_id'] ?? null, 'vehicle_observation.vehicle_id'),
            new Coordinate(
                DatabaseRecord::float($record['latitude'] ?? null, 'vehicle_observation.latitude'),
                DatabaseRecord::float($record['longitude'] ?? null, 'vehicle_observation.longitude'),
            ),
            DatabaseRecord::dateTime($record['observed_at'] ?? null, 'vehicle_observation.observed_at'),
            DatabaseRecord::nullableFloat($record['bearing'] ?? null, 'vehicle_observation.bearing'),
        );
    }

    /** @param array<string, mixed> $record */
    public static function watch(array $record): Watch
    {
        return new Watch(
            DatabaseRecord::string($record['watch_id'] ?? null, 'watch.watch_id'),
            WatchType::from(DatabaseRecord::string($record['type'] ?? null, 'watch.type')),
            DatabaseRecord::string($record['scope'] ?? null, 'watch.scope'),
            DatabaseRecord::string($record['entity_id'] ?? null, 'watch.entity_id'),
            DatabaseRecord::int($record['client_count'] ?? null, 'watch.client_count'),
            WatchPriority::from(DatabaseRecord::string($record['priority'] ?? null, 'watch.priority')),
            DatabaseRecord::nullableDateTime($record['last_refresh_at'] ?? null, 'watch.last_refresh_at'),
            DatabaseRecord::nullableDateTime($record['next_refresh_at'] ?? null, 'watch.next_refresh_at'),
            DatabaseRecord::dateTime($record['expires_at'] ?? null, 'watch.expires_at'),
            WatchState::from(DatabaseRecord::string($record['state'] ?? null, 'watch.state')),
            DatabaseRecord::nullableString($record['last_error_code'] ?? null, 'watch.last_error_code'),
        );
    }

    /** @param array<string, mixed> $record */
    public static function enturLog(array $record): EnturRequestLog
    {
        return new EnturRequestLog(
            DatabaseRecord::string($record['log_id'] ?? null, 'entur_request_log.log_id'),
            DatabaseRecord::string($record['service'] ?? null, 'entur_request_log.service'),
            DatabaseRecord::string($record['scope'] ?? null, 'entur_request_log.scope'),
            DatabaseRecord::dateTime($record['requested_at'] ?? null, 'entur_request_log.requested_at'),
            DatabaseRecord::nullableInt($record['http_status'] ?? null, 'entur_request_log.http_status'),
            (int) DatabaseRecord::float($record['latency_ms'] ?? null, 'entur_request_log.latency_ms'),
            DatabaseRecord::int($record['item_count'] ?? null, 'entur_request_log.item_count'),
            DatabaseRecord::string($record['cache'] ?? null, 'entur_request_log.cache'),
            DatabaseRecord::string($record['outcome'] ?? null, 'entur_request_log.outcome'),
            DatabaseRecord::nullableDateTime($record['retry_at'] ?? null, 'entur_request_log.retry_at'),
            DatabaseRecord::string($record['request_id'] ?? null, 'entur_request_log.request_id'),
            DatabaseRecord::nullableString($record['error_code'] ?? null, 'entur_request_log.error_code'),
        );
    }

    /** @param array<string, mixed> $record */
    public static function realtimeEvent(array $record): RealtimeEvent
    {
        $normalized = DatabaseRecord::normalize($record);

        if (!is_array($normalized) || array_is_list($normalized)) {
            throw new InvalidArgumentException('Invalid normalized realtime event record.');
        }

        $stringKeys = [];
        foreach ($normalized as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Realtime event record keys must be strings.');
            }
            $stringKeys[$key] = $value;
        }

        return RealtimeEvent::fromRecord($stringKeys);
    }

    /** @param array<string, mixed> $record */
    private static function departure(array $record): Departure
    {
        return new Departure(
            DatabaseRecord::string($record['id'] ?? null, 'departure.id'),
            DatabaseRecord::nullableString($record['serviceJourneyId'] ?? null, 'departure.serviceJourneyId'),
            DatabaseRecord::nullableString($record['lineId'] ?? null, 'departure.lineId'),
            DatabaseRecord::nullableString($record['lineCode'] ?? null, 'departure.lineCode'),
            DatabaseRecord::nullableString($record['destination'] ?? null, 'departure.destination'),
            DatabaseRecord::dateTime($record['aimedDepartureAt'] ?? null, 'departure.aimedDepartureAt'),
            DatabaseRecord::nullableDateTime($record['expectedDepartureAt'] ?? null, 'departure.expectedDepartureAt'),
            DepartureStatus::from(DatabaseRecord::string($record['status'] ?? null, 'departure.status')),
            DatabaseRecord::nullableInt($record['delaySeconds'] ?? null, 'departure.delaySeconds'),
            DatabaseRecord::nullableString($record['platform'] ?? null, 'departure.platform'),
            self::bool($record['realtime'] ?? null, 'departure.realtime'),
        );
    }

    /** @param array<string, mixed> $record */
    private static function vehiclePayload(array $record): VehicleState
    {
        $latitude = DatabaseRecord::nullableFloat($record['latitude'] ?? null, 'vehicle.latitude');
        $longitude = DatabaseRecord::nullableFloat($record['longitude'] ?? null, 'vehicle.longitude');

        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException('Vehicle coordinates must both be present or absent.');
        }

        $nextStop = self::nullableObject($record['nextStop'] ?? null, 'vehicle.nextStop');

        return new VehicleState(
            DatabaseRecord::string($record['id'] ?? null, 'vehicle.id'),
            DatabaseRecord::string($record['version'] ?? null, 'vehicle.version'),
            hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)),
            VehicleFreshness::from(DatabaseRecord::string($record['state'] ?? null, 'vehicle.state')),
            $latitude === null ? null : new Coordinate($latitude, (float) $longitude),
            DatabaseRecord::nullableString($record['lineCode'] ?? null, 'vehicle.lineCode'),
            DatabaseRecord::nullableString($record['routeName'] ?? null, 'vehicle.routeName'),
            DatabaseRecord::nullableString($record['destination'] ?? null, 'vehicle.destination'),
            DatabaseRecord::nullableFloat($record['bearing'] ?? null, 'vehicle.bearing'),
            DatabaseRecord::nullableInt($record['delaySeconds'] ?? null, 'vehicle.delaySeconds'),
            DatabaseRecord::nullableFloat($record['distanceMeters'] ?? null, 'vehicle.distanceMeters'),
            DatabaseRecord::dateTime($record['lastSeenAt'] ?? null, 'vehicle.lastSeenAt'),
            DatabaseRecord::dateTime($record['lastSeenAt'] ?? null, 'vehicle.lastSeenAt'),
            $nextStop === null ? null : self::stopCall($nextStop),
        );
    }

    /** @param array<string, mixed> $record */
    private static function stopCall(array $record): StopCall
    {
        return new StopCall(
            DatabaseRecord::string($record['stopPlaceId'] ?? null, 'stopCall.stopPlaceId'),
            DatabaseRecord::string($record['name'] ?? null, 'stopCall.name'),
            DatabaseRecord::dateTime($record['aimedArrivalAt'] ?? null, 'stopCall.aimedArrivalAt'),
            DatabaseRecord::nullableDateTime($record['expectedArrivalAt'] ?? null, 'stopCall.expectedArrivalAt'),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("Expected {$field} to be a list.");
        }

        $strings = [];
        foreach ($value as $item) {
            $strings[] = DatabaseRecord::string($item, $field);
        }

        return $strings;
    }

    /** @return list<array<string, mixed>> */
    private static function objectList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("Expected {$field} to be a list of objects.");
        }

        $objects = [];
        foreach ($value as $item) {
            $object = self::nullableObject($item, $field);
            if ($object === null) {
                throw new InvalidArgumentException("Expected {$field} to contain objects.");
            }
            $objects[] = $object;
        }

        return $objects;
    }

    /** @return array<string, mixed>|null */
    private static function nullableObject(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("Expected {$field} to be an object.");
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidArgumentException("Expected {$field} keys to be strings.");
            }
            $object[$key] = $item;
        }

        return $object;
    }

    private static function bool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException("Expected {$field} to be a boolean.");
        }

        return $value;
    }
}
