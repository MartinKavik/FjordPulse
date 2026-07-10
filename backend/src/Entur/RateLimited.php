<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use RuntimeException;

final class RateLimited extends RuntimeException
{
    public function __construct(public readonly DateTimeImmutable $retryAt, string $message = 'Entur request budget is in backoff.')
    {
        parent::__construct($message);
    }
}
