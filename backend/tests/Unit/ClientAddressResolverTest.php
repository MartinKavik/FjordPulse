<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use Cake\Http\ServerRequest;
use FjordPulse\Config\TrustedProxyConfig;
use FjordPulse\Http\ClientAddressResolver;
use FjordPulse\Http\IpAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientAddressResolver::class)]
#[CoversClass(IpAddress::class)]
#[CoversClass(TrustedProxyConfig::class)]
final class ClientAddressResolverTest extends TestCase
{
    public function testDirectPeerIsCanonicalizedAndForwardingFromItIsIgnoredWhenUntrusted(): void
    {
        $resolver = self::resolver('10.0.0.0/8');
        $request = self::request('2001:0DB8:0:0:0:0:0:9')
            ->withHeader('Forwarded', 'for=198.51.100.90')
            ->withHeader('X-Forwarded-For', '198.51.100.91');

        self::assertSame('2001:db8::9', $resolver->resolve($request));
    }

    public function testTrustedIpv4ProxyChainSelectsNearestUntrustedClientFromTheRight(): void
    {
        $resolver = self::resolver('10.20.0.0/16,192.0.2.10');
        $request = self::request('10.20.1.7')
            ->withHeader('X-Forwarded-For', '203.0.113.200, 198.51.100.40, 192.0.2.10');

        self::assertSame('198.51.100.40', $resolver->resolve($request));
    }

    public function testTrustedIpv6ProxyParsesQuotedForwardedAddressAndPorts(): void
    {
        $resolver = self::resolver('2001:db8:24::/64,192.0.2.0/24');
        $request = self::request('2001:db8:24::10')->withHeader(
            'Forwarded',
            'for="[2001:db8:100::25]:4711";proto=https, for=192.0.2.40:8443',
        );

        self::assertSame('2001:db8:100::25', $resolver->resolve($request));
    }

    public function testMatchingForwardedHeadersResolveTheSameClient(): void
    {
        $resolver = self::resolver('10.0.0.0/8');
        $request = self::request('10.0.0.9')
            ->withHeader('Forwarded', 'for=198.51.100.44')
            ->withHeader('X-Forwarded-For', '198.51.100.44');

        self::assertSame('198.51.100.44', $resolver->resolve($request));
    }

    public function testConflictingForwardingHeaderFamiliesFallBackToTheDirectPeer(): void
    {
        $resolver = self::resolver('10.0.0.0/8');
        $request = self::request('10.0.0.9')
            ->withHeader('Forwarded', 'for=198.51.100.44')
            ->withHeader('X-Forwarded-For', '203.0.113.99');

        self::assertSame('10.0.0.9', $resolver->resolve($request));
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedForwardingHeaders(): iterable
    {
        yield 'empty XFF member' => ['X-Forwarded-For', '198.51.100.2,,192.0.2.2'];
        yield 'invalid XFF address' => ['X-Forwarded-For', '198.51.100.2, attacker.example'];
        yield 'unclosed quote' => ['Forwarded', 'for="[2001:db8::2]'];
        yield 'unknown node' => ['Forwarded', 'for=unknown'];
        yield 'obfuscated node' => ['Forwarded', 'for=_hidden'];
        yield 'duplicate for' => ['Forwarded', 'for=198.51.100.2;for=198.51.100.3'];
        yield 'missing for' => ['Forwarded', 'by=192.0.2.2;proto=https'];
        yield 'invalid port' => ['Forwarded', 'for="[2001:db8::2]:70000"'];
    }

    #[DataProvider('malformedForwardingHeaders')]
    public function testMalformedForwardingChainFallsBackToTrustedDirectPeer(string $header, string $value): void
    {
        $resolver = self::resolver('10.0.0.0/8');
        $request = self::request('10.0.0.9')->withHeader($header, $value);

        self::assertSame('10.0.0.9', $resolver->resolve($request));
    }

    public function testMalformedForwardedDoesNotFallThroughToAnAmbiguousXForwardedFor(): void
    {
        $resolver = self::resolver('10.0.0.0/8');
        $request = self::request('10.0.0.9')
            ->withHeader('Forwarded', 'for=_hidden')
            ->withHeader('X-Forwarded-For', '198.51.100.40');

        self::assertSame('10.0.0.9', $resolver->resolve($request));
    }

    public function testMissingOrInvalidDirectPeerNeverMakesForwardingHeadersTrusted(): void
    {
        $resolver = self::resolver('0.0.0.0/0,::/0');

        self::assertSame('unknown', $resolver->resolve(self::request('not-an-address')->withHeader('Forwarded', 'for=198.51.100.1')));
        self::assertSame('unknown', $resolver->resolve(self::request(null)->withHeader('X-Forwarded-For', '198.51.100.1')));
    }

    public function testIpv4MappedIpv6PeerMatchesAnIpv4TrustedRange(): void
    {
        $resolver = self::resolver('192.0.2.0/24');
        $request = self::request('::ffff:192.0.2.20')->withHeader('X-Forwarded-For', '198.51.100.20');

        self::assertSame('198.51.100.20', $resolver->resolve($request));
    }

    public function testNonByteAlignedIpv4AndIpv6CidrsMatchOnlyTheirPrefixes(): void
    {
        $config = TrustedProxyConfig::fromCommaSeparated('10.24.128.0/17,2001:db8:24::/65');

        self::assertTrue($config->isTrusted(self::ip('10.24.200.1')));
        self::assertFalse($config->isTrusted(self::ip('10.24.127.255')));
        self::assertTrue($config->isTrusted(self::ip('2001:db8:24:0:7000::1')));
        self::assertFalse($config->isTrusted(self::ip('2001:db8:24:0:8000::1')));
    }

    public function testAChainContainingOnlyTrustedAddressesReturnsItsOriginalAddress(): void
    {
        $resolver = self::resolver('10.0.0.0/8');
        $request = self::request('10.0.0.9')->withHeader('X-Forwarded-For', '10.1.1.1, 10.2.2.2');

        self::assertSame('10.1.1.1', $resolver->resolve($request));
    }

    private static function resolver(string $trusted): ClientAddressResolver
    {
        return new ClientAddressResolver(TrustedProxyConfig::fromCommaSeparated($trusted));
    }

    private static function ip(string $value): IpAddress
    {
        $address = IpAddress::parse($value);
        self::assertNotNull($address);

        return $address;
    }

    private static function request(?string $remoteAddress): ServerRequest
    {
        $environment = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        if ($remoteAddress !== null) {
            $environment['REMOTE_ADDR'] = $remoteAddress;
        }

        return new ServerRequest(['url' => '/', 'environment' => $environment]);
    }
}
