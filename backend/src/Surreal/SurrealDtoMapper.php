<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\StationKind;
use FjordPulse\Domain\StationVehicleCallRole;
use FjordPulse\Domain\StationVehicleProgress;
use FjordPulse\Domain\StationVehicleRelation;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehiclePassengerServiceClassifier;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Domain\VehicleTransportMode;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Dto\JourneyGeometry;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\MonitoredCallReference;
use FjordPulse\Dto\ProgressBetweenStops;
use FjordPulse\Dto\RealtimeEvent;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\StationVehicle;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleJourneyReference;
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
        $servingVehicles = [];
        foreach (self::objectList($record['serving_vehicles'] ?? [], 'station_snapshot.serving_vehicles') as $vehicle) {
            [$callRole, $progress] = self::stationVehicleSemantics($vehicle);
            $servingVehicles[] = new StationVehicle(
                self::vehiclePayload($vehicle),
                $callRole,
                $progress,
                DatabaseRecord::nullableDateTime($vehicle['stationCallAt'] ?? null, 'stationVehicle.stationCallAt'),
            );
        }
        $departureBoardFields = [
            'departure_window_started_at',
            'departure_window_ends_at',
            'departure_limit',
            'departure_has_more',
        ];
        $departureBoardFieldCount = count(array_filter(
            $departureBoardFields,
            static fn(string $field): bool => array_key_exists($field, $record),
        ));
        if ($departureBoardFieldCount !== 0 && $departureBoardFieldCount !== count($departureBoardFields)) {
            throw new InvalidArgumentException('Station snapshot departure board coverage is incomplete.');
        }
        $departureBoard = $departureBoardFieldCount === 0
            ? null
            : new \FjordPulse\Dto\DepartureBoard(
                DatabaseRecord::dateTime($record['departure_window_started_at'], 'station_snapshot.departure_window_started_at'),
                DatabaseRecord::dateTime($record['departure_window_ends_at'], 'station_snapshot.departure_window_ends_at'),
                DatabaseRecord::int($record['departure_limit'], 'station_snapshot.departure_limit'),
                self::bool($record['departure_has_more'], 'station_snapshot.departure_has_more'),
            );

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
            $servingVehicles,
            DatabaseRecord::nullableDateTime($record['serving_window_started_at'] ?? null, 'station_snapshot.serving_window_started_at'),
            DatabaseRecord::nullableDateTime($record['serving_window_ends_at'] ?? null, 'station_snapshot.serving_window_ends_at'),
            DatabaseRecord::nullableInt($record['serving_candidate_journey_count'] ?? null, 'station_snapshot.serving_candidate_journey_count') ?? 0,
            DatabaseRecord::nullableInt($record['serving_queried_journey_count'] ?? null, 'station_snapshot.serving_queried_journey_count') ?? 0,
            isset($record['serving_vehicles_truncated'])
                ? self::bool($record['serving_vehicles_truncated'], 'station_snapshot.serving_vehicles_truncated')
                : false,
            $departureBoard,
        );
    }

    /** @param array<string, mixed> $record */
    public static function stationTimetable(array $record): \FjordPulse\Dto\StationTimetable
    {
        $departures = [];
        foreach (self::objectList($record['departures'] ?? [], 'station_timetable.departures') as $departure) {
            $departures[] = self::departure($departure);
        }

        $timeZoneName = DatabaseRecord::string($record['time_zone'] ?? null, 'station_timetable.time_zone');
        $timeZone = new \DateTimeZone($timeZoneName);

        return new \FjordPulse\Dto\StationTimetable(
            DatabaseRecord::string($record['station_id'] ?? null, 'station_timetable.station_id'),
            DatabaseRecord::string($record['service_date'] ?? null, 'station_timetable.service_date'),
            $timeZoneName,
            DatabaseRecord::dateTime($record['window_start'] ?? null, 'station_timetable.window_start')->setTimezone($timeZone),
            DatabaseRecord::dateTime($record['window_end'] ?? null, 'station_timetable.window_end')->setTimezone($timeZone),
            $departures,
            self::bool($record['complete'] ?? null, 'station_timetable.complete'),
            DatabaseRecord::dateTime($record['fetched_at'] ?? null, 'station_timetable.fetched_at'),
            DatabaseRecord::string($record['version'] ?? null, 'station_timetable.version'),
        );
    }

    /**
     * Accept legacy relation-only station snapshots so an existing database can
     * be read safely while all newly written records use the orthogonal model.
     *
     * @param array<string, mixed> $vehicle
     * @return array{StationVehicleCallRole, StationVehicleProgress}
     */
    private static function stationVehicleSemantics(array $vehicle): array
    {
        $hasCallRole = array_key_exists('callRole', $vehicle);
        $hasProgress = array_key_exists('progress', $vehicle);
        if ($hasCallRole !== $hasProgress) {
            throw new InvalidArgumentException('Station vehicle callRole and progress must be present together.');
        }
        if ($hasCallRole) {
            return [
                StationVehicleCallRole::from(DatabaseRecord::string($vehicle['callRole'], 'stationVehicle.callRole')),
                StationVehicleProgress::from(DatabaseRecord::string($vehicle['progress'], 'stationVehicle.progress')),
            ];
        }

        $relation = StationVehicleRelation::from(
            DatabaseRecord::string($vehicle['relation'] ?? null, 'stationVehicle.relation'),
        );

        return [
            $relation === StationVehicleRelation::StartingHere
                ? StationVehicleCallRole::StartsHere
                : StationVehicleCallRole::CallsHere,
            match ($relation) {
                StationVehicleRelation::AtStation => StationVehicleProgress::AtStation,
                StationVehicleRelation::Approaching => StationVehicleProgress::BeforeStation,
                StationVehicleRelation::Departed => StationVehicleProgress::AfterStation,
                StationVehicleRelation::StartingHere,
                StationVehicleRelation::ServesStation => StationVehicleProgress::Unknown,
            },
        ];
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
        $journeyReference = self::nullableObject($record['journey_reference'] ?? null, 'current_vehicle.journey_reference');
        $monitoredCall = self::nullableObject($record['monitored_call'] ?? null, 'current_vehicle.monitored_call');
        $progress = self::nullableObject($record['progress_between_stops'] ?? null, 'current_vehicle.progress_between_stops');
        $updatedAt = DatabaseRecord::dateTime($record['updated_at'] ?? null, 'current_vehicle.updated_at');
        $referenceDto = $journeyReference === null ? null : self::journeyReference($journeyReference);
        $monitoredCallDto = $monitoredCall === null ? null : self::monitoredCall($monitoredCall);
        $destination = DatabaseRecord::nullableString($record['destination'] ?? null, 'current_vehicle.destination');

        return new VehicleState(
            DatabaseRecord::string($record['vehicle_id'] ?? null, 'current_vehicle.vehicle_id'),
            DatabaseRecord::string($record['version'] ?? null, 'current_vehicle.version'),
            DatabaseRecord::string($record['content_hash'] ?? null, 'current_vehicle.content_hash'),
            VehicleFreshness::from(DatabaseRecord::string($record['state'] ?? null, 'current_vehicle.state')),
            $latitude === null ? null : new Coordinate($latitude, (float) $longitude),
            DatabaseRecord::nullableString($record['line_code'] ?? null, 'current_vehicle.line_code'),
            DatabaseRecord::nullableString($record['route_name'] ?? null, 'current_vehicle.route_name'),
            $destination,
            DatabaseRecord::nullableFloat($record['bearing'] ?? null, 'current_vehicle.bearing'),
            DatabaseRecord::nullableInt($record['delay_seconds'] ?? null, 'current_vehicle.delay_seconds'),
            DatabaseRecord::nullableFloat($record['distance_meters'] ?? null, 'current_vehicle.distance_meters'),
            DatabaseRecord::dateTime($record['last_seen_at'] ?? null, 'current_vehicle.last_seen_at'),
            $updatedAt,
            $nextStop === null ? null : self::stopCall($nextStop),
            $observations,
            $referenceDto,
            $monitoredCallDto,
            $progress === null ? null : self::progressBetweenStops($progress),
            DatabaseRecord::nullableString($record['journey_version'] ?? null, 'current_vehicle.journey_version'),
            DatabaseRecord::nullableFloat($record['route_progress'] ?? null, 'current_vehicle.route_progress'),
            DatabaseRecord::nullableDateTime($record['refreshed_at'] ?? null, 'current_vehicle.refreshed_at') ?? $updatedAt,
            self::vehicleTransportMode($record['transport_mode'] ?? null, 'current_vehicle.transport_mode'),
            self::vehiclePassengerServiceState(
                $record['passenger_service_state'] ?? null,
                'current_vehicle.passenger_service_state',
                $referenceDto,
                $monitoredCallDto,
                $destination,
            ),
        );
    }

    /** @param array<string, mixed> $record */
    public static function journeySnapshot(array $record): JourneySnapshot
    {
        $route = self::nullableObject($record['route'] ?? null, 'journey_snapshot.route');
        $calls = array_map(self::stopCall(...), self::objectList($record['calls'] ?? [], 'journey_snapshot.calls'));

        return new JourneySnapshot(
            DatabaseRecord::string($record['service_journey_id'] ?? null, 'journey_snapshot.service_journey_id'),
            DatabaseRecord::string($record['operating_date'] ?? null, 'journey_snapshot.operating_date'),
            DatabaseRecord::nullableString($record['dated_service_journey_id'] ?? null, 'journey_snapshot.dated_service_journey_id'),
            DatabaseRecord::string($record['version'] ?? null, 'journey_snapshot.version'),
            DatabaseRecord::string($record['content_hash'] ?? null, 'journey_snapshot.content_hash'),
            SourceState::from(DatabaseRecord::string($record['state'] ?? null, 'journey_snapshot.state')),
            $route === null ? null : self::journeyGeometry($route),
            $calls,
            DatabaseRecord::dateTime($record['refreshed_at'] ?? null, 'journey_snapshot.refreshed_at'),
            DatabaseRecord::nullableDateTime($record['last_successful_at'] ?? null, 'journey_snapshot.last_successful_at'),
            DatabaseRecord::nullableString($record['warning'] ?? null, 'journey_snapshot.warning'),
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
        $journeyReference = self::nullableObject($record['journeyReference'] ?? null, 'vehicle.journeyReference');
        $monitoredCall = self::nullableObject($record['monitoredCall'] ?? null, 'vehicle.monitoredCall');
        $progress = self::nullableObject($record['progressBetweenStops'] ?? null, 'vehicle.progressBetweenStops');
        $lastSeenAt = DatabaseRecord::dateTime($record['lastSeenAt'] ?? null, 'vehicle.lastSeenAt');
        $referenceDto = $journeyReference === null ? null : self::journeyReference($journeyReference);
        $monitoredCallDto = $monitoredCall === null ? null : self::monitoredCall($monitoredCall);
        $destination = DatabaseRecord::nullableString($record['destination'] ?? null, 'vehicle.destination');

        return new VehicleState(
            DatabaseRecord::string($record['id'] ?? null, 'vehicle.id'),
            DatabaseRecord::string($record['version'] ?? null, 'vehicle.version'),
            hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)),
            VehicleFreshness::from(DatabaseRecord::string($record['state'] ?? null, 'vehicle.state')),
            $latitude === null ? null : new Coordinate($latitude, (float) $longitude),
            DatabaseRecord::nullableString($record['lineCode'] ?? null, 'vehicle.lineCode'),
            DatabaseRecord::nullableString($record['routeName'] ?? null, 'vehicle.routeName'),
            $destination,
            DatabaseRecord::nullableFloat($record['bearing'] ?? null, 'vehicle.bearing'),
            DatabaseRecord::nullableInt($record['delaySeconds'] ?? null, 'vehicle.delaySeconds'),
            DatabaseRecord::nullableFloat($record['distanceMeters'] ?? null, 'vehicle.distanceMeters'),
            $lastSeenAt,
            DatabaseRecord::nullableDateTime($record['refreshedAt'] ?? null, 'vehicle.refreshedAt') ?? $lastSeenAt,
            $nextStop === null ? null : self::stopCall($nextStop),
            [],
            $referenceDto,
            $monitoredCallDto,
            $progress === null ? null : self::progressBetweenStops($progress),
            DatabaseRecord::nullableString($record['journeyVersion'] ?? null, 'vehicle.journeyVersion'),
            DatabaseRecord::nullableFloat($record['routeProgress'] ?? null, 'vehicle.routeProgress'),
            DatabaseRecord::nullableDateTime($record['refreshedAt'] ?? null, 'vehicle.refreshedAt'),
            self::vehicleTransportMode($record['transportMode'] ?? null, 'vehicle.transportMode'),
            self::vehiclePassengerServiceState(
                $record['passengerServiceState'] ?? null,
                'vehicle.passengerServiceState',
                $referenceDto,
                $monitoredCallDto,
                $destination,
            ),
        );
    }

    /** @param array<string, mixed> $record */
    private static function stopCall(array $record): StopCall
    {
        return new StopCall(
            DatabaseRecord::nullableString($record['stopPlaceId'] ?? null, 'stopCall.stopPlaceId'),
            DatabaseRecord::string($record['name'] ?? null, 'stopCall.name'),
            DatabaseRecord::nullableDateTime($record['aimedArrivalAt'] ?? null, 'stopCall.aimedArrivalAt'),
            DatabaseRecord::nullableDateTime($record['expectedArrivalAt'] ?? null, 'stopCall.expectedArrivalAt'),
            DatabaseRecord::nullableInt($record['order'] ?? null, 'stopCall.order') ?? 0,
            DatabaseRecord::nullableString($record['quayId'] ?? null, 'stopCall.quayId'),
            self::coordinate($record, 'stopCall'),
            DatabaseRecord::nullableDateTime($record['aimedDepartureAt'] ?? null, 'stopCall.aimedDepartureAt'),
            DatabaseRecord::nullableDateTime($record['expectedDepartureAt'] ?? null, 'stopCall.expectedDepartureAt'),
            isset($record['realtime']) ? self::bool($record['realtime'], 'stopCall.realtime') : false,
            isset($record['cancellation']) ? self::bool($record['cancellation'], 'stopCall.cancellation') : false,
        );
    }

    /** @param array<string, mixed> $record */
    private static function journeyReference(array $record): VehicleJourneyReference
    {
        return new VehicleJourneyReference(
            DatabaseRecord::string($record['serviceJourneyId'] ?? null, 'journeyReference.serviceJourneyId'),
            DatabaseRecord::string($record['operatingDate'] ?? null, 'journeyReference.operatingDate'),
            DatabaseRecord::nullableString($record['datedServiceJourneyId'] ?? null, 'journeyReference.datedServiceJourneyId'),
            DatabaseRecord::nullableString($record['originRef'] ?? null, 'journeyReference.originRef'),
            DatabaseRecord::nullableString($record['originName'] ?? null, 'journeyReference.originName'),
            DatabaseRecord::nullableString($record['destinationRef'] ?? null, 'journeyReference.destinationRef'),
            DatabaseRecord::nullableString($record['destinationName'] ?? null, 'journeyReference.destinationName'),
        );
    }

    /** @param array<string, mixed> $record */
    private static function monitoredCall(array $record): MonitoredCallReference
    {
        return new MonitoredCallReference(
            DatabaseRecord::nullableString($record['stopPointRef'] ?? null, 'monitoredCall.stopPointRef'),
            DatabaseRecord::int($record['order'] ?? null, 'monitoredCall.order'),
            self::bool($record['vehicleAtStop'] ?? null, 'monitoredCall.vehicleAtStop'),
        );
    }

    /** @param array<string, mixed> $record */
    private static function progressBetweenStops(array $record): ProgressBetweenStops
    {
        return new ProgressBetweenStops(
            DatabaseRecord::nullableFloat($record['linkDistance'] ?? null, 'progressBetweenStops.linkDistance'),
            DatabaseRecord::nullableFloat($record['percentage'] ?? null, 'progressBetweenStops.percentage'),
        );
    }

    /** @param array<string, mixed> $record */
    private static function journeyGeometry(array $record): JourneyGeometry
    {
        if (($record['type'] ?? null) !== 'LineString') {
            throw new InvalidArgumentException('Journey geometry must be a GeoJSON LineString.');
        }
        $coordinates = $record['coordinates'] ?? null;
        if (!is_array($coordinates) || !array_is_list($coordinates)) {
            throw new InvalidArgumentException('Journey geometry coordinates must be a list.');
        }
        $mapped = [];
        foreach ($coordinates as $coordinate) {
            if (!is_array($coordinate) || count($coordinate) !== 2 || !is_numeric($coordinate[0] ?? null) || !is_numeric($coordinate[1] ?? null)) {
                throw new InvalidArgumentException('Journey geometry coordinate must be [longitude, latitude].');
            }
            $mapped[] = new Coordinate((float)$coordinate[1], (float)$coordinate[0]);
        }

        return new JourneyGeometry(
            $mapped,
            DatabaseRecord::nullableFloat($record['distanceMeters'] ?? null, 'journeyGeometry.distanceMeters'),
        );
    }

    /** @param array<string, mixed> $record */
    private static function coordinate(array $record, string $field): ?Coordinate
    {
        $latitude = DatabaseRecord::nullableFloat($record['latitude'] ?? null, $field . '.latitude');
        $longitude = DatabaseRecord::nullableFloat($record['longitude'] ?? null, $field . '.longitude');
        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException("{$field} coordinates must both be present or absent.");
        }

        return $latitude === null ? null : new Coordinate($latitude, (float)$longitude);
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

    private static function vehicleTransportMode(mixed $value, string $field): VehicleTransportMode
    {
        $mode = DatabaseRecord::nullableString($value, $field);
        if ($mode === null) {
            return VehicleTransportMode::Unknown;
        }

        return VehicleTransportMode::tryFrom($mode)
            ?? throw new InvalidArgumentException("Expected {$field} to be a known vehicle transport mode.");
    }

    private static function vehiclePassengerServiceState(
        mixed $value,
        string $field,
        ?VehicleJourneyReference $reference,
        ?MonitoredCallReference $monitoredCall,
        ?string $destination,
    ): VehiclePassengerServiceState {
        $stored = DatabaseRecord::nullableString($value, $field);
        $state = $stored === null
            ? VehiclePassengerServiceState::Unknown
            : (VehiclePassengerServiceState::tryFrom($stored)
                ?? throw new InvalidArgumentException("Expected {$field} to be a known passenger service state."));
        if ($state !== VehiclePassengerServiceState::Unknown) {
            return $state;
        }

        return VehiclePassengerServiceClassifier::classify(
            $reference?->serviceJourneyId,
            $reference?->originRef,
            $reference?->destinationRef,
            $monitoredCall?->stopPointRef,
            $reference->destinationName ?? $destination,
        );
    }
}
