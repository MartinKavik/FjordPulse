<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Domain\WatchState;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\SourceUnavailable;
use FjordPulse\Realtime\ActiveWatchRegistry;
use FjordPulse\Realtime\WatchRefreshHandler;
use FjordPulse\Realtime\WatchScheduler;
use FjordPulse\Realtime\WatchStore;
use PHPUnit\Framework\TestCase;

final class RealtimeWatchRegistryTest extends TestCase
{
    public function testClientsShareOneStationWatchAndDisconnectUsesTtl(): void
    {
        $store = new RecordingWatchStore();
        $registry = new ActiveWatchRegistry($store, 60);
        $now = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $first = $registry->acquire('client-a', WatchType::Station, 'station:NSR:StopPlace:548', 'NSR:StopPlace:548', WatchPriority::Station, $now);
        $second = $registry->acquire('client-b', WatchType::Station, 'station:NSR:StopPlace:548', 'NSR:StopPlace:548', WatchPriority::Station, $now);

        self::assertSame($first->id, $second->id);
        self::assertSame(2, $second->clientCount);
        self::assertSame(1, count($registry->all()));
        self::assertSame(1, $registry->release('client-a', WatchType::Station, 'station:NSR:StopPlace:548'));

        $registry->detachClient('client-b', $now);
        self::assertSame(0, $registry->all()[0]->clientCount);
        self::assertSame(WatchState::Expired, $registry->all()[0]->state);
        self::assertSame([], $registry->expire(new DateTimeImmutable('2026-07-10T10:00:59Z')));
        self::assertSame(['station:NSR:StopPlace:548'], $registry->expire(new DateTimeImmutable('2026-07-10T10:01:00Z')));
        self::assertContains($first->id, $store->deleted);
    }

    public function testDisconnectGraceWatchBecomesActiveWhenReacquiredBeforeTtl(): void
    {
        $registry = new ActiveWatchRegistry(new RecordingWatchStore(), 60);
        $now = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $scope = 'vehicle:SKY:Vehicle:12345';
        $first = $registry->acquire('client-a', WatchType::Vehicle, $scope, 'SKY:Vehicle:12345', WatchPriority::Vehicle, $now);

        $registry->detachClient('client-a', $now);
        $reacquired = $registry->acquire(
            'client-b',
            WatchType::Vehicle,
            $scope,
            'SKY:Vehicle:12345',
            WatchPriority::Vehicle,
            $now->add(new DateInterval('PT30S')),
        );

        self::assertSame($first->id, $reacquired->id);
        self::assertSame(1, $reacquired->clientCount);
        self::assertSame(WatchState::Active, $reacquired->state);
        self::assertSame('2026-07-10T10:01:30.000+00:00', $reacquired->expiresAt->format(DATE_RFC3339_EXTENDED));
    }

    public function testCompletedRefreshCannotReactivateAWatchAfterItsLastClientDisconnects(): void
    {
        $registry = new ActiveWatchRegistry(new RecordingWatchStore(), 60);
        $now = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $watch = $registry->acquire(
            'client-a',
            WatchType::Focus,
            'focus:client-a:SKY:Vehicle:12345',
            'SKY:Vehicle:12345',
            WatchPriority::Focus,
            $now,
        );

        $registry->detachClient('client-a', $now);
        $registry->markRefreshed($watch->id, $now->add(new DateInterval('PT1S')));

        $detached = $registry->all()[0];
        self::assertSame(0, $detached->clientCount);
        self::assertSame(WatchState::Expired, $detached->state);
        self::assertSame('2026-07-10T10:01:00.000+00:00', $detached->expiresAt->format(DATE_RFC3339_EXTENDED));
    }

    public function testFocusPauseResumeChangesPriorityAndRefreshDemand(): void
    {
        $registry = new ActiveWatchRegistry(new RecordingWatchStore(), 60);
        $now = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $scope = 'focus:client-a:SKY:Vehicle:12345';
        $watch = $registry->acquire('client-a', WatchType::Focus, $scope, 'SKY:Vehicle:12345', WatchPriority::Focus, $now);
        self::assertSame(WatchPriority::Focus, $watch->priority);

        self::assertSame(WatchPriority::Vehicle, $registry->pauseFocus('client-a', $scope, $now)?->priority);
        self::assertSame(WatchPriority::Focus, $registry->resumeFocus('client-a', $scope, $now)?->priority);
        self::assertSame($watch->id, $registry->due($now)[0]->id);
    }

    public function testSchedulerDeduplicatesVehicleAndFocusUpstreamRefresh(): void
    {
        $registry = new ActiveWatchRegistry(new RecordingWatchStore(), 60);
        $now = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $registry->acquire('client-a', WatchType::Vehicle, 'vehicle:SKY:Vehicle:12345', 'SKY:Vehicle:12345', WatchPriority::Vehicle, $now);
        $registry->acquire('client-b', WatchType::Focus, 'focus:client-b:SKY:Vehicle:12345', 'SKY:Vehicle:12345', WatchPriority::Focus, $now);
        $handler = new RecordingRefreshHandler();

        (new WatchScheduler($registry, $handler))->tick($now);

        self::assertCount(1, $handler->refreshed);
        self::assertSame(WatchType::Focus, $handler->refreshed[0]->type);
        self::assertSame([], $registry->due($now));
        $focus = array_values(array_filter(
            $registry->all(),
            static fn(Watch $watch): bool => $watch->type === WatchType::Focus,
        ));
        self::assertSame(
            $now->add(new DateInterval('PT3S'))->format(DATE_RFC3339_EXTENDED),
            $focus[0]->nextRefreshAt?->format(DATE_RFC3339_EXTENDED),
        );
    }

    public function testSchedulerStartsSourceRetryDelayAfterSlowFailureCompletes(): void
    {
        $registry = new ActiveWatchRegistry(new RecordingWatchStore(), 60);
        $startedAt = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $registry->acquire('client-a', WatchType::Station, 'station:NSR:StopPlace:548', 'NSR:StopPlace:548', WatchPriority::Station, $startedAt);
        $monotonicTimes = [1_000_000_000, 21_000_000_000];
        $scheduler = new WatchScheduler(
            $registry,
            new FailingRefreshHandler(new SourceUnavailable('Controlled timeout.')),
            monotonicClock: static function () use (&$monotonicTimes): int {
                return array_shift($monotonicTimes) ?? 21_000_000_000;
            },
        );

        $scheduler->tick($startedAt);

        $watch = $registry->all()[0];
        self::assertSame(
            $startedAt->add(new DateInterval('PT35S'))->format(DATE_RFC3339_EXTENDED),
            $watch->nextRefreshAt?->format(DATE_RFC3339_EXTENDED),
        );
        self::assertSame([], $registry->due($startedAt->add(new DateInterval('PT34S'))));
        self::assertCount(1, $registry->due($startedAt->add(new DateInterval('PT35S'))));
    }

    public function testSchedulerPreservesExplicitRateLimitBoundaryAfterSlowFailure(): void
    {
        $registry = new ActiveWatchRegistry(new RecordingWatchStore(), 60);
        $startedAt = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $retryAt = $startedAt->add(new DateInterval('PT60S'));
        $registry->acquire('client-a', WatchType::Station, 'station:NSR:StopPlace:548', 'NSR:StopPlace:548', WatchPriority::Station, $startedAt);
        $monotonicTimes = [1_000_000_000, 21_000_000_000];
        $scheduler = new WatchScheduler(
            $registry,
            new FailingRefreshHandler(new RateLimited($retryAt)),
            monotonicClock: static function () use (&$monotonicTimes): int {
                return array_shift($monotonicTimes) ?? 21_000_000_000;
            },
        );

        $scheduler->tick($startedAt);

        self::assertSame(
            $retryAt->format(DATE_RFC3339_EXTENDED),
            $registry->all()[0]->nextRefreshAt?->format(DATE_RFC3339_EXTENDED),
        );
    }
}

final class RecordingWatchStore implements WatchStore
{
    /** @var array<string, Watch> */
    public array $saved = [];

    /** @var list<string> */
    public array $deleted = [];

    public function save(Watch $watch): void
    {
        $this->saved[$watch->id] = $watch;
    }

    public function delete(string $watchId): void
    {
        $this->deleted[] = $watchId;
        unset($this->saved[$watchId]);
    }
}

final class RecordingRefreshHandler implements WatchRefreshHandler
{
    /** @var list<Watch> */
    public array $refreshed = [];

    public function refresh(Watch $watch): void
    {
        $this->refreshed[] = $watch;
    }
}

final readonly class FailingRefreshHandler implements WatchRefreshHandler
{
    public function __construct(private \Throwable $error)
    {
    }

    public function refresh(Watch $watch): void
    {
        unset($watch);

        throw $this->error;
    }
}
