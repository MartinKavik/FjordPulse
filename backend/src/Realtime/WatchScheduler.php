<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\SourceUnavailable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class WatchScheduler
{
    public const int SOURCE_RETRY_SECONDS = 15;

    private bool $running = false;
    private readonly LoggerInterface $logger;

    /** @var (\Closure(Watch, \Throwable): void)|null */
    private readonly ?\Closure $onFailure;

    /** @var \Closure(): int */
    private readonly \Closure $monotonicClock;

    /**
     * @param (\Closure(Watch, \Throwable): void)|null $onFailure
     * @param (\Closure(): int)|null $monotonicClock
     */
    public function __construct(
        private readonly ActiveWatchRegistry $registry,
        private readonly WatchRefreshHandler $refreshHandler,
        ?LoggerInterface $logger = null,
        ?\Closure $onFailure = null,
        ?\Closure $monotonicClock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->onFailure = $onFailure;
        $this->monotonicClock = $monotonicClock ?? static fn(): int => hrtime(true);
    }

    public function tick(?DateTimeImmutable $now = null): void
    {
        if ($this->running) {
            return;
        }
        $now ??= new DateTimeImmutable();
        $startedAt = ($this->monotonicClock)();
        $this->running = true;
        try {
            $this->registry->expire($now);
            $groups = [];
            foreach ($this->registry->due($now) as $watch) {
                $key = $watch->type === WatchType::Station
                    ? 'station:' . $watch->entityId
                    : 'vehicle:' . $watch->entityId;
                $groups[$key][] = $watch;
            }
            foreach ($groups as $group) {
                $primary = $group[0];
                try {
                    $this->refreshHandler->refresh($primary);
                    foreach ($group as $watch) {
                        $this->registry->markRefreshed($watch->id, $now);
                    }
                } catch (\Throwable $error) {
                    $failedAt = $this->completionTime($now, $startedAt);
                    $retryAt = match (true) {
                        $error instanceof RateLimited => $error->retryAt,
                        $error instanceof SourceUnavailable => $failedAt->add(new DateInterval('PT' . self::SOURCE_RETRY_SECONDS . 'S')),
                        default => null,
                    };
                    $errorCode = match (true) {
                        $error instanceof RateLimited => 'rate_limited',
                        $error instanceof SourceUnavailable => 'source_unavailable',
                        default => 'refresh_failed',
                    };
                    foreach ($group as $watch) {
                        $this->registry->markFailed($watch->id, $errorCode, $retryAt, $failedAt);
                    }
                    $this->logger->warning('Demand-driven watch refresh failed.', [
                        'scope' => $primary->scope,
                        'watchType' => $primary->type->value,
                        'errorCode' => $errorCode,
                        'error' => $error->getMessage(),
                    ]);
                    if ($this->onFailure !== null) {
                        ($this->onFailure)($primary, $error);
                    }
                }
            }
        } finally {
            $this->running = false;
        }
    }

    private function completionTime(DateTimeImmutable $startedAt, int $startedNanoseconds): DateTimeImmutable
    {
        $elapsedNanoseconds = max(0, ($this->monotonicClock)() - $startedNanoseconds);
        $elapsedSeconds = intdiv($elapsedNanoseconds, 1_000_000_000);
        if ($elapsedSeconds === 0) {
            return $startedAt;
        }
        if ($elapsedNanoseconds % 1_000_000_000 !== 0) {
            $elapsedSeconds++;
        }

        return $startedAt->add(new DateInterval('PT' . $elapsedSeconds . 'S'));
    }
}
