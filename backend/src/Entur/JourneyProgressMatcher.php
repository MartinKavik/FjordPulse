<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\JourneyGeometry;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\StopCall;
use FjordPulse\Dto\VehicleState;

final class JourneyProgressMatcher
{
    private const float CALL_FRACTION_TOLERANCE = 0.002;

    public function enrich(VehicleState $vehicle, JourneySnapshot $journey): VehicleState
    {
        $callIndex = $this->callIndex($vehicle, $journey);
        $atStop = $callIndex !== null && $this->atMatchedStop($vehicle, $journey->calls[$callIndex]);
        $nextIndex = $callIndex === null
            ? null
            : $callIndex + ($atStop ? 1 : 0);
        $nextStop = $nextIndex !== null ? ($journey->calls[$nextIndex] ?? null) : null;
        $routeProgress = $this->routeProgress($vehicle, $journey, $callIndex);
        $semantic = $vehicle->toArray();
        unset($semantic['version'], $semantic['refreshedAt']);
        $semantic['nextStop'] = $nextStop?->toArray();
        $semantic['journeyVersion'] = $journey->version;
        $semantic['routeProgress'] = $routeProgress;

        return new VehicleState(
            $vehicle->id,
            $vehicle->version,
            hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            $vehicle->state,
            $vehicle->coordinate,
            $vehicle->lineCode,
            $vehicle->routeName,
            $vehicle->destination,
            $vehicle->bearing,
            $vehicle->delaySeconds,
            $vehicle->distanceMeters,
            $vehicle->lastSeenAt,
            $vehicle->updatedAt,
            $nextStop,
            $vehicle->observations,
            $vehicle->journeyReference,
            $vehicle->monitoredCall,
            $vehicle->progressBetweenStops,
            $journey->version,
            $routeProgress,
            $vehicle->refreshedAt,
        );
    }

    /** @return list<StopCall> */
    public function upcoming(JourneySnapshot $journey, VehicleState $vehicle): array
    {
        $index = $this->callIndex($vehicle, $journey);
        if ($index === null) {
            return [];
        }
        if ($this->atMatchedStop($vehicle, $journey->calls[$index])) {
            $index++;
        }

        return array_slice($journey->calls, $index);
    }

    private function callIndex(VehicleState $vehicle, JourneySnapshot $journey): ?int
    {
        $monitored = $vehicle->monitoredCall;
        if ($journey->calls === []) {
            return null;
        }

        if ($monitored !== null) {
            foreach ($journey->calls as $index => $call) {
                if ($call->order === $monitored->order
                    && ($monitored->stopPointRef === null || $call->quayId === $monitored->stopPointRef)) {
                    return $index;
                }
            }
            if ($monitored->stopPointRef !== null) {
                foreach ($journey->calls as $index => $call) {
                    if ($call->quayId === $monitored->stopPointRef) {
                        return $index;
                    }
                }
            }
            foreach ($journey->calls as $index => $call) {
                if ($call->order === $monitored->order) {
                    return $index;
                }
            }
        }

        return $this->inferredCallIndex($vehicle, $journey);
    }

    private function inferredCallIndex(VehicleState $vehicle, JourneySnapshot $journey): ?int
    {
        if ($vehicle->coordinate !== null && $journey->route !== null) {
            $fractions = $this->callFractions($journey->calls, $journey->route);
            $vehicleFraction = $this->coordinateFraction($vehicle->coordinate, $journey->route);
            foreach ($fractions as $index => $callFraction) {
                if ($callFraction + self::CALL_FRACTION_TOLERANCE >= $vehicleFraction) {
                    return $index;
                }
            }
            $lastIndex = array_key_last($fractions);
            if ($lastIndex !== null) {
                $lastCall = $journey->calls[$lastIndex];
                $lastTime = $this->effectiveCallTime($lastCall, $vehicle->delaySeconds);
                if ($lastTime !== null
                    && $lastTime < $vehicle->lastSeenAt
                    && $vehicleFraction >= $fractions[$lastIndex] - self::CALL_FRACTION_TOLERANCE) {
                    return null;
                }

                // Geometry can extend beyond the final stop or be imprecise. Keep
                // the last call visible unless both position and service time
                // establish that the journey has actually finished.
                return $lastIndex;
            }
        }

        $foundTimedCall = false;
        foreach ($journey->calls as $index => $call) {
            $time = $this->effectiveCallTime($call, $vehicle->delaySeconds);
            if ($time === null) {
                continue;
            }
            $foundTimedCall = true;
            if ($time >= $vehicle->lastSeenAt) {
                return $index;
            }
        }
        if ($foundTimedCall) {
            return null;
        }

        // With no monitored call, usable geometry, or schedule time, showing the
        // known journey is more truthful than claiming it has no remaining stops.
        return 0;
    }

    private function effectiveCallTime(StopCall $call, ?int $delaySeconds): ?\DateTimeImmutable
    {
        $expected = $call->expectedArrivalAt ?? $call->expectedDepartureAt;
        if ($expected !== null) {
            return $expected;
        }
        $aimed = $call->aimedArrivalAt ?? $call->aimedDepartureAt;
        if ($aimed === null || $delaySeconds === null || $delaySeconds === 0) {
            return $aimed;
        }

        return $aimed->modify(sprintf('%+d seconds', $delaySeconds));
    }

    private function routeProgress(VehicleState $vehicle, JourneySnapshot $journey, ?int $callIndex): ?float
    {
        $route = $journey->route;
        if ($route === null) {
            return null;
        }
        $fractions = $this->callFractions($journey->calls, $route);
        if ($callIndex !== null && isset($fractions[$callIndex])) {
            if ($this->atMatchedStop($vehicle, $journey->calls[$callIndex])) {
                return $fractions[$callIndex];
            }
            $to = $fractions[$callIndex];
            $from = $fractions[max(0, $callIndex - 1)] ?? $to;
            $percentage = $vehicle->progressBetweenStops?->percentage;
            if ($percentage !== null) {
                return min(1.0, max(0.0, $from + (($to - $from) * $percentage)));
            }
        }
        if ($vehicle->coordinate === null) {
            return $callIndex !== null ? ($fractions[$callIndex] ?? null) : null;
        }

        return $this->coordinateFraction($vehicle->coordinate, $route);
    }

    private function atMatchedStop(VehicleState $vehicle, StopCall $call): bool
    {
        $monitored = $vehicle->monitoredCall;
        if ($monitored === null || !$monitored->vehicleAtStop) {
            return false;
        }

        return ($monitored->stopPointRef !== null && $call->quayId === $monitored->stopPointRef)
            || $call->order === $monitored->order;
    }

    /**
     * @param list<StopCall> $calls
     * @return array<int, float>
     */
    private function callFractions(array $calls, JourneyGeometry $route): array
    {
        $cumulative = $this->cumulativeDistances($route->coordinates);
        $total = $cumulative[count($cumulative) - 1];
        if ($total <= 0.0) {
            return [];
        }
        $minimumIndex = 0;
        $fractions = [];
        foreach ($calls as $callIndex => $call) {
            if ($call->coordinate === null) {
                continue;
            }
            $nearest = $minimumIndex;
            $distance = INF;
            for ($index = $minimumIndex, $count = count($route->coordinates); $index < $count; $index++) {
                $candidate = self::distance($call->coordinate, $route->coordinates[$index]);
                if ($candidate < $distance) {
                    $distance = $candidate;
                    $nearest = $index;
                }
            }
            if ($callIndex > 0 && $nearest === $minimumIndex && $minimumIndex + 1 < count($route->coordinates)) {
                $laterNearest = $minimumIndex + 1;
                $laterDistance = INF;
                for ($index = $minimumIndex + 1, $count = count($route->coordinates); $index < $count; $index++) {
                    $candidate = self::distance($call->coordinate, $route->coordinates[$index]);
                    if ($candidate < $laterDistance) {
                        $laterDistance = $candidate;
                        $laterNearest = $index;
                    }
                }
                if ($laterDistance <= max(30.0, $distance + 5.0)) {
                    $nearest = $laterNearest;
                }
            }
            $minimumIndex = $nearest;
            $fractions[$callIndex] = $cumulative[$nearest] / $total;
        }

        return $fractions;
    }

    private function coordinateFraction(Coordinate $coordinate, JourneyGeometry $route): float
    {
        $cumulative = $this->cumulativeDistances($route->coordinates);
        $total = $cumulative[count($cumulative) - 1];
        if ($total <= 0.0) {
            return 0.0;
        }
        $nearest = 0;
        $distance = INF;
        foreach ($route->coordinates as $index => $candidate) {
            $candidateDistance = self::distance($coordinate, $candidate);
            if ($candidateDistance < $distance) {
                $distance = $candidateDistance;
                $nearest = $index;
            }
        }

        return $cumulative[$nearest] / $total;
    }

    /**
     * @param list<Coordinate> $coordinates
     * @return list<float>
     */
    private function cumulativeDistances(array $coordinates): array
    {
        $cumulative = [0.0];
        for ($index = 1, $count = count($coordinates); $index < $count; $index++) {
            $cumulative[] = $cumulative[$index - 1] + self::distance($coordinates[$index - 1], $coordinates[$index]);
        }

        return $cumulative;
    }

    private static function distance(Coordinate $left, Coordinate $right): float
    {
        $latitude = deg2rad(($left->latitude + $right->latitude) / 2.0);
        $x = deg2rad($right->longitude - $left->longitude) * cos($latitude);
        $y = deg2rad($right->latitude - $left->latitude);

        return 6_371_000.0 * sqrt(($x * $x) + ($y * $y));
    }
}
