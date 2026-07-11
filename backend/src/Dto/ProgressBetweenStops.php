<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

final readonly class ProgressBetweenStops
{
    public function __construct(
        public ?float $linkDistance,
        public ?float $percentage,
    ) {
        if ($linkDistance !== null && $linkDistance < 0.0) {
            throw new \InvalidArgumentException('Progress link distance must be non-negative.');
        }
        if ($percentage !== null && ($percentage < 0.0 || $percentage > 1.0)) {
            throw new \InvalidArgumentException('Progress percentage must be between zero and one.');
        }
    }

    /** @return array{linkDistance: ?float, percentage: ?float} */
    public function toArray(): array
    {
        return [
            'linkDistance' => $this->linkDistance,
            'percentage' => $this->percentage,
        ];
    }
}
