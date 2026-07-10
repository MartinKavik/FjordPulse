<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use FjordPulse\Http\JsonErrorResponse;
use FjordPulse\Security\SignedToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdminAuthMiddleware implements MiddlewareInterface
{
    public const string COOKIE = 'fjordpulse_admin';

    public function __construct(private SignedToken $tokens)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/admin')) {
            return $handler->handle($request);
        }
        if ($path === '/api/admin/session' && strtoupper($request->getMethod()) === 'POST') {
            return $handler->handle($request);
        }
        $cookies = $request->getCookieParams();
        $token = is_string($cookies[self::COOKIE] ?? null) ? $cookies[self::COOKIE] : '';
        $claims = $this->tokens->verify($token, 'admin-session');
        if ($claims === null || !is_string($claims['username'] ?? null)) {
            $requestId = $request->getAttribute('requestId');

            return JsonErrorResponse::create(401, 'admin_unauthorized', 'Admin authentication is required.', [], is_string($requestId) ? $requestId : null);
        }

        return $handler->handle(
            $request
                ->withAttribute('adminUsername', $claims['username'])
                ->withAttribute('adminClaims', $claims),
        );
    }
}
