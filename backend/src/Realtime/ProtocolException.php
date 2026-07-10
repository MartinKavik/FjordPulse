<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

final class ProtocolException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly ?string $messageId = null,
    ) {
        parent::__construct($message);
    }
}
