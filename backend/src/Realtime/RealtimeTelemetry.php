<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class RealtimeTelemetry
{
    private readonly DateTimeImmutable $startedAt;
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

    public function __construct(private readonly string $dataMode = 'real')
    {
        if (!in_array($dataMode, ['real', 'fake'], true)) {
            throw new \InvalidArgumentException('Realtime telemetry data mode must be real or fake.');
        }
        $this->startedAt = self::now();
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
        $this->lastMessageAt = self::now();
    }

    public function sent(int $count = 1): void
    {
        $this->messagesSent += max(0, $count);
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
        $this->broadcasts++;
        $this->messagesSent += max(0, $recipients);
        $this->lastBroadcastAt = self::now();
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
            $this->enturObservedAt = self::now();
            $this->enturState = 'backoff';
        }
    }

    public function sourceOutcome(string $outcome, ?DateTimeImmutable $retryAt = null): void
    {
        if ($this->dataMode === 'fake') {
            return;
        }
        $this->enturObservedAt = self::now();
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
        $now = self::now();
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
        return [
            'startedAt' => self::format($this->startedAt),
            'activeClients' => $this->activeClients,
            'connectionsAccepted' => $this->connectionsAccepted,
            'connectionsClosed' => $this->connectionsClosed,
            'messagesReceived' => $this->messagesReceived,
            'messagesSent' => $this->messagesSent,
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

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
