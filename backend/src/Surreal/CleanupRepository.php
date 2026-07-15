<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;

final readonly class CleanupRepository extends AbstractSurrealRepository
{
    public function prune(
        DateTimeImmutable $now,
        DateTimeImmutable $observationCutoff,
        DateTimeImmutable $eventCutoff,
        DateTimeImmutable $enturLogCutoff,
    ): CleanupReport {
        $results = $this->connection->run(<<<'SURQL'
DELETE vehicle_observation
WHERE expires_at <= type::datetime(type::string_lossy(encoding::base64::decode($now)))
   OR observed_at < type::datetime(type::string_lossy(encoding::base64::decode($observation_cutoff)))
RETURN BEFORE;
DELETE station_timetable
WHERE expires_at <= type::datetime(type::string_lossy(encoding::base64::decode($now)))
RETURN BEFORE;
DELETE realtime_event
WHERE created_at < type::datetime(type::string_lossy(encoding::base64::decode($event_cutoff)))
RETURN BEFORE;
DELETE watch
WHERE expires_at <= type::datetime(type::string_lossy(encoding::base64::decode($now)))
RETURN BEFORE;
DELETE entur_request_log
WHERE requested_at < type::datetime(type::string_lossy(encoding::base64::decode($entur_log_cutoff)))
RETURN BEFORE;
SURQL, [
            'now' => SurrealEncoding::string(self::timestamp($now)),
            'observation_cutoff' => SurrealEncoding::string(self::timestamp($observationCutoff)),
            'event_cutoff' => SurrealEncoding::string(self::timestamp($eventCutoff)),
            'entur_log_cutoff' => SurrealEncoding::string(self::timestamp($enturLogCutoff)),
        ]);

        return new CleanupReport(
            count(DatabaseRecord::many($results[0] ?? [])),
            count(DatabaseRecord::many($results[1] ?? [])),
            count(DatabaseRecord::many($results[2] ?? [])),
            count(DatabaseRecord::many($results[3] ?? [])),
            count(DatabaseRecord::many($results[4] ?? [])),
        );
    }
}
