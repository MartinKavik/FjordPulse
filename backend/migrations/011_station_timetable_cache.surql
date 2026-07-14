-- Versioned, read-only-to-the-browser cache for on-demand station timetables.
-- No DEFINE EVENT: timetable pages are not part of the realtime event stream.

DEFINE FIELD OVERWRITE departure_window_started_at ON TABLE station_snapshot TYPE option<datetime>;
DEFINE FIELD OVERWRITE departure_window_ends_at ON TABLE station_snapshot TYPE option<datetime>;
DEFINE FIELD OVERWRITE departure_limit ON TABLE station_snapshot TYPE option<int> ASSERT $value = NONE OR $value > 0;
DEFINE FIELD OVERWRITE departure_has_more ON TABLE station_snapshot TYPE option<bool>;

DEFINE TABLE OVERWRITE station_timetable SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE timetable_id ON TABLE station_timetable TYPE string;
DEFINE FIELD OVERWRITE station_id ON TABLE station_timetable TYPE string;
DEFINE FIELD OVERWRITE service_date ON TABLE station_timetable TYPE string;
DEFINE FIELD OVERWRITE time_zone ON TABLE station_timetable TYPE string;
DEFINE FIELD OVERWRITE window_start ON TABLE station_timetable TYPE datetime;
DEFINE FIELD OVERWRITE window_end ON TABLE station_timetable TYPE datetime;
DEFINE FIELD OVERWRITE departures ON TABLE station_timetable TYPE array<object> FLEXIBLE DEFAULT [];
DEFINE FIELD OVERWRITE departures.* ON TABLE station_timetable TYPE object FLEXIBLE;
DEFINE FIELD OVERWRITE complete ON TABLE station_timetable TYPE bool;
DEFINE FIELD OVERWRITE fetched_at ON TABLE station_timetable TYPE datetime;
DEFINE FIELD OVERWRITE version ON TABLE station_timetable TYPE string;
DEFINE FIELD OVERWRITE expires_at ON TABLE station_timetable TYPE datetime;
DEFINE INDEX OVERWRITE station_timetable_id_unique ON TABLE station_timetable FIELDS timetable_id UNIQUE;
DEFINE INDEX OVERWRITE station_timetable_station_date_fetch ON TABLE station_timetable FIELDS station_id, service_date, fetched_at;
DEFINE INDEX OVERWRITE station_timetable_expiry ON TABLE station_timetable FIELDS expires_at;

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
            departureBoard: {
                windowStart: $after.departure_window_started_at ?? $after.updated_at,
                windowEnd: $after.departure_window_ends_at ?? ($after.updated_at + 1d),
                limit: $after.departure_limit ?? 20,
                hasMore: $after.departure_has_more ?? (array::len($after.departures) >= 20)
            },
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
