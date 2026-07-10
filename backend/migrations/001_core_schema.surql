-- FjordPulse canonical state, diagnostics, and migration ledger.
-- Runtime access is exclusively through database-scoped system credentials.

DEFINE TABLE OVERWRITE schema_migration SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE name ON TABLE schema_migration TYPE string;
DEFINE FIELD OVERWRITE checksum ON TABLE schema_migration TYPE string;
DEFINE FIELD OVERWRITE applied_at ON TABLE schema_migration TYPE datetime;
DEFINE INDEX OVERWRITE schema_migration_name_unique ON TABLE schema_migration FIELDS name UNIQUE;

DEFINE TABLE OVERWRITE station SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE station_id ON TABLE station TYPE string;
DEFINE FIELD OVERWRITE name ON TABLE station TYPE string;
DEFINE FIELD OVERWRITE kind ON TABLE station TYPE string ASSERT $value IN [
    "stop_place", "station", "bus_station", "ferry_terminal", "rail_station",
    "tram_stop", "metro_station", "airport", "unknown"
];
DEFINE FIELD OVERWRITE locality ON TABLE station TYPE option<string>;
DEFINE FIELD OVERWRITE municipality ON TABLE station TYPE option<string>;
DEFINE FIELD OVERWRITE transport_modes ON TABLE station TYPE array<string> DEFAULT [];
DEFINE FIELD OVERWRITE latitude ON TABLE station TYPE float ASSERT $value >= -90.0 AND $value <= 90.0;
DEFINE FIELD OVERWRITE longitude ON TABLE station TYPE float ASSERT $value >= -180.0 AND $value <= 180.0;
DEFINE FIELD OVERWRITE search_text ON TABLE station TYPE string;
DEFINE FIELD OVERWRITE source ON TABLE station TYPE string;
DEFINE FIELD OVERWRITE source_version ON TABLE station TYPE option<string>;
DEFINE FIELD OVERWRITE imported_at ON TABLE station TYPE datetime;
DEFINE FIELD OVERWRITE updated_at ON TABLE station TYPE datetime;
DEFINE FIELD OVERWRITE metadata ON TABLE station TYPE object FLEXIBLE DEFAULT {};
DEFINE INDEX OVERWRITE station_station_id_unique ON TABLE station FIELDS station_id UNIQUE;
DEFINE INDEX OVERWRITE station_name ON TABLE station FIELDS name;
DEFINE INDEX OVERWRITE station_coordinates ON TABLE station FIELDS latitude, longitude;

DEFINE TABLE OVERWRITE station_snapshot SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE station_id ON TABLE station_snapshot TYPE string;
DEFINE FIELD OVERWRITE state ON TABLE station_snapshot TYPE string ASSERT $value IN [
    "loading", "fresh", "refreshing", "empty", "stale", "unavailable", "error", "backoff", "rate_limited"
];
DEFINE FIELD OVERWRITE version ON TABLE station_snapshot TYPE string;
DEFINE FIELD OVERWRITE content_hash ON TABLE station_snapshot TYPE string;
DEFINE FIELD OVERWRITE updated_at ON TABLE station_snapshot TYPE datetime;
DEFINE FIELD OVERWRITE last_successful_at ON TABLE station_snapshot TYPE option<datetime>;
DEFINE FIELD OVERWRITE warning ON TABLE station_snapshot TYPE option<string>;
DEFINE FIELD OVERWRITE departures ON TABLE station_snapshot TYPE array<object> FLEXIBLE DEFAULT [];
DEFINE FIELD OVERWRITE departures.* ON TABLE station_snapshot TYPE object FLEXIBLE;
DEFINE FIELD OVERWRITE nearby_vehicles ON TABLE station_snapshot TYPE array<object> FLEXIBLE DEFAULT [];
DEFINE FIELD OVERWRITE nearby_vehicles.* ON TABLE station_snapshot TYPE object FLEXIBLE;
DEFINE INDEX OVERWRITE station_snapshot_station_id_unique ON TABLE station_snapshot FIELDS station_id UNIQUE;
DEFINE INDEX OVERWRITE station_snapshot_updated_at ON TABLE station_snapshot FIELDS updated_at;

DEFINE TABLE OVERWRITE current_vehicle SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE vehicle_id ON TABLE current_vehicle TYPE string;
DEFINE FIELD OVERWRITE line_code ON TABLE current_vehicle TYPE option<string>;
DEFINE FIELD OVERWRITE route_name ON TABLE current_vehicle TYPE option<string>;
DEFINE FIELD OVERWRITE destination ON TABLE current_vehicle TYPE option<string>;
DEFINE FIELD OVERWRITE state ON TABLE current_vehicle TYPE string ASSERT $value IN ["live", "stale", "lost"];
DEFINE FIELD OVERWRITE latitude ON TABLE current_vehicle TYPE option<float> ASSERT $value = NONE OR ($value >= -90.0 AND $value <= 90.0);
DEFINE FIELD OVERWRITE longitude ON TABLE current_vehicle TYPE option<float> ASSERT $value = NONE OR ($value >= -180.0 AND $value <= 180.0);
DEFINE FIELD OVERWRITE bearing ON TABLE current_vehicle TYPE option<float> ASSERT $value = NONE OR ($value >= 0.0 AND $value <= 360.0);
DEFINE FIELD OVERWRITE delay_seconds ON TABLE current_vehicle TYPE option<int>;
DEFINE FIELD OVERWRITE distance_meters ON TABLE current_vehicle TYPE option<float> ASSERT $value = NONE OR $value >= 0.0;
DEFINE FIELD OVERWRITE last_seen_at ON TABLE current_vehicle TYPE datetime;
DEFINE FIELD OVERWRITE version ON TABLE current_vehicle TYPE string;
DEFINE FIELD OVERWRITE content_hash ON TABLE current_vehicle TYPE string;
DEFINE FIELD OVERWRITE next_stop ON TABLE current_vehicle TYPE option<object> FLEXIBLE;
DEFINE FIELD OVERWRITE updated_at ON TABLE current_vehicle TYPE datetime;
DEFINE INDEX OVERWRITE current_vehicle_vehicle_id_unique ON TABLE current_vehicle FIELDS vehicle_id UNIQUE;
DEFINE INDEX OVERWRITE current_vehicle_state_updated_at ON TABLE current_vehicle FIELDS state, updated_at;

DEFINE TABLE OVERWRITE vehicle_observation SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE observation_id ON TABLE vehicle_observation TYPE string;
DEFINE FIELD OVERWRITE vehicle_id ON TABLE vehicle_observation TYPE string;
DEFINE FIELD OVERWRITE latitude ON TABLE vehicle_observation TYPE float ASSERT $value >= -90.0 AND $value <= 90.0;
DEFINE FIELD OVERWRITE longitude ON TABLE vehicle_observation TYPE float ASSERT $value >= -180.0 AND $value <= 180.0;
DEFINE FIELD OVERWRITE bearing ON TABLE vehicle_observation TYPE option<float> ASSERT $value = NONE OR ($value >= 0.0 AND $value <= 360.0);
DEFINE FIELD OVERWRITE observed_at ON TABLE vehicle_observation TYPE datetime;
DEFINE FIELD OVERWRITE version ON TABLE vehicle_observation TYPE string;
DEFINE FIELD OVERWRITE expires_at ON TABLE vehicle_observation TYPE datetime;
DEFINE INDEX OVERWRITE vehicle_observation_id_unique ON TABLE vehicle_observation FIELDS observation_id UNIQUE;
DEFINE INDEX OVERWRITE vehicle_observation_vehicle_time ON TABLE vehicle_observation FIELDS vehicle_id, observed_at;
DEFINE INDEX OVERWRITE vehicle_observation_expiry ON TABLE vehicle_observation FIELDS expires_at;

DEFINE TABLE OVERWRITE watch SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE watch_id ON TABLE watch TYPE string;
DEFINE FIELD OVERWRITE type ON TABLE watch TYPE string ASSERT $value IN ["station", "vehicle", "focus"];
DEFINE FIELD OVERWRITE scope ON TABLE watch TYPE string;
DEFINE FIELD OVERWRITE entity_id ON TABLE watch TYPE string;
DEFINE FIELD OVERWRITE client_count ON TABLE watch TYPE int ASSERT $value >= 0;
DEFINE FIELD OVERWRITE priority ON TABLE watch TYPE string ASSERT $value IN ["background", "station", "selected_vehicle", "focus"];
DEFINE FIELD OVERWRITE state ON TABLE watch TYPE string ASSERT $value IN ["active", "stale", "backoff", "failed", "expired"];
DEFINE FIELD OVERWRITE last_refresh_at ON TABLE watch TYPE option<datetime>;
DEFINE FIELD OVERWRITE next_refresh_at ON TABLE watch TYPE option<datetime>;
DEFINE FIELD OVERWRITE expires_at ON TABLE watch TYPE datetime;
DEFINE FIELD OVERWRITE last_error_code ON TABLE watch TYPE option<string>;
DEFINE FIELD OVERWRITE updated_at ON TABLE watch TYPE datetime;
DEFINE INDEX OVERWRITE watch_id_unique ON TABLE watch FIELDS watch_id UNIQUE;
DEFINE INDEX OVERWRITE watch_scope_unique ON TABLE watch FIELDS scope UNIQUE;
DEFINE INDEX OVERWRITE watch_due ON TABLE watch FIELDS state, next_refresh_at;
DEFINE INDEX OVERWRITE watch_expiry ON TABLE watch FIELDS expires_at;

DEFINE TABLE OVERWRITE realtime_event SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE event_id ON TABLE realtime_event TYPE string;
DEFINE FIELD OVERWRITE type ON TABLE realtime_event TYPE string ASSERT $value IN [
    "station_snapshot_changed", "vehicle_moved", "vehicle_stale", "vehicle_lost"
];
DEFINE FIELD OVERWRITE scope ON TABLE realtime_event TYPE string;
DEFINE FIELD OVERWRITE entity_id ON TABLE realtime_event TYPE string;
DEFINE FIELD OVERWRITE version ON TABLE realtime_event TYPE string;
DEFINE FIELD OVERWRITE payload ON TABLE realtime_event TYPE object FLEXIBLE;
DEFINE FIELD OVERWRITE source ON TABLE realtime_event TYPE string;
DEFINE FIELD OVERWRITE created_at ON TABLE realtime_event TYPE datetime;
DEFINE INDEX OVERWRITE realtime_event_id_unique ON TABLE realtime_event FIELDS event_id UNIQUE;
DEFINE INDEX OVERWRITE realtime_event_scope_created ON TABLE realtime_event FIELDS scope, created_at;
DEFINE INDEX OVERWRITE realtime_event_created_at ON TABLE realtime_event FIELDS created_at;

DEFINE TABLE OVERWRITE entur_request_log SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE log_id ON TABLE entur_request_log TYPE string;
DEFINE FIELD OVERWRITE request_id ON TABLE entur_request_log TYPE string;
DEFINE FIELD OVERWRITE requested_at ON TABLE entur_request_log TYPE datetime;
DEFINE FIELD OVERWRITE service ON TABLE entur_request_log TYPE string ASSERT $value IN [
    "stop_place_register", "geocoder", "journey_planner", "vehicle_positions"
];
DEFINE FIELD OVERWRITE scope ON TABLE entur_request_log TYPE string;
DEFINE FIELD OVERWRITE outcome ON TABLE entur_request_log TYPE string ASSERT $value IN [
    "success", "cache_hit", "skipped_budget", "rate_limited", "backoff", "timeout", "error"
];
DEFINE FIELD OVERWRITE http_status ON TABLE entur_request_log TYPE option<int>;
DEFINE FIELD OVERWRITE latency_ms ON TABLE entur_request_log TYPE option<float> ASSERT $value = NONE OR $value >= 0.0;
DEFINE FIELD OVERWRITE item_count ON TABLE entur_request_log TYPE option<int> ASSERT $value = NONE OR $value >= 0;
DEFINE FIELD OVERWRITE cache ON TABLE entur_request_log TYPE string ASSERT $value IN ["hit", "miss", "bypassed", "stale"];
DEFINE FIELD OVERWRITE retry_at ON TABLE entur_request_log TYPE option<datetime>;
DEFINE FIELD OVERWRITE error_code ON TABLE entur_request_log TYPE option<string>;
DEFINE FIELD OVERWRITE metadata ON TABLE entur_request_log TYPE object FLEXIBLE DEFAULT {};
DEFINE INDEX OVERWRITE entur_request_log_id_unique ON TABLE entur_request_log FIELDS log_id UNIQUE;
DEFINE INDEX OVERWRITE entur_request_log_request_id ON TABLE entur_request_log FIELDS request_id;
DEFINE INDEX OVERWRITE entur_request_log_service_time ON TABLE entur_request_log FIELDS service, requested_at;
DEFINE INDEX OVERWRITE entur_request_log_requested_at ON TABLE entur_request_log FIELDS requested_at;

DEFINE TABLE OVERWRITE system_status SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE service ON TABLE system_status TYPE string;
DEFINE FIELD OVERWRITE state ON TABLE system_status TYPE string;
DEFINE FIELD OVERWRITE detail ON TABLE system_status TYPE string;
DEFINE FIELD OVERWRITE checked_at ON TABLE system_status TYPE datetime;
DEFINE FIELD OVERWRITE latency_ms ON TABLE system_status TYPE option<float> ASSERT $value = NONE OR $value >= 0.0;
DEFINE FIELD OVERWRITE metadata ON TABLE system_status TYPE object FLEXIBLE DEFAULT {};
DEFINE INDEX OVERWRITE system_status_service_unique ON TABLE system_status FIELDS service UNIQUE;
DEFINE INDEX OVERWRITE system_status_checked_at ON TABLE system_status FIELDS checked_at;
