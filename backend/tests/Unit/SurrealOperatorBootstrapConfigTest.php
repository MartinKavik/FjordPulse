<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Config\SurrealOperatorBootstrapConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SurrealOperatorBootstrapConfig::class)]
final class SurrealOperatorBootstrapConfigTest extends TestCase
{
    private const string ROOT_PASSWORD = 'root-database-secret-distinct';
    private const string APP_PASSWORD = 'application-database-secret-distinct';
    private const string ADMIN_PASSWORD = 'admin-operator-secret-distinct-and-long';
    private const string SESSION_SECRET = 'admin-session-signing-secret-distinct-and-long';
    private const string OPERATOR_PASSWORD = 'surreal-viewer-secret-with-32-bytes-minimum';

    public function testOperatorCredentialsRemainOptionalOutsideProduction(): void
    {
        $this->withEnvironment([
            'SURREAL_OPERATOR_USERNAME' => null,
            'SURREAL_OPERATOR_PASSWORD' => null,
        ], static function (): void {
            $runtime = RuntimeConfig::fromEnvironment();

            self::assertNull(SurrealOperatorBootstrapConfig::fromEnvironment(
                $runtime,
                'root',
                self::ROOT_PASSWORD,
            ));
        });
    }

    public function testProductionRequiresBothOperatorCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Production migrations require SURREAL_OPERATOR_USERNAME');

        $this->withEnvironment([
            'APP_ENV' => 'production',
            'SURREAL_OPERATOR_USERNAME' => null,
            'SURREAL_OPERATOR_PASSWORD' => null,
        ], static function (): void {
            $runtime = RuntimeConfig::fromEnvironment();
            SurrealOperatorBootstrapConfig::fromEnvironment($runtime, 'root', self::ROOT_PASSWORD);
        });
    }

    public function testCredentialsMustBeConfiguredTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be configured together');

        $this->withEnvironment([
            'SURREAL_OPERATOR_USERNAME' => 'fjordpulse_operator',
            'SURREAL_OPERATOR_PASSWORD' => null,
        ], static function (): void {
            $runtime = RuntimeConfig::fromEnvironment();
            SurrealOperatorBootstrapConfig::fromEnvironment($runtime, 'root', self::ROOT_PASSWORD);
        });
    }

    public function testProductionAcceptsDistinctStrongViewerCredentials(): void
    {
        $this->withEnvironment([
            'APP_ENV' => 'production',
            'SURREAL_OPERATOR_USERNAME' => 'fjordpulse_operator',
            'SURREAL_OPERATOR_PASSWORD' => self::OPERATOR_PASSWORD,
        ], static function (): void {
            $runtime = RuntimeConfig::fromEnvironment();
            $operator = SurrealOperatorBootstrapConfig::fromEnvironment(
                $runtime,
                'root',
                self::ROOT_PASSWORD,
            );

            self::assertNotNull($operator);
            self::assertSame('fjordpulse_operator', $operator->credentials->username);
            self::assertSame(self::OPERATOR_PASSWORD, $operator->credentials->password);
        });
    }

    public function testProductionRejectsAWeakOperatorSecretWithoutEchoingIt(): void
    {
        $weak = 'too-short';
        try {
            $this->withEnvironment([
                'APP_ENV' => 'production',
                'SURREAL_OPERATOR_USERNAME' => 'fjordpulse_operator',
                'SURREAL_OPERATOR_PASSWORD' => $weak,
            ], static function (): void {
                $runtime = RuntimeConfig::fromEnvironment();
                SurrealOperatorBootstrapConfig::fromEnvironment($runtime, 'root', self::ROOT_PASSWORD);
            });
            self::fail('A weak production operator secret must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('at least 32 bytes', $error->getMessage());
            self::assertStringNotContainsString($weak, $error->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function reusedCredentials(): iterable
    {
        yield 'application username' => ['SURREAL_OPERATOR_USERNAME', 'fjordpulse_app'];
        yield 'root username' => ['SURREAL_OPERATOR_USERNAME', 'root'];
        yield 'application password' => ['SURREAL_OPERATOR_PASSWORD', self::APP_PASSWORD];
        yield 'root password' => ['SURREAL_OPERATOR_PASSWORD', self::ROOT_PASSWORD];
        yield 'Admin password' => ['SURREAL_OPERATOR_PASSWORD', self::ADMIN_PASSWORD];
        yield 'Admin session secret' => ['SURREAL_OPERATOR_PASSWORD', self::SESSION_SECRET];
    }

    #[DataProvider('reusedCredentials')]
    public function testOperatorCredentialsCannotReusePrivilegedIdentitiesOrSecrets(
        string $variable,
        string $reusedValue,
    ): void {
        $variables = [
            'SURREAL_OPERATOR_USERNAME' => 'fjordpulse_operator',
            'SURREAL_OPERATOR_PASSWORD' => self::OPERATOR_PASSWORD,
            $variable => $reusedValue,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->withEnvironment($variables, static function (): void {
            $runtime = RuntimeConfig::fromEnvironment();
            SurrealOperatorBootstrapConfig::fromEnvironment($runtime, 'root', self::ROOT_PASSWORD);
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
            'ADMIN_PASSWORD' => self::ADMIN_PASSWORD,
            'ADMIN_SESSION_SECRET' => self::SESSION_SECRET,
            'ADMIN_DEMO_ACCESS' => 'false',
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
