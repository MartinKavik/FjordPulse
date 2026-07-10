<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class SystemStatusRepository extends AbstractSurrealRepository
{
    public function save(SystemStatus $status): SystemStatus
    {
        $results = $this->connection->run(<<<'SURQL'
UPSERT ONLY type::record("system_status", type::string_lossy(encoding::base64::decode($service))) CONTENT {
    service: type::string_lossy(encoding::base64::decode($service)),
    state: type::string_lossy(encoding::base64::decode($state)),
    detail: type::string_lossy(encoding::base64::decode($detail)),
    checked_at: type::datetime(type::string_lossy(encoding::base64::decode($checked_at))),
    latency_ms: $latency_ms ?? NONE,
    metadata: encoding::json::decode($metadata)
} RETURN AFTER;
SURQL, [
            'service' => SurrealEncoding::string($status->service),
            'state' => SurrealEncoding::string($status->state),
            'detail' => SurrealEncoding::string($status->detail),
            'checked_at' => SurrealEncoding::string(self::timestamp($status->checkedAt)),
            'latency_ms' => $status->latencyMs,
            'metadata' => SurrealEncoding::json($status->metadata),
        ]);

        return SystemStatus::fromRecord(self::lastRecord($results, 'system status save'));
    }

    public function find(string $service): ?SystemStatus
    {
        $results = $this->connection->run(
            'SELECT * FROM ONLY type::record("system_status", type::string_lossy(encoding::base64::decode($service)));',
            ['service' => SurrealEncoding::string($service)],
        );
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SystemStatus::fromRecord($record);
    }

    /** @return list<SystemStatus> */
    public function all(): array
    {
        $results = $this->connection->run('SELECT * FROM system_status ORDER BY service ASC;');

        return array_map(SystemStatus::fromRecord(...), DatabaseRecord::many($results[0] ?? []));
    }
}
