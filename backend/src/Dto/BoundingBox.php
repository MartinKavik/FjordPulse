<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use InvalidArgumentException;

final readonly class BoundingBox
{
    public function __construct(
        public float $minLongitude,
        public float $minLatitude,
        public float $maxLongitude,
        public float $maxLatitude,
    ) {
        if ($minLongitude < -180.0 || $maxLongitude > 180.0 || $minLongitude >= $maxLongitude) {
            throw new InvalidArgumentException('Invalid longitude bounds.');
        }
        if ($minLatitude < -90.0 || $maxLatitude > 90.0 || $minLatitude >= $maxLatitude) {
            throw new InvalidArgumentException('Invalid latitude bounds.');
        }
    }

    public function contains(Coordinate $coordinate): bool
    {
        return $coordinate->longitude >= $this->minLongitude
            && $coordinate->longitude <= $this->maxLongitude
            && $coordinate->latitude >= $this->minLatitude
            && $coordinate->latitude <= $this->maxLatitude;
    }
}
