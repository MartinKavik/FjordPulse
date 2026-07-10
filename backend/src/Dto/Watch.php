<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;

final readonly class Watch
{
    public function __construct(
        public string $id,
        public WatchType $type,
        public string $scope,
        public string $entityId,
        public int $clientCount,
        public WatchPriority $priority,
        public ?DateTimeImmutable $lastRefreshAt,
        public ?DateTimeImmutable $nextRefreshAt,
        public DateTimeImmutable $expiresAt,
        public WatchState $state,
        public ?string $lastErrorCode = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'scope' => $this->scope,
            'entityId' => $this->entityId,
            'clientCount' => $this->clientCount,
            'priority' => $this->priority->value,
            'lastRefreshAt' => $this->lastRefreshAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'nextRefreshAt' => $this->nextRefreshAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'expiresAt' => $this->expiresAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'state' => $this->state->value,
            'lastErrorCode' => $this->lastErrorCode,
        ];
    }
}
