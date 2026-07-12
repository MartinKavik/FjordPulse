-- Preserve Entur's authoritative vehicle mode through current state and realtime events.

DEFINE FIELD OVERWRITE transport_mode ON TABLE current_vehicle TYPE string DEFAULT "unknown"
ASSERT $value IN ["air", "bus", "coach", "ferry", "metro", "taxi", "tram", "rail", "unknown"];
UPDATE current_vehicle SET transport_mode = "unknown" WHERE transport_mode = NONE;

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
                transportMode: $after.transport_mode,
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
