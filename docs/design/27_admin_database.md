# 27_admin_database: Admin database inspector

**Image:** coded-state reference; reviewed Playwright baselines live under `tests/visual/__snapshots__`  
**Category:** Admin/dev  
**State represented:** Read-only current SurrealDB structure and release/database migration compatibility.

## Why this screen matters

Database answers two bounded operator questions without turning FjordPulse into
a database console: what structure is currently installed, and is that database
compatible with the migration files bundled in this release?

## Safety boundary

- The page is protected and read-only. It has no query editor, schema editor,
  Apply, Retry, Edit, or Rollback action.
- The browser calls typed CakePHP GET endpoints only and never connects to
  SurrealDB.
- Schema data comes from one fixed, backend-owned, allowlisted `INFO ... STRUCTURE` query.
  PHP maps only allowlisted table, field, index, event, and permission values;
  raw INFO users, password hashes,
  authentication definitions, and credentials are never returned.
- Migration source comes only from server-discovered bundled files. The client
  cannot choose a filesystem path or execute the source.
- The deployment CLI runner, not Admin, writes migration ledger and attempt
  audit records. Admin only reports those records.

## Page structure

- One `Database` heading and compact `READ ONLY` / `SKRIVEBESKYTTET` badge.
- A slim compatibility banner summarizes `in_sync`, `pending`, `drift`, or
  `failed` and shows the last applied time when known.
- URL-backed `Current schema` / `Gjeldende skjema` and `Migrations` /
  `Migreringer` tabs use `/admin/database/schema` and
  `/admin/database/migrations`; `/admin/migrations` remains a compatibility
  route to the latter.
- A short boundary note directs record exploration and operator-run queries to
  Surrealist through the private operator connection. FjordPulse Admin never
  embeds Surrealist or database credentials.

## Current schema tab

- A local filter narrows the already-loaded table list without issuing
  user-authored database queries.
- Each collapsed row shows table name, kind/schema mode, normalized permission
  summary, and field/index/event counts.
- An expanded row groups fields, indexes, events, and permissions.
  `full`, `none`, and `conditional` describe the stored SurrealDB permission
  mode; they are not editable controls.
- The layout uses disclosure rows rather than one wide schema table so names,
  types, assertions, and Norwegian labels remain usable at 320/390 px.

## Migrations tab

- The banner and counts distinguish `applied`, `pending`,
  `checksum_mismatch`, `orphaned`, and `failed`; the first unhealthy row opens
  automatically.
- Each row exposes a human description, database and release checksums, applied
  and last-attempted times, bounded failure text, and structured affected
  tables/fields/indexes/events.
- Bundled SurrealQL is shown in a read-only code disclosure. An orphaned ledger
  row has no bundled source, which is itself useful drift evidence.
- Historical migrations applied before attempt auditing use their applied time
  as the only known attempt evidence. Historical failures cannot be invented.

## Suggested visual/regression scenarios

- `admin_database` schema view in Norwegian and English
- expanded desktop migration view in Norwegian and English
- expanded mobile schema view at 390 px in Norwegian and English
- expanded mobile migration view at 390 px in Norwegian and English
- keyboard/disclosure/filter behavior and 320 px overflow checks
- all five migration states and no mutation controls
- unauthenticated 401 behavior and raw-INFO secret rejection
