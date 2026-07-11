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
}
