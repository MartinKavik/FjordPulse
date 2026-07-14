<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use Cake\Log\Log;
use FjordPulse\Http\ClientAddressResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class StructuredAccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(private ClientAddressResolver $clientAddresses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startedAt = hrtime(true);
        $response = $handler->handle($request);
        $requestId = $request->getAttribute('requestId');

        Log::info(json_encode([
            'event' => 'http_request',
            'requestId' => is_string($requestId) ? $requestId : null,
            'clientAddress' => $this->clientAddresses->resolve($request),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'durationMs' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response;
    }
}
