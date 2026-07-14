<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class DepartureBoard
{
    public function __construct(
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
        public int $limit,
        public bool $hasMore,
    ) {
        if ($windowEnd <= $windowStart || $limit < 1) {
            throw new \InvalidArgumentException('Departure board coverage is invalid.');
        }
    }

    /** @return array{windowStart: string, windowEnd: string, limit: int, hasMore: bool} */
    public function toArray(): array
    {
        return [
            'windowStart' => $this->windowStart->format(DateTimeInterface::RFC3339_EXTENDED),
            'windowEnd' => $this->windowEnd->format(DateTimeInterface::RFC3339_EXTENDED),
            'limit' => $this->limit,
            'hasMore' => $this->hasMore,
        ];
    }

    /** @return array{windowEnd: string, limit: int, hasMore: bool} */
    public function semanticArray(): array
    {
        return [
            'windowEnd' => $this->windowEnd->format(DateTimeInterface::RFC3339_EXTENDED),
            'limit' => $this->limit,
            'hasMore' => $this->hasMore,
        ];
    }
}
