<?php

declare(strict_types=1);

namespace FjordPulse\Http;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $retryAfterSeconds,
    ) {
        if ($retryAfterSeconds < 0) {
            throw new \InvalidArgumentException('Rate-limit retry delay cannot be negative.');
        }
    }
}
