<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Watch;

final class ActiveWatch
{
    /** @var array<string, true> */
    private array $clients = [];

    public function __construct(
        public readonly string $id,
        public readonly WatchType $type,
        public readonly string $scope,
        public readonly string $entityId,
        public WatchPriority $priority,
        public ?DateTimeImmutable $lastRefreshAt,
        public ?DateTimeImmutable $nextRefreshAt,
        public DateTimeImmutable $expiresAt,
        public WatchState $state = WatchState::Active,
        public ?string $lastErrorCode = null,
        public bool $paused = false,
    ) {
    }

    public function attach(string $clientId): void
    {
        $this->clients[$clientId] = true;
    }

    public function detach(string $clientId): void
    {
        unset($this->clients[$clientId]);
    }

    public function hasClient(string $clientId): bool
    {
        return isset($this->clients[$clientId]);
    }

    public function clientCount(): int
    {
        return count($this->clients);
    }

    public function dto(): Watch
    {
        return new Watch(
            $this->id,
            $this->type,
            $this->scope,
            $this->entityId,
            $this->clientCount(),
            $this->priority,
            $this->lastRefreshAt,
            $this->nextRefreshAt,
            $this->expiresAt,
            $this->state,
            $this->lastErrorCode,
        );
    }
}
