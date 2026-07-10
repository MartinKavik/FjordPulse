<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final readonly class RealtimeHttpHandler implements RequestHandler
{
    /** @var \Closure(): array<string, mixed> */
    private \Closure $health;

    /** @param \Closure(): array<string, mixed> $health */
    public function __construct(
        private RequestHandler $websocket,
        \Closure $health,
    ) {
        $this->health = $health;
    }

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        if ($path === '/live') {
            return $this->websocket->handleRequest($request);
        }
        if ($path === '/health' || $path === '/health/realtime') {
            return new Response(HttpStatus::OK, [
                'content-type' => 'application/json; charset=utf-8',
                'cache-control' => 'no-store',
            ], EnvelopeFactory::encode(($this->health)()));
        }

        return new Response(HttpStatus::NOT_FOUND, [
            'content-type' => 'application/json; charset=utf-8',
        ], EnvelopeFactory::encode(['error' => 'not_found']));
    }
}
