#!/usr/bin/env bash

set -euo pipefail

DB_DIR=${CLAMAV_DB_DIR:-/var/lib/clamav}
LOG_DIR=${CLAMAV_LOG_DIR:-/var/log/clamav}
FORCE_REFRESH=0
QUIET=0

while (( "$#" )); do
    case "$1" in
        --force)
            FORCE_REFRESH=1
            shift
            ;;
        --quiet)
            QUIET=1
            shift
            ;;
        *)
            # Ignora banderas desconocidas para mantener compatibilidad futura.
            shift
            ;;
    esac
done

mkdir -p "${DB_DIR}" "${LOG_DIR}"
chown clamav:clamav "${DB_DIR}" "${LOG_DIR}"

# Usa flock para evitar condiciones de carrera cuando múltiples contenedores arrancan a la vez.
LOCK_FILE="${DB_DIR}/.ensure-clamav-db.lock"
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
    [ "${QUIET}" -eq 1 ] || echo "ClamAV DB setup already running, skipping duplicate invocation."
    exit 0
fi

shopt -s nullglob
existing_db_files=("${DB_DIR}"/*.c[lv]d)
shopt -u nullglob

if [ "${FORCE_REFRESH}" -ne 1 ] && [ "${#existing_db_files[@]}" -gt 0 ]; then
    [ "${QUIET}" -eq 1 ] || echo "ClamAV DB already present at ${DB_DIR}, skipping download."
    exit 0
fi

FRESHCLAM_BIN=$(command -v freshclam || true)
if [ -z "${FRESHCLAM_BIN}" ]; then
    echo "freshclam binary not found. Install clamav-freshclam before running this script." >&2
    exit 1
fi

FRESHCLAM_ARGS=(--stdout "--datadir=${DB_DIR}")

if [ "${QUIET}" -eq 1 ]; then
    FRESHCLAM_ARGS+=(--quiet)
fi

echo "Downloading ClamAV definitions into ${DB_DIR}..."
if ! "${FRESHCLAM_BIN}" "${FRESHCLAM_ARGS[@]}"; then
    echo "Unable to download ClamAV definitions. Check your network connection or adjust IMG_SCAN_* settings if you need to disable the scanner." >&2
    exit 1
fi

echo "ClamAV definitions up to date."
