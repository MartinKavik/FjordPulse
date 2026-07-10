#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SHUTDOWN_FILE="${REALTIME_SHUTDOWN_FILE:-${TMPDIR:-/tmp}/fjordpulse-realtime-shutdown-$$}"

cd "${ROOT_DIR}"
rm -f "${SHUTDOWN_FILE}"

request_shutdown() {
  touch "${SHUTDOWN_FILE}"
}

cleanup() {
  rm -f "${SHUTDOWN_FILE}"
}

trap request_shutdown INT TERM
trap cleanup EXIT

backend/bin/cake realtime start --shutdown-file "${SHUTDOWN_FILE}" "$@" &
child=$!
status=0

while kill -0 "${child}" 2>/dev/null; do
  sleep 0.1
done

set +e
wait "${child}"
status=$?
set -e
if [[ ${status} -ne 0 ]]; then
  printf 'Realtime child exited with status %s.\n' "${status}" >&2
fi

exit "${status}"
