<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleState;

final class NearbyVehicleSelector
{
    public const int DEFAULT_RADIUS_METERS = 5_000;
    public const float DEFAULT_RADIUS_KM = self::DEFAULT_RADIUS_METERS / 1_000.0;
    public const int DEFAULT_LIMIT = 20;

    private const float EARTH_RADIUS_METERS = 6_371_008.8;

    /**
     * @param list<VehicleState> $vehicles
     * @return list<VehicleState>
     */
    public static function select(
        Coordinate $center,
        array $vehicles,
        float $radiusKm = self::DEFAULT_RADIUS_KM,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        if (!is_finite($radiusKm) || $radiusKm <= 0.0) {
            throw new \InvalidArgumentException('Nearby vehicle radius must be a positive finite distance.');
        }
        $boundedLimit = max(0, min(100, $limit));
        if ($boundedLimit === 0) {
            return [];
        }

        $radiusMeters = $radiusKm * 1_000.0;
        /** @var list<array{vehicle: VehicleState, distance: float}> $matches */
        $matches = [];
        foreach ($vehicles as $vehicle) {
            if ($vehicle->coordinate === null) {
                continue;
            }
            $distance = self::distanceMeters($center, $vehicle->coordinate);
            if ($distance <= $radiusMeters) {
                $matches[] = ['vehicle' => $vehicle, 'distance' => $distance];
            }
        }
        usort($matches, static function (array $left, array $right): int {
            $byDistance = $left['distance'] <=> $right['distance'];

            return $byDistance !== 0
                ? $byDistance
                : $left['vehicle']->id <=> $right['vehicle']->id;
        });

        return array_map(
            static fn(array $match): VehicleState => $match['vehicle'],
            array_slice($matches, 0, $boundedLimit),
        );
    }

    private static function distanceMeters(Coordinate $left, Coordinate $right): float
    {
        $leftLatitude = deg2rad($left->latitude);
        $rightLatitude = deg2rad($right->latitude);
        $latitudeDelta = $rightLatitude - $leftLatitude;
        $longitudeDelta = deg2rad($right->longitude - $left->longitude);
        $a = sin($latitudeDelta / 2.0) ** 2
            + cos($leftLatitude) * cos($rightLatitude) * sin($longitudeDelta / 2.0) ** 2;
        $centralAngle = 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return self::EARTH_RADIUS_METERS * $centralAngle;
    }
}
