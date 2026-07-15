-- Expose authoritative WGS84 positions as native GeoJSON-compatible points.
-- Scalar latitude/longitude fields stay available for the existing bounded map
-- indexes and public DTOs. COMPUTED fields keep the point derived from those
-- coordinates instead of storing a second location source of truth (longitude
-- is always first).

DEFINE FIELD OVERWRITE location ON TABLE station
COMPUTED type::point([longitude, latitude]);
DEFINE FIELD OVERWRITE location ON TABLE current_vehicle
COMPUTED IF longitude = NONE OR latitude = NONE {
    NONE
} ELSE {
    type::point([longitude, latitude])
};
DEFINE FIELD OVERWRITE location ON TABLE vehicle_observation
COMPUTED type::point([longitude, latitude]);
