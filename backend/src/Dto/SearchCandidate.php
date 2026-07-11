<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

final readonly class SearchCandidate
{
    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        public array $result,
        public int $rank,
        public int $typePriority,
        public int $entityPriority,
        public string $normalizedLabel,
        public string $id,
    ) {
    }
}
