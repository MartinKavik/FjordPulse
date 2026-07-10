<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class EnturRequestLog
{
    public function __construct(
        public string $id,
        public string $service,
        public string $scope,
        public DateTimeImmutable $requestedAt,
        public ?int $httpStatus,
        public int $latencyMs,
        public int $itemCount,
        public string $cache,
        public string $outcome,
        public ?DateTimeImmutable $retryAt,
        public string $requestId,
        public ?string $errorCode = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service' => $this->service,
            'scope' => $this->scope,
            'requestedAt' => $this->requestedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'outcome' => $this->outcome,
            'httpStatus' => $this->httpStatus,
            'latencyMs' => $this->latencyMs,
            'itemCount' => $this->itemCount,
            'cache' => $this->cache,
            'retryAt' => $this->retryAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'requestId' => $this->requestId,
            'errorCode' => $this->errorCode,
        ];
    }
}
