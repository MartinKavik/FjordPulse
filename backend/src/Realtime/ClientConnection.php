<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

interface ClientConnection
{
    public function id(): int;

    public function send(string $message): void;

    public function close(int $code, string $reason): void;

    public function closed(): bool;
}
