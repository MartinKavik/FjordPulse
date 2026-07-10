#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly RUN_DIR="${ROOT_DIR}/.run"
readonly LOG_DIR="${RUN_DIR}/logs"

cd "${ROOT_DIR}"
if [[ ! -f .env ]]; then
  cp .env.example .env
fi
set -a
# shellcheck disable=SC1091
source .env
set +a

export PATH="${ROOT_DIR}/tools:${PATH}"
export BACKEND_WEBROOT="${BACKEND_WEBROOT:-${ROOT_DIR}/backend/webroot}"
export FRONTEND_DIST="${FRONTEND_DIST:-${ROOT_DIR}/frontend/dist}"
export REALTIME_UPSTREAM="${REALTIME_UPSTREAM:-127.0.0.1:${REALTIME_PORT:-8081}}"

mkdir -p "${RUN_DIR}" "${LOG_DIR}" "${SURREAL_DATA_PATH:-.data/surreal}"
rm -f "${RUN_DIR}/stop.requested"

if compgen -G "${RUN_DIR}/*.pid" >/dev/null; then
  bash scripts/stop.sh
fi

start_process() {
  local name="$1"
  shift
  "$@" >"${LOG_DIR}/${name}.log" 2>&1 &
  local pid=$!
  printf '%s\n' "${pid}" >"${RUN_DIR}/${name}.pid"
  printf 'Started %-10s pid=%s log=%s\n' "${name}" "${pid}" ".run/logs/${name}.log"
}

wait_for() {
  local name="$1"
  local url="$2"
  local attempts="${3:-80}"
  local delay="${4:-0.25}"
  for ((attempt = 1; attempt <= attempts; attempt++)); do
    if curl --fail --silent --show-error --max-time 2 "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep "${delay}"
  done
  printf '%s did not become ready at %s\n' "${name}" "${url}" >&2
  tail -n 80 "${LOG_DIR}/${name}.log" >&2 || true
  return 1
}

cleanup() {
  trap - EXIT INT TERM
  if [[ -f "${RUN_DIR}/stop.requested" ]]; then
    return
  fi
  bash scripts/stop.sh
}
trap cleanup EXIT INT TERM

start_process surreal ./tools/surreal start \
  --no-banner \
  --log info \
  --bind 127.0.0.1:8000 \
  --user "${SURREAL_ROOT_USERNAME:-root}" \
  --pass "${SURREAL_ROOT_PASSWORD:-root}" \
  "surrealkv:${SURREAL_DATA_PATH:-.data/surreal}"

for ((attempt = 1; attempt <= 80; attempt++)); do
  if ./tools/surreal is-ready --endpoint "${SURREAL_HTTP_URL:-http://127.0.0.1:8000}" --log none >/dev/null 2>&1; then
    break
  fi
  if [[ ${attempt} -eq 80 ]]; then
    printf 'SurrealDB did not become ready. See .run/logs/surreal.log\n' >&2
    exit 1
  fi
  sleep 0.25
done

backend/bin/cake migrations migrate
backend/bin/cake stations import --limit "${STATION_IMPORT_LIMIT:-10000}"

if [[ ! -s frontend/dist/index.html ]]; then
  npm --prefix frontend run build
fi

start_process realtime scripts/realtime.sh --host "${REALTIME_HOST:-127.0.0.1}" --port "${REALTIME_PORT:-8081}"
wait_for realtime "http://127.0.0.1:${REALTIME_PORT:-8081}/health/realtime"

start_process http ./tools/frankenphp run --config infra/Caddyfile --adapter caddyfile
wait_for http "http://127.0.0.1:${HTTP_PORT:-8080}/api/health"

start_process frontend npm --prefix frontend run dev -- --host 127.0.0.1 --port 5173
wait_for frontend "http://127.0.0.1:5173/"

printf '\nFjordPulse is ready:\n'
printf '  app (Vite):     http://127.0.0.1:5173\n'
printf '  built/CakePHP:  http://127.0.0.1:%s\n' "${HTTP_PORT:-8080}"
printf '  realtime:       ws://127.0.0.1:%s/live\n' "${REALTIME_PORT:-8081}"
printf '  admin:          http://127.0.0.1:5173/admin/status\n'
printf 'Press Ctrl-C to stop all services.\n\n'

service_pids=(
  "$(<"${RUN_DIR}/surreal.pid")"
  "$(<"${RUN_DIR}/realtime.pid")"
  "$(<"${RUN_DIR}/http.pid")"
  "$(<"${RUN_DIR}/frontend.pid")"
)
set +e
wait -n "${service_pids[@]}"
service_status=$?
set -e
if [[ -f "${RUN_DIR}/stop.requested" ]]; then
  set +e
  for pid in "${service_pids[@]}"; do
    wait "${pid}" 2>/dev/null
  done
  set -e
  exit 0
fi
printf 'A FjordPulse service stopped unexpectedly with status %s; inspect .run/logs/.\n' "${service_status}" >&2
if [[ ${service_status} -eq 0 ]]; then
  service_status=1
fi
exit "${service_status}"
