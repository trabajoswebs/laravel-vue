#!/usr/bin/env bash
set -euo pipefail

# Uso: ./scripts/check_storage_exec.sh <base_url>
# Ejemplo: ./scripts/check_storage_exec.sh https://miapp.com

if [[ $# -ne 1 ]]; then
    echo "Uso: $0 <base_url>" >&2
    exit 64
fi

BASE_URL="${1%/}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

PAYLOAD_PATH="${TMP_DIR}/test.php"
PAYLOAD_CONTENT='<?php echo "pwned"; ?>'

printf '%s' "$PAYLOAD_CONTENT" > "$PAYLOAD_PATH"

# Copiamos al storage público (requiere acceso local o rsync previo).
TARGET="storage/app/public/security-test.php"
mkdir -p "$(dirname "$TARGET")"
cp "$PAYLOAD_PATH" "$TARGET"

URL="${BASE_URL}/storage/security-test.php"
HTTP_STATUS=$(curl -ks -o /dev/null -w '%{http_code}' "$URL")

if [[ "$HTTP_STATUS" == "200" ]]; then
    echo "[ERROR] ${URL} devolvió 200. Bloqueo de ejecución inexistente." >&2
    exit 1
fi

echo "[OK] ${URL} devolvió ${HTTP_STATUS}, ejecución bloqueada."
