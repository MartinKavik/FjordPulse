-- Track the base-version cohort used to serialize concurrent station refreshes.
-- This is internal write metadata; it is not exposed in realtime payloads.

DEFINE FIELD OVERWRITE refresh_base_version ON TABLE station_snapshot TYPE option<string>;
