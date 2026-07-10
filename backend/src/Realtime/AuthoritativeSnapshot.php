<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

final readonly class AuthoritativeSnapshot
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $type,
        public string $scope,
        public string $entityId,
        public string $version,
        public array $payload,
    ) {
    }

    /** @return array<string, mixed> */
    public function envelope(): array
    {
        return EnvelopeFactory::snapshot($this->type, $this->scope, $this->entityId, $this->version, $this->payload);
    }
}
