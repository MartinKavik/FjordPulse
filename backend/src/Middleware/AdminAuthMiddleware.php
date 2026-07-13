<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use Cake\Routing\Route\Route;
use FjordPulse\Http\JsonErrorResponse;
use FjordPulse\Security\SignedToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdminAuthMiddleware implements MiddlewareInterface
{
    public const string COOKIE = 'fjordpulse_admin';

    /** @var array<string, true> */
    private const array DEMO_READ_ROUTES = [
        '/api/admin/session' => true,
        '/api/admin/status' => true,
        '/api/admin/watches' => true,
        '/api/admin/entur-log' => true,
        '/api/admin/realtime' => true,
        '/api/admin/events' => true,
        '/api/admin/database/schema' => true,
        '/api/admin/database/migrations' => true,
        '/api/admin/migrations' => true,
    ];

    public function __construct(private SignedToken $tokens, private bool $adminDemoAccess = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute('route');
        if (!$route instanceof Route || !self::isAdminRouteTemplate($route->template)) {
            return $handler->handle($request);
        }
        $method = strtoupper($request->getMethod());
        if (($route->template === '/api/admin/session' && $method === 'POST')
            || ($route->template === '/api/admin/demo-credentials' && $method === 'GET')) {
            return $handler->handle($request);
        }
        $cookies = $request->getCookieParams();
        $token = is_string($cookies[self::COOKIE] ?? null) ? $cookies[self::COOKIE] : '';
        $claims = $this->tokens->verify($token, 'admin-session');
        $access = $claims['access'] ?? 'operator';
        if ($claims === null
            || !is_string($claims['username'] ?? null)
            || !is_string($access)
            || !in_array($access, ['operator', 'demo'], true)) {
            $requestId = $request->getAttribute('requestId');

            return JsonErrorResponse::create(401, 'admin_unauthorized', 'Admin authentication is required.', [], is_string($requestId) ? $requestId : null);
        }
        if ($access === 'demo' && !$this->adminDemoAccess) {
            $requestId = $request->getAttribute('requestId');

            return JsonErrorResponse::create(
                401,
                'admin_unauthorized',
                'Public Admin demo access is disabled.',
                [],
                is_string($requestId) ? $requestId : null,
            );
        }
        if ($access === 'demo' && !self::isAllowedDemoRoute($route->template, $method)) {
            $requestId = $request->getAttribute('requestId');

            return JsonErrorResponse::create(
                403,
                'admin_read_only',
                'The public Admin demo is read-only.',
                [],
                is_string($requestId) ? $requestId : null,
            );
        }

        return $handler->handle(
            $request
                ->withAttribute('adminUsername', $claims['username'])
                ->withAttribute('adminAccess', $access)
                ->withAttribute('adminClaims', $claims),
        );
    }

    private static function isAdminRouteTemplate(string $template): bool
    {
        return $template === '/api/admin' || str_starts_with($template, '/api/admin/');
    }

    private static function isAllowedDemoRoute(string $template, string $method): bool
    {
        return ($method === 'GET' && isset(self::DEMO_READ_ROUTES[$template]))
            || ($method === 'DELETE' && $template === '/api/admin/session');
    }
}
