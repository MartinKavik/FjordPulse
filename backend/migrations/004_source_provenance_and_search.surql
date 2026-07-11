-- Make source provenance explicit and persist locale-independent search forms.
-- Existing databases intentionally have no healthy station_catalog status;
-- startup performs a complete, resumable import before serving station data.

DEFINE FIELD OVERWRITE source_mode ON TABLE station TYPE string DEFAULT "real"
    ASSERT $value IN ["fake", "real"];
DEFINE FIELD OVERWRITE catalog_id ON TABLE station TYPE option<string>;
DEFINE FIELD OVERWRITE search_name ON TABLE station TYPE string DEFAULT "";
DEFINE FIELD OVERWRITE search_locality ON TABLE station TYPE option<string>;
DEFINE FIELD OVERWRITE search_municipality ON TABLE station TYPE option<string>;
DEFINE FIELD OVERWRITE search_tokens ON TABLE station TYPE array<string> DEFAULT [];
DEFINE INDEX OVERWRITE station_source_catalog ON TABLE station FIELDS source_mode, catalog_id;
DEFINE INDEX OVERWRITE station_search_name ON TABLE station FIELDS search_name;

UPDATE station SET
    source_mode = IF source = "fake" { "fake" } ELSE { "real" },
    catalog_id = NONE,
    search_name = string::lowercase(name),
    search_locality = IF locality = NONE { NONE } ELSE { string::lowercase(locality) },
    search_municipality = IF municipality = NONE { NONE } ELSE { string::lowercase(municipality) },
    search_tokens = [];

DEFINE FIELD OVERWRITE search_text ON TABLE current_vehicle TYPE string DEFAULT "";
DEFINE FIELD OVERWRITE search_tokens ON TABLE current_vehicle TYPE array<string> DEFAULT [];
DEFINE INDEX OVERWRITE current_vehicle_search_text ON TABLE current_vehicle FIELDS search_text;

-- Catalog provenance changes can remove snapshots. Guard DELETE explicitly so
-- publication never dereferences the absent $after record.
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
            nearbyVehicles: $after.nearby_vehicles
        },
        source: "database_event",
        created_at: time::now()
    }
);
