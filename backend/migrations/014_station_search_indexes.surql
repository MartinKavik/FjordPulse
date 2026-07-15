-- Replace national-catalog search scans with compact scalar and array indexes.
-- SurrealDB derives token-prefix keys plus two selective one-edit keys per
-- normalized token. The exact Damerau predicate remains a database residual,
-- avoiding full-text, n-gram, deletion, and PHP-side fuzzy indexes.
-- SearchNormalizer stores locale-independent name, locality, and
-- municipality forms, including Norwegian ø/æ/å folding.

DEFINE FIELD OVERWRITE search_one_edit_keys
    ON TABLE station TYPE array<string>
    VALUE array::distinct(array::flatten(array::map(
        search_tokens,
        |$term| IF string::len($term) < 3 {
            []
        } ELSE {
            [
                "f:" + <string>string::len($term) + ":" + string::slice($term, 0, 1),
                "l:" + <string>string::len($term) + ":" + string::slice($term, -1)
            ]
        }
    )));

DEFINE FIELD OVERWRITE search_token_prefixes
    ON TABLE station TYPE array<string>
    VALUE array::distinct(array::flatten(array::map(
        search_tokens,
        |$term| IF string::len($term) < 2 {
            []
        } ELSE {
            array::map(
                array::range(2, string::len($term) + 1),
                |$length| "p:" + string::slice($term, 0, $length)
            )
        }
    )));

-- VALUE clauses run on writes; touch legacy rows once to materialize the new
-- derived field before building its element index.
UPDATE station SET search_tokens = search_tokens RETURN NONE;

DEFINE INDEX OVERWRITE station_search_name
    ON TABLE station FIELDS search_name;
DEFINE INDEX OVERWRITE station_search_locality
    ON TABLE station FIELDS search_locality;
DEFINE INDEX OVERWRITE station_search_municipality
    ON TABLE station FIELDS search_municipality;
DEFINE INDEX OVERWRITE station_search_one_edit
    ON TABLE station FIELDS search_one_edit_keys.*;
DEFINE INDEX OVERWRITE station_search_token_prefixes
    ON TABLE station FIELDS search_token_prefixes.*;

-- Current vehicles form a much smaller, demand-driven set, but ordinary
-- line/route/destination search should still use the same native prefix path
-- instead of a CONTAINS table scan. SurrealDB derives both fields from the one
-- normalized search_text value written by the repository.
DEFINE FIELD OVERWRITE search_tokens
    ON TABLE current_vehicle TYPE array<string>
    VALUE array::distinct(string::split(search_text, " "));
DEFINE FIELD OVERWRITE search_prefixes
    ON TABLE current_vehicle TYPE array<string>
    VALUE array::distinct(array::flatten(array::map(
        string::split(search_text, " "),
        |$term| IF string::len($term) < 1 {
            []
        } ELSE {
            array::map(
                array::range(1, string::len($term) + 1),
                |$length| "p:" + string::slice($term, 0, $length)
            )
        }
    )));
UPDATE current_vehicle SET search_text = search_text RETURN NONE;
REMOVE INDEX IF EXISTS current_vehicle_search_text ON TABLE current_vehicle;
DEFINE INDEX OVERWRITE current_vehicle_search_prefixes
    ON TABLE current_vehicle FIELDS search_prefixes.*;

-- ensureStations() is intentionally called at every public station boundary.
-- Keep that readiness check cheap even with the complete national catalog.
DEFINE INDEX OVERWRITE station_count ON TABLE station COUNT;
