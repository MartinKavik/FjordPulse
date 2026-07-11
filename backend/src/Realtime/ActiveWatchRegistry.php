<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Watch;

final class ActiveWatchRegistry
{
    /** @var array<string, ActiveWatch> */
    private array $entries = [];

    public function __construct(
        private readonly WatchStore $store,
        public readonly int $ttlSeconds = 60,
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Watch TTL must be positive.');
        }
    }

    public function acquire(
        string $clientId,
        WatchType $type,
        string $scope,
        string $entityId,
        WatchPriority $priority,
        ?DateTimeImmutable $now = null,
    ): Watch {
        $now ??= self::now();
        $key = self::key($type, $scope);
        $entry = $this->entries[$key] ??= new ActiveWatch(
            self::watchId($type, $scope),
            $type,
            $scope,
            $entityId,
            $priority,
            null,
            $now,
            $this->expires($now),
        );
        if ($entry->entityId !== $entityId) {
            throw new \LogicException('Watch scope cannot be reused for a different entity.');
        }
        $entry->attach($clientId);
        $entry->priority = $priority;
        $entry->state = WatchState::Active;
        $entry->lastErrorCode = null;
        $entry->expiresAt = $this->expires($now);
        $entry->nextRefreshAt ??= $now;
        $this->store->save($entry->dto());

        return $entry->dto();
    }

    public function release(
        string $clientId,
        WatchType $type,
        string $scope,
        bool $immediate = true,
        ?DateTimeImmutable $now = null,
    ): int {
        $key = self::key($type, $scope);
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return 0;
        }
        $entry->detach($clientId);
        if ($entry->clientCount() === 0 && $immediate) {
            $this->store->delete($entry->id);
            unset($this->entries[$key]);

            return 0;
        }
        if ($entry->clientCount() === 0) {
            $entry->expiresAt = $this->expires($now ?? self::now());
        }
        $this->store->save($entry->dto());

        return $entry->clientCount();
    }

    public function pauseFocus(string $clientId, string $scope, ?DateTimeImmutable $now = null): ?Watch
    {
        $entry = $this->entries[self::key(WatchType::Focus, $scope)] ?? null;
        if ($entry === null || !$entry->hasClient($clientId)) {
            return null;
        }
        $entry->paused = true;
        $entry->priority = WatchPriority::Vehicle;
        $entry->expiresAt = $this->expires($now ?? self::now());
        $this->store->save($entry->dto());

        return $entry->dto();
    }

    public function resumeFocus(string $clientId, string $scope, ?DateTimeImmutable $now = null): ?Watch
    {
        $entry = $this->entries[self::key(WatchType::Focus, $scope)] ?? null;
        if ($entry === null || !$entry->hasClient($clientId)) {
            return null;
        }
        $entry->paused = false;
        $entry->priority = WatchPriority::Focus;
        $entry->state = WatchState::Active;
        $entry->nextRefreshAt = $now ?? self::now();
        $entry->expiresAt = $this->expires($now ?? self::now());
        $this->store->save($entry->dto());

        return $entry->dto();
    }

    public function detachClient(string $clientId, ?DateTimeImmutable $now = null): void
    {
        $now ??= self::now();
        foreach ($this->entries as $entry) {
            if (!$entry->hasClient($clientId)) {
                continue;
            }
            $entry->detach($clientId);
            if ($entry->clientCount() === 0) {
                $entry->expiresAt = $this->expires($now);
            }
            $this->store->save($entry->dto());
        }
    }

    /** @return list<Watch> */
    public function due(?DateTimeImmutable $now = null): array
    {
        $now ??= self::now();
        $due = [];
        foreach ($this->entries as $entry) {
            if ($entry->expiresAt <= $now || $entry->clientCount() === 0 || $entry->state === WatchState::Expired) {
                continue;
            }
            if ($entry->nextRefreshAt === null || $entry->nextRefreshAt <= $now) {
                $due[] = $entry->dto();
            }
        }
        usort($due, static fn(Watch $left, Watch $right): int => self::priority($right->priority) <=> self::priority($left->priority)
            ?: $left->scope <=> $right->scope);

        return $due;
    }

    public function markRefreshed(string $watchId, ?DateTimeImmutable $now = null): void
    {
        $entry = $this->findById($watchId);
        if ($entry === null) {
            return;
        }
        $now ??= self::now();
        $entry->lastRefreshAt = $now;
        $entry->nextRefreshAt = $now->add(new DateInterval('PT' . $this->refreshSeconds($entry) . 'S'));
        if ($entry->clientCount() > 0) {
            $entry->expiresAt = $this->expires($now);
        }
        $entry->state = WatchState::Active;
        $entry->lastErrorCode = null;
        $this->store->save($entry->dto());
    }

    public function markFailed(
        string $watchId,
        string $errorCode,
        ?DateTimeImmutable $retryAt = null,
        ?DateTimeImmutable $now = null,
    ): void {
        $entry = $this->findById($watchId);
        if ($entry === null) {
            return;
        }
        $now ??= self::now();
        $entry->state = $retryAt === null ? WatchState::Failed : WatchState::Backoff;
        $entry->lastErrorCode = $errorCode;
        $entry->nextRefreshAt = $retryAt ?? $now->add(new DateInterval('PT15S'));
        if ($entry->clientCount() > 0) {
            $entry->expiresAt = $this->expires($now);
        }
        $this->store->save($entry->dto());
    }

    /** @return list<string> */
    public function expire(?DateTimeImmutable $now = null): array
    {
        $now ??= self::now();
        $expired = [];
        foreach ($this->entries as $key => $entry) {
            if ($entry->expiresAt > $now) {
                continue;
            }
            if ($entry->clientCount() > 0) {
                $entry->expiresAt = $this->expires($now);
                $this->store->save($entry->dto());
                continue;
            }
            $expired[] = $entry->scope;
            $this->store->delete($entry->id);
            unset($this->entries[$key]);
        }

        return $expired;
    }

    /** @return list<Watch> */
    public function all(): array
    {
        $watches = array_map(static fn(ActiveWatch $entry): Watch => $entry->dto(), array_values($this->entries));
        usort($watches, static fn(Watch $left, Watch $right): int => $left->scope <=> $right->scope);

        return $watches;
    }

    public function count(WatchType $type): int
    {
        return count(array_filter($this->entries, static fn(ActiveWatch $entry): bool => $entry->type === $type));
    }

    private function findById(string $watchId): ?ActiveWatch
    {
        foreach ($this->entries as $entry) {
            if ($entry->id === $watchId) {
                return $entry;
            }
        }

        return null;
    }

    private function refreshSeconds(ActiveWatch $entry): int
    {
        return match ($entry->priority) {
            WatchPriority::Focus => 3,
            WatchPriority::Vehicle => 5,
            WatchPriority::Station => 15,
            WatchPriority::Background => 60,
        };
    }

    private function expires(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
    }

    private static function key(WatchType $type, string $scope): string
    {
        return $type->value . '|' . $scope;
    }

    private static function watchId(WatchType $type, string $scope): string
    {
        return 'watch_' . substr(hash('sha256', $type->value . '|' . $scope), 0, 32);
    }

    private static function priority(WatchPriority $priority): int
    {
        return match ($priority) {
            WatchPriority::Background => 10,
            WatchPriority::Station => 30,
            WatchPriority::Vehicle => 60,
            WatchPriority::Focus => 100,
        };
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
