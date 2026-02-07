#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CACHE_FILE="bootstrap/cache/config.testing.php"

cleanup() {
  rm -f "$CACHE_FILE"
}

trap cleanup EXIT

run_in_sail() {
  ./vendor/bin/sail run sh -lc "$1"
}

run_in_sail "APP_ENV=testing APP_CONFIG_CACHE='$CACHE_FILE' php artisan config:clear --env=testing"
run_in_sail "APP_ENV=testing APP_CONFIG_CACHE='$CACHE_FILE' php artisan config:cache --env=testing"
run_in_sail "APP_ENV=testing APP_CONFIG_CACHE='$CACHE_FILE' php artisan test --env=testing"
