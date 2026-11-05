#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
export LIGHT_DB_TEST_PORT="${LIGHT_DB_TEST_PORT:-33390}"
compose=(docker compose -p "light-db-v2-test-$$" -f compose.test.yaml)
cleanup() {
    status=$?
    if (( status != 0 )); then "${compose[@]}" logs --tail=20 mysql || true; fi
    "${compose[@]}" down --volumes
    exit "$status"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait --wait-timeout 150
export LIGHT_DB_TEST_DSN="mysql:host=127.0.0.1;port=${LIGHT_DB_TEST_PORT};charset=utf8mb4"
export LIGHT_DB_TEST_USER=root
export LIGHT_DB_TEST_PASSWORD=light_db_test
"${PHP_BINARY:-php}" vendor/bin/phpunit "$@"
