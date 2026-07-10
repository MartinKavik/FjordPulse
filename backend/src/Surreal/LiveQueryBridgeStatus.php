<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class LiveQueryBridgeStatus
{
    public function __construct(
        public LiveQueryBridgeState $state,
        public ?string $queryId,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $connectedAt,
        public ?DateTimeImmutable $lastEventAt,
        public ?DateTimeImmutable $lastErrorAt,
        public ?string $lastError,
        public int $failureCount,
        public int $subscriptionCount,
    ) {
    }

    public function healthy(): bool
    {
        return $this->state === LiveQueryBridgeState::Healthy;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'queryId' => $this->queryId,
            'startedAt' => $this->startedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'connectedAt' => $this->connectedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'lastEventAt' => $this->lastEventAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'lastErrorAt' => $this->lastErrorAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'lastError' => $this->lastError,
            'failureCount' => $this->failureCount,
            'subscriptionCount' => $this->subscriptionCount,
        ];
    }
}
