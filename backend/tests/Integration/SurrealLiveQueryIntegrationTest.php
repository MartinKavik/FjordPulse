<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use Amp\TimeoutCancellation;
use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\RealtimeEvent;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Surreal\LiveQueryBridgeState;
use FjordPulse\Surreal\StationSnapshotRepository;
use FjordPulse\Surreal\SupervisedLiveQueryBridge;
use PHPUnit\Framework\Attributes\CoversNothing;

use function Amp\async;
use function Amp\delay;

#[CoversNothing]
final class SurrealLiveQueryIntegrationTest extends SurrealIntegrationTestCase
{
    public function testRuntimeAmpLiveSelectReceivesCommittedDatabaseEventWithoutBlockingTimers(): void
    {
        [$factory] = $this->database('live_query');
        $bridge = new SupervisedLiveQueryBridge($factory);
        $events = [];
        $ticks = 0;

        $bridgeFuture = async(function () use ($bridge, &$events): void {
            $bridge->run(function (RealtimeEvent $event) use (&$events): void {
                $events[] = $event;
            });
        });
        $tickerFuture = async(function () use (&$ticks): void {
            for ($index = 0; $index < 20; $index++) {
                delay(0.01);
                $ticks++;
            }
        });

        $this->waitUntil(
            static fn(): bool => $bridge->status()->state === LiveQueryBridgeState::Healthy,
            'Live-query bridge did not become healthy.',
            static fn(): array => $bridge->status()->toArray(),
        );

        $command = $factory->ampCommand();
        try {
            (new StationSnapshotRepository($command))->save(new StationSnapshot(
                'NSR:StopPlace:58366',
                '2026-07-10T11:00:00.000000Z',
                'live-query-snapshot',
                self::at('2026-07-10T11:00:00Z'),
                SourceState::Fresh,
                [],
                [],
                self::at('2026-07-10T11:00:00Z'),
            ));
        } finally {
            $command->close();
        }

        try {
            $this->waitUntil(
                static function () use (&$events): bool {
                    return $events !== [];
                },
                'Committed realtime_event was not delivered by LIVE SELECT.',
                static fn(): array => $bridge->status()->toArray(),
            );
        } finally {
            $bridge->stop();
        }
        $bridgeFuture->await(new TimeoutCancellation(8.0));
        $tickerFuture->await(new TimeoutCancellation(2.0));

        self::assertCount(1, $events);
        self::assertSame('station_snapshot_changed', $events[0]->type->value);
        self::assertSame('station:NSR:StopPlace:58366', $events[0]->scope);
        self::assertSame('2026-07-10T11:00:00.000000Z', $events[0]->version);
        self::assertSame(20, $ticks);
        self::assertSame(LiveQueryBridgeState::Stopped, $bridge->status()->state);
        self::assertSame(1, $bridge->status()->subscriptionCount);
        self::assertSame(0, $bridge->status()->failureCount);
    }

    public function testSupervisorRecreatesGlobalSubscriptionAfterRealDatabaseRestart(): void
    {
        [$factory] = $this->database('live_reconnect');
        $bridge = new SupervisedLiveQueryBridge(
            $factory,
            minimumRetryDelay: 0.05,
            maximumRetryDelay: 0.2,
        );
        $events = [];
        $recoveries = 0;
        $bridgeFuture = async(function () use ($bridge, &$events, &$recoveries): void {
            $bridge->run(
                static function (RealtimeEvent $event) use (&$events): void {
                    $events[] = $event;
                },
                static function () use (&$recoveries): void {
                    $recoveries++;
                },
            );
        });

        try {
            $this->waitUntil(
                static fn(): bool => $bridge->status()->state === LiveQueryBridgeState::Healthy,
                'Initial live-query subscription did not become healthy.',
                static fn(): array => $bridge->status()->toArray(),
            );

            self::stopServerForReconnect();
            $this->waitUntil(
                static fn(): bool => $bridge->status()->failureCount >= 1,
                'Bridge did not expose degraded state after SurrealDB stopped.',
                static fn(): array => $bridge->status()->toArray(),
            );

            self::startServerAfterReconnect();
            $this->waitUntil(
                static fn(): bool => $bridge->status()->state === LiveQueryBridgeState::Healthy
                    && $bridge->status()->subscriptionCount >= 2,
                'Bridge did not recreate LIVE SELECT after SurrealDB restarted.',
                static fn(): array => $bridge->status()->toArray(),
                15.0,
            );

            $command = $factory->ampCommand();
            try {
                (new StationSnapshotRepository($command))->save(new StationSnapshot(
                    'NSR:StopPlace:58366',
                    '2026-07-10T12:00:00.000000Z',
                    'recovered-snapshot',
                    self::at('2026-07-10T12:00:00Z'),
                    SourceState::Fresh,
                    [],
                    [],
                    self::at('2026-07-10T12:00:00Z'),
                ));
            } finally {
                $command->close();
            }

            $this->waitUntil(
                static function () use (&$events): bool {
                    return $events !== [];
                },
                'Recreated live query did not deliver events.',
                static fn(): array => $bridge->status()->toArray(),
            );
        } finally {
            // Ensure subsequent tests and teardown always have a live server.
            self::startServerAfterReconnect();
            $bridge->stop();
        }

        $bridgeFuture->await(new TimeoutCancellation(8.0));
        self::assertGreaterThanOrEqual(2, $bridge->status()->subscriptionCount);
        self::assertGreaterThanOrEqual(1, $bridge->status()->failureCount);
        self::assertGreaterThanOrEqual(1, $recoveries);
        self::assertCount(1, $events);
        self::assertSame('2026-07-10T12:00:00.000000Z', $events[0]->version);
        self::assertSame(LiveQueryBridgeState::Stopped, $bridge->status()->state);
    }

    /**
     * @param \Closure(): bool $condition
     * @param \Closure(): mixed $diagnostic
     */
    private function waitUntil(\Closure $condition, string $message, \Closure $diagnostic, float $timeout = 8.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$condition()) {
            if (microtime(true) >= $deadline) {
                self::fail($message . ' Status: ' . json_encode($diagnostic(), JSON_THROW_ON_ERROR));
            }
            delay(0.01);
        }
    }

    private static function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
