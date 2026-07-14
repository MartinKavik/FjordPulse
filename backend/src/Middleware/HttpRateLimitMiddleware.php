<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use Cake\Routing\Route\Route;
use FjordPulse\Http\ClientAddressResolver;
use FjordPulse\Http\FileSlidingWindowRateLimiter;
use FjordPulse\Http\JsonErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $hashSecret,
        private readonly ClientAddressResolver $clientAddresses,
        private readonly FileSlidingWindowRateLimiter $limiter,
        private readonly int $limitPerMinute = 120,
        private readonly int $adminLoginLimitPerMinute = 60,
        private readonly int $demoDiagnosticsLimitPerMinute = 60,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute('route');
        $method = strtoupper($request->getMethod());
        if (!$route instanceof Route) {
            return $handler->handle($request);
        }
        $template = $route->template;
        $isAdminLogin = $method === 'POST' && $template === '/api/admin/session';
        $isDemoDiagnostic = $method === 'GET'
            && $request->getAttribute('adminAccess') === 'demo'
            && str_starts_with($template, '/api/admin/');
        $isLimited = ($method === 'GET' && $template === '/api/search')
            || ($method === 'POST' && $template === '/api/realtime-token')
            || ($method === 'GET' && $template === '/api/admin/demo-credentials')
            || $isAdminLogin
            || $isDemoDiagnostic;
        if (!$isLimited) {
            return $handler->handle($request);
        }
        $limit = $isAdminLogin
            ? $this->adminLoginLimitPerMinute
            : ($isDemoDiagnostic ? $this->demoDiagnosticsLimitPerMinute : $this->limitPerMinute);
        $address = $this->clientAddresses->resolve($request);
        $bucket = $method . '|' . $template;
        if ($isDemoDiagnostic) {
            $token = $request->getCookieParams()[AdminAuthMiddleware::COOKIE] ?? '';
            $session = is_string($token) && $token !== ''
                ? hash_hmac('sha256', $token, $this->hashSecret)
                : 'missing';
            $address .= '|session:' . $session;
            $bucket = 'demo-diagnostics';
        }
        $key = hash_hmac('sha256', $address . '|' . $bucket, $this->hashSecret);
        $decision = $this->limiter->consume($key, $limit, microtime(true));
        if (!$decision->allowed) {
            $requestId = $request->getAttribute('requestId');
            $retryAfter = $decision->retryAfterSeconds;

            return JsonErrorResponse::create(429, 'rate_limited', 'Too many requests; retry shortly.', ['retryAfterSeconds' => $retryAfter], is_string($requestId) ? $requestId : null)
                ->withHeader('Retry-After', (string)$retryAfter);
        }

        return $handler->handle($request);
    }
}
