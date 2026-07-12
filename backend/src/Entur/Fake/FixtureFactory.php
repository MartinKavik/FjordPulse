<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Fake;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\StationKind;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Domain\VehicleTransportMode;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\JourneyGeometry;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\MonitoredCallReference;
use FjordPulse\Dto\ProgressBetweenStops;
use FjordPulse\Dto\Station;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;

final class FixtureFactory
{
    private const string BASE_TIME = '2026-07-09T12:00:00Z';

    /** @return list<Station> */
    public static function stations(): array
    {
        $at = new DateTimeImmutable(self::BASE_TIME);

        return [
            new Station('NSR:StopPlace:36025', 'Førde rutebilstasjon', StationKind::BusStation, new Coordinate(61.4522, 5.8572), 'Sunnfjord', 'Sunnfjord', ['bus'], $at),
            new Station('NSR:StopPlace:34562', 'Sandane rutebilstasjon', StationKind::BusStation, new Coordinate(61.776581, 6.21389), 'Gloppen', 'Gloppen', ['bus'], $at),
            new Station('NSR:StopPlace:35453', 'Nordfjordeid rutebilstasjon', StationKind::BusStation, new Coordinate(61.906336, 5.991119), 'Stad', 'Stad', ['bus'], $at),
            new Station('NSR:StopPlace:337', 'Oslo S', StationKind::RailStation, new Coordinate(59.9111, 10.7528), 'Oslo', 'Oslo', ['rail', 'bus', 'tram'], $at),
            new Station('NSR:StopPlace:58366', 'Bergen busstasjon', StationKind::BusStation, new Coordinate(60.3894, 5.3336), 'Bergen', 'Bergen', ['bus', 'rail'], $at),
            new Station('NSR:StopPlace:548', 'Trondheim S', StationKind::RailStation, new Coordinate(63.4364, 10.3990), 'Trondheim', 'Trondheim', ['rail', 'bus'], $at),
            new Station('NSR:StopPlace:59872', 'Tromsø Prostneset', StationKind::BusStation, new Coordinate(69.6489, 18.9568), 'Tromsø', 'Tromsø', ['bus', 'water'], $at),
        ];
    }

    /** @return list<Station> */
    public static function places(): array
    {
        $at = new DateTimeImmutable(self::BASE_TIME);

        return [
            new Station('OSM:TopographicPlace:fjordpulse-forde', 'Førde sentrum', StationKind::Unknown, new Coordinate(61.4520, 5.8600), 'Sunnfjord', 'Sunnfjord', [], $at),
            new Station('OSM:TopographicPlace:fjordpulse-radhus', 'Oslo rådhus', StationKind::Unknown, new Coordinate(59.9119, 10.7336), 'Oslo', 'Oslo', [], $at),
        ];
    }

    /** @return list<Departure> */
    public static function departures(string $stationId): array
    {
        $base = new DateTimeImmutable(self::BASE_TIME);
        $prefix = str_replace(':', '-', $stationId);

        return [
            new Departure($prefix . '-dep-1', 'SKY:ServiceJourney:100-1', 'SKY:Line:100', '100', 'Florø', $base->add(new DateInterval('PT4M')), $base->add(new DateInterval('PT5M')), DepartureStatus::Delayed, 60, 'A', true),
            new Departure($prefix . '-dep-2', 'SKY:ServiceJourney:110-1', 'SKY:Line:110', '110', 'Sandane', $base->add(new DateInterval('PT12M')), $base->add(new DateInterval('PT12M')), DepartureStatus::Realtime, 0, 'B', true),
            new Departure($prefix . '-dep-3', 'SKY:ServiceJourney:FB59-1', 'SKY:Line:FB59', 'FB59', 'Bergen', $base->add(new DateInterval('PT22M')), $base->add(new DateInterval('PT25M')), DepartureStatus::Delayed, 180, null, true),
            new Departure($prefix . '-dep-4', 'SKY:ServiceJourney:201-1', 'SKY:Line:201', '201', 'Sogndal', $base->add(new DateInterval('PT38M')), $base->add(new DateInterval('PT38M')), DepartureStatus::Scheduled, 0, 'C', false),
        ];
    }

    /** @return list<VehicleState> */
    public static function vehicles(int $tick = 0, VehicleFreshness $state = VehicleFreshness::Live): array
    {
        $base = (new DateTimeImmutable(self::BASE_TIME))->add(new DateInterval('PT' . max(0, $tick) . 'S'));
        $offset = 0.00012 * $tick;

        return [
            self::vehicle('SKY:Vehicle:1001', '100', 'Florø', new Coordinate(61.4540 + $offset, 5.8610 + $offset), $base, $state, 43.0),
            self::vehicle('SKY:Vehicle:1102', '110', 'Sandane', new Coordinate(61.4482 - $offset, 5.8520 + $offset), $base, $state, 211.0),
            self::vehicle('SKY:Vehicle:5903', 'FB59', 'Bergen', new Coordinate(61.4571 + $offset, 5.8488 - $offset), $base, $state, 126.0),
        ];
    }

    public static function journey(VehicleJourneyReference $reference): JourneySnapshot
    {
        $line = str_contains($reference->serviceJourneyId, '110') ? '110' : (str_contains($reference->serviceJourneyId, '590') ? 'FB59' : '100');
        $base = new DateTimeImmutable(self::BASE_TIME);
        $calls = match ($line) {
            '110' => [
                new StopCall('NSR:StopPlace:36025', 'Førde rutebilstasjon', $base, $base, 0, 'NSR:Quay:36025', new Coordinate(61.4522, 5.8572), $base, $base, true),
                new StopCall('NSR:StopPlace:35453', 'Nordfjordeid rutebilstasjon', $base->add(new DateInterval('PT45M')), $base->add(new DateInterval('PT46M')), 1, 'NSR:Quay:35453', new Coordinate(61.906336, 5.991119), null, null, true),
                new StopCall('NSR:StopPlace:34562', 'Sandane rutebilstasjon', $base->add(new DateInterval('PT75M')), $base->add(new DateInterval('PT76M')), 2, 'NSR:Quay:34562', new Coordinate(61.776581, 6.21389), null, null, true),
            ],
            default => [
                new StopCall('NSR:StopPlace:36025', 'Førde rutebilstasjon', $base, $base, 0, 'NSR:Quay:36025', new Coordinate(61.4522, 5.8572), $base, $base, true),
                new StopCall('NSR:StopPlace:58366', $line === 'FB59' ? 'Bergen busstasjon' : 'Florø terminal', $base->add(new DateInterval('PT60M')), $base->add(new DateInterval('PT61M')), 1, 'NSR:Quay:end', $line === 'FB59' ? new Coordinate(60.3894, 5.3336) : new Coordinate(61.5996, 5.0328), null, null, true),
            ],
        };
        $routeCoordinates = array_values(array_filter(array_map(static fn(StopCall $call): ?Coordinate => $call->coordinate, $calls)));
        $semantic = array_map(static fn(StopCall $call): array => $call->toArray(), $calls);

        return new JourneySnapshot(
            $reference->serviceJourneyId,
            $reference->operatingDate,
            $reference->datedServiceJourneyId,
            $base->format('Y-m-d\\TH:i:s.v\\Z'),
            hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR)),
            SourceState::Fresh,
            new JourneyGeometry($routeCoordinates, null),
            $calls,
            $base,
            $base,
        );
    }

    private static function vehicle(
        string $id,
        string $line,
        string $destination,
        Coordinate $coordinate,
        DateTimeImmutable $at,
        VehicleFreshness $state,
        float $bearing,
    ): VehicleState {
        $version = $at->format('Y-m-d\\TH:i:s.v\\Z');
        $position = $state === VehicleFreshness::Lost ? null : $coordinate;
        $observation = new VehicleObservation(
            str_replace(':', '-', $id) . '-' . $at->format('His'),
            $id,
            $coordinate,
            $at,
            $bearing,
        );
        $content = [$id, VehicleTransportMode::Bus->value, $line, $destination, $position?->latitude, $position?->longitude, $state->value, $version];
        $journey = new VehicleJourneyReference(
            'SKY:ServiceJourney:' . $line . '-1',
            '2026-07-09',
            null,
            'NSR:StopPlace:36025',
            'Førde rutebilstasjon',
            null,
            $destination,
        );

        return new VehicleState(
            $id,
            $version,
            hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)),
            $state,
            $position,
            $line,
            'Førde–' . $destination,
            $destination,
            $bearing,
            60,
            null,
            $at,
            $at,
            null,
            [$observation],
            $journey,
            new MonitoredCallReference('NSR:Quay:36025', 0, false),
            new ProgressBetweenStops(null, min(0.95, max(0.0, $at->getTimestamp() - (new DateTimeImmutable(self::BASE_TIME))->getTimestamp()) / 100.0)),
            refreshedAt: $at,
            transportMode: VehicleTransportMode::Bus,
            passengerServiceState: VehiclePassengerServiceState::Passenger,
        );
    }
}
