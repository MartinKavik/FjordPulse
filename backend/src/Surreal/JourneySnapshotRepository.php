<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use FjordPulse\Dto\JourneySnapshot;

final readonly class JourneySnapshotRepository extends AbstractSurrealRepository
{
    public function save(JourneySnapshot $journey): JourneySnapshot
    {
        $results = $this->connection->run(<<<'SURQL'
UPDATE ONLY type::record("journey_snapshot", type::string_lossy(encoding::base64::decode($journey_key))) SET
    refreshed_at = type::datetime(type::string_lossy(encoding::base64::decode($refreshed_at))),
    last_successful_at = IF $last_successful_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($last_successful_at))) }
WHERE content_hash = type::string_lossy(encoding::base64::decode($content_hash));
UPSERT ONLY type::record("journey_snapshot", type::string_lossy(encoding::base64::decode($journey_key))) CONTENT {
    journey_key: type::string_lossy(encoding::base64::decode($journey_key)),
    service_journey_id: type::string_lossy(encoding::base64::decode($service_journey_id)),
    operating_date: type::string_lossy(encoding::base64::decode($operating_date)),
    dated_service_journey_id: IF $dated_service_journey_id = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($dated_service_journey_id)) },
    version: type::string_lossy(encoding::base64::decode($version)),
    content_hash: type::string_lossy(encoding::base64::decode($content_hash)),
    state: type::string_lossy(encoding::base64::decode($state)),
    route: IF $route = NULL { NONE } ELSE { encoding::json::decode($route) },
    calls: encoding::json::decode($calls),
    refreshed_at: type::datetime(type::string_lossy(encoding::base64::decode($refreshed_at))),
    last_successful_at: IF $last_successful_at = NULL { NONE } ELSE { type::datetime(type::string_lossy(encoding::base64::decode($last_successful_at))) },
    warning: IF $warning = NULL { NONE } ELSE { type::string_lossy(encoding::base64::decode($warning)) }
}
WHERE (version = NONE OR type::datetime(type::string_lossy(encoding::base64::decode($version))) > type::datetime(version))
  AND (content_hash = NONE OR content_hash != type::string_lossy(encoding::base64::decode($content_hash)))
RETURN AFTER;
SELECT * FROM ONLY type::record("journey_snapshot", type::string_lossy(encoding::base64::decode($journey_key)));
SURQL, [
            'journey_key' => SurrealEncoding::string($journey->key()),
            'service_journey_id' => SurrealEncoding::string($journey->serviceJourneyId),
            'operating_date' => SurrealEncoding::string($journey->operatingDate),
            'dated_service_journey_id' => SurrealEncoding::nullableString($journey->datedServiceJourneyId),
            'version' => SurrealEncoding::string($journey->version),
            'content_hash' => SurrealEncoding::string($journey->contentHash),
            'state' => SurrealEncoding::string($journey->state->value),
            'route' => $journey->route === null ? null : SurrealEncoding::json($journey->route->toArray()),
            'calls' => SurrealEncoding::json(array_map(static fn($call): array => $call->toArray(), $journey->calls)),
            'refreshed_at' => SurrealEncoding::string(self::timestamp($journey->refreshedAt)),
            'last_successful_at' => $journey->lastSuccessfulAt === null ? null : SurrealEncoding::string(self::timestamp($journey->lastSuccessfulAt)),
            'warning' => SurrealEncoding::nullableString($journey->warning),
        ]);

        return SurrealDtoMapper::journeySnapshot(self::lastRecord($results, 'journey snapshot save'));
    }

    public function find(string $serviceJourneyId, string $operatingDate): ?JourneySnapshot
    {
        $results = $this->connection->run(
            'SELECT * FROM ONLY type::record("journey_snapshot", type::string_lossy(encoding::base64::decode($journey_key)));',
            ['journey_key' => SurrealEncoding::string($serviceJourneyId . '|' . $operatingDate)],
        );
        $record = DatabaseRecord::one($results[0] ?? null);

        return $record === null ? null : SurrealDtoMapper::journeySnapshot($record);
    }
}
