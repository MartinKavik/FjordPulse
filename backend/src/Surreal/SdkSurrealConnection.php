<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use SurrealDB\SDK\Protocol\Feature;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Events\LiveMessageReceived;

final readonly class SdkSurrealConnection implements SurrealConnection
{
    public function __construct(
        private Surreal $client,
        private SdkEventDispatcher $events,
    )
    {
    }

    public function run(string $surql, array $bindings = []): array
    {
        return $this->client->run($surql, $bindings);
    }

    public function live(string $queryId): iterable
    {
        return $this->client->live($queryId);
    }

    public function subscribeLiveMessages(string $queryId, callable $listener): \Closure
    {
        return $this->events->subscribe(
            LiveMessageReceived::class,
            static function (object $event) use ($queryId, $listener): void {
                if ($event instanceof LiveMessageReceived && $event->message->queryId === $queryId) {
                    $listener($event->message);
                }
            },
        );
    }

    public function supports(Feature $feature): bool
    {
        return $this->client->isFeatureSupported($feature);
    }

    public function subscribe(string $event, callable $listener): \Closure
    {
        return $this->client->subscribe($event, $listener);
    }

    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    public function version(): string
    {
        return $this->client->version();
    }

    public function health(): void
    {
        $this->client->health();
    }

    public function close(): void
    {
        $this->client->close();
    }

    public function sdk(): Surreal
    {
        return $this->client;
    }
}
