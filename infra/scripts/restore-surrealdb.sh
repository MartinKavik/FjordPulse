#!/usr/bin/env bash
set -euo pipefail

# SurrealDB 3.2.0 can emit prompts into --json output when TERM=dumb.
unset TERM

readonly EXPECTED_SURREAL_VERSION='3.2.0'
readonly EXPECTED_RESTIC_VERSION='0.19.1'
readonly SURREAL_BIN="${SURREAL_BIN:-surreal}"
readonly RESTIC_BIN="${RESTIC_BIN:-restic}"
readonly BACKUP_WORK_ROOT="${BACKUP_WORK_ROOT:-/work}"

emit() {
    local event="$1"
    local status="$2"
    local detail="${3:-}"
    jq --null-input --compact-output \
        --arg event "$event" \
        --arg status "$status" \
        --arg detail "$detail" \
        '{event: $event, status: $status, detail: $detail}'
}

fail() {
    emit 'surreal_restore_failed' 'error' "$1" >&2
    exit 1
}

require_nonempty() {
    local name="$1"
    [[ -n "${!name:-}" ]] || fail "Required environment variable ${name} is missing."
}

normalize_http_endpoint() {
    local endpoint="$1"
    while [[ "$endpoint" == */ ]]; do
        endpoint="${endpoint%/}"
    done

    if [[ ! "$endpoint" =~ ^(https?)://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9._-]+)(:([0-9]+))?$ ]]; then
        fail 'SurrealDB endpoints must be absolute HTTP(S) origins without a path, query or credentials.'
    fi

    local scheme="${BASH_REMATCH[1],,}"
    local host="${BASH_REMATCH[2],,}"
    local port="${BASH_REMATCH[4]:-}"
    if [[ -n "$port" ]]; then
        [[ "$port" =~ ^[0-9]{1,5}$ ]] || fail 'SurrealDB endpoint port is invalid.'
        port="$((10#$port))"
        (( port >= 1 && port <= 65535 )) || fail 'SurrealDB endpoint port is invalid.'
    fi
    if [[ ( "$scheme" == 'http' && "$port" == '80' ) || ( "$scheme" == 'https' && "$port" == '443' ) ]]; then
        port=''
    fi

    printf '%s://%s%s\n' "$scheme" "$host" "${port:+:${port}}"
}

for name in \
    SURREAL_HTTP_URL \
    SURREAL_ROOT_USERNAME \
    SURREAL_ROOT_PASSWORD \
    SURREAL_NAMESPACE \
    SURREAL_DATABASE \
    RESTORE_HTTP_URL \
    RESTORE_ROOT_USERNAME \
    RESTORE_ROOT_PASSWORD \
    RESTIC_REPOSITORY \
    RESTIC_PASSWORD \
    RESTORE_SNAPSHOT \
    RESTORE_NAMESPACE \
    RESTORE_DATABASE; do
    require_nonempty "$name"
done

[[ "${RESTORE_CONFIRM_ISOLATED:-false}" == 'true' ]] \
    || fail 'Set RESTORE_CONFIRM_ISOLATED=true after preparing an isolated restore target.'
source_endpoint="$(normalize_http_endpoint "$SURREAL_HTTP_URL")"
restore_endpoint="$(normalize_http_endpoint "$RESTORE_HTTP_URL")"
if [[ "$restore_endpoint" == "$source_endpoint" ]]; then
    fail 'Refusing to restore to the configured source/production endpoint.'
fi
if [[ "$RESTORE_ROOT_USERNAME" == "$SURREAL_ROOT_USERNAME" ]]; then
    fail 'Restore root username must differ from the source/production root username.'
fi
if [[ "$RESTORE_ROOT_PASSWORD" == "$SURREAL_ROOT_PASSWORD" ]]; then
    fail 'Restore root password must differ from the source/production root password.'
fi
if [[ "$RESTORE_NAMESPACE" == "$SURREAL_NAMESPACE" && "$RESTORE_DATABASE" == "$SURREAL_DATABASE" ]]; then
    fail 'Refusing to restore into the configured production namespace/database.'
fi
[[ "$RESTORE_NAMESPACE" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] || fail 'RESTORE_NAMESPACE is invalid.'
[[ "$RESTORE_DATABASE" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] || fail 'RESTORE_DATABASE is invalid.'

surreal_version="$($SURREAL_BIN version 2>&1)"
[[ "$surreal_version" == *"$EXPECTED_SURREAL_VERSION"* ]] || fail "Expected SurrealDB CLI ${EXPECTED_SURREAL_VERSION}."
restic_version="$($RESTIC_BIN version 2>&1)"
[[ "$restic_version" == *"restic $EXPECTED_RESTIC_VERSION"* ]] || fail "Expected Restic ${EXPECTED_RESTIC_VERSION}."

mkdir -p "$BACKUP_WORK_ROOT"
exec 9>"${BACKUP_WORK_ROOT}/.fjordpulse-maintenance.lock"
flock --nonblock 9 || fail 'Another FjordPulse database maintenance operation is already running.'

export SURREAL_USER="$RESTORE_ROOT_USERNAME"
export SURREAL_PASS="$RESTORE_ROOT_PASSWORD"

if ! target_info="$(printf '%s\n' 'INFO FOR DB;' \
    | "$SURREAL_BIN" sql \
    --log none \
    --endpoint "$RESTORE_HTTP_URL" \
    --auth-level root \
    --namespace "$RESTORE_NAMESPACE" \
    --database "$RESTORE_DATABASE" \
    --hide-welcome \
    --json)"; then
    fail 'Could not authenticate to and inspect the isolated restore target.'
fi
printf '%s\n' "$target_info" | jq --exit-status \
    'type == "array"
        and length == 1
        and (.[0] | type == "object" and length > 0 and all(.[]; type == "object" and length == 0))' >/dev/null \
    || fail 'Restore target database is not empty; prepare a fresh isolated database/volume.'

work_dir="$(mktemp --directory "${BACKUP_WORK_ROOT}/restore.XXXXXXXX")"
cleanup() {
    rm -rf -- "$work_dir"
}
trap cleanup EXIT

emit 'surreal_restore_started' 'running' "$RESTORE_SNAPSHOT"
"$RESTIC_BIN" restore "$RESTORE_SNAPSHOT" --target "$work_dir" --verify --json

mapfile -t exports < <(find "$work_dir" -type f -name '*.surql' -print)
[[ "${#exports[@]}" -eq 1 ]] || fail 'Restore snapshot must contain exactly one SurrealQL export.'
export_path="${exports[0]}"
checksum_path="${export_path}.sha256"
[[ -f "$checksum_path" ]] || fail 'Restore snapshot is missing its SHA-256 checksum.'
(
    cd "$(dirname "$export_path")"
    sha256sum --check "$(basename "$checksum_path")" >/dev/null
)

"$SURREAL_BIN" import \
    --log none \
    --endpoint "$RESTORE_HTTP_URL" \
    --auth-level root \
    --namespace "$RESTORE_NAMESPACE" \
    --database "$RESTORE_DATABASE" \
    "$export_path" >/dev/null

verification="$(printf '%s\n' \
    'RETURN { station: count((SELECT VALUE id FROM station)), migrations: count((SELECT VALUE id FROM schema_migration)), events: count((SELECT VALUE id FROM realtime_event)) };' \
    | "$SURREAL_BIN" sql \
    --log none \
    --endpoint "$RESTORE_HTTP_URL" \
    --auth-level root \
    --namespace "$RESTORE_NAMESPACE" \
    --database "$RESTORE_DATABASE" \
    --hide-welcome \
    --json)"
printf '%s\n' "$verification" | jq --exit-status \
    'type == "array"
        and length == 1
        and (.[0].station | type) == "number"
        and (.[0].migrations | type) == "number"
        and (.[0].events | type) == "number"
        and .[0].station > 0
        and .[0].migrations > 0
        and .[0].events >= 0' >/dev/null \
    || fail 'Restored database verification query returned invalid evidence.'

if [[ -n "${RESTORE_APP_SMOKE_URL:-}" ]]; then
    curl --fail --silent --show-error \
        "${RESTORE_APP_SMOKE_URL%/}/api/readiness" >/dev/null
    curl --fail --silent --show-error --get \
        --data-urlencode 'q=Forde' \
        "${RESTORE_APP_SMOKE_URL%/}/api/search" >/dev/null
fi

emit 'surreal_restore_complete' 'ok' "${RESTORE_NAMESPACE}/${RESTORE_DATABASE}"
