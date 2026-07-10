<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

final readonly class ClientMessage
{
    /** @param array<string, string> $payload */
    public function __construct(
        public string $id,
        public ClientMessageType $type,
        public array $payload,
    ) {
    }

    public function stationId(): string
    {
        return $this->payload['stationId'] ?? throw new \LogicException('Message has no station identifier.');
    }

    public function vehicleId(): string
    {
        return $this->payload['vehicleId'] ?? throw new \LogicException('Message has no vehicle identifier.');
    }
}
