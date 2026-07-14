#!/usr/bin/env bash
set -euo pipefail

readonly ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# SurrealDB 3.2.0 can emit interactive prompts into --json output when a CI
# environment exports TERM=dumb. This smoke is deliberately non-interactive.
unset TERM
readonly SOURCE_PORT="${BACKUP_SMOKE_SOURCE_PORT:-18002}"
readonly RESTORE_PORT="${BACKUP_SMOKE_RESTORE_PORT:-18003}"
readonly SOURCE_ENDPOINT="http://127.0.0.1:${SOURCE_PORT}"
readonly RESTORE_ENDPOINT="http://127.0.0.1:${RESTORE_PORT}"
readonly SOURCE_ROOT_USERNAME='source-root'
readonly SOURCE_ROOT_PASSWORD='backup-smoke-source-root-secret'
readonly TARGET_ROOT_USERNAME='restore-root'
readonly TARGET_ROOT_PASSWORD='backup-smoke-restore-root-secret'
readonly RESTIC_PASSWORD_VALUE='backup-smoke-restic-secret'

[[ "$SOURCE_PORT" != "$RESTORE_PORT" ]] || {
  printf '%s\n' 'Backup smoke source and restore ports must be distinct.' >&2
  exit 1
}

work="$(mktemp --directory)"
source_server_pid=''
restore_server_pid=''
stage='starting the isolated SurrealDB servers'
cleanup() {
  local status=$?

  if (( status != 0 )); then
    printf 'Backup/restore smoke failed during %s. Printing relevant process and guard diagnostics:\n' "$stage" >&2
    local diagnostic
    for diagnostic in \
      "${work}/surreal-source.log" \
      "${work}/surreal-restore.log" \
      "${work}/same-endpoint-error.jsonl" \
      "${work}/username-reuse-error.jsonl" \
      "${work}/password-reuse-error.jsonl" \
      "${work}/occupied-target-error.jsonl"; do
      if [[ -s "$diagnostic" ]]; then
        printf '%s\n' "--- $(basename "$diagnostic") ---" >&2
        sed -n '1,160p' "$diagnostic" >&2
      fi
    done
  fi

  for pid in "$source_server_pid" "$restore_server_pid"; do
    if [[ -n "$pid" ]]; then
      kill "$pid" 2>/dev/null || true
      wait "$pid" 2>/dev/null || true
    fi
  done
  rm -rf -- "$work"
  return "$status"
}
trap cleanup EXIT

stage='starting the source SurrealDB server'
"${ROOT}/tools/surreal" start \
  --no-banner \
  --log error \
  --bind "127.0.0.1:${SOURCE_PORT}" \
  --user "$SOURCE_ROOT_USERNAME" \
  --pass "$SOURCE_ROOT_PASSWORD" \
  memory >"${work}/surreal-source.log" 2>&1 &
source_server_pid=$!

stage='starting the restore SurrealDB server'
"${ROOT}/tools/surreal" start \
  --no-banner \
  --log error \
  --bind "127.0.0.1:${RESTORE_PORT}" \
  --user "$TARGET_ROOT_USERNAME" \
  --pass "$TARGET_ROOT_PASSWORD" \
  memory >"${work}/surreal-restore.log" 2>&1 &
restore_server_pid=$!

stage='waiting for both SurrealDB servers'
for _ in $(seq 1 50); do
  if "${ROOT}/tools/surreal" is-ready --endpoint "$SOURCE_ENDPOINT" >/dev/null 2>&1 \
    && "${ROOT}/tools/surreal" is-ready --endpoint "$RESTORE_ENDPOINT" >/dev/null 2>&1; then
    break
  fi
  sleep 0.2
done
"${ROOT}/tools/surreal" is-ready --endpoint "$SOURCE_ENDPOINT" >/dev/null
"${ROOT}/tools/surreal" is-ready --endpoint "$RESTORE_ENDPOINT" >/dev/null

stage='seeding the source database'
printf '%s\n' \
  'DEFINE TABLE station SCHEMALESS; DEFINE TABLE schema_migration SCHEMALESS; DEFINE TABLE realtime_event SCHEMALESS; CREATE station:test SET name = "Forde"; CREATE schema_migration:test SET name = "initial"; CREATE realtime_event:test SET version = 1;' \
  | SURREAL_USER="$SOURCE_ROOT_USERNAME" SURREAL_PASS="$SOURCE_ROOT_PASSWORD" "${ROOT}/tools/surreal" sql \
      --log none \
      --endpoint "$SOURCE_ENDPOINT" \
      --auth-level root \
      --namespace fjordpulse_source \
      --database fjordpulse_source \
      --hide-welcome >/dev/null

stage='creating the encrypted backup'
backup_output="$(
  SURREAL_BIN="${ROOT}/tools/surreal" \
  RESTIC_BIN="${ROOT}/tools/restic" \
  BACKUP_WORK_ROOT="${work}/staging" \
  SURREAL_HTTP_URL="$SOURCE_ENDPOINT" \
  SURREAL_ROOT_USERNAME="$SOURCE_ROOT_USERNAME" \
  SURREAL_ROOT_PASSWORD="$SOURCE_ROOT_PASSWORD" \
  SURREAL_NAMESPACE=fjordpulse_source \
  SURREAL_DATABASE=fjordpulse_source \
  RESTIC_REPOSITORY="${work}/repository" \
  RESTIC_PASSWORD="$RESTIC_PASSWORD_VALUE" \
  RESTIC_INITIALIZE_REPOSITORY=true \
  BACKUP_HOST=fjordpulse-smoke \
  "${ROOT}/infra/scripts/backup-surrealdb.sh"
)"
snapshot="$(printf '%s\n' "$backup_output" | jq --raw-output \
  'select(type == "object" and .event == "surreal_backup_complete") | .detail')"
[[ -n "$snapshot" ]]

stage='changing source state after the backup'
printf '%s\n' 'CREATE station:after_backup SET name = "Source only";' \
  | SURREAL_USER="$SOURCE_ROOT_USERNAME" SURREAL_PASS="$SOURCE_ROOT_PASSWORD" "${ROOT}/tools/surreal" sql \
      --log none \
      --endpoint "$SOURCE_ENDPOINT" \
      --auth-level root \
      --namespace fjordpulse_source \
      --database fjordpulse_source \
      --hide-welcome >/dev/null

stage='checking the same-endpoint restore guard'
same_endpoint_error="${work}/same-endpoint-error.jsonl"
if SURREAL_BIN="${ROOT}/tools/surreal" \
  RESTIC_BIN="${ROOT}/tools/restic" \
  BACKUP_WORK_ROOT="${work}/staging" \
  SURREAL_HTTP_URL="$SOURCE_ENDPOINT" \
  SURREAL_ROOT_USERNAME="$SOURCE_ROOT_USERNAME" \
  SURREAL_ROOT_PASSWORD="$SOURCE_ROOT_PASSWORD" \
  SURREAL_NAMESPACE=fjordpulse_source \
  SURREAL_DATABASE=fjordpulse_source \
  RESTORE_HTTP_URL="${SOURCE_ENDPOINT}/" \
  RESTORE_ROOT_USERNAME="$SOURCE_ROOT_USERNAME" \
  RESTORE_ROOT_PASSWORD="$SOURCE_ROOT_PASSWORD" \
  RESTIC_REPOSITORY="${work}/repository" \
  RESTIC_PASSWORD="$RESTIC_PASSWORD_VALUE" \
  RESTORE_SNAPSHOT="$snapshot" \
  RESTORE_NAMESPACE=must_not_exist \
  RESTORE_DATABASE=must_not_exist \
  RESTORE_CONFIRM_ISOLATED=true \
  "${ROOT}/infra/scripts/restore-surrealdb.sh" >"${work}/same-endpoint-output.jsonl" 2>"$same_endpoint_error"; then
  printf '%s\n' 'Restore unexpectedly accepted the source endpoint.' >&2
  exit 1
fi
jq --exit-status \
  'select(.event == "surreal_restore_failed" and .detail == "Refusing to restore to the configured source/production endpoint.")' \
  "$same_endpoint_error" >/dev/null

alias_endpoint="http://localhost:${SOURCE_PORT}"
stage='checking the source-username restore guard through an endpoint alias'
username_reuse_error="${work}/username-reuse-error.jsonl"
if SURREAL_BIN="${ROOT}/tools/surreal" \
  RESTIC_BIN="${ROOT}/tools/restic" \
  BACKUP_WORK_ROOT="${work}/staging" \
  SURREAL_HTTP_URL="$SOURCE_ENDPOINT" \
  SURREAL_ROOT_USERNAME="$SOURCE_ROOT_USERNAME" \
  SURREAL_ROOT_PASSWORD="$SOURCE_ROOT_PASSWORD" \
  SURREAL_NAMESPACE=fjordpulse_source \
  SURREAL_DATABASE=fjordpulse_source \
  RESTORE_HTTP_URL="$alias_endpoint" \
  RESTORE_ROOT_USERNAME="$SOURCE_ROOT_USERNAME" \
  RESTORE_ROOT_PASSWORD="$TARGET_ROOT_PASSWORD" \
  RESTIC_REPOSITORY="${work}/repository" \
  RESTIC_PASSWORD="$RESTIC_PASSWORD_VALUE" \
  RESTORE_SNAPSHOT="$snapshot" \
  RESTORE_NAMESPACE=credential_reuse_guard \
  RESTORE_DATABASE=credential_reuse_guard \
  RESTORE_CONFIRM_ISOLATED=true \
  "${ROOT}/infra/scripts/restore-surrealdb.sh" >"${work}/username-reuse-output.jsonl" 2>"$username_reuse_error"; then
  printf '%s\n' 'Restore unexpectedly accepted the source root username through an endpoint alias.' >&2
  exit 1
fi
jq --exit-status \
  'select(.event == "surreal_restore_failed" and .detail == "Restore root username must differ from the source/production root username.")' \
  "$username_reuse_error" >/dev/null

stage='checking the source-password restore guard through an endpoint alias'
password_reuse_error="${work}/password-reuse-error.jsonl"
if SURREAL_BIN="${ROOT}/tools/surreal" \
  RESTIC_BIN="${ROOT}/tools/restic" \
  BACKUP_WORK_ROOT="${work}/staging" \
  SURREAL_HTTP_URL="$SOURCE_ENDPOINT" \
  SURREAL_ROOT_USERNAME="$SOURCE_ROOT_USERNAME" \
  SURREAL_ROOT_PASSWORD="$SOURCE_ROOT_PASSWORD" \
  SURREAL_NAMESPACE=fjordpulse_source \
  SURREAL_DATABASE=fjordpulse_source \
  RESTORE_HTTP_URL="$alias_endpoint" \
  RESTORE_ROOT_USERNAME="$TARGET_ROOT_USERNAME" \
  RESTORE_ROOT_PASSWORD="$SOURCE_ROOT_PASSWORD" \
  RESTIC_REPOSITORY="${work}/repository" \
  RESTIC_PASSWORD="$RESTIC_PASSWORD_VALUE" \
  RESTORE_SNAPSHOT="$snapshot" \
  RESTORE_NAMESPACE=credential_reuse_guard \
  RESTORE_DATABASE=credential_reuse_guard \
  RESTORE_CONFIRM_ISOLATED=true \
  "${ROOT}/infra/scripts/restore-surrealdb.sh" >"${work}/password-reuse-output.jsonl" 2>"$password_reuse_error"; then
  printf '%s\n' 'Restore unexpectedly accepted the source root password through an endpoint alias.' >&2
  exit 1
fi
jq --exit-status \
  'select(.event == "surreal_restore_failed" and .detail == "Restore root password must differ from the source/production root password.")' \
  "$password_reuse_error" >/dev/null

stage='verifying that endpoint-alias guard checks did not mutate the source'
alias_guard_info="$(printf '%s\n' 'INFO FOR DB;' \
  | SURREAL_USER="$SOURCE_ROOT_USERNAME" SURREAL_PASS="$SOURCE_ROOT_PASSWORD" "${ROOT}/tools/surreal" sql \
      --log none \
      --endpoint "$SOURCE_ENDPOINT" \
      --auth-level root \
      --namespace credential_reuse_guard \
      --database credential_reuse_guard \
      --hide-welcome \
      --json)"
printf '%s\n' "$alias_guard_info" | jq --exit-status \
  'type == "array" and length == 1 and (.[0] | type == "object" and length > 0 and all(.[]; type == "object" and length == 0))' >/dev/null

stage='seeding a non-empty restore target'
printf '%s\n' 'DEFINE TABLE station SCHEMALESS; CREATE station:occupied SET name = "Do not overwrite";' \
  | SURREAL_USER="$TARGET_ROOT_USERNAME" SURREAL_PASS="$TARGET_ROOT_PASSWORD" "${ROOT}/tools/surreal" sql \
      --log none \
      --endpoint "$RESTORE_ENDPOINT" \
      --auth-level root \
      --namespace occupied_restore \
      --database occupied_restore \
      --hide-welcome >/dev/null

stage='checking the non-empty-target restore guard'
occupied_error="${work}/occupied-target-error.jsonl"
if SURREAL_BIN="${ROOT}/tools/surreal" \
  RESTIC_BIN="${ROOT}/tools/restic" \
  BACKUP_WORK_ROOT="${work}/staging" \
  SURREAL_HTTP_URL="$SOURCE_ENDPOINT" \
  SURREAL_ROOT_USERNAME="$SOURCE_ROOT_USERNAME" \
  SURREAL_ROOT_PASSWORD="$SOURCE_ROOT_PASSWORD" \
  SURREAL_NAMESPACE=fjordpulse_source \
  SURREAL_DATABASE=fjordpulse_source \
  RESTORE_HTTP_URL="$RESTORE_ENDPOINT" \
  RESTORE_ROOT_USERNAME="$TARGET_ROOT_USERNAME" \
  RESTORE_ROOT_PASSWORD="$TARGET_ROOT_PASSWORD" \
  RESTIC_REPOSITORY="${work}/repository" \
  RESTIC_PASSWORD="$RESTIC_PASSWORD_VALUE" \
  RESTORE_SNAPSHOT="$snapshot" \
  RESTORE_NAMESPACE=occupied_restore \
  RESTORE_DATABASE=occupied_restore \
  RESTORE_CONFIRM_ISOLATED=true \
  "${ROOT}/infra/scripts/restore-surrealdb.sh" >"${work}/occupied-target-output.jsonl" 2>"$occupied_error"; then
  printf '%s\n' 'Restore unexpectedly accepted a non-empty target database.' >&2
  exit 1
fi
jq --exit-status \
  'select(.event == "surreal_restore_failed" and (.detail | startswith("Restore target database is not empty")))' \
  "$occupied_error" >/dev/null

stage='restoring the encrypted backup into the isolated target'
restore_output="$(
  SURREAL_BIN="${ROOT}/tools/surreal" \
  RESTIC_BIN="${ROOT}/tools/restic" \
  BACKUP_WORK_ROOT="${work}/staging" \
  SURREAL_HTTP_URL="$SOURCE_ENDPOINT" \
  SURREAL_ROOT_USERNAME="$SOURCE_ROOT_USERNAME" \
  SURREAL_ROOT_PASSWORD="$SOURCE_ROOT_PASSWORD" \
  SURREAL_NAMESPACE=fjordpulse_source \
  SURREAL_DATABASE=fjordpulse_source \
  RESTORE_HTTP_URL="$RESTORE_ENDPOINT" \
  RESTORE_ROOT_USERNAME="$TARGET_ROOT_USERNAME" \
  RESTORE_ROOT_PASSWORD="$TARGET_ROOT_PASSWORD" \
  RESTIC_REPOSITORY="${work}/repository" \
  RESTIC_PASSWORD="$RESTIC_PASSWORD_VALUE" \
  RESTORE_SNAPSHOT="$snapshot" \
  RESTORE_NAMESPACE=fjordpulse_restore \
  RESTORE_DATABASE=fjordpulse_restore \
  RESTORE_CONFIRM_ISOLATED=true \
  "${ROOT}/infra/scripts/restore-surrealdb.sh"
)"
printf '%s\n' "$restore_output" | jq --exit-status \
  'select(type == "object" and .event == "surreal_restore_complete" and .status == "ok")' >/dev/null

stage='verifying the isolated restored database'
verification="$(
  printf '%s\n' 'RETURN count((SELECT VALUE id FROM station));' \
    | SURREAL_USER="$TARGET_ROOT_USERNAME" SURREAL_PASS="$TARGET_ROOT_PASSWORD" "${ROOT}/tools/surreal" sql \
        --log none \
        --endpoint "$RESTORE_ENDPOINT" \
        --auth-level root \
        --namespace fjordpulse_restore \
        --database fjordpulse_restore \
        --hide-welcome \
        --json
)"
printf '%s\n' "$verification" | jq --exit-status '.[0] == 1' >/dev/null

stage='verifying the source database remained untouched'
source_verification="$(
  printf '%s\n' 'RETURN count((SELECT VALUE id FROM station));' \
    | SURREAL_USER="$SOURCE_ROOT_USERNAME" SURREAL_PASS="$SOURCE_ROOT_PASSWORD" "${ROOT}/tools/surreal" sql \
        --log none \
        --endpoint "$SOURCE_ENDPOINT" \
        --auth-level root \
        --namespace fjordpulse_source \
        --database fjordpulse_source \
        --hide-welcome \
        --json
)"
printf '%s\n' "$source_verification" | jq --exit-status '.[0] == 2' >/dev/null

printf '%s\n' 'Encrypted backup and independent-endpoint restore smoke passed.'
