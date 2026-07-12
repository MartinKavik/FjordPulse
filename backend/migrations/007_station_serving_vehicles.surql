-- Preserve vehicles matched to dated journeys that call at a selected station.
-- This remains part of the canonical station_snapshot -> realtime_event path.

DEFINE FIELD OVERWRITE serving_vehicles ON TABLE station_snapshot TYPE array<object> FLEXIBLE DEFAULT [];
DEFINE FIELD OVERWRITE serving_vehicles.* ON TABLE station_snapshot TYPE object FLEXIBLE;
DEFINE FIELD OVERWRITE serving_window_started_at ON TABLE station_snapshot TYPE option<datetime>;
DEFINE FIELD OVERWRITE serving_window_ends_at ON TABLE station_snapshot TYPE option<datetime>;
DEFINE FIELD OVERWRITE serving_candidate_journey_count ON TABLE station_snapshot TYPE int DEFAULT 0 ASSERT $value >= 0;
DEFINE FIELD OVERWRITE serving_queried_journey_count ON TABLE station_snapshot TYPE int DEFAULT 0 ASSERT $value >= 0;
DEFINE FIELD OVERWRITE serving_vehicles_truncated ON TABLE station_snapshot TYPE bool DEFAULT false;

DEFINE EVENT OVERWRITE publish_station_snapshot
ON TABLE station_snapshot
WHEN $event = "CREATE" OR ($event = "UPDATE" AND $before.content_hash != $after.content_hash)
THEN (
    CREATE realtime_event CONTENT {
        event_id: type::string(rand::uuid::v7()),
        type: "station_snapshot_changed",
        scope: "station:" + $after.station_id,
        entity_id: $after.station_id,
        version: $after.version,
        payload: {
            stationId: $after.station_id,
            state: $after.state,
            version: $after.version,
            updatedAt: $after.updated_at,
            lastSuccessfulAt: $after.last_successful_at ?? NULL,
            warning: $after.warning ?? NULL,
            departures: $after.departures,
            nearbyVehicles: $after.nearby_vehicles,
            servingVehicles: $after.serving_vehicles,
            servingVehicleCoverage: {
                windowStart: $after.serving_window_started_at ?? NULL,
                windowEnd: $after.serving_window_ends_at ?? NULL,
                candidateJourneyCount: $after.serving_candidate_journey_count,
                queriedJourneyCount: $after.serving_queried_journey_count,
                truncated: $after.serving_vehicles_truncated
            }
        },
        source: "database_event",
        created_at: time::now()
    }
);
