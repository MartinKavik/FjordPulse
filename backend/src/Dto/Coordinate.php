<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use InvalidArgumentException;

final readonly class Coordinate
{
    public function __construct(public float $latitude, public float $longitude)
    {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }
        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }

    /** @return array{latitude: float, longitude: float} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
