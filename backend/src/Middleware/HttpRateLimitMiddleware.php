<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use Cake\Routing\Route\Route;
use FjordPulse\Http\JsonErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpRateLimitMiddleware implements MiddlewareInterface
{
    /** @var array<string, list<float>> */
    private static array $requests = [];

    public function __construct(
        private readonly string $hashSecret,
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
        $server = $request->getServerParams();
        $address = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : 'unknown';
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
        $now = microtime(true);
        $entries = array_values(array_filter(self::$requests[$key] ?? [], static fn(float $at): bool => $at > $now - 60.0));
        if (count($entries) >= $limit) {
            self::$requests[$key] = $entries;
            $requestId = $request->getAttribute('requestId');

            return JsonErrorResponse::create(429, 'rate_limited', 'Too many requests; retry shortly.', ['retryAfterSeconds' => 60], is_string($requestId) ? $requestId : null)
                ->withHeader('Retry-After', '60');
        }
        $entries[] = $now;
        self::$requests[$key] = $entries;

        return $handler->handle($request);
    }
}
