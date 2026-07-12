<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

use DateTimeImmutable;
use FjordPulse\Dto\VehicleState;

final readonly class VehicleFreshnessPolicy
{
    public function __construct(
        private int $staleAfterSeconds = 30,
        private int $lostAfterSeconds = 300,
    ) {
        if ($staleAfterSeconds < 1 || $lostAfterSeconds <= $staleAfterSeconds) {
            throw new \InvalidArgumentException('Vehicle freshness thresholds are invalid.');
        }
    }

    public function at(DateTimeImmutable $observedAt, DateTimeImmutable $checkedAt): VehicleFreshness
    {
        $ageSeconds = max(0, $checkedAt->getTimestamp() - $observedAt->getTimestamp());

        return match (true) {
            $ageSeconds > $this->lostAfterSeconds => VehicleFreshness::Lost,
            $ageSeconds > $this->staleAfterSeconds => VehicleFreshness::Stale,
            default => VehicleFreshness::Live,
        };
    }

    public function withoutNewObservation(VehicleState $existing, DateTimeImmutable $checkedAt): VehicleState
    {
        $state = $this->at($existing->lastSeenAt, $checkedAt);
        if ($state === $existing->state || self::severity($state) < self::severity($existing->state)) {
            return $existing;
        }

        $semantic = $existing->toArray();
        $semantic['state'] = $state->value;
        unset($semantic['version'], $semantic['refreshedAt']);

        return new VehicleState(
            $existing->id,
            $checkedAt->format('Y-m-d\\TH:i:s.v\\Z'),
            hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            $state,
            $existing->coordinate,
            $existing->lineCode,
            $existing->routeName,
            $existing->destination,
            $existing->bearing,
            $existing->delaySeconds,
            $existing->distanceMeters,
            $existing->lastSeenAt,
            $checkedAt,
            $existing->nextStop,
            $existing->observations,
            $existing->journeyReference,
            $existing->monitoredCall,
            $existing->progressBetweenStops,
            $existing->journeyVersion,
            $existing->routeProgress,
            $checkedAt,
            $existing->transportMode,
            $existing->passengerServiceState,
        );
    }

    private static function severity(VehicleFreshness $state): int
    {
        return match ($state) {
            VehicleFreshness::Live => 0,
            VehicleFreshness::Stale => 1,
            VehicleFreshness::Lost => 2,
        };
    }
}
