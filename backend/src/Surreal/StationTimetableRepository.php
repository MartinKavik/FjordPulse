<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use FjordPulse\Dto\Departure;
use FjordPulse\Dto\StationTimetable;

final readonly class StationTimetableRepository extends AbstractSurrealRepository
{
    public function save(StationTimetable $timetable, DateTimeImmutable $expiresAt): StationTimetable
    {
        $timetableId = hash('sha256', implode('|', [
            $timetable->stationId,
            $timetable->serviceDate,
            $timetable->version,
        ]));
        $results = $this->connection->run(<<<'SURQL'
DELETE station_timetable WHERE expires_at <= time::now();
UPSERT ONLY type::record("station_timetable", type::string_lossy(encoding::base64::decode($timetable_id))) CONTENT {
    timetable_id: type::string_lossy(encoding::base64::decode($timetable_id)),
    station_id: type::string_lossy(encoding::base64::decode($station_id)),
    service_date: type::string_lossy(encoding::base64::decode($service_date)),
    time_zone: type::string_lossy(encoding::base64::decode($time_zone)),
    window_start: type::datetime(type::string_lossy(encoding::base64::decode($window_start))),
    window_end: type::datetime(type::string_lossy(encoding::base64::decode($window_end))),
    departures: encoding::json::decode($departures),
    complete: $complete,
    fetched_at: type::datetime(type::string_lossy(encoding::base64::decode($fetched_at))),
    version: type::string_lossy(encoding::base64::decode($version)),
    expires_at: type::datetime(type::string_lossy(encoding::base64::decode($expires_at)))
};
SELECT * FROM ONLY type::record("station_timetable", type::string_lossy(encoding::base64::decode($timetable_id)));
SURQL, [
            'timetable_id' => SurrealEncoding::string($timetableId),
            'station_id' => SurrealEncoding::string($timetable->stationId),
            'service_date' => SurrealEncoding::string($timetable->serviceDate),
            'time_zone' => SurrealEncoding::string($timetable->timeZone),
            'window_start' => SurrealEncoding::string(self::timestamp($timetable->windowStart)),
            'window_end' => SurrealEncoding::string(self::timestamp($timetable->windowEnd)),
            'departures' => SurrealEncoding::json(array_map(
                static fn(Departure $departure): array => $departure->toArray(),
                $timetable->departures,
            )),
            'complete' => $timetable->complete,
            'fetched_at' => SurrealEncoding::string(self::timestamp($timetable->fetchedAt)),
            'version' => SurrealEncoding::string($timetable->version),
            'expires_at' => SurrealEncoding::string(self::timestamp($expiresAt)),
        ]);

        return SurrealDtoMapper::stationTimetable(self::lastRecord($results, 'station timetable save'));
    }

    public function findFresh(string $stationId, string $serviceDate, DateTimeImmutable $notBefore): ?StationTimetable
    {
        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM station_timetable
WHERE station_id = type::string_lossy(encoding::base64::decode($station_id))
  AND service_date = type::string_lossy(encoding::base64::decode($service_date))
  AND fetched_at >= type::datetime(type::string_lossy(encoding::base64::decode($not_before)))
  AND expires_at > time::now()
ORDER BY fetched_at DESC
LIMIT 1;
SURQL, [
            'station_id' => SurrealEncoding::string($stationId),
            'service_date' => SurrealEncoding::string($serviceDate),
            'not_before' => SurrealEncoding::string(self::timestamp($notBefore)),
        ]);
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::stationTimetable($record);
    }

    public function findVersion(
        string $stationId,
        string $serviceDate,
        string $version,
    ): ?StationTimetable {
        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM station_timetable
WHERE station_id = type::string_lossy(encoding::base64::decode($station_id))
  AND service_date = type::string_lossy(encoding::base64::decode($service_date))
  AND version = type::string_lossy(encoding::base64::decode($version))
  AND expires_at > time::now()
LIMIT 1;
SURQL, [
            'station_id' => SurrealEncoding::string($stationId),
            'service_date' => SurrealEncoding::string($serviceDate),
            'version' => SurrealEncoding::string($version),
        ]);
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::stationTimetable($record);
    }
}
