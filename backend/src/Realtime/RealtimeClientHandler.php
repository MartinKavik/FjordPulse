<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use Amp\ByteStream\BufferException;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseCode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class RealtimeClientHandler implements WebsocketClientHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private RoomRegistry $rooms,
        private ProtocolRouter $router,
        private int $maximumMessageBytes = 65_536,
        private int $messagesPerWindow = 30,
        private float $rateWindowSeconds = 10.0,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        unset($response);
        $connection = new AmpClientConnection($client);
        $claimsValue = $request->hasAttribute(SecuredWebsocketAcceptor::TOKEN_CLAIMS_ATTRIBUTE)
            ? $request->getAttribute(SecuredWebsocketAcceptor::TOKEN_CLAIMS_ATTRIBUTE)
            : [];
        $claims = self::claims($claimsValue);
        $session = new ClientSession(
            $connection->id(),
            'client-' . $connection->id(),
            $claims,
            new MessageRateLimiter($this->messagesPerWindow, $this->rateWindowSeconds),
        );
        $this->rooms->connect($connection);
        $this->logger->info('Realtime client connected.', [
            'clientId' => $connection->id(),
            'sessionId' => $session->sessionId,
            'remoteAddress' => $client->getRemoteAddress()->toString(),
        ]);

        try {
            while (($message = $client->receive()) !== null) {
                if (!$message->isText()) {
                    $client->close(WebsocketCloseCode::UNACCEPTABLE_TYPE, 'Only text messages are accepted.');
                    break;
                }
                try {
                    $raw = $message->buffer(limit: $this->maximumMessageBytes);
                } catch (BufferException) {
                    $client->close(WebsocketCloseCode::MESSAGE_TOO_LARGE, 'Message exceeds the allowed size.');
                    break;
                }
                $this->router->handle($session, $raw);
            }
        } finally {
            $this->router->disconnect($session);
        }
    }

    /** @return array<string, mixed> */
    private static function claims(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $claims = [];
        foreach ($value as $key => $claim) {
            if (is_string($key)) {
                $claims[$key] = $claim;
            }
        }

        return $claims;
    }
}
