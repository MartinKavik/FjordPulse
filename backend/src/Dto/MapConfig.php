<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use FjordPulse\Domain\BasemapId;

final readonly class MapConfig
{
    private const string MAPTILER_ORIGIN = 'https://api.maptiler.com';

    /** @param list<Basemap> $basemaps */
    public function __construct(
        public string $provider,
        public BasemapId $defaultBasemap,
        public array $basemaps,
    ) {
    }

    public static function mapTiler(string $apiKey): self
    {
        $encodedKey = rawurlencode($apiKey);

        return new self(
            'maptiler',
            BasemapId::Satellite,
            [
                new Basemap(
                    BasemapId::Satellite,
                    'Satellite',
                    self::MAPTILER_ORIGIN . '/maps/hybrid-v4/style.json?key=' . $encodedKey,
                ),
                new Basemap(
                    BasemapId::Streets,
                    'Map',
                    self::MAPTILER_ORIGIN . '/maps/streets-v4/style.json?key=' . $encodedKey,
                ),
            ],
        );
    }

    /** @return array{provider: string, defaultBasemap: string, basemaps: list<array{id: string, label: string, styleUrl: string}>} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'defaultBasemap' => $this->defaultBasemap->value,
            'basemaps' => array_map(
                static fn(Basemap $basemap): array => $basemap->toArray(),
                $this->basemaps,
            ),
        ];
    }
}
