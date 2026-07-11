<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Dto\MapConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MapConfig::class)]
#[CoversClass(RuntimeConfig::class)]
final class MapConfigTest extends TestCase
{
    public function testMapTilerConfigurationUsesOnlyFixedVersionedStyles(): void
    {
        $configuration = MapConfig::mapTiler('browser key/with unsafe characters')->toArray();

        self::assertSame('maptiler', $configuration['provider']);
        self::assertSame('satellite', $configuration['defaultBasemap']);
        self::assertSame([
            [
                'id' => 'satellite',
                'label' => 'Satellite',
                'styleUrl' => 'https://api.maptiler.com/maps/hybrid-v4/style.json?key=browser%20key%2Fwith%20unsafe%20characters',
            ],
            [
                'id' => 'streets',
                'label' => 'Map',
                'styleUrl' => 'https://api.maptiler.com/maps/streets-v4/style.json?key=browser%20key%2Fwith%20unsafe%20characters',
            ],
        ], $configuration['basemaps']);
    }

    public function testRuntimeConfigurationTreatsMissingOrWhitespaceMapKeyAsUnconfigured(): void
    {
        $previous = getenv('MAPTILER_API_KEY');
        try {
            putenv('MAPTILER_API_KEY=   ');
            self::assertNull(RuntimeConfig::fromEnvironment()->mapTilerApiKey);
            self::assertFalse(RuntimeConfig::fromEnvironment()->mapTilesConfigured());

            putenv('MAPTILER_API_KEY=protected-browser-key');
            self::assertSame('protected-browser-key', RuntimeConfig::fromEnvironment()->mapTilerApiKey);
            self::assertTrue(RuntimeConfig::fromEnvironment()->mapTilesConfigured());
        } finally {
            if (is_string($previous)) {
                putenv('MAPTILER_API_KEY=' . $previous);
            } else {
                putenv('MAPTILER_API_KEY');
            }
        }
    }

    public function testDatabaseDiagnosticStripsSecretsAndWarnsAboutStagingLoopback(): void
    {
        $variables = [
            'APP_ENV' => 'staging',
            'DATA_MODE' => 'real',
            'SURREAL_URL' => 'wss://database-user:database-secret@127.0.0.9:9443/rpc?token=query-secret#live',
            'SURREAL_NAMESPACE' => 'fjordpulse_ops',
            'SURREAL_DATABASE' => 'fjordpulse_staging',
        ];
        $previous = [];
        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            $diagnostic = RuntimeConfig::fromEnvironment()->databaseDiagnostic();

            self::assertSame('surrealdb', $diagnostic['engine']);
            self::assertSame('wss://127.0.0.9:9443', $diagnostic['endpointOrigin']);
            self::assertSame('fjordpulse_ops', $diagnostic['namespace']);
            self::assertSame('fjordpulse_staging', $diagnostic['name']);
            self::assertSame(
                'Loopback database target configured for staging; localhost resolves inside the running service.',
                $diagnostic['warning'],
            );
            $encoded = json_encode($diagnostic, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('database-user', $encoded);
            self::assertStringNotContainsString('database-secret', $encoded);
            self::assertStringNotContainsString('/rpc', $encoded);
            self::assertStringNotContainsString('query-secret', $encoded);

            putenv('APP_ENV=development');
            self::assertNull(RuntimeConfig::fromEnvironment()->databaseDiagnostic()['warning']);
        } finally {
            foreach ($previous as $name => $value) {
                if (is_string($value)) {
                    putenv($name . '=' . $value);
                } else {
                    putenv($name);
                }
            }
        }
    }
}
