-- Add typed SurrealDB record links alongside the stable Entur string identities.
-- Snapshot payloads stay denormalized; these references provide traversal,
-- deletion policy, schema visibility, and database-native reference semantics.

-- SurrealDB DEFAULT values apply when a record is created; they do not rewrite
-- rows that existed before a field was defined. Repair snapshots written before
-- migration 007 first, otherwise adding any new field would revalidate those
-- legacy rows and fail on their missing default-backed serving fields.
UPDATE station_snapshot SET
    departures = departures ?? [],
    nearby_vehicles = nearby_vehicles ?? [],
    serving_vehicles = serving_vehicles ?? [],
    serving_candidate_journey_count = serving_candidate_journey_count ?? 0,
    serving_queried_journey_count = serving_queried_journey_count ?? 0,
    serving_vehicles_truncated = serving_vehicles_truncated ?? false
RETURN NONE;

DEFINE FIELD OVERWRITE station ON TABLE station_snapshot
TYPE record<station> REFERENCE ON DELETE CASCADE
VALUE type::record("station", station_id);
DEFINE FIELD OVERWRITE station ON TABLE station_timetable
TYPE record<station> REFERENCE ON DELETE CASCADE
VALUE type::record("station", station_id);
DEFINE FIELD OVERWRITE vehicle ON TABLE vehicle_observation
TYPE record<current_vehicle> REFERENCE ON DELETE CASCADE
VALUE type::record("current_vehicle", vehicle_id);
DEFINE FIELD OVERWRITE journey ON TABLE current_vehicle
TYPE option<record<journey_snapshot>> REFERENCE ON DELETE UNSET
VALUE IF journey_reference = NONE
    OR journey_reference.serviceJourneyId = NONE
    OR journey_reference.operatingDate = NONE {
    NONE
} ELSE {
    LET $candidate = type::record(
        "journey_snapshot",
        journey_reference.serviceJourneyId + "|" + journey_reference.operatingDate
    );
    IF record::exists($candidate) { $candidate } ELSE { NONE }
};

-- Existing rows predate the native links. Deterministic record ids are already
-- derived from the same canonical external identifiers, so the backfill does
-- not change any public id or snapshot shape.
UPDATE station_snapshot SET station = type::record("station", station_id) RETURN NONE;
UPDATE station_timetable SET station = type::record("station", station_id) RETURN NONE;
UPDATE vehicle_observation SET vehicle = type::record("current_vehicle", vehicle_id) RETURN NONE;
UPDATE current_vehicle SET journey = IF journey_reference = NONE
    OR journey_reference.serviceJourneyId = NONE
    OR journey_reference.operatingDate = NONE {
    NONE
} ELSE {
    LET $candidate = type::record(
        "journey_snapshot",
        journey_reference.serviceJourneyId + "|" + journey_reference.operatingDate
    );
    IF record::exists($candidate) { $candidate } ELSE { NONE }
} RETURN NONE;
