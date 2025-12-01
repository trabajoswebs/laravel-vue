#!/usr/bin/env bash
# Minimal clamdscan-compatible wrapper for environments without the clamdscan binary.
# Supports scanning a file path or reading from stdin when path is "-".

set -euo pipefail

SOCKET="${CLAMD_SOCKET:-/var/run/clamav/clamd.ctl}"

if [ ! -S "${SOCKET}" ]; then
    echo "clamd socket not found at ${SOCKET}" >&2
    exit 2
fi

args=("$@")
target="${args[-1]:--}"
shift $(( $# - 1 ))

# Ignore flags like --no-summary/--stream/--fdpass (handled upstream).
if [ "${target}" = "-" ]; then
    tmpfile="$(mktemp /tmp/clamdscan.XXXXXX)"
    trap 'rm -f "${tmpfile}"' EXIT
    cat > "${tmpfile}"
    target="${tmpfile}"
fi

if [ ! -f "${target}" ]; then
    echo "Target not found: ${target}" >&2
    exit 2
fi

# Send SCAN command to clamd via UNIX socket using socat.
result="$(printf 'SCAN %s\n' "${target}" | socat -t 30 - UNIX-CONNECT:"${SOCKET}")" || rc=$?
rc=${rc:-0}

echo "${result}"

case "${result}" in
    *"OK")
        exit 0
        ;;
    *"FOUND")
        exit 1
        ;;
    *)
        exit "${rc:-2}"
        ;;
esac
