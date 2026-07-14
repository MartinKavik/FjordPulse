<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use FjordPulse\Domain\StationVehicleCallRole;
use FjordPulse\Domain\StationVehicleProgress;
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
                $call->order === 0 ? StationVehicleCallRole::StartsHere : StationVehicleCallRole::CallsHere,
                $this->progress($vehicle, $call),
                $call->displayAt(),
            );
        }

        $matches = array_values($matches);
        usort($matches, static function (StationVehicle $left, StationVehicle $right): int {
            $priority = static fn(StationVehicleProgress $progress): int => match ($progress) {
                StationVehicleProgress::AtStation => 0,
                StationVehicleProgress::BeforeStation => 1,
                StationVehicleProgress::Unknown => 2,
                StationVehicleProgress::AfterStation => 3,
            };
            $byProgress = $priority($left->progress) <=> $priority($right->progress);
            if ($byProgress !== 0) {
                return $byProgress;
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

    private function progress(
        VehicleState $vehicle,
        StationServiceCall $call,
    ): StationVehicleProgress {
        $monitored = $vehicle->monitoredCall;
        $sameMonitoredCall = $monitored !== null
            && $monitored->order === $call->order
            && ($monitored->stopPointRef === null || $call->quayId === $monitored->stopPointRef);
        if ($sameMonitoredCall && $monitored->vehicleAtStop) {
            return StationVehicleProgress::AtStation;
        }

        if ($call->actualDepartureAt !== null) {
            return StationVehicleProgress::AfterStation;
        }
        if ($monitored !== null) {
            if ($monitored->order < $call->order) {
                return StationVehicleProgress::BeforeStation;
            }
            if ($monitored->order > $call->order) {
                return StationVehicleProgress::AfterStation;
            }
            if ($sameMonitoredCall) {
                return StationVehicleProgress::BeforeStation;
            }
        }

        return StationVehicleProgress::Unknown;
    }
}
