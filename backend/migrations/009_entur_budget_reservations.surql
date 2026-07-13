-- Shared rolling Entur request reservations.
--
-- Every HTTP and realtime caller conditionally updates this one record before
-- starting transport work. The common record is the SurrealDB write-conflict
-- boundary which prevents otherwise independent callers from oversubscribing
-- the global or per-service budget.

DEFINE TABLE OVERWRITE entur_budget_state SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE reservations ON TABLE entur_budget_state TYPE array<object> DEFAULT [];
DEFINE FIELD OVERWRITE reservations.* ON TABLE entur_budget_state TYPE object;
DEFINE FIELD OVERWRITE reservations.*.reservation_id ON TABLE entur_budget_state TYPE string;
DEFINE FIELD OVERWRITE reservations.*.service ON TABLE entur_budget_state TYPE string ASSERT $value IN [
    "stop_place_register", "geocoder", "journey_planner", "vehicle_positions"
];
DEFINE FIELD OVERWRITE reservations.*.reserved_at ON TABLE entur_budget_state TYPE datetime;
DEFINE FIELD OVERWRITE reservations.*.expires_at ON TABLE entur_budget_state TYPE datetime;

UPSERT ONLY entur_budget_state:shared CONTENT { reservations: [] };
