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
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Entur\SourceUnavailable;

final class JourneyPlannerMapper
{
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
