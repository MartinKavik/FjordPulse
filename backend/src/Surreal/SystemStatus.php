<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;

final readonly class SystemStatus
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $service,
        public string $state,
        public string $detail,
        public DateTimeImmutable $checkedAt,
        public ?float $latencyMs = null,
        public array $metadata = [],
    ) {
    }

    /** @param array<string, mixed> $record */
    public static function fromRecord(array $record): self
    {
        $metadata = $record['metadata'] ?? [];
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new \InvalidArgumentException('system_status.metadata must be an object.');
        }

        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('system_status.metadata keys must be strings.');
            }
            $normalized[$key] = DatabaseRecord::normalize($value);
        }

        return new self(
            DatabaseRecord::string($record['service'] ?? null, 'system_status.service'),
            DatabaseRecord::string($record['state'] ?? null, 'system_status.state'),
            DatabaseRecord::string($record['detail'] ?? null, 'system_status.detail'),
            DatabaseRecord::dateTime($record['checked_at'] ?? null, 'system_status.checked_at'),
            DatabaseRecord::nullableFloat($record['latency_ms'] ?? null, 'system_status.latency_ms'),
            $normalized,
        );
    }
}
