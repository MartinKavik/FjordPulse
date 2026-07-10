<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class MessageRateLimiter
{
    /** @var list<float> */
    private array $timestamps = [];

    public function __construct(
        public readonly int $limit = 30,
        public readonly float $windowSeconds = 10.0,
    ) {
        if ($limit < 1 || $windowSeconds <= 0.0) {
            throw new \InvalidArgumentException('Realtime rate limit must be positive.');
        }
    }

    public function allow(?float $now = null): bool
    {
        $now ??= microtime(true);
        $threshold = $now - $this->windowSeconds;
        $this->timestamps = array_values(array_filter(
            $this->timestamps,
            static fn(float $timestamp): bool => $timestamp > $threshold,
        ));
        if (count($this->timestamps) >= $this->limit) {
            return false;
        }
        $this->timestamps[] = $now;

        return true;
    }

    public function remaining(): int
    {
        return max(0, $this->limit - count($this->timestamps));
    }

    public function retryAt(): string
    {
        $seconds = $this->timestamps === []
            ? $this->windowSeconds
            : max(0.0, $this->timestamps[0] + $this->windowSeconds - microtime(true));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->modify(sprintf('+%d seconds', (int)ceil($seconds)))->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
