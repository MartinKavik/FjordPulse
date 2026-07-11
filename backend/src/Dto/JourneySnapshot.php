<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\SourceState;

final readonly class JourneySnapshot
{
    /** @param list<StopCall> $calls */
    public function __construct(
        public string $serviceJourneyId,
        public string $operatingDate,
        public ?string $datedServiceJourneyId,
        public string $version,
        public string $contentHash,
        public SourceState $state,
        public ?JourneyGeometry $route,
        public array $calls,
        public DateTimeImmutable $refreshedAt,
        public ?DateTimeImmutable $lastSuccessfulAt,
        public ?string $warning = null,
    ) {
        if ($serviceJourneyId === '' || count($calls) > 1_000) {
            throw new \InvalidArgumentException('Journey identity is required and calls are limited to 1,000.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $operatingDate);
        if ($date === false || $date->format('Y-m-d') !== $operatingDate) {
            throw new \InvalidArgumentException('Journey operating date must use YYYY-MM-DD.');
        }
        if (!in_array($state, [SourceState::Fresh, SourceState::Stale, SourceState::Unavailable, SourceState::Error, SourceState::Backoff, SourceState::RateLimited], true)) {
            throw new \InvalidArgumentException('Journey snapshot uses an unsupported source state.');
        }
    }

    public function key(): string
    {
        return $this->serviceJourneyId . '|' . $this->operatingDate;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'serviceJourneyId' => $this->serviceJourneyId,
            'operatingDate' => $this->operatingDate,
            'datedServiceJourneyId' => $this->datedServiceJourneyId,
            'version' => $this->version,
            'state' => $this->state->value,
            'route' => $this->route?->toArray(),
            'calls' => array_map(static fn(StopCall $call): array => $call->toArray(), $this->calls),
            'refreshedAt' => $this->refreshedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'lastSuccessfulAt' => $this->lastSuccessfulAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'warning' => $this->warning,
        ];
    }
}
