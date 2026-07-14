<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Http\IpAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeConfig::class)]
final class RuntimeConfigTest extends TestCase
{
    private const string PRODUCTION_APP_PASSWORD = 'production-application-database-secret';
    private const string PRODUCTION_ADMIN_PASSWORD = 'production-admin-operator-secret-value';
    private const string PRODUCTION_SESSION_SECRET = 'production-admin-session-signing-secret-value';

    /** @var list<string> */
    private const ENTUR_BUDGET_VARIABLES = [
        'ENTUR_GLOBAL_REQUESTS_PER_MINUTE',
        'ENTUR_STOP_PLACE_REQUESTS_PER_MINUTE',
        'ENTUR_GEOCODER_REQUESTS_PER_MINUTE',
        'ENTUR_JOURNEY_REQUESTS_PER_MINUTE',
        'ENTUR_VEHICLE_REQUESTS_PER_MINUTE',
    ];

    public function testEnturRequestBudgetDefaultsHaveOneCanonicalConfiguration(): void
    {
        $this->withEnvironment(array_fill_keys(self::ENTUR_BUDGET_VARIABLES, null), static function (): void {
            $config = RuntimeConfig::fromEnvironment();

            self::assertSame(120, $config->enturGlobalRequestsPerMinute);
            self::assertSame(60, $config->enturStopPlaceRequestsPerMinute);
            self::assertSame(20, $config->enturGeocoderRequestsPerMinute);
            self::assertSame(30, $config->enturJourneyPlannerRequestsPerMinute);
            self::assertSame(30, $config->enturVehiclePositionsRequestsPerMinute);
            self::assertSame([
                'stop_place_register' => 60,
                'geocoder' => 20,
                'journey_planner' => 30,
                'vehicle_positions' => 30,
            ], $config->enturPerServiceRequestsPerMinute());
        });
    }

    public function testEnturRequestBudgetsUseOperatorOverridesTogether(): void
    {
        $this->withEnvironment([
            'ENTUR_GLOBAL_REQUESTS_PER_MINUTE' => '240',
            'ENTUR_STOP_PLACE_REQUESTS_PER_MINUTE' => '70',
            'ENTUR_GEOCODER_REQUESTS_PER_MINUTE' => '25',
            'ENTUR_JOURNEY_REQUESTS_PER_MINUTE' => '40',
            'ENTUR_VEHICLE_REQUESTS_PER_MINUTE' => '35',
        ], static function (): void {
            $config = RuntimeConfig::fromEnvironment();

            self::assertSame(240, $config->enturGlobalRequestsPerMinute);
            self::assertSame([
                'stop_place_register' => 70,
                'geocoder' => 25,
                'journey_planner' => 40,
                'vehicle_positions' => 35,
            ], $config->enturPerServiceRequestsPerMinute());
        });
    }

    public function testAdminDemoAccessDefaultsOffInEveryEnvironment(): void
    {
        $this->withEnvironment([
            'ADMIN_DEMO_ACCESS' => null,
            'ADMIN_DEMO_USERNAME' => null,
            'ADMIN_DEMO_PASSWORD' => null,
        ], static function (): void {
            $config = RuntimeConfig::fromEnvironment();

            self::assertFalse($config->adminDemoAccess);
            self::assertSame('demo', $config->adminDemoUsername);
            self::assertSame('fjordpulse-demo', $config->adminDemoPassword);
        });

        $this->withEnvironment([
            ...self::productionEnvironment(),
            'ADMIN_DEMO_ACCESS' => null,
        ], static function (): void {
            self::assertFalse(RuntimeConfig::fromEnvironment()->adminDemoAccess);
        });
    }

    public function testProductionCanExplicitlyEnableASeparatePublicReadOnlyDemoIdentity(): void
    {
        $this->withEnvironment([
            ...self::productionEnvironment(),
            'ADMIN_USERNAME' => 'operator',
            'ADMIN_DEMO_ACCESS' => 'true',
            'ADMIN_DEMO_USERNAME' => 'public-demo',
            'ADMIN_DEMO_PASSWORD' => 'intentionally-public',
        ], static function (): void {
            $config = RuntimeConfig::fromEnvironment();

            self::assertTrue($config->adminDemoAccess);
            self::assertSame('public-demo', $config->adminDemoUsername);
            self::assertSame('intentionally-public', $config->adminDemoPassword);
            self::assertNotSame($config->adminUsername, $config->adminDemoUsername);
        });
    }

    public function testAdminDemoAccessRejectsInvalidFlagsAndSharedOperatorIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ADMIN_DEMO_ACCESS must be true or false.');
        $this->withEnvironment([
            'ADMIN_DEMO_ACCESS' => 'sometimes',
        ], static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    public function testAdminDemoAccessRejectsTheOperatorUsername(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Admin demo access must use a separate username');
        $this->withEnvironment([
            'ADMIN_USERNAME' => 'same-user',
            'ADMIN_DEMO_ACCESS' => 'true',
            'ADMIN_DEMO_USERNAME' => 'same-user',
        ], static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    public function testAdminDemoAccessRejectsTheOperatorPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Admin demo access must use a separate password');
        $this->withEnvironment([
            'ADMIN_PASSWORD' => 'same-password',
            'ADMIN_DEMO_ACCESS' => 'true',
            'ADMIN_DEMO_PASSWORD' => 'same-password',
        ], static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    public function testAdminDemoAccessRejectsTheSessionSigningSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Admin demo access must not reuse the Admin session signing secret');
        $this->withEnvironment([
            'ADMIN_PASSWORD' => 'operator-only-password',
            'ADMIN_SESSION_SECRET' => 'shared-demo-secret',
            'SURREAL_PASSWORD' => 'database-only-password',
            'ADMIN_DEMO_ACCESS' => 'true',
            'ADMIN_DEMO_PASSWORD' => 'shared-demo-secret',
        ], static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    public function testAdminDemoAccessRejectsTheSurrealDbApplicationPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Admin demo access must not reuse the SurrealDB application password');
        $this->withEnvironment([
            'ADMIN_PASSWORD' => 'operator-only-password',
            'ADMIN_SESSION_SECRET' => 'session-only-secret',
            'SURREAL_PASSWORD' => 'shared-demo-secret',
            'ADMIN_DEMO_ACCESS' => 'true',
            'ADMIN_DEMO_PASSWORD' => 'shared-demo-secret',
        ], static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    public function testTrustedProxiesDefaultToNoTrustedPeers(): void
    {
        $this->withEnvironment(['TRUSTED_PROXIES' => null], static function (): void {
            self::assertTrue(RuntimeConfig::fromEnvironment()->trustedProxies->isEmpty());
        });
    }

    public function testTrustedProxiesAcceptExplicitIpv4AndIpv6AddressesAndCidrs(): void
    {
        $this->withEnvironment([
            'TRUSTED_PROXIES' => '127.0.0.1, 10.24.0.0/16, 2001:db8:24::/64',
        ], static function (): void {
            $trusted = RuntimeConfig::fromEnvironment()->trustedProxies;

            self::assertTrue($trusted->isTrusted(self::address('127.0.0.1')));
            self::assertTrue($trusted->isTrusted(self::address('10.24.5.4')));
            self::assertTrue($trusted->isTrusted(self::address('2001:db8:24::9')));
            self::assertFalse($trusted->isTrusted(self::address('10.25.5.4')));
            self::assertFalse($trusted->isTrusted(self::address('2001:db8:25::9')));
        });
    }

    public function testTrustedProxiesRejectMalformedEnvironmentValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TRUSTED_PROXIES contains an invalid IP address or CIDR');
        $this->withEnvironment([
            'TRUSTED_PROXIES' => '10.24.0.0/16,proxy.internal',
        ], static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    /** @return iterable<string, array{string}> */
    public static function supportedEnvironments(): iterable
    {
        yield 'local' => ['local'];
        yield 'development' => ['development'];
        yield 'test' => ['test'];
        yield 'staging' => ['staging'];
    }

    #[DataProvider('supportedEnvironments')]
    public function testAppEnvironmentAcceptsOnlyCanonicalSupportedValues(string $environment): void
    {
        $this->withEnvironment(['APP_ENV' => $environment], static function () use ($environment): void {
            self::assertSame($environment, RuntimeConfig::fromEnvironment()->environment);
        });
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedEnvironments(): iterable
    {
        yield 'production abbreviation' => ['prod'];
        yield 'production with different case' => ['Production'];
        yield 'development abbreviation' => ['dev'];
        yield 'testing alias' => ['testing'];
        yield 'surrounding whitespace' => [' production '];
        yield 'empty explicit value' => [''];
    }

    #[DataProvider('unsupportedEnvironments')]
    public function testAppEnvironmentRejectsNonCanonicalValues(string $environment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_ENV must be one of');
        $this->withEnvironment([
            'APP_ENV' => $environment,
        ], static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    /** @return iterable<string, array{string, string|null, string}> */
    public static function unsafeProductionBoundaries(): iterable
    {
        yield 'debug' => ['APP_DEBUG', 'true', 'APP_DEBUG=false'];
        yield 'HTTP app origin' => ['APP_ORIGIN', 'http://fjordpulse.kavik.cz', 'APP_ORIGIN must be an HTTPS origin'];
        yield 'origin with path' => ['APP_ORIGIN', 'https://fjordpulse.kavik.cz/app', 'APP_ORIGIN must be an HTTPS origin'];
        yield 'origin with user' => ['APP_ORIGIN', 'https://operator@fjordpulse.kavik.cz', 'APP_ORIGIN must be an HTTPS origin'];
        yield 'origin with password' => ['APP_ORIGIN', 'https://operator:secret@fjordpulse.kavik.cz', 'APP_ORIGIN must be an HTTPS origin'];
        yield 'origin with query' => ['APP_ORIGIN', 'https://fjordpulse.kavik.cz?source=test', 'APP_ORIGIN must be an HTTPS origin'];
        yield 'origin with fragment' => ['APP_ORIGIN', 'https://fjordpulse.kavik.cz#map', 'APP_ORIGIN must be an HTTPS origin'];
        yield 'untrusted allowed origin' => ['ALLOWED_ORIGINS', 'http://fjordpulse.kavik.cz', 'ALLOWED_ORIGINS'];
        yield 'no proxy boundary' => ['TRUSTED_PROXIES', null, 'explicit TRUSTED_PROXIES'];
        yield 'universal IPv4 proxy boundary' => ['TRUSTED_PROXIES', '0.0.0.0/0', 'must not trust an entire IP address family'];
        yield 'universal IPv6 proxy boundary' => ['TRUSTED_PROXIES', '::/0', 'must not trust an entire IP address family'];
        yield 'weak database secret' => ['SURREAL_PASSWORD', 'too-short', 'SurrealDB application credentials'];
        yield 'weak Admin secret' => ['ADMIN_PASSWORD', 'too-short', 'admin credentials/session secret'];
    }

    #[DataProvider('unsafeProductionBoundaries')]
    public function testProductionRejectsUnsafeExternalBoundaries(
        string $variable,
        ?string $value,
        string $message,
    ): void {
        $environment = [...self::productionEnvironment(), $variable => $value];
        if ($variable === 'APP_ORIGIN') {
            $environment['ALLOWED_ORIGINS'] = $value;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->withEnvironment($environment, static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBudgetVariables(): iterable
    {
        foreach (self::ENTUR_BUDGET_VARIABLES as $variable) {
            yield $variable . ' zero' => [$variable, '0'];
            yield $variable . ' non-numeric' => [$variable, 'many'];
        }
    }

    #[DataProvider('invalidBudgetVariables')]
    public function testEnturRequestBudgetsRejectInvalidValues(string $variable, string $value): void
    {
        $variables = array_fill_keys(self::ENTUR_BUDGET_VARIABLES, null);
        $variables[$variable] = $value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($variable . ' must be a positive integer.');
        $this->withEnvironment($variables, static fn(): RuntimeConfig => RuntimeConfig::fromEnvironment());
    }

    /**
     * @param array<string, string|null> $variables
     * @param callable(): mixed $assertions
     */
    private function withEnvironment(array $variables, callable $assertions): void
    {
        $variables = ['APP_ENV' => 'test', 'DATA_MODE' => 'real', ...$variables];
        $previous = [];
        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($value === null ? $name : $name . '=' . $value);
        }

        try {
            $assertions();
        } finally {
            foreach ($previous as $name => $value) {
                putenv(is_string($value) ? $name . '=' . $value : $name);
            }
        }
    }

    /** @return array<string, string> */
    private static function productionEnvironment(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DATA_MODE' => 'real',
            'APP_ORIGIN' => 'https://fjordpulse.kavik.cz',
            'ALLOWED_ORIGINS' => 'https://fjordpulse.kavik.cz',
            'TRUSTED_PROXIES' => '172.20.0.0/24',
            'SURREAL_PASSWORD' => self::PRODUCTION_APP_PASSWORD,
            'ADMIN_PASSWORD' => self::PRODUCTION_ADMIN_PASSWORD,
            'ADMIN_SESSION_SECRET' => self::PRODUCTION_SESSION_SECRET,
        ];
    }

    private static function address(string $value): IpAddress
    {
        return IpAddress::parse($value) ?? throw new \LogicException('Test fixture must be a valid IP address.');
    }
}
