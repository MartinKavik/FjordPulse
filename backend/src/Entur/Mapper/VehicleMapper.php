<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Mapper;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\MonitoredCallReference;
use FjordPulse\Dto\ProgressBetweenStops;
use FjordPulse\Dto\VehicleObservation;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Entur\SourceUnavailable;

final class VehicleMapper
{
    /** @var \Closure(): DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly int $staleAfterSeconds = 30,
        private readonly int $lostAfterSeconds = 120,
        ?\Closure $clock = null,
    ) {
        if ($staleAfterSeconds < 1 || $lostAfterSeconds <= $staleAfterSeconds) {
            throw new \InvalidArgumentException('Vehicle freshness thresholds are invalid.');
        }
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @param array<mixed> $payload
     * @return list<VehicleState>
     */
    public function map(array $payload): array
    {
        if (isset($payload['errors'])) {
            throw new SourceUnavailable('Entur Vehicle Positions returned GraphQL errors.');
        }
        $records = ArrayValue::get($payload, ['data', 'vehicles']) ?? [];
        if (!is_array($records)) {
            return [];
        }
        /** @var array<string, VehicleState> $vehicles */
        $vehicles = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $id = $record['vehicleId'] ?? null;
            $latitude = ArrayValue::get($record, ['location', 'latitude']);
            $longitude = ArrayValue::get($record, ['location', 'longitude']);
            $updatedAt = $this->date($record['lastUpdated'] ?? null);
            if (!is_string($id) || !is_numeric($latitude) || !is_numeric($longitude) || $updatedAt === null) {
                continue;
            }
            $updatedAt = $updatedAt->setTimezone(new DateTimeZone('UTC'));
            $mappedAt = ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
            $ageSeconds = max(0, $mappedAt->getTimestamp() - $updatedAt->getTimestamp());
            $state = match (true) {
                $ageSeconds > $this->lostAfterSeconds => VehicleFreshness::Lost,
                $ageSeconds > $this->staleAfterSeconds => VehicleFreshness::Stale,
                default => VehicleFreshness::Live,
            };
            $coordinate = new Coordinate((float)$latitude, (float)$longitude);
            $bearing = $this->bearing($record['bearing'] ?? null);
            $delay = is_numeric($record['delay'] ?? null) ? (int)round((float)$record['delay']) : null;
            $lineCode = $this->string(ArrayValue::get($record, ['line', 'publicCode']));
            $routeName = $this->string(ArrayValue::get($record, ['line', 'lineName']));
            $destination = $this->string($record['destinationName'] ?? null);
            $journeyReference = $this->journeyReference($record);
            $monitoredCall = $this->monitoredCall($record['monitoredCall'] ?? null);
            $progress = $this->progress($record['progressBetweenStops'] ?? null);
            $semantic = [
                $id,
                $state->value,
                $coordinate->latitude,
                $coordinate->longitude,
                $bearing,
                $delay,
                $lineCode,
                $routeName,
                $destination,
                $updatedAt->format(DateTimeInterface::RFC3339_EXTENDED),
                $journeyReference?->toArray(),
                $monitoredCall?->toArray(),
                $progress?->toArray(),
            ];
            $version = $mappedAt->format('Y-m-d\\TH:i:s.v\\Z');
            $observation = new VehicleObservation(str_replace(':', '-', $id) . '-' . $updatedAt->format('U'), $id, $coordinate, $updatedAt, $bearing);
            $vehicle = new VehicleState(
                $id,
                $version,
                hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR)),
                $state,
                $coordinate,
                $lineCode,
                $routeName,
                $destination,
                $bearing,
                $delay,
                null,
                $updatedAt,
                $mappedAt,
                null,
                [$observation],
                $journeyReference,
                $monitoredCall,
                $progress,
                refreshedAt: $mappedAt,
            );
            $previous = $vehicles[$id] ?? null;
            if ($previous === null || $vehicle->lastSeenAt > $previous->lastSeenAt) {
                $vehicles[$id] = $vehicle;
            }
        }

        return array_values($vehicles);
    }

    /** @param array<mixed> $record */
    private function journeyReference(array $record): ?VehicleJourneyReference
    {
        $service = $record['serviceJourney'] ?? null;
        $dated = $record['datedServiceJourney'] ?? null;
        $serviceId = is_array($service) ? $this->string($service['id'] ?? null) : null;
        $operatingDate = is_array($service) ? $this->string($service['date'] ?? null) : null;
        if (($serviceId === null || $operatingDate === null) && is_array($dated)) {
            $datedService = $dated['serviceJourney'] ?? null;
            if (is_array($datedService)) {
                $serviceId ??= $this->string($datedService['id'] ?? null);
                $operatingDate ??= $this->string($datedService['date'] ?? null);
            }
        }
        if ($serviceId === null || $operatingDate === null) {
            return null;
        }

        try {
            return new VehicleJourneyReference(
                $serviceId,
                $operatingDate,
                is_array($dated) ? $this->string($dated['id'] ?? null) : null,
                $this->string($record['originRef'] ?? null),
                $this->string($record['originName'] ?? null),
                $this->string($record['destinationRef'] ?? null),
                $this->string($record['destinationName'] ?? null),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function monitoredCall(mixed $value): ?MonitoredCallReference
    {
        if (!is_array($value)) {
            return null;
        }
        $order = $value['order'] ?? null;
        if (!is_int($order) && !is_numeric($order)) {
            return null;
        }

        return new MonitoredCallReference(
            $this->string($value['stopPointRef'] ?? null),
            max(0, (int)$order - 1),
            ($value['vehicleAtStop'] ?? false) === true,
        );
    }

    private function bearing(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $bearing = fmod((float)$value, 360.0);

        return $bearing < 0.0 ? $bearing + 360.0 : $bearing;
    }

    private function progress(mixed $value): ?ProgressBetweenStops
    {
        if (!is_array($value)) {
            return null;
        }
        $linkDistance = is_numeric($value['linkDistance'] ?? null) ? (float)$value['linkDistance'] : null;
        $percentage = is_numeric($value['percentage'] ?? null) ? (float)$value['percentage'] : null;
        if ($percentage !== null && $percentage > 1.0 && $percentage <= 100.0) {
            $percentage /= 100.0;
        }
        if ($percentage !== null) {
            $percentage = min(1.0, max(0.0, $percentage));
        }

        return $linkDistance === null && $percentage === null
            ? null
            : new ProgressBetweenStops($linkDistance, $percentage);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
