<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $provided = $request->getHeaderLine('X-Request-Id');
        $requestId = preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $provided) === 1
            ? $provided
            : 'req_' . bin2hex(random_bytes(8));

        return $handler->handle($request->withAttribute('requestId', $requestId))
            ->withHeader('X-Request-Id', $requestId);
    }
}
