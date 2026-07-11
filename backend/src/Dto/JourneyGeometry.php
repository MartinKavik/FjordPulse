<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

final readonly class JourneyGeometry
{
    /** @param list<Coordinate> $coordinates */
    public function __construct(
        public array $coordinates,
        public ?float $distanceMeters,
    ) {
        if (count($coordinates) < 2 || count($coordinates) > 20_000) {
            throw new \InvalidArgumentException('Journey geometry must contain between 2 and 20,000 coordinates.');
        }
        if ($distanceMeters !== null && $distanceMeters < 0.0) {
            throw new \InvalidArgumentException('Journey geometry distance must be non-negative.');
        }
    }

    /** @return array{type: string, coordinates: list<array{0: float, 1: float}>, distanceMeters: ?float} */
    public function toArray(): array
    {
        return [
            'type' => 'LineString',
            'coordinates' => array_map(
                static fn(Coordinate $coordinate): array => [$coordinate->longitude, $coordinate->latitude],
                $this->coordinates,
            ),
            'distanceMeters' => $this->distanceMeters,
        ];
    }
}
