-- Keep global vehicle-observation retention index-backed as well as per-vehicle
-- trail reads. The cleanup predicate combines expires_at and observed_at, so
-- observed_at must also lead its own index instead of appearing only after
-- vehicle_id in the ordered trail index.

DEFINE INDEX OVERWRITE vehicle_observation_observed_at
ON TABLE vehicle_observation FIELDS observed_at;
