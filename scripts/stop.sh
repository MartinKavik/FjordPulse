#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly RUN_DIR="${ROOT_DIR}/.run"

[[ -d "${RUN_DIR}" ]] || exit 0
touch "${RUN_DIR}/stop.requested"

stopped=0
stop_one() {
  local name="$1"
  local file="${RUN_DIR}/${name}.pid"
  [[ -f "${file}" ]] || return 0
  local pid
  pid="$(<"${file}")"
  if [[ ! "${pid}" =~ ^[0-9]+$ ]] || ! kill -0 "${pid}" 2>/dev/null; then
    rm -f "${file}"
    return 0
  fi
  kill -TERM "${pid}" 2>/dev/null || true
  for ((attempt = 1; attempt <= 80; attempt++)); do
    if ! kill -0 "${pid}" 2>/dev/null; then
      break
    fi
    sleep 0.1
  done
  if kill -0 "${pid}" 2>/dev/null; then
    kill -KILL "${pid}" 2>/dev/null || true
  fi
  rm -f "${file}"
  stopped=1
}

# Keep the database available while realtime cancels and kills its live query.
stop_one frontend
stop_one http
stop_one realtime
stop_one surreal

if [[ ${stopped} -eq 1 ]]; then
  printf 'Stopped FjordPulse local services.\n'
fi
