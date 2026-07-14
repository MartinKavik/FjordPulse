<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use FjordPulse\Config\TrustedProxyConfig;
use FjordPulse\Http\ClientAddressResolver;
use FjordPulse\Middleware\StructuredAccessLogMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(StructuredAccessLogMiddleware::class)]
final class StructuredAccessLogMiddlewareTest extends TestCase
{
    public function testAccessLogUsesTheSameResolvedAddressAsRequestControls(): void
    {
        $logger = new ArrayLog(['levels' => ['info']]);
        Log::setConfig('client-address-test', $logger);

        try {
            $middleware = new StructuredAccessLogMiddleware(new ClientAddressResolver(
                TrustedProxyConfig::fromCommaSeparated('10.24.0.0/16'),
            ));
            $request = (new ServerRequest([
                'url' => '/api/search',
                'environment' => [
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/api/search',
                    'REMOTE_ADDR' => '10.24.1.9',
                ],
            ]))->withHeader('X-Forwarded-For', '198.51.100.42')
                ->withAttribute('requestId', 'req_client_address_test');
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->willReturn(new Response(['status' => 204, 'charset' => 'UTF-8']));

            $response = $middleware->process($request, $handler);
            $messages = $logger->read();
        } finally {
            Log::drop('client-address-test');
        }

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(1, $messages);
        $payload = json_decode(substr($messages[0], strlen('info: ')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('http_request', $payload['event'] ?? null);
        self::assertSame('req_client_address_test', $payload['requestId'] ?? null);
        self::assertSame('198.51.100.42', $payload['clientAddress'] ?? null);
    }
}
