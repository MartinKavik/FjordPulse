<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use FjordPulse\Dto\VehicleObservation;

final readonly class VehicleObservationRepository extends AbstractSurrealRepository
{
    public function append(VehicleObservation $observation, DateTimeImmutable $expiresAt): VehicleObservation
    {
        $results = $this->connection->run(<<<'SURQL'
UPSERT ONLY type::record("vehicle_observation", type::string_lossy(encoding::base64::decode($observation_id))) CONTENT {
    observation_id: type::string_lossy(encoding::base64::decode($observation_id)),
    vehicle_id: type::string_lossy(encoding::base64::decode($vehicle_id)),
    latitude: $latitude,
    longitude: $longitude,
    bearing: $bearing ?? NONE,
    observed_at: type::datetime(type::string_lossy(encoding::base64::decode($observed_at))),
    version: type::string_lossy(encoding::base64::decode($version)),
    expires_at: type::datetime(type::string_lossy(encoding::base64::decode($expires_at)))
} RETURN AFTER;
SURQL, [
            'observation_id' => SurrealEncoding::string($observation->id),
            'vehicle_id' => SurrealEncoding::string($observation->vehicleId),
            'latitude' => $observation->coordinate->latitude,
            'longitude' => $observation->coordinate->longitude,
            'bearing' => $observation->bearing,
            'observed_at' => SurrealEncoding::string(self::timestamp($observation->observedAt)),
            'version' => SurrealEncoding::string(self::timestamp($observation->observedAt)),
            'expires_at' => SurrealEncoding::string(self::timestamp($expiresAt)),
        ]);

        return SurrealDtoMapper::observation(self::lastRecord($results, 'vehicle observation append'));
    }

    /** @return list<VehicleObservation> */
    public function recent(string $vehicleId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Observation limit must be between 1 and 500.');
        }

        $results = $this->connection->run(<<<'SURQL'
SELECT * FROM vehicle_observation
WHERE vehicle_id = type::string_lossy(encoding::base64::decode($vehicle_id))
ORDER BY observed_at DESC, observation_id DESC
LIMIT $limit;
SURQL, ['vehicle_id' => SurrealEncoding::string($vehicleId), 'limit' => $limit]);
        $observations = array_map(SurrealDtoMapper::observation(...), DatabaseRecord::many($results[0] ?? []));

        return array_reverse($observations);
    }
}
