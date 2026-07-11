-- Cached Entur journey geometry/calls and compact journey references on current vehicles.
-- journey_snapshot intentionally has no DEFINE EVENT; current_vehicle remains the sole
-- notification trigger and carries only the journey version/reference.

DEFINE TABLE OVERWRITE journey_snapshot SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE journey_key ON TABLE journey_snapshot TYPE string;
DEFINE FIELD OVERWRITE service_journey_id ON TABLE journey_snapshot TYPE string;
DEFINE FIELD OVERWRITE operating_date ON TABLE journey_snapshot TYPE string;
DEFINE FIELD OVERWRITE dated_service_journey_id ON TABLE journey_snapshot TYPE option<string>;
DEFINE FIELD OVERWRITE version ON TABLE journey_snapshot TYPE string;
DEFINE FIELD OVERWRITE content_hash ON TABLE journey_snapshot TYPE string;
DEFINE FIELD OVERWRITE state ON TABLE journey_snapshot TYPE string ASSERT $value IN ["fresh", "stale", "unavailable", "error", "backoff", "rate_limited"];
DEFINE FIELD OVERWRITE route ON TABLE journey_snapshot TYPE option<object> FLEXIBLE;
DEFINE FIELD OVERWRITE calls ON TABLE journey_snapshot TYPE array<object> FLEXIBLE DEFAULT [];
DEFINE FIELD OVERWRITE calls.* ON TABLE journey_snapshot TYPE object FLEXIBLE;
DEFINE FIELD OVERWRITE refreshed_at ON TABLE journey_snapshot TYPE datetime;
DEFINE FIELD OVERWRITE last_successful_at ON TABLE journey_snapshot TYPE option<datetime>;
DEFINE FIELD OVERWRITE warning ON TABLE journey_snapshot TYPE option<string>;
DEFINE INDEX OVERWRITE journey_snapshot_key_unique ON TABLE journey_snapshot FIELDS journey_key UNIQUE;
DEFINE INDEX OVERWRITE journey_snapshot_identity ON TABLE journey_snapshot FIELDS service_journey_id, operating_date UNIQUE;
DEFINE INDEX OVERWRITE journey_snapshot_refreshed ON TABLE journey_snapshot FIELDS refreshed_at;

DEFINE FIELD OVERWRITE journey_reference ON TABLE current_vehicle TYPE option<object> FLEXIBLE;
DEFINE FIELD OVERWRITE monitored_call ON TABLE current_vehicle TYPE option<object> FLEXIBLE;
DEFINE FIELD OVERWRITE progress_between_stops ON TABLE current_vehicle TYPE option<object> FLEXIBLE;
DEFINE FIELD OVERWRITE journey_version ON TABLE current_vehicle TYPE option<string>;
DEFINE FIELD OVERWRITE route_progress ON TABLE current_vehicle TYPE option<float> ASSERT $value = NONE OR ($value >= 0.0 AND $value <= 1.0);
DEFINE FIELD OVERWRITE refreshed_at ON TABLE current_vehicle TYPE option<datetime>;

DEFINE EVENT OVERWRITE publish_current_vehicle
ON TABLE current_vehicle
WHEN $event = "CREATE" OR ($event = "UPDATE" AND $before.content_hash != $after.content_hash)
THEN (
    CREATE realtime_event CONTENT {
        event_id: type::string(rand::uuid::v7()),
        type: IF $after.state = "lost" {
            "vehicle_lost"
        } ELSE IF $after.state = "stale" {
            "vehicle_stale"
        } ELSE {
            "vehicle_moved"
        },
        scope: "vehicle:" + $after.vehicle_id,
        entity_id: $after.vehicle_id,
        version: $after.version,
        payload: {
            vehicle: {
                id: $after.vehicle_id,
                lineCode: $after.line_code ?? NULL,
                routeName: $after.route_name ?? NULL,
                destination: $after.destination ?? NULL,
                state: $after.state,
                latitude: $after.latitude ?? NULL,
                longitude: $after.longitude ?? NULL,
                bearing: $after.bearing ?? NULL,
                delaySeconds: $after.delay_seconds ?? NULL,
                distanceMeters: $after.distance_meters ?? NULL,
                lastSeenAt: $after.last_seen_at,
                refreshedAt: $after.refreshed_at ?? $after.updated_at,
                version: $after.version,
                nextStop: $after.next_stop ?? NULL,
                journeyReference: $after.journey_reference ?? NULL,
                monitoredCall: $after.monitored_call ?? NULL,
                progressBetweenStops: $after.progress_between_stops ?? NULL,
                journeyVersion: $after.journey_version ?? NULL,
                routeProgress: $after.route_progress ?? NULL
            },
            observation: IF $after.latitude != NONE AND $after.longitude != NONE {
                {
                    latitude: $after.latitude,
                    longitude: $after.longitude,
                    bearing: $after.bearing ?? NULL,
                    observedAt: $after.last_seen_at
                }
            } ELSE {
                NULL
            }
        },
        source: "database_event",
        created_at: time::now()
    }
);
