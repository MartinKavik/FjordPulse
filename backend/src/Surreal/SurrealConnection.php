<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use SurrealDB\SDK\Live\LiveMessage;
use SurrealDB\SDK\Protocol\Feature;

interface SurrealConnection
{
    /**
     * @param array<string, mixed> $bindings
     * @return list<mixed>
     */
    public function run(string $surql, array $bindings = []): array;

    /** @return iterable<LiveMessage<mixed>> */
    public function live(string $queryId): iterable;

    /**
     * Subscribe through the SDK's PSR-14 live-message event seam. This avoids
     * alpha.1's non-interruptible iterable waiter while its background reader
     * remains the sole WebSocket consumer.
     *
     * @return \Closure(): void
     */
    public function subscribeLiveMessages(string $queryId, callable $listener): \Closure;

    public function supports(Feature $feature): bool;

    /** @return \Closure(): void */
    public function subscribe(string $event, callable $listener): \Closure;

    public function isConnected(): bool;

    public function version(): string;

    public function health(): void;

    public function close(): void;
}
