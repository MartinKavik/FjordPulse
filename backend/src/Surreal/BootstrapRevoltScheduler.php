<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use Revolt\EventLoop;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\Scheduler;
use SurrealDB\SDK\Scheduler\Amp\RevoltDeferred;

/**
 * Compatibility scheduler for surrealdb.php 2.0.0-alpha.1.
 *
 * WebSocketEngine performs bootstrap RPCs before spawning its read loop. The
 * stock RevoltScheduler ignores Deferred's optional drive closure, leaving the
 * first `version` RPC parked forever. Drive closures are queued only until the
 * engine starts background tasks; normal operation then matches the stock
 * scheduler and retains a single WebSocket reader.
 */
final class BootstrapRevoltScheduler implements Scheduler
{
    private bool $backgroundStarted = false;

    public function spawn(\Closure $task): void
    {
        $this->backgroundStarted = true;
        EventLoop::queue($task);
    }

    public function delay(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, static fn() => $suspension->resume());
        $suspension->suspend();
    }

    public function defer(?\Closure $drive = null): Deferred
    {
        $deferred = new RevoltDeferred();

        if (!$this->backgroundStarted && $drive !== null) {
            EventLoop::queue(static function () use ($drive): void {
                $drive();
            });
        }

        return $deferred;
    }
}
