-- Record completed and interrupted deployment migration attempts for truthful diagnostics.
-- The Admin API only reads this ledger; only the deployment migration command writes it.

DEFINE TABLE OVERWRITE schema_migration_attempt SCHEMAFULL PERMISSIONS NONE;
DEFINE FIELD OVERWRITE attempt_id ON TABLE schema_migration_attempt TYPE string;
DEFINE FIELD OVERWRITE name ON TABLE schema_migration_attempt TYPE string;
DEFINE FIELD OVERWRITE checksum ON TABLE schema_migration_attempt TYPE string;
DEFINE FIELD OVERWRITE state ON TABLE schema_migration_attempt TYPE string
    ASSERT $value IN ["running", "applied", "failed", "checksum_mismatch"];
DEFINE FIELD OVERWRITE started_at ON TABLE schema_migration_attempt TYPE datetime;
DEFINE FIELD OVERWRITE finished_at ON TABLE schema_migration_attempt TYPE option<datetime>;
DEFINE FIELD OVERWRITE failure_message ON TABLE schema_migration_attempt TYPE option<string>;
DEFINE INDEX OVERWRITE schema_migration_attempt_id_unique
    ON TABLE schema_migration_attempt FIELDS attempt_id UNIQUE;
DEFINE INDEX OVERWRITE schema_migration_attempt_name_started
    ON TABLE schema_migration_attempt FIELDS name, started_at;
