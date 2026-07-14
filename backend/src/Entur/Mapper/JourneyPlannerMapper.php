<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Mapper;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\JourneyGeometry;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\StationBoard;
use FjordPulse\Dto\StationServiceCall;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Entur\SourceUnavailable;

final class JourneyPlannerMapper
{
    private const int MAX_STATION_VEHICLE_JOURNEYS = 200;

    public function __construct(private readonly PolylineDecoder $polylines = new PolylineDecoder())
    {
    }

    /**
     * @param array<mixed> $payload
     * @return list<Departure>
     */
    public function map(array $payload): array
    {
        if (isset($payload['errors'])) {
            throw new SourceUnavailable('Entur Journey Planner returned GraphQL errors.');
        }
        $calls = ArrayValue::get($payload, ['data', 'stopPlace', 'estimatedCalls']) ?? [];

        return $this->mapDepartures($calls);
    }

    /** @param array<mixed> $payload */
    public function estimatedCallCount(array $payload): int
    {
        $calls = ArrayValue::get($payload, ['data', 'stopPlace', 'estimatedCalls']);

        return is_array($calls) ? count($calls) : 0;
    }

    /** @param array<mixed> $payload */
    public function mapStationBoard(
        array $payload,
        DateTimeImmutable $now,
        int $departureLimit = 20,
        ?DateTimeImmutable $departureWindowStart = null,
        ?DateTimeImmutable $departureWindowEnd = null,
    ): StationBoard
    {
        $departureLimit = max(1, $departureLimit);
        $departureWindowStart ??= $now;
        $departureWindowEnd ??= $now
            ->setTimezone(new DateTimeZone('Europe/Oslo'))
            ->setTime(0, 0)
            ->modify('+1 day');
        if (isset($payload['errors'])) {
            throw new SourceUnavailable('Entur Journey Planner returned GraphQL errors.');
        }
        $stopPlace = ArrayValue::get($payload, ['data', 'stopPlace']);
        if (!is_array($stopPlace)) {
            return new StationBoard(
                [],
                [],
                $now->modify('-6 hours'),
                $now->modify('+6 hours'),
                0,
                0,
                false,
                $departureWindowStart,
                $departureWindowEnd,
                $departureLimit,
                false,
            );
        }

        $mappedDepartures = $this->mapDepartures($stopPlace['departureCalls'] ?? []);
        $departureHasMore = count($mappedDepartures) > $departureLimit;
        $departures = array_slice($mappedDepartures, 0, $departureLimit);
        $upcomingRaw = $this->stationServiceCalls($stopPlace['upcomingVehicleCalls'] ?? []);
        $recentRaw = $this->stationServiceCalls($stopPlace['recentVehicleCalls'] ?? []);
        $upcoming = array_values(array_filter($upcomingRaw, static fn(StationServiceCall $call): bool => !$call->cancellation));
        $recent = array_reverse(array_values(array_filter($recentRaw, static fn(StationServiceCall $call): bool => !$call->cancellation)));
        $departureKeys = [];
        foreach ($departures as $departure) {
            if ($departure->serviceJourneyId !== null) {
                $departureKeys[$departure->serviceJourneyId] = true;
            }
        }

        $prioritized = [
            ...array_values(array_filter(
                $upcoming,
                static fn(StationServiceCall $call): bool => isset($departureKeys[$call->journeyReference->serviceJourneyId]),
            )),
            ...$upcoming,
            ...$recent,
        ];
        /** @var array<string, true> $candidateJourneys */
        $candidateJourneys = [];
        /** @var array<string, true> $selectedJourneys */
        $selectedJourneys = [];
        /** @var array<string, StationServiceCall> $selectedCalls */
        $selectedCalls = [];
        foreach ($prioritized as $call) {
            $journeyKey = $call->journeyReference->key();
            $candidateJourneys[$journeyKey] = true;
            if (!isset($selectedJourneys[$journeyKey]) && count($selectedJourneys) >= self::MAX_STATION_VEHICLE_JOURNEYS) {
                continue;
            }
            $selectedJourneys[$journeyKey] = true;
            $callKey = $journeyKey . '|' . $call->order . '|' . ($call->quayId ?? '');
            $selectedCalls[$callKey] = $call;
        }
        $truncated = count($upcomingRaw) >= 200
            || count($recentRaw) >= 200
            || count($candidateJourneys) > self::MAX_STATION_VEHICLE_JOURNEYS;

        return new StationBoard(
            $departures,
            array_values($selectedCalls),
            $now->modify('-6 hours'),
            $now->modify('+6 hours'),
            count($candidateJourneys),
            count($selectedJourneys),
            $truncated,
            $departureWindowStart,
            $departureWindowEnd,
            $departureLimit,
            $departureHasMore,
        );
    }

    /**
     * @return list<Departure>
     */
    private function mapDepartures(mixed $calls): array
    {
        if (!is_array($calls)) {
            return [];
        }
        $departures = [];
        foreach ($calls as $index => $call) {
            if (!is_array($call)) {
                continue;
            }
            $aimed = $this->date($call['aimedDepartureTime'] ?? null);
            if ($aimed === null) {
                continue;
            }
            $expected = $this->date($call['expectedDepartureTime'] ?? null);
            $actual = $this->date($call['actualDepartureTime'] ?? null);
            $cancelled = ($call['cancellation'] ?? false) === true;
            $serviceJourneyId = $this->string(ArrayValue::get($call, ['serviceJourney', 'id']));
            $lineId = $this->string(ArrayValue::get($call, ['serviceJourney', 'journeyPattern', 'line', 'id']));
            $lineCode = $this->string(ArrayValue::get($call, ['serviceJourney', 'journeyPattern', 'line', 'publicCode']));
            $delay = $expected === null ? null : $expected->getTimestamp() - $aimed->getTimestamp();
            $realtime = $expected !== null;
            $status = match (true) {
                $cancelled => DepartureStatus::Cancelled,
                $actual !== null => DepartureStatus::Departed,
                $delay !== null && abs($delay) >= 60 => DepartureStatus::Delayed,
                $realtime => DepartureStatus::Realtime,
                default => DepartureStatus::Scheduled,
            };
            $id = $serviceJourneyId ?? 'departure-' . $index . '-' . $aimed->format('U');
            $departures[] = new Departure(
                $id,
                $serviceJourneyId,
                $lineId,
                $lineCode,
                $this->string(ArrayValue::get($call, ['destinationDisplay', 'frontText'])),
                $aimed,
                $expected,
                $status,
                $delay,
                $this->string(ArrayValue::get($call, ['quay', 'publicCode'])),
                $realtime,
            );
        }

        return $departures;
    }

    /** @return list<StationServiceCall> */
    private function stationServiceCalls(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        $calls = [];
        foreach ($value as $record) {
            if (!is_array($record)) {
                continue;
            }
            $serviceJourneyId = $this->string(ArrayValue::get($record, ['serviceJourney', 'id']));
            $operatingDate = $this->string($record['date'] ?? null);
            $rawOrder = $record['stopPositionInPattern'] ?? null;
            if ($serviceJourneyId === null || $operatingDate === null || (!is_int($rawOrder) && !is_numeric($rawOrder))) {
                continue;
            }
            try {
                $reference = new VehicleJourneyReference($serviceJourneyId, $operatingDate);
                $calls[] = new StationServiceCall(
                    $reference,
                    (int)$rawOrder,
                    $this->string(ArrayValue::get($record, ['quay', 'id'])),
                    $this->date($record['aimedArrivalTime'] ?? null),
                    $this->date($record['expectedArrivalTime'] ?? null),
                    $this->date($record['actualArrivalTime'] ?? null),
                    $this->date($record['aimedDepartureTime'] ?? null),
                    $this->date($record['expectedDepartureTime'] ?? null),
                    $this->date($record['actualDepartureTime'] ?? null),
                    ($record['cancellation'] ?? false) === true,
                );
            } catch (\InvalidArgumentException $error) {
                throw new SourceUnavailable('Entur station service call is invalid.', previous: $error);
            }
        }

        return $calls;
    }

    /** @param array<mixed> $payload */
    public function mapJourney(
        array $payload,
        VehicleJourneyReference $reference,
        ?DateTimeImmutable $mappedAt = null,
    ): ?JourneySnapshot {
        if (isset($payload['errors'])) {
            throw new SourceUnavailable('Entur Journey Planner returned GraphQL errors.');
        }
        $journey = ArrayValue::get($payload, ['data', 'serviceJourney']);
        if ($journey === null) {
            return null;
        }
        if (!is_array($journey)) {
            throw new SourceUnavailable('Entur Journey Planner returned an invalid service journey.');
        }

        $serviceJourneyId = $this->string($journey['id'] ?? null);
        if ($serviceJourneyId === null || $serviceJourneyId !== $reference->serviceJourneyId) {
            throw new SourceUnavailable('Entur Journey Planner returned a mismatched service journey.');
        }
        $calls = $this->journeyCalls($journey['estimatedCalls'] ?? null);
        $route = $this->journeyGeometry($journey['pointsOnLink'] ?? null);
        $mappedAt = ($mappedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $semantic = [
            'serviceJourneyId' => $serviceJourneyId,
            'operatingDate' => $reference->operatingDate,
            'datedServiceJourneyId' => $reference->datedServiceJourneyId,
            'route' => $route?->toArray(),
            'calls' => array_map(static fn(StopCall $call): array => $call->toArray(), $calls),
        ];

        return new JourneySnapshot(
            $serviceJourneyId,
            $reference->operatingDate,
            $reference->datedServiceJourneyId,
            $mappedAt->format('Y-m-d\\TH:i:s.v\\Z'),
            hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            SourceState::Fresh,
            $route,
            $calls,
            $mappedAt,
            $mappedAt,
        );
    }

    /** @return list<StopCall> */
    private function journeyCalls(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new SourceUnavailable('Entur service journey calls are invalid.');
        }
        if (count($value) > 1_000) {
            throw new SourceUnavailable('Entur service journey exceeds the supported 1,000-call limit.');
        }

        $calls = [];
        foreach ($value as $index => $call) {
            if (!is_array($call)) {
                throw new SourceUnavailable('Entur service journey contains an invalid call.');
            }
            $quay = $call['quay'] ?? null;
            if (!is_array($quay)) {
                throw new SourceUnavailable('Entur service journey call has no quay.');
            }
            $quayId = $this->string($quay['id'] ?? null);
            $name = $this->string(ArrayValue::get($quay, ['stopPlace', 'name']))
                ?? $this->string($quay['name'] ?? null);
            if ($quayId === null || $name === null) {
                throw new SourceUnavailable('Entur service journey call is missing its quay identity.');
            }
            $latitude = $quay['latitude'] ?? null;
            $longitude = $quay['longitude'] ?? null;
            $coordinate = $this->coordinate($latitude, $longitude);
            $rawOrder = $call['stopPositionInPattern'] ?? $index;
            $order = is_int($rawOrder) && $rawOrder >= 0 ? $rawOrder : $index;
            $calls[] = new StopCall(
                $this->string(ArrayValue::get($quay, ['stopPlace', 'id'])),
                $name,
                $this->date($call['aimedArrivalTime'] ?? null),
                $this->date($call['expectedArrivalTime'] ?? null),
                $order,
                $quayId,
                $coordinate,
                $this->date($call['aimedDepartureTime'] ?? null),
                $this->date($call['expectedDepartureTime'] ?? null),
                ($call['realtime'] ?? false) === true,
                ($call['cancellation'] ?? false) === true,
            );
        }
        usort($calls, static fn(StopCall $left, StopCall $right): int => $left->order <=> $right->order);

        return $calls;
    }

    private function journeyGeometry(mixed $value): ?JourneyGeometry
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new SourceUnavailable('Entur service journey geometry is invalid.');
        }
        $points = $this->string($value['points'] ?? null);
        if ($points === null) {
            return null;
        }
        $distance = $value['distance'] ?? null;

        return new JourneyGeometry(
            $this->polylines->decode($points),
            is_numeric($distance) ? (float)$distance : null,
        );
    }

    private function coordinate(mixed $latitude, mixed $longitude): ?Coordinate
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }
        try {
            return new Coordinate((float)$latitude, (float)$longitude);
        } catch (\InvalidArgumentException $error) {
            throw new SourceUnavailable('Entur service journey call contains an out-of-range coordinate.', previous: $error);
        }
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
