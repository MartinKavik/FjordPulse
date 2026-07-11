<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\TimeoutException;
use FjordPulse\Entur\Http\AmpTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AmpTransport::class)]
final class AmpTransportTest extends TestCase
{
    public function testFailedClientIsReplacedOnlyWhenTheNextRequestStarts(): void
    {
        $failedDelegate = new FailingAmpDelegate(new TimeoutException('stale pooled connection'));
        $recoveredDelegate = new SuccessfulAmpDelegate();
        $factoryCalls = 0;
        $transport = new AmpTransport(
            client: self::client($failedDelegate),
            clientFactory: static function () use (&$factoryCalls, $recoveredDelegate): HttpClient {
                $factoryCalls++;

                return self::client($recoveredDelegate);
            },
        );

        try {
            $transport->request('POST', 'https://example.test/graphql', [], ['query' => '{}']);
            self::fail('The first client must expose its transport failure.');
        } catch (TimeoutException $error) {
            self::assertSame('stale pooled connection', $error->getMessage());
        }

        self::assertSame(1, $failedDelegate->requests);
        self::assertSame(0, $recoveredDelegate->requests);
        self::assertSame(0, $factoryCalls, 'A transport failure must not trigger an immediate duplicate request.');

        $response = $transport->request('POST', 'https://example.test/graphql', [], ['query' => '{}']);

        self::assertSame(200, $response->status);
        self::assertSame('{"data":{"stopPlace":true}}', $response->body);
        self::assertSame(1, $failedDelegate->requests);
        self::assertSame(1, $recoveredDelegate->requests);
        self::assertSame(1, $factoryCalls);
    }

    public function testHealthyInjectedClientIsReusedWithoutCallingRecoveryFactory(): void
    {
        $delegate = new SuccessfulAmpDelegate();
        $factoryCalls = 0;
        $transport = new AmpTransport(
            client: self::client($delegate),
            clientFactory: static function () use (&$factoryCalls): HttpClient {
                $factoryCalls++;

                return self::client(new SuccessfulAmpDelegate());
            },
        );

        $transport->request('GET', 'https://example.test/first', []);
        $transport->request('GET', 'https://example.test/second', []);

        self::assertSame(2, $delegate->requests);
        self::assertSame(0, $factoryCalls);
    }

    private static function client(DelegateHttpClient $delegate): HttpClient
    {
        return new HttpClient($delegate, []);
    }
}

final class FailingAmpDelegate implements DelegateHttpClient
{
    public int $requests = 0;

    public function __construct(private readonly TimeoutException $error)
    {
    }

    public function request(Request $request, Cancellation $cancellation): Response
    {
        unset($request, $cancellation);
        $this->requests++;

        throw $this->error;
    }
}

final class SuccessfulAmpDelegate implements DelegateHttpClient
{
    public int $requests = 0;

    public function request(Request $request, Cancellation $cancellation): Response
    {
        unset($cancellation);
        $this->requests++;

        return new Response(
            '1.1',
            200,
            'OK',
            ['content-type' => 'application/json'],
            '{"data":{"stopPlace":true}}',
            $request,
        );
    }
}
