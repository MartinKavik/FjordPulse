<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use FjordPulse\Domain\BasemapId;

final readonly class Basemap
{
    public function __construct(
        public BasemapId $id,
        public string $label,
        public string $styleUrl,
    ) {
    }

    /** @return array{id: string, label: string, styleUrl: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'label' => $this->label,
            'styleUrl' => $this->styleUrl,
        ];
    }
}
