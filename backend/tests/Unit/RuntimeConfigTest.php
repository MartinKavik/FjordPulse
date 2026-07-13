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
