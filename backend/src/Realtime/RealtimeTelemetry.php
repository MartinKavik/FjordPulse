<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class RealtimeTelemetry
{
    private const int MESSAGE_WINDOW_MICROSECONDS = 60_000_000;

    private readonly DateTimeImmutable $startedAt;
    /** @var \Closure(): DateTimeImmutable */
    private readonly \Closure $clock;
    private int $activeClients = 0;
    private int $connectionsAccepted = 0;
    private int $connectionsClosed = 0;
    private int $messagesReceived = 0;
    private int $messagesSent = 0;
    private int $messagesRejected = 0;
    private int $rateLimited = 0;
    private int $broadcasts = 0;
    private int $sendFailures = 0;
    private int $bridgeRecoveries = 0;
    private ?DateTimeImmutable $lastBroadcastAt = null;
    private ?DateTimeImmutable $lastMessageAt = null;
    private ?DateTimeImmutable $enturBackoffUntil = null;
    private ?DateTimeImmutable $enturObservedAt = null;
    private string $enturState;

    /** @var list<array{at: int, count: int}> */
    private array $receivedWindow = [];

    /** @var list<array{at: int, count: int}> */
    private array $sentWindow = [];

    /** @param (\Closure(): DateTimeImmutable)|null $clock */
    public function __construct(private readonly string $dataMode = 'real', ?\Closure $clock = null)
    {
        if (!in_array($dataMode, ['real', 'fake'], true)) {
            throw new \InvalidArgumentException('Realtime telemetry data mode must be real or fake.');
        }
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->startedAt = $this->now();
        $this->enturState = $dataMode === 'fake' ? 'not_used' : 'idle';
    }

    public function connected(): void
    {
        $this->activeClients++;
        $this->connectionsAccepted++;
    }

    public function disconnected(): void
    {
        $this->activeClients = max(0, $this->activeClients - 1);
        $this->connectionsClosed++;
    }

    public function received(): void
    {
        $this->messagesReceived++;
        $now = $this->now();
        $this->recordWindow($this->receivedWindow, 1, $now);
        $this->lastMessageAt = $now;
    }

    public function sent(int $count = 1): void
    {
        $count = max(0, $count);
        $this->messagesSent += $count;
        $this->recordWindow($this->sentWindow, $count, $this->now());
    }

    public function rejected(): void
    {
        $this->messagesRejected++;
    }

    public function limited(): void
    {
        $this->rateLimited++;
    }

    public function broadcast(int $recipients): void
    {
        $recipients = max(0, $recipients);
        $this->broadcasts++;
        $this->messagesSent += $recipients;
        if ($recipients > 0) {
            $now = $this->now();
            $this->recordWindow($this->sentWindow, $recipients, $now);
            $this->lastBroadcastAt = $now;
        }
    }

    public function sendFailed(): void
    {
        $this->sendFailures++;
    }

    public function bridgeRecovered(): void
    {
        $this->bridgeRecoveries++;
    }

    public function sourceBackoff(DateTimeImmutable $retryAt): void
    {
        if ($this->enturBackoffUntil === null || $retryAt > $this->enturBackoffUntil) {
            $this->enturBackoffUntil = $retryAt;
        }
        if ($this->dataMode === 'real') {
            $this->enturObservedAt = $this->now();
            $this->enturState = 'backoff';
        }
    }

    public function sourceOutcome(string $outcome, ?DateTimeImmutable $retryAt = null): void
    {
        if ($this->dataMode === 'fake') {
            return;
        }
        $this->enturObservedAt = $this->now();
        $this->enturState = match ($outcome) {
            'success', 'cache_hit' => 'ok',
            'rate_limited' => 'rate_limited',
            'backoff', 'skipped_budget' => 'backoff',
            'timeout', 'error' => 'delayed',
            default => 'idle',
        };
        if ($retryAt !== null && ($this->enturBackoffUntil === null || $retryAt > $this->enturBackoffUntil)) {
            $this->enturBackoffUntil = $retryAt;
        }
        if (in_array($outcome, ['success', 'cache_hit'], true)) {
            $this->enturBackoffUntil = null;
        }
    }

    public function enturState(): string
    {
        if ($this->dataMode === 'fake') {
            return 'not_used';
        }
        $now = $this->now();
        if ($this->enturBackoffUntil !== null && $this->enturBackoffUntil > $now) {
            return $this->enturState === 'rate_limited' ? 'rate_limited' : 'backoff';
        }
        if ($this->enturObservedAt === null || $this->enturObservedAt < $now->modify('-5 minutes')) {
            return 'idle';
        }

        return in_array($this->enturState, ['backoff', 'rate_limited'], true) ? 'idle' : $this->enturState;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        $now = $this->now();

        return [
            'startedAt' => self::format($this->startedAt),
            'activeClients' => $this->activeClients,
            'connectionsAccepted' => $this->connectionsAccepted,
            'connectionsClosed' => $this->connectionsClosed,
            'messagesReceived' => $this->messagesReceived,
            'messagesSent' => $this->messagesSent,
            'messagesReceivedLastMinute' => $this->windowCount($this->receivedWindow, $now),
            'messagesSentLastMinute' => $this->windowCount($this->sentWindow, $now),
            'messagesRejected' => $this->messagesRejected,
            'rateLimited' => $this->rateLimited,
            'broadcasts' => $this->broadcasts,
            'sendFailures' => $this->sendFailures,
            'bridgeRecoveries' => $this->bridgeRecoveries,
            'lastBroadcastAt' => $this->lastBroadcastAt === null ? null : self::format($this->lastBroadcastAt),
            'lastMessageAt' => $this->lastMessageAt === null ? null : self::format($this->lastMessageAt),
            'enturBackoffUntil' => $this->enturBackoffUntil === null ? null : self::format($this->enturBackoffUntil),
        ];
    }

    public function activeClients(): int
    {
        return $this->activeClients;
    }

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();

        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    /** @param list<array{at: int, count: int}> $window */
    private function recordWindow(array &$window, int $count, DateTimeImmutable $now): void
    {
        if ($count < 1) {
            return;
        }
        $timestamp = self::microsecondTimestamp($now);
        $lastIndex = array_key_last($window);
        if ($lastIndex !== null && $window[$lastIndex]['at'] === $timestamp) {
            $window[$lastIndex]['count'] += $count;
        } else {
            $window[] = ['at' => $timestamp, 'count' => $count];
        }
        $this->pruneWindow($window, $now);
    }

    /** @param list<array{at: int, count: int}> $window */
    private function windowCount(array &$window, DateTimeImmutable $now): int
    {
        $this->pruneWindow($window, $now);

        return array_sum(array_column($window, 'count'));
    }

    /** @param list<array{at: int, count: int}> $window */
    private function pruneWindow(array &$window, DateTimeImmutable $now): void
    {
        $cutoff = self::microsecondTimestamp($now) - self::MESSAGE_WINDOW_MICROSECONDS;
        while (($window[0]['at'] ?? null) !== null && $window[0]['at'] <= $cutoff) {
            array_shift($window);
        }
    }

    private static function microsecondTimestamp(DateTimeImmutable $date): int
    {
        return ((int)$date->format('U') * 1_000_000) + (int)$date->format('u');
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
