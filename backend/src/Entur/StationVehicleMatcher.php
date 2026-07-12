<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use FjordPulse\Domain\StationVehicleRelation;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehiclePassengerServiceState;
use FjordPulse\Dto\StationServiceCall;
use FjordPulse\Dto\StationVehicle;
use FjordPulse\Dto\VehicleState;

final class StationVehicleMatcher
{
    /**
     * @param list<VehicleState> $vehicles
     * @param list<StationServiceCall> $calls
     * @return list<StationVehicle>
     */
    public function match(array $vehicles, array $calls, DateTimeImmutable $now): array
    {
        /** @var array<string, list<StationServiceCall>> $callsByJourney */
        $callsByJourney = [];
        foreach ($calls as $call) {
            if ($call->cancellation) {
                continue;
            }
            $callsByJourney[$call->journeyReference->key()][] = $call;
        }

        $matches = [];
        foreach ($vehicles as $vehicle) {
            if ($vehicle->state === VehicleFreshness::Lost
                || $vehicle->passengerServiceState === VehiclePassengerServiceState::NonPassenger) {
                continue;
            }
            $reference = $vehicle->journeyReference;
            if ($reference === null) {
                continue;
            }
            $journeyCalls = $callsByJourney[$reference->key()] ?? [];
            if ($journeyCalls === []) {
                continue;
            }
            $call = $this->bestCall($vehicle, $journeyCalls, $now);
            $matches[$vehicle->id] = new StationVehicle(
                $vehicle,
                $this->relation($vehicle, $call, $now),
                $call->displayAt(),
            );
        }

        $matches = array_values($matches);
        usort($matches, static function (StationVehicle $left, StationVehicle $right): int {
            $priority = static fn(StationVehicleRelation $relation): int => match ($relation) {
                StationVehicleRelation::AtStation => 0,
                StationVehicleRelation::StartingHere => 1,
                StationVehicleRelation::Approaching => 2,
                StationVehicleRelation::Departed => 3,
                StationVehicleRelation::ServesStation => 4,
            };
            $byRelation = $priority($left->relation) <=> $priority($right->relation);
            if ($byRelation !== 0) {
                return $byRelation;
            }
            $leftAt = $left->stationCallAt?->getTimestamp() ?? PHP_INT_MAX;
            $rightAt = $right->stationCallAt?->getTimestamp() ?? PHP_INT_MAX;
            $byTime = $leftAt <=> $rightAt;

            return $byTime !== 0 ? $byTime : $left->vehicle->id <=> $right->vehicle->id;
        });

        return $matches;
    }

    /**
     * @param list<StationServiceCall> $calls
     */
    private function bestCall(VehicleState $vehicle, array $calls, DateTimeImmutable $now): StationServiceCall
    {
        usort($calls, static fn(StationServiceCall $left, StationServiceCall $right): int => $left->order <=> $right->order);
        $monitored = $vehicle->monitoredCall;
        if ($monitored !== null) {
            foreach ($calls as $call) {
                if ($call->order === $monitored->order
                    && ($monitored->stopPointRef === null || $call->quayId === $monitored->stopPointRef)) {
                    return $call;
                }
            }
            foreach ($calls as $call) {
                if ($call->order >= $monitored->order) {
                    return $call;
                }
            }

            $last = array_pop($calls);
            if ($last === null) {
                throw new \LogicException('A matched station journey must contain at least one call.');
            }

            return $last;
        }

        usort($calls, static function (StationServiceCall $left, StationServiceCall $right) use ($now): int {
            $leftAt = $left->displayAt()?->getTimestamp() ?? PHP_INT_MAX;
            $rightAt = $right->displayAt()?->getTimestamp() ?? PHP_INT_MAX;
            $nowAt = $now->getTimestamp();
            $leftFuture = $leftAt >= $nowAt;
            $rightFuture = $rightAt >= $nowAt;
            if ($leftFuture !== $rightFuture) {
                return $leftFuture ? -1 : 1;
            }

            return abs($leftAt - $nowAt) <=> abs($rightAt - $nowAt);
        });

        $first = array_shift($calls);
        if ($first === null) {
            throw new \LogicException('A matched station journey must contain at least one call.');
        }

        return $first;
    }

    private function relation(
        VehicleState $vehicle,
        StationServiceCall $call,
        DateTimeImmutable $now,
    ): StationVehicleRelation {
        $monitored = $vehicle->monitoredCall;
        $sameMonitoredCall = $monitored !== null
            && $monitored->order === $call->order
            && ($monitored->stopPointRef === null || $call->quayId === $monitored->stopPointRef);
        if ($sameMonitoredCall && $monitored->vehicleAtStop) {
            return StationVehicleRelation::AtStation;
        }

        $callAt = $call->displayAt();
        $hasNotLeft = $call->actualDepartureAt === null
            && ($callAt === null || $callAt >= $now->modify('-5 minutes'));
        if ($call->actualDepartureAt !== null) {
            return StationVehicleRelation::Departed;
        }
        if ($call->order === 0 && (($monitored === null && $hasNotLeft) || $sameMonitoredCall)) {
            return StationVehicleRelation::StartingHere;
        }
        if ($monitored !== null) {
            if ($monitored->order < $call->order) {
                return StationVehicleRelation::Approaching;
            }
            if ($monitored->order > $call->order) {
                return StationVehicleRelation::Departed;
            }
            if ($sameMonitoredCall) {
                return StationVehicleRelation::Approaching;
            }
        }

        return StationVehicleRelation::ServesStation;
    }
}
