<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\WebsocketAcceptor;

final readonly class SecuredWebsocketAcceptor implements WebsocketAcceptor
{
    public const string TOKEN_CLAIMS_ATTRIBUTE = self::class . '.tokenClaims';

    /** @param list<string> $allowedOrigins */
    public function __construct(
        private array $allowedOrigins,
        private TokenVerifier $tokens,
        private WebsocketAcceptor $delegate = new Rfc6455Acceptor(),
        private string $path = '/live',
    ) {
        if ($allowedOrigins === []) {
            throw new \InvalidArgumentException('At least one WebSocket origin must be allowed.');
        }
        foreach ($allowedOrigins as $origin) {
            if (filter_var($origin, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException('Allowed WebSocket origin is invalid.');
            }
        }
    }

    public function handleHandshake(Request $request): Response
    {
        if ($request->getUri()->getPath() !== $this->path) {
            return self::reject(HttpStatus::NOT_FOUND, 'WebSocket endpoint not found.');
        }
        $origin = $request->getHeader('origin');
        if ($origin === null || !in_array($origin, $this->allowedOrigins, true)) {
            return self::reject(HttpStatus::FORBIDDEN, 'Origin forbidden.');
        }
        $tokenValues = $request->getQueryParameterArray('token');
        if (count($tokenValues) !== 1 || $tokenValues[0] === '' || strlen($tokenValues[0]) > 4096) {
            return self::reject(HttpStatus::UNAUTHORIZED, 'Realtime token required.');
        }
        $claims = $this->tokens->verify($tokenValues[0]);
        if ($claims === null) {
            return self::reject(HttpStatus::UNAUTHORIZED, 'Realtime token is invalid or expired.');
        }
        $request->setAttribute(self::TOKEN_CLAIMS_ATTRIBUTE, $claims);

        return $this->delegate->handleHandshake($request);
    }

    private static function reject(int $status, string $message): Response
    {
        return new Response($status, [
            'content-type' => 'text/plain; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
        ], $message);
    }
}
