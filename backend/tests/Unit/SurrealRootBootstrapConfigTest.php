<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Config\SurrealRootBootstrapConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SurrealRootBootstrapConfig::class)]
final class SurrealRootBootstrapConfigTest extends TestCase
{
    private const string ROOT_PASSWORD = 'root-database-secret-distinct-and-long';
    private const string APP_PASSWORD = 'application-database-secret-distinct';
    private const string ADMIN_PASSWORD = 'admin-operator-secret-distinct-and-long';
    private const string SESSION_SECRET = 'admin-session-signing-secret-distinct-and-long';
    private const string OPERATOR_PASSWORD = 'surreal-viewer-secret-with-32-bytes-minimum';
    private const string DEMO_PASSWORD = 'public-demo-password-distinct-from-private-secrets';

    public function testLocalDefaultsRemainAvailableOutsideProduction(): void
    {
        $this->withEnvironment([
            'SURREAL_ROOT_USERNAME' => null,
            'SURREAL_ROOT_PASSWORD' => null,
        ], static function (): void {
            $root = SurrealRootBootstrapConfig::fromEnvironment(RuntimeConfig::fromEnvironment());

            self::assertSame('root', $root->credentials->username);
            self::assertSame('root', $root->credentials->password);
        });
    }

    public function testProductionAcceptsDistinctStrongRootCredentials(): void
    {
        $this->withEnvironment([
            'APP_ENV' => 'production',
            'SURREAL_ROOT_USERNAME' => 'fjordpulse_root',
            'SURREAL_ROOT_PASSWORD' => self::ROOT_PASSWORD,
        ], static function (): void {
            $root = SurrealRootBootstrapConfig::fromEnvironment(RuntimeConfig::fromEnvironment());

            self::assertSame('fjordpulse_root', $root->credentials->username);
            self::assertSame(self::ROOT_PASSWORD, $root->credentials->password);
        });
    }

    public function testProductionRejectsAWeakRootSecretWithoutEchoingIt(): void
    {
        $weak = 'too-short';
        try {
            $this->withEnvironment([
                'APP_ENV' => 'production',
                'SURREAL_ROOT_USERNAME' => 'fjordpulse_root',
                'SURREAL_ROOT_PASSWORD' => $weak,
            ], static function (): void {
                SurrealRootBootstrapConfig::fromEnvironment(RuntimeConfig::fromEnvironment());
            });
            self::fail('A weak production root secret must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('at least 32 bytes', $error->getMessage());
            self::assertStringNotContainsString($weak, $error->getMessage());
        }
    }

    public function testProductionRootPasswordCannotReuseItsUsername(): void
    {
        $reusedIdentity = 'fjordpulse_root_identity_credential_123';
        $this->expectException(\InvalidArgumentException::class);
        $this->withEnvironment([
            'APP_ENV' => 'production',
            'SURREAL_ROOT_USERNAME' => $reusedIdentity,
            'SURREAL_ROOT_PASSWORD' => $reusedIdentity,
        ], static function (): void {
            SurrealRootBootstrapConfig::fromEnvironment(RuntimeConfig::fromEnvironment());
        });
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function reusedCredentials(): iterable
    {
        yield 'application username' => ['SURREAL_ROOT_USERNAME', 'fjordpulse_app', false];
        yield 'viewer username' => ['SURREAL_ROOT_USERNAME', 'fjordpulse_viewer', false];
        yield 'Admin username' => ['SURREAL_ROOT_USERNAME', 'admin', false];
        yield 'demo username' => ['SURREAL_ROOT_USERNAME', 'demo', true];
        yield 'application password' => ['SURREAL_ROOT_PASSWORD', self::APP_PASSWORD, false];
        yield 'viewer password' => ['SURREAL_ROOT_PASSWORD', self::OPERATOR_PASSWORD, false];
        yield 'Admin password' => ['SURREAL_ROOT_PASSWORD', self::ADMIN_PASSWORD, false];
        yield 'Admin session secret' => ['SURREAL_ROOT_PASSWORD', self::SESSION_SECRET, false];
        yield 'demo password' => ['SURREAL_ROOT_PASSWORD', self::DEMO_PASSWORD, true];
    }

    #[DataProvider('reusedCredentials')]
    public function testProductionRootCannotReuseApplicationViewerOrAdminCredentials(
        string $variable,
        string $reusedValue,
        bool $demoAccess,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->withEnvironment([
            'APP_ENV' => 'production',
            'SURREAL_ROOT_USERNAME' => 'fjordpulse_root',
            'SURREAL_ROOT_PASSWORD' => self::ROOT_PASSWORD,
            'ADMIN_DEMO_ACCESS' => $demoAccess ? 'true' : 'false',
            $variable => $reusedValue,
        ], static function (): void {
            SurrealRootBootstrapConfig::fromEnvironment(RuntimeConfig::fromEnvironment());
        });
    }

    /**
     * @param array<string, string|null> $variables
     * @param callable(): void $assertions
     */
    private function withEnvironment(array $variables, callable $assertions): void
    {
        $production = ($variables['APP_ENV'] ?? null) === 'production'
            ? [
                'APP_DEBUG' => 'false',
                'APP_ORIGIN' => 'https://fjordpulse.kavik.cz',
                'ALLOWED_ORIGINS' => 'https://fjordpulse.kavik.cz',
                'TRUSTED_PROXIES' => '172.20.0.0/24',
            ]
            : [];
        $variables = [
            'APP_ENV' => 'test',
            'DATA_MODE' => 'real',
            'SURREAL_USERNAME' => 'fjordpulse_app',
            'SURREAL_PASSWORD' => self::APP_PASSWORD,
            'SURREAL_OPERATOR_USERNAME' => 'fjordpulse_viewer',
            'SURREAL_OPERATOR_PASSWORD' => self::OPERATOR_PASSWORD,
            'ADMIN_USERNAME' => 'admin',
            'ADMIN_PASSWORD' => self::ADMIN_PASSWORD,
            'ADMIN_SESSION_SECRET' => self::SESSION_SECRET,
            'ADMIN_DEMO_ACCESS' => 'false',
            'ADMIN_DEMO_USERNAME' => 'demo',
            'ADMIN_DEMO_PASSWORD' => self::DEMO_PASSWORD,
            ...$production,
            ...$variables,
        ];
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
}
