<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use Amp\Websocket\WebsocketClient;

final readonly class AmpClientConnection implements ClientConnection
{
    public function __construct(private WebsocketClient $client)
    {
    }

    public function id(): int
    {
        return $this->client->getId();
    }

    public function send(string $message): void
    {
        $this->client->sendText($message);
    }

    public function close(int $code, string $reason): void
    {
        $this->client->close($code, $reason);
    }

    public function closed(): bool
    {
        return $this->client->isClosed();
    }
}
