#!/usr/bin/env bash

set -euo pipefail

CLAMDSCAN_BIN=$(command -v clamdscan || true)
CLAMD_WAIT_TIMEOUT=${CLAMD_WAIT_TIMEOUT:-20}

if [[ -z "${CLAMDSCAN_BIN}" ]] || [[ "${SKIP_CLAMD_WAIT:-0}" == "1" ]]; then
    exec "$@"
fi

attempt=1
echo "Waiting for ClamAV daemon (clamdscan) to accept stream scans..." >&2

while ! printf 'PING\n' | "${CLAMDSCAN_BIN}" --no-summary --stream - >/dev/null 2>&1; do
    if (( attempt >= CLAMD_WAIT_TIMEOUT )); then
        echo "ClamAV daemon did not become ready after ${CLAMD_WAIT_TIMEOUT}s." >&2
        exit 1
    fi

    sleep 1
    attempt=$((attempt + 1))
done

exec "$@"
