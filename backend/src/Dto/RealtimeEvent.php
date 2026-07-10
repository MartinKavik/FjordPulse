<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\RealtimeType;
use InvalidArgumentException;

final readonly class RealtimeEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventId,
        public string $entityId,
        public string $scope,
        public RealtimeType $type,
        public string $version,
        public DateTimeImmutable $createdAt,
        public array $payload,
    ) {
    }

    /** @param array<string, mixed> $record */
    public static function fromRecord(array $record): self
    {
        $eventId = $record['event_id'] ?? $record['eventId'] ?? null;
        $entityId = $record['entity_id'] ?? $record['entityId'] ?? null;
        $scope = $record['scope'] ?? null;
        $type = $record['type'] ?? null;
        $version = $record['version'] ?? null;
        $createdAt = $record['created_at'] ?? $record['createdAt'] ?? null;
        $payload = $record['payload'] ?? null;

        if (!is_string($eventId) || !is_string($entityId) || !is_string($scope) || !is_string($type)
            || !is_string($version) || !is_string($createdAt) || !is_array($payload)) {
            throw new InvalidArgumentException('Invalid realtime_event record.');
        }

        $normalizedPayload = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Realtime event payload keys must be strings.');
            }
            $normalizedPayload[$key] = $value;
        }

        return new self(
            $eventId,
            $entityId,
            $scope,
            RealtimeType::from($type),
            $version,
            new DateTimeImmutable($createdAt),
            $normalizedPayload,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'protocolVersion' => 1,
            'eventId' => $this->eventId,
            'entityId' => $this->entityId,
            'scope' => $this->scope,
            'type' => $this->type->value,
            'version' => $this->version,
            'createdAt' => $this->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'payload' => $this->payload,
        ];
    }
}
