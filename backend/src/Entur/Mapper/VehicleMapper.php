<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Mapper;

use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleObservation;
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
        $vehicles = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $id = $record['vehicleId'] ?? null;
            $latitude = ArrayValue::get($record, ['location', 'latitude']);
            $longitude = ArrayValue::get($record, ['location', 'longitude']);
            $updatedAt = is_string($record['lastUpdated'] ?? null) ? new DateTimeImmutable($record['lastUpdated']) : null;
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
            $bearing = is_numeric($record['bearing'] ?? null) ? (float)$record['bearing'] : null;
            $delay = is_numeric($record['delay'] ?? null) ? (int)round((float)$record['delay']) : null;
            $lineCode = $this->string(ArrayValue::get($record, ['line', 'publicCode']));
            $routeName = $this->string(ArrayValue::get($record, ['line', 'lineName']));
            $destination = $this->string($record['destinationName'] ?? null);
            $semantic = [$id, $state->value, $coordinate->latitude, $coordinate->longitude, $bearing, $delay, $lineCode, $destination];
            $version = $mappedAt->format('Y-m-d\\TH:i:s.v\\Z');
            $observation = new VehicleObservation(str_replace(':', '-', $id) . '-' . $updatedAt->format('U'), $id, $coordinate, $updatedAt, $bearing);
            $vehicles[] = new VehicleState(
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
                $mappedAt,
                $updatedAt,
                null,
                [$observation],
            );
        }

        return $vehicles;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
