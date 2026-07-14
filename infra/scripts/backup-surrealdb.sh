#!/usr/bin/env bash
set -euo pipefail

readonly EXPECTED_SURREAL_VERSION='3.2.0'
readonly EXPECTED_RESTIC_VERSION='0.19.1'
readonly SURREAL_BIN="${SURREAL_BIN:-surreal}"
readonly RESTIC_BIN="${RESTIC_BIN:-restic}"
readonly BACKUP_WORK_ROOT="${BACKUP_WORK_ROOT:-/work}"
readonly BACKUP_KIND="${BACKUP_KIND:-scheduled}"
readonly BACKUP_HOST="${BACKUP_HOST:-fjordpulse-production}"
readonly KEEP_DAILY="${BACKUP_RETENTION_DAILY:-7}"
readonly KEEP_WEEKLY="${BACKUP_RETENTION_WEEKLY:-4}"

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
    emit 'surreal_backup_failed' 'error' "$1" >&2
    exit 1
}

require_nonempty() {
    local name="$1"
    [[ -n "${!name:-}" ]] || fail "Required environment variable ${name} is missing."
}

for name in \
    SURREAL_HTTP_URL \
    SURREAL_ROOT_USERNAME \
    SURREAL_ROOT_PASSWORD \
    SURREAL_NAMESPACE \
    SURREAL_DATABASE \
    RESTIC_REPOSITORY \
    RESTIC_PASSWORD; do
    require_nonempty "$name"
done

[[ "$BACKUP_KIND" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || fail 'BACKUP_KIND contains unsupported characters.'
[[ "$KEEP_DAILY" =~ ^[1-9][0-9]*$ ]] || fail 'BACKUP_RETENTION_DAILY must be a positive integer.'
[[ "$KEEP_WEEKLY" =~ ^[1-9][0-9]*$ ]] || fail 'BACKUP_RETENTION_WEEKLY must be a positive integer.'

surreal_version="$($SURREAL_BIN version 2>&1)"
[[ "$surreal_version" == *"$EXPECTED_SURREAL_VERSION"* ]] || fail "Expected SurrealDB CLI ${EXPECTED_SURREAL_VERSION}."
restic_version="$($RESTIC_BIN version 2>&1)"
[[ "$restic_version" == *"restic $EXPECTED_RESTIC_VERSION"* ]] || fail "Expected Restic ${EXPECTED_RESTIC_VERSION}."

mkdir -p "$BACKUP_WORK_ROOT"
exec 9>"${BACKUP_WORK_ROOT}/.fjordpulse-maintenance.lock"
flock --nonblock 9 || fail 'Another FjordPulse database maintenance operation is already running.'

work_dir="$(mktemp --directory "${BACKUP_WORK_ROOT}/backup.XXXXXXXX")"
cleanup() {
    rm -rf -- "$work_dir"
}
trap cleanup EXIT

timestamp="$(date --utc +%Y%m%dT%H%M%SZ)"
base="fjordpulse-${SURREAL_NAMESPACE}-${SURREAL_DATABASE}-${timestamp}"
export_path="${work_dir}/${base}.surql"
checksum_path="${export_path}.sha256"

emit 'surreal_backup_started' 'running' "$base"

export SURREAL_USER="$SURREAL_ROOT_USERNAME"
export SURREAL_PASS="$SURREAL_ROOT_PASSWORD"

"$SURREAL_BIN" export \
    --log none \
    --endpoint "$SURREAL_HTTP_URL" \
    --auth-level root \
    --namespace "$SURREAL_NAMESPACE" \
    --database "$SURREAL_DATABASE" \
    "$export_path"

[[ -s "$export_path" ]] || fail 'SurrealDB export is empty.'
(
    cd "$work_dir"
    sha256sum "$(basename "$export_path")" >"$(basename "$checksum_path")"
)

if ! "$RESTIC_BIN" snapshots --json >/dev/null 2>&1; then
    [[ "${RESTIC_INITIALIZE_REPOSITORY:-false}" == 'true' ]] \
        || fail 'Restic repository is unavailable or uninitialized.'
    "$RESTIC_BIN" init --json
fi

restic_log="${work_dir}/restic-backup.jsonl"
"$RESTIC_BIN" backup \
    --json \
    --host "$BACKUP_HOST" \
    --tag fjordpulse \
    --tag "$BACKUP_KIND" \
    "$export_path" \
    "$checksum_path" | tee "$restic_log"

snapshot_id="$(jq --raw-output 'select(.message_type == "summary") | .snapshot_id // empty' "$restic_log" | tail -n 1)"
[[ -n "$snapshot_id" ]] || fail 'Restic did not report a completed snapshot id.'

if [[ "$BACKUP_KIND" == 'scheduled' ]]; then
    "$RESTIC_BIN" forget \
        --host "$BACKUP_HOST" \
        --tag scheduled \
        --keep-daily "$KEEP_DAILY" \
        --keep-weekly "$KEEP_WEEKLY" \
        --prune \
        --json
fi

emit 'surreal_backup_complete' 'ok' "$snapshot_id"
