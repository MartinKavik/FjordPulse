<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use Cake\Http\Response;
use FjordPulse\Http\JsonErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CorsMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private array $allowedOrigins)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && !in_array($origin, $this->allowedOrigins, true)) {
            $requestId = $request->getAttribute('requestId');

            return JsonErrorResponse::create(403, 'origin_forbidden', 'Request origin is not allowed.', [], is_string($requestId) ? $requestId : null);
        }

        $response = strtoupper($request->getMethod()) === 'OPTIONS' ? (new Response())->withStatus(204) : $handler->handle($request);
        if ($origin !== '') {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-Request-Id')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
