<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Routing\Route\DashedRoute;
use FjordPulse\Middleware\AdminAuthMiddleware;
use FjordPulse\Security\SignedToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminAuthMiddlewareTest extends TestCase
{
    private const string SECRET = 'admin-auth-test-secret-that-is-long-enough';

    private bool $hadAppEncoding = false;
    private mixed $previousAppEncoding = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadAppEncoding = Configure::check('App.encoding');
        $this->previousAppEncoding = Configure::read('App.encoding');
        Configure::write('App.encoding', 'UTF-8');
    }

    protected function tearDown(): void
    {
        if ($this->hadAppEncoding) {
            Configure::write('App.encoding', $this->previousAppEncoding);
        } else {
            Configure::delete('App.encoding');
        }
        parent::tearDown();
    }

    #[DataProvider('encodedAdminRoutes')]
    public function testMatchedAdminRouteRequiresAuthenticationIndependentlyOfEncodedRequestPath(
        string $requestPath,
        string $routeTemplate,
    ): void {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $response = (new AdminAuthMiddleware(new SignedToken(self::SECRET)))->process(
            self::request('GET', $requestPath, $routeTemplate),
            $handler,
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('admin_unauthorized', (string)$response->getBody());
    }

    /** @return iterable<string, array{string, string}> */
    public static function encodedAdminRoutes(): iterable
    {
        yield 'encoded admin segment' => ['/api/%61dmin/database/schema', '/api/admin/database/schema'];
        yield 'encoded database segment' => ['/api/admin/%64atabase/migrations', '/api/admin/database/migrations'];
        yield 'encoded status segment' => ['/api/admin/%73tatus', '/api/admin/status'];
    }

    public function testAuthenticatedMatchedAdminRouteReceivesVerifiedClaims(): void
    {
        $tokens = new SignedToken(self::SECRET);
        $token = $tokens->issue(['username' => 'operator'], 60, 'admin-session');
        $request = self::request('GET', '/api/%61dmin/status', '/api/admin/status')
            ->withCookieParams([AdminAuthMiddleware::COOKIE => $token]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static function (ServerRequestInterface $handled): bool {
                self::assertSame('operator', $handled->getAttribute('adminUsername'));
                $claims = $handled->getAttribute('adminClaims');
                self::assertIsArray($claims);
                self::assertSame('operator', $claims['username'] ?? null);

                return true;
            }))
            ->willReturn(new Response(['status' => 204]));

        $response = (new AdminAuthMiddleware($tokens))->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testOnlyMatchedPostSessionRouteIsPublic(): void
    {
        $middleware = new AdminAuthMiddleware(new SignedToken(self::SECRET));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn(new Response(['status' => 204]));

        $response = $middleware->process(
            self::request('POST', '/api/%61dmin/%73ession', '/api/admin/session'),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testMatchedGetDemoCredentialsRouteIsPublicEvenWhenEncoded(): void
    {
        $middleware = new AdminAuthMiddleware(new SignedToken(self::SECRET));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn(new Response(['status' => 204]));

        $response = $middleware->process(
            self::request('GET', '/api/admin/%64emo-credentials', '/api/admin/demo-credentials'),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[DataProvider('demoDiagnosticRoutes')]
    public function testDemoSessionCanReadAdminDiagnosticsAndCarriesItsAccessLevel(string $routeTemplate): void
    {
        $tokens = new SignedToken(self::SECRET);
        $token = $tokens->issue(['username' => 'demo', 'access' => 'demo'], 60, 'admin-session');
        $request = self::request('GET', $routeTemplate, $routeTemplate)
            ->withCookieParams([AdminAuthMiddleware::COOKIE => $token]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static function (ServerRequestInterface $handled): bool {
                self::assertSame('demo', $handled->getAttribute('adminUsername'));
                self::assertSame('demo', $handled->getAttribute('adminAccess'));

                return true;
            }))
            ->willReturn(new Response(['status' => 204]));

        $response = (new AdminAuthMiddleware($tokens, true))->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function demoDiagnosticRoutes(): iterable
    {
        foreach ([
            '/api/admin/session',
            '/api/admin/status',
            '/api/admin/watches',
            '/api/admin/entur-log',
            '/api/admin/realtime',
            '/api/admin/events',
            '/api/admin/database/schema',
            '/api/admin/database/migrations',
            '/api/admin/migrations',
        ] as $routeTemplate) {
            yield $routeTemplate => [$routeTemplate];
        }
    }

    public function testDemoSessionIsDeniedAnyFutureAdminMutation(): void
    {
        $tokens = new SignedToken(self::SECRET);
        $token = $tokens->issue(['username' => 'demo', 'access' => 'demo'], 60, 'admin-session');
        $request = self::request('POST', '/api/admin/future-action', '/api/admin/future-action')
            ->withCookieParams([AdminAuthMiddleware::COOKIE => $token]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = (new AdminAuthMiddleware($tokens, true))->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('admin_read_only', (string)$response->getBody());
    }

    public function testDemoSessionIsDeniedAnyFutureAdminReadRoute(): void
    {
        $tokens = new SignedToken(self::SECRET);
        $token = $tokens->issue(['username' => 'demo', 'access' => 'demo'], 60, 'admin-session');
        $request = self::request('GET', '/api/admin/future-export', '/api/admin/future-export')
            ->withCookieParams([AdminAuthMiddleware::COOKIE => $token]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = (new AdminAuthMiddleware($tokens, true))->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('admin_read_only', (string)$response->getBody());
    }

    public function testDisablingDemoAccessImmediatelyRejectsAnExistingDemoSession(): void
    {
        $tokens = new SignedToken(self::SECRET);
        $token = $tokens->issue(['username' => 'demo', 'access' => 'demo'], 60, 'admin-session');
        $request = self::request('GET', '/api/admin/status', '/api/admin/status')
            ->withCookieParams([AdminAuthMiddleware::COOKIE => $token]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = (new AdminAuthMiddleware($tokens, false))->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('admin_unauthorized', (string)$response->getBody());
    }

    public function testDemoSessionCanStillLogOut(): void
    {
        $tokens = new SignedToken(self::SECRET);
        $token = $tokens->issue(['username' => 'demo', 'access' => 'demo'], 60, 'admin-session');
        $request = self::request('DELETE', '/api/admin/session', '/api/admin/session')
            ->withCookieParams([AdminAuthMiddleware::COOKIE => $token]);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn(new Response(['status' => 204]));

        $response = (new AdminAuthMiddleware($tokens, true))->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testNonAdminMatchedRoutePassesThrough(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn(new Response(['status' => 204]));

        $response = (new AdminAuthMiddleware(new SignedToken(self::SECRET)))->process(
            self::request('GET', '/api/health', '/api/health'),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAdminLikeRouteOutsideExactPathSegmentPassesThrough(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn(new Response(['status' => 204]));

        $response = (new AdminAuthMiddleware(new SignedToken(self::SECRET)))->process(
            self::request('GET', '/api/administrator', '/api/administrator'),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
    }

    private static function request(string $method, string $path, string $routeTemplate): ServerRequest
    {
        return (new ServerRequest([
            'url' => $path,
            'environment' => [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $path,
            ],
        ]))->withAttribute('route', new DashedRoute($routeTemplate, [], ['_method' => $method]));
    }
}
