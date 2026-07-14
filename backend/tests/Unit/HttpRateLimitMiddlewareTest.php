<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Routing\Route\DashedRoute;
use FjordPulse\Config\TrustedProxyConfig;
use FjordPulse\Http\ClientAddressResolver;
use FjordPulse\Http\FileSlidingWindowRateLimiter;
use FjordPulse\Middleware\AdminAuthMiddleware;
use FjordPulse\Middleware\HttpRateLimitMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpRateLimitMiddlewareTest extends TestCase
{
    private bool $hadAppEncoding = false;
    private mixed $previousAppEncoding = null;

    /** @var list<string> */
    private array $rateLimitDirectories = [];

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
        foreach ($this->rateLimitDirectories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
        parent::tearDown();
    }

    public function testEncodedLoginRouteSharesTheCanonicalRateLimitBucket(): void
    {
        $middleware = new HttpRateLimitMiddleware('rate-limit-test-secret', self::directClients(), $this->limiter(), 120, 1);
        $firstHandler = $this->createMock(RequestHandlerInterface::class);
        $firstHandler->expects(self::once())->method('handle')->willReturn(new Response(['status' => 204]));
        $secondHandler = $this->createMock(RequestHandlerInterface::class);
        $secondHandler->expects(self::never())->method('handle');

        $first = $middleware->process(
            self::request('POST', '/api/admin/session', '/api/admin/session', '198.51.100.41'),
            $firstHandler,
        );
        $second = $middleware->process(
            self::request('POST', '/api/%61dmin/%73ession', '/api/admin/session', '198.51.100.41'),
            $secondHandler,
        );

        self::assertSame(204, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertSame('60', $second->getHeaderLine('Retry-After'));
        self::assertStringContainsString('rate_limited', (string)$second->getBody());
    }

    public function testOnlyTheConfiguredRouteMethodPairIsLimited(): void
    {
        $middleware = new HttpRateLimitMiddleware('rate-limit-method-test-secret', self::directClients(), $this->limiter(), 1, 1);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')->willReturn(new Response(['status' => 204]));

        $getSession = $middleware->process(
            self::request('GET', '/api/admin/session', '/api/admin/session', '198.51.100.42'),
            $handler,
        );
        $unmatched = $middleware->process(
            self::request('POST', '/api/admin/future', '/api/admin/future', '198.51.100.42'),
            $handler,
        );

        self::assertSame(204, $getSession->getStatusCode());
        self::assertSame(204, $unmatched->getStatusCode());
    }

    public function testDemoDiagnosticsShareOneSessionScopedBudgetWhileOperatorReadsBypassIt(): void
    {
        $middleware = new HttpRateLimitMiddleware('demo-diagnostic-limit-test-secret', self::directClients(), $this->limiter(), 120, 60, 1);
        $firstHandler = $this->createMock(RequestHandlerInterface::class);
        $firstHandler->expects(self::once())->method('handle')->willReturn(new Response(['status' => 204]));
        $blockedHandler = $this->createMock(RequestHandlerInterface::class);
        $blockedHandler->expects(self::never())->method('handle');
        $operatorHandler = $this->createMock(RequestHandlerInterface::class);
        $operatorHandler->expects(self::exactly(2))->method('handle')->willReturn(new Response(['status' => 204]));
        $demoStatus = self::request('GET', '/api/admin/status', '/api/admin/status', '198.51.100.43')
            ->withAttribute('adminAccess', 'demo')
            ->withCookieParams([AdminAuthMiddleware::COOKIE => 'signed-demo-session']);
        $demoEvents = self::request('GET', '/api/admin/%65vents', '/api/admin/events', '198.51.100.43')
            ->withAttribute('adminAccess', 'demo')
            ->withCookieParams([AdminAuthMiddleware::COOKIE => 'signed-demo-session']);

        $first = $middleware->process($demoStatus, $firstHandler);
        $blocked = $middleware->process($demoEvents, $blockedHandler);
        $operatorFirst = $middleware->process(
            self::request('GET', '/api/admin/status', '/api/admin/status', '198.51.100.43')->withAttribute('adminAccess', 'operator'),
            $operatorHandler,
        );
        $operatorSecond = $middleware->process(
            self::request('GET', '/api/admin/status', '/api/admin/status', '198.51.100.43')->withAttribute('adminAccess', 'operator'),
            $operatorHandler,
        );

        self::assertSame(204, $first->getStatusCode());
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(204, $operatorFirst->getStatusCode());
        self::assertSame(204, $operatorSecond->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function publicLimitedRoutes(): iterable
    {
        yield 'search' => ['GET', '/api/search'];
        yield 'realtime token' => ['POST', '/api/realtime-token'];
        yield 'admin login' => ['POST', '/api/admin/session'];
        yield 'demo credentials' => ['GET', '/api/admin/demo-credentials'];
    }

    #[DataProvider('publicLimitedRoutes')]
    public function testExternalClientsBehindOneTrustedProxyHaveIndependentPublicBudgets(string $method, string $route): void
    {
        $middleware = new HttpRateLimitMiddleware(
            'trusted-proxy-route-' . $method . '-' . $route,
            self::trustedClients('10.24.0.0/16'),
            $this->limiter(),
            1,
            1,
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')->willReturn(new Response(['status' => 204]));

        $first = $middleware->process(
            self::request($method, $route, $route, '10.24.1.9')->withHeader('X-Forwarded-For', '198.51.100.10'),
            $handler,
        );
        $blocked = $middleware->process(
            self::request($method, $route, $route, '10.24.1.9')->withHeader('X-Forwarded-For', '198.51.100.10'),
            $handler,
        );
        $otherClient = $middleware->process(
            self::request($method, $route, $route, '10.24.1.9')->withHeader('X-Forwarded-For', '198.51.100.11'),
            $handler,
        );

        self::assertSame(204, $first->getStatusCode());
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(204, $otherClient->getStatusCode());
    }

    public function testUntrustedPeerCannotEvadeSearchLimitWithSpoofedForwardingHeaders(): void
    {
        $middleware = new HttpRateLimitMiddleware(
            'untrusted-forwarding-rate-limit-secret',
            self::trustedClients('10.24.0.0/16'),
            $this->limiter(),
            1,
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response(['status' => 204]));

        $first = $middleware->process(
            self::request('GET', '/api/search', '/api/search', '203.0.113.30')
                ->withHeader('Forwarded', 'for=198.51.100.30'),
            $handler,
        );
        $blocked = $middleware->process(
            self::request('GET', '/api/search', '/api/search', '203.0.113.30')
                ->withHeader('X-Forwarded-For', '198.51.100.31'),
            $handler,
        );

        self::assertSame(204, $first->getStatusCode());
        self::assertSame(429, $blocked->getStatusCode());
    }

    public function testDemoDiagnosticBudgetUsesResolvedClientAndSessionTogether(): void
    {
        $middleware = new HttpRateLimitMiddleware(
            'trusted-demo-diagnostic-limit-secret',
            self::trustedClients('2001:db8:24::/64'),
            $this->limiter(),
            120,
            60,
            1,
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')->willReturn(new Response(['status' => 204]));
        $request = static fn(string $client): ServerRequest => self::request(
            'GET',
            '/api/admin/status',
            '/api/admin/status',
            '2001:db8:24::9',
        )->withHeader('Forwarded', 'for="[' . $client . ']"')
            ->withAttribute('adminAccess', 'demo')
            ->withCookieParams([AdminAuthMiddleware::COOKIE => 'shared-demo-session']);

        $first = $middleware->process($request('2001:db8:100::10'), $handler);
        $blocked = $middleware->process($request('2001:db8:100::10'), $handler);
        $otherClient = $middleware->process($request('2001:db8:100::11'), $handler);

        self::assertSame(204, $first->getStatusCode());
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(204, $otherClient->getStatusCode());
    }

    private static function request(
        string $method,
        string $path,
        string $routeTemplate,
        string $remoteAddress,
    ): ServerRequest {
        return (new ServerRequest([
            'url' => $path,
            'environment' => [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $path,
                'REMOTE_ADDR' => $remoteAddress,
            ],
        ]))->withAttribute('route', new DashedRoute($routeTemplate, [], ['_method' => $method]));
    }

    private static function directClients(): ClientAddressResolver
    {
        return self::trustedClients('');
    }

    private static function trustedClients(string $proxies): ClientAddressResolver
    {
        return new ClientAddressResolver(TrustedProxyConfig::fromCommaSeparated($proxies));
    }

    private function limiter(): FileSlidingWindowRateLimiter
    {
        $directory = sys_get_temp_dir() . '/fjordpulse-rate-limit-unit-' . bin2hex(random_bytes(8));
        $this->rateLimitDirectories[] = $directory;

        return new FileSlidingWindowRateLimiter($directory);
    }
}
