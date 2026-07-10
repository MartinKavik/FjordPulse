<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Mapper;

use DateTimeImmutable;
use FjordPulse\Domain\DepartureStatus;
use FjordPulse\Dto\Departure;
use FjordPulse\Entur\SourceUnavailable;

final class JourneyPlannerMapper
{
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

    private function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? new DateTimeImmutable($value) : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
