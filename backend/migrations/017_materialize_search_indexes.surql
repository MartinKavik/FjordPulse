-- Migration 014 materializes VALUE-derived arrays and defines their indexes in
-- one transaction. On SurrealDB 3.2 with RocksDB, the stored arrays commit but
-- the indexes built in that same transaction can contain no element entries.
-- Rebuild from the now-committed records in a later transaction. Keep these
-- rebuilds blocking so migration success means both indexes are queryable.

REBUILD INDEX station_search_one_edit ON TABLE station;
REBUILD INDEX station_search_token_prefixes ON TABLE station;
