<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleJourneyReference;

final class DegradedJourneyFactory
{
    public static function create(
        VehicleJourneyReference $reference,
        ?JourneySnapshot $cached,
        SourceState $state,
        string $warning,
        DateTimeImmutable $now,
    ): JourneySnapshot {
        $message = $warning !== '' ? $warning : 'Journey details are temporarily unavailable.';
        $semantic = [
            $reference->key(),
            $state->value,
            $message,
            $cached?->route?->toArray(),
            $cached === null ? [] : array_map(static fn(StopCall $call): array => $call->toArray(), $cached->calls),
            $cached?->lastSuccessfulAt?->format(DateTimeInterface::RFC3339_EXTENDED),
        ];

        return new JourneySnapshot(
            $reference->serviceJourneyId,
            $reference->operatingDate,
            $reference->datedServiceJourneyId,
            $now->format('Y-m-d\\TH:i:s.v\\Z'),
            hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            $state,
            $cached?->route,
            $cached !== null ? $cached->calls : [],
            $now,
            $cached?->lastSuccessfulAt,
            $message,
        );
    }
}
