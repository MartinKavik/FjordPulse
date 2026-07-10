<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use FjordPulse\Domain\WatchType;
use FjordPulse\Dto\Watch;
use FjordPulse\Entur\RateLimited;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class WatchScheduler
{
    private bool $running = false;
    private readonly LoggerInterface $logger;

    /** @var (\Closure(Watch, \Throwable): void)|null */
    private readonly ?\Closure $onFailure;

    /**
     * @param (\Closure(Watch, \Throwable): void)|null $onFailure
     */
    public function __construct(
        private readonly ActiveWatchRegistry $registry,
        private readonly WatchRefreshHandler $refreshHandler,
        ?LoggerInterface $logger = null,
        ?\Closure $onFailure = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->onFailure = $onFailure;
    }

    public function tick(?DateTimeImmutable $now = null): void
    {
        if ($this->running) {
            return;
        }
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
                    $retryAt = $error instanceof RateLimited ? $error->retryAt : null;
                    $errorCode = $error instanceof RateLimited ? 'rate_limited' : 'refresh_failed';
                    foreach ($group as $watch) {
                        $this->registry->markFailed($watch->id, $errorCode, $retryAt, $now);
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
}
