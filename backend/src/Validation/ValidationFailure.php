<?php

declare(strict_types=1);

namespace FjordPulse\Validation;

use InvalidArgumentException;

final class ValidationFailure extends InvalidArgumentException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
