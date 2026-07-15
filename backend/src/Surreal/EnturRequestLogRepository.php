<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use FjordPulse\Dto\EnturRequestLog;

final readonly class EnturRequestLogRepository extends AbstractSurrealRepository
{
    public function append(EnturRequestLog $log): EnturRequestLog
    {
        $results = $this->connection->run(<<<'SURQL'
UPSERT ONLY type::record("entur_request_log", type::string_lossy(encoding::base64::decode($log_id))) CONTENT {
    log_id: type::string_lossy(encoding::base64::decode($log_id)),
    request_id: type::string_lossy(encoding::base64::decode($request_id)),
    requested_at: type::datetime(type::string_lossy(encoding::base64::decode($requested_at))),
    service: type::string_lossy(encoding::base64::decode($service)),
    scope: type::string_lossy(encoding::base64::decode($scope)),
    outcome: type::string_lossy(encoding::base64::decode($outcome)),
    http_status: $http_status ?? NONE,
    latency_ms: $latency_ms,
    item_count: $item_count,
    cache: type::string_lossy(encoding::base64::decode($cache)),
    retry_at: IF $retry_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($retry_at))) },
    error_code: IF $error_code = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($error_code)) },
    metadata: {}
} RETURN AFTER;
SURQL, [
            'log_id' => SurrealEncoding::string($log->id),
            'request_id' => SurrealEncoding::string($log->requestId),
            'requested_at' => SurrealEncoding::string(self::timestamp($log->requestedAt)),
            'service' => SurrealEncoding::string($log->service),
            'scope' => SurrealEncoding::string($log->scope),
            'outcome' => SurrealEncoding::string($log->outcome),
            'http_status' => $log->httpStatus,
            'latency_ms' => (float) $log->latencyMs,
            'item_count' => $log->itemCount,
            'cache' => SurrealEncoding::string($log->cache),
            'retry_at' => $log->retryAt === null ? null : SurrealEncoding::string(self::timestamp($log->retryAt)),
            'error_code' => SurrealEncoding::nullableString($log->errorCode),
        ]);

        return SurrealDtoMapper::enturLog(self::lastRecord($results, 'Entur request log append'));
    }

    /** @return list<EnturRequestLog> */
    public function recent(?string $service = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Entur log limit must be between 1 and 1000.');
        }

        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM entur_request_log
WHERE $service = NULL OR service = type::string_lossy(encoding::base64::decode($service))
ORDER BY requested_at DESC, log_id DESC
LIMIT $limit;
SURQL, ['service' => SurrealEncoding::nullableString($service), 'limit' => $limit]);

        return array_map(SurrealDtoMapper::enturLog(...), DatabaseRecord::many($results[0] ?? []));
    }

    /**
     * Return a database-filtered diagnostic sample. Filtering before LIMIT
     * prevents unrelated cache rows from crowding matching outbound evidence
     * out of the Admin metrics.
     *
     * @return list<EnturRequestLog>
     */
    public function filtered(
        ?string $service = null,
        ?string $outcome = null,
        ?string $scope = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $limit = 100,
        bool $outboundOnly = false,
        ?DateTimeImmutable $retryAfter = null,
    ): array {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Entur log limit must be between 1 and 1000.');
        }

        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM entur_request_log
WHERE ($service = NULL OR service = type::string_lossy(encoding::base64::decode($service)))
  AND ($outcome = NULL OR outcome = type::string_lossy(encoding::base64::decode($outcome)))
  AND ($scope = NULL OR string::lowercase(scope) CONTAINS type::string_lossy(encoding::base64::decode($scope)))
  AND ($from = NULL OR requested_at >= type::datetime(type::string_lossy(encoding::base64::decode($from))))
  AND ($to = NULL OR requested_at <= type::datetime(type::string_lossy(encoding::base64::decode($to))))
  AND ($outbound_only = false OR outcome NOT IN ["cache_hit", "skipped_budget", "backoff"])
  AND ($retry_after = NULL OR (retry_at != NONE AND retry_at > type::datetime(type::string_lossy(encoding::base64::decode($retry_after)))))
ORDER BY requested_at DESC, log_id DESC
LIMIT $limit;
SURQL, [
            'service' => SurrealEncoding::nullableString($service),
            'outcome' => SurrealEncoding::nullableString($outcome),
            'scope' => SurrealEncoding::nullableString($scope === null ? null : strtolower($scope)),
            'from' => $from === null ? null : SurrealEncoding::string(self::timestamp($from)),
            'to' => $to === null ? null : SurrealEncoding::string(self::timestamp($to)),
            'limit' => $limit,
            'outbound_only' => $outboundOnly,
            'retry_after' => $retryAfter === null ? null : SurrealEncoding::string(self::timestamp($retryAfter)),
        ]);

        return array_map(SurrealDtoMapper::enturLog(...), DatabaseRecord::many($results[0] ?? []));
    }

    public function latestNonCacheEvidence(): ?EnturRequestLog
    {
        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM entur_request_log
WHERE outcome != "cache_hit"
ORDER BY requested_at DESC, log_id DESC
LIMIT 1;
SURQL);
        $record = DatabaseRecord::many($results[0] ?? [])[0] ?? null;

        return $record === null ? null : SurrealDtoMapper::enturLog($record);
    }

    /** @return array<string, int> */
    public function usageSince(DateTimeImmutable $since): array
    {
        $results = $this->connection->run(
            'SELECT service FROM entur_request_log WHERE requested_at >= type::datetime(type::string_lossy(encoding::base64::decode($since))) AND outcome NOT IN ["skipped_budget", "cache_hit"];',
            ['since' => SurrealEncoding::string(self::timestamp($since))],
        );
        $usage = [];
        foreach (DatabaseRecord::many($results[0] ?? []) as $record) {
            $service = DatabaseRecord::string($record['service'] ?? null, 'entur_request_log.service');
            $usage[$service] = ($usage[$service] ?? 0) + 1;
        }

        return $usage;
    }
}
