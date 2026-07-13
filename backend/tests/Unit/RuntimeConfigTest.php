<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Config\RuntimeConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuntimeConfig::class)]
final class RuntimeConfigTest extends TestCase
{
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
            'APP_ENV' => 'production',
            'DATA_MODE' => 'real',
            'ADMIN_PASSWORD' => 'a-strong-production-operator-password',
            'ADMIN_SESSION_SECRET' => str_repeat('production-session-secret-', 2),
            'ADMIN_DEMO_ACCESS' => null,
        ], static function (): void {
            self::assertFalse(RuntimeConfig::fromEnvironment()->adminDemoAccess);
        });
    }

    public function testProductionCanExplicitlyEnableASeparatePublicReadOnlyDemoIdentity(): void
    {
        $this->withEnvironment([
            'APP_ENV' => 'production',
            'DATA_MODE' => 'real',
            'ADMIN_USERNAME' => 'operator',
            'ADMIN_PASSWORD' => 'a-strong-production-operator-password',
            'ADMIN_SESSION_SECRET' => str_repeat('production-session-secret-', 2),
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
}
