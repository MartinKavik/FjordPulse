<?php

declare(strict_types=1);

namespace FjordPulse\Middleware;

use FjordPulse\Http\JsonErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpRateLimitMiddleware implements MiddlewareInterface
{
    /** @var array<string, list<float>> */
    private static array $requests = [];

    public function __construct(private readonly string $hashSecret, private readonly int $limitPerMinute = 120)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/search') && $path !== '/api/realtime-token') {
            return $handler->handle($request);
        }
        $server = $request->getServerParams();
        $address = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : 'unknown';
        $key = hash_hmac('sha256', $address . '|' . $path, $this->hashSecret);
        $now = microtime(true);
        $entries = array_values(array_filter(self::$requests[$key] ?? [], static fn(float $at): bool => $at > $now - 60.0));
        if (count($entries) >= $this->limitPerMinute) {
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
