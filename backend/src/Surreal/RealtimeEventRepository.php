<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Dto\RealtimeEvent;

/**
 * Read-only by design: realtime_event rows may only be appended by database
 * events on canonical state tables, never directly after a PHP write.
 */
final readonly class RealtimeEventRepository extends AbstractSurrealRepository
{
    public function find(string $eventId): ?RealtimeEvent
    {
        $results = $this->connection->run(
            'SELECT * FROM ONLY realtime_event WHERE event_id = type::string_lossy(encoding::base64::decode($event_id));',
            ['event_id' => SurrealEncoding::string($eventId)],
        );
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::realtimeEvent($record);
    }

    /** @return list<RealtimeEvent> */
    public function recent(?string $scope = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Realtime event limit must be between 1 and 1000.');
        }

        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM realtime_event
WHERE $scope = NULL OR scope = type::string_lossy(encoding::base64::decode($scope))
ORDER BY created_at DESC, event_id DESC
LIMIT $limit;
SURQL, ['scope' => SurrealEncoding::nullableString($scope), 'limit' => $limit]);

        return array_map(SurrealDtoMapper::realtimeEvent(...), DatabaseRecord::many($results[0] ?? []));
    }
}
