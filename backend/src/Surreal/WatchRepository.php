<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Dto\Watch;

final readonly class WatchRepository extends AbstractSurrealRepository
{
    public function save(Watch $watch): Watch
    {
        $results = $this->connection->run(<<<'SURQL'
UPSERT ONLY type::record("watch", type::string_lossy(encoding::base64::decode($watch_id))) CONTENT {
    watch_id: type::string_lossy(encoding::base64::decode($watch_id)),
    type: type::string_lossy(encoding::base64::decode($type)),
    scope: type::string_lossy(encoding::base64::decode($scope)),
    entity_id: type::string_lossy(encoding::base64::decode($entity_id)),
    client_count: $client_count,
    priority: type::string_lossy(encoding::base64::decode($priority)),
    state: type::string_lossy(encoding::base64::decode($state)),
    last_refresh_at: IF $last_refresh_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($last_refresh_at))) },
    next_refresh_at: IF $next_refresh_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($next_refresh_at))) },
    expires_at: type::datetime(type::string_lossy(encoding::base64::decode($expires_at))),
    last_error_code: IF $last_error_code = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($last_error_code)) },
    updated_at: time::now()
} RETURN AFTER;
SURQL, [
            'watch_id' => SurrealEncoding::string($watch->id),
            'type' => SurrealEncoding::string($watch->type->value),
            'scope' => SurrealEncoding::string($watch->scope),
            'entity_id' => SurrealEncoding::string($watch->entityId),
            'client_count' => $watch->clientCount,
            'priority' => SurrealEncoding::string($watch->priority->value),
            'state' => SurrealEncoding::string($watch->state->value),
            'last_refresh_at' => $watch->lastRefreshAt === null ? null : SurrealEncoding::string(self::timestamp($watch->lastRefreshAt)),
            'next_refresh_at' => $watch->nextRefreshAt === null ? null : SurrealEncoding::string(self::timestamp($watch->nextRefreshAt)),
            'expires_at' => SurrealEncoding::string(self::timestamp($watch->expiresAt)),
            'last_error_code' => SurrealEncoding::nullableString($watch->lastErrorCode),
        ]);

        return SurrealDtoMapper::watch(self::lastRecord($results, 'watch save'));
    }

    public function findByScope(string $scope): ?Watch
    {
        $results = $this->connection->run(
            'SELECT * FROM ONLY watch WHERE scope = type::string_lossy(encoding::base64::decode($scope));',
            ['scope' => SurrealEncoding::string($scope)],
        );
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::watch($record);
    }

    /** @return list<Watch> */
    public function all(int $limit = 1_000): array
    {
        if ($limit < 1 || $limit > 10_000) {
            throw new \InvalidArgumentException('Watch list limit must be between 1 and 10000.');
        }

        $results = $this->connection->run(
            'SELECT * FROM watch ORDER BY expires_at ASC, scope ASC LIMIT $limit;',
            ['limit' => $limit],
        );

        return array_map(SurrealDtoMapper::watch(...), DatabaseRecord::many($results[0] ?? []));
    }

    /** @return list<Watch> */
    public function due(DateTimeImmutable $at, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Due watch limit must be between 1 and 1000.');
        }

        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM watch
WHERE state IN ["active", "stale", "backoff"]
  AND expires_at > type::datetime(type::string_lossy(encoding::base64::decode($at)))
  AND (next_refresh_at = NONE OR next_refresh_at <= type::datetime(type::string_lossy(encoding::base64::decode($at))))
ORDER BY next_refresh_at ASC, scope ASC
LIMIT $limit;
SURQL, ['at' => SurrealEncoding::string(self::timestamp($at)), 'limit' => $limit]);
        $watches = array_map(SurrealDtoMapper::watch(...), DatabaseRecord::many($results[0] ?? []));

        usort($watches, static function (Watch $left, Watch $right): int {
            $priority = static fn(WatchPriority $value): int => match ($value) {
                WatchPriority::Background => 10,
                WatchPriority::Station => 30,
                WatchPriority::Vehicle => 60,
                WatchPriority::Focus => 100,
            };

            return $priority($right->priority) <=> $priority($left->priority)
                ?: $left->scope <=> $right->scope;
        });

        return $watches;
    }

    public function delete(string $watchId): bool
    {
        $results = $this->connection->run(
            'DELETE ONLY type::record("watch", type::string_lossy(encoding::base64::decode($watch_id))) RETURN BEFORE;',
            ['watch_id' => SurrealEncoding::string($watchId)],
        );

        return DatabaseRecord::one($results[0] ?? null) !== null;
    }
}
