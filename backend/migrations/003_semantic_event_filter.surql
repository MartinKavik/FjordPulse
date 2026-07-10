-- Publication is semantic, not clock-driven. A refresh that only proposes a
-- newer timestamp/version must not create another durable notification.

DEFINE EVENT OVERWRITE publish_station_snapshot
ON TABLE station_snapshot
WHEN $event = "CREATE" OR $before.content_hash != $after.content_hash
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
            nearbyVehicles: $after.nearby_vehicles
        },
        source: "database_event",
        created_at: time::now()
    }
);

DEFINE EVENT OVERWRITE publish_current_vehicle
ON TABLE current_vehicle
WHEN $event = "CREATE" OR $before.content_hash != $after.content_hash
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
                version: $after.version,
                nextStop: $after.next_stop ?? NULL
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
