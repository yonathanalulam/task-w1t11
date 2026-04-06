#!/usr/bin/env bash
set -euo pipefail

./init_db.sh

wait_for_db() {
  docker compose up -d db >/dev/null

  local db_ready=0
  for _ in $(seq 1 60); do
    if docker compose exec -T db mysqladmin ping -h localhost --silent >/dev/null 2>&1; then
      db_ready=1
      break
    fi
    sleep 2
  done

  if [[ "$db_ready" -ne 1 ]]; then
    echo "Database did not become healthy in time." >&2
    exit 1
  fi
}

wait_for_db

cleanup() {
  docker compose down --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT

WEB_HOST_PORT="${WEB_HOST_PORT:-4280}"

echo "Running backend tests..."
docker compose run --rm --no-deps api bash -lc '
  set -a
  source /workspace/runtime/dev/runtime.env
  set +a
  TEST_DB_NAME="${DB_NAME}_test"
  export APP_ENV=test APP_DEBUG=1
  export APP_SECRET="${APP_SECRET}"
  export DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@db:3306/${TEST_DB_NAME}?serverVersion=8.4.0&charset=utf8mb4"
  export MESSENGER_TRANSPORT_DSN="sync://"
  export FIELD_ENCRYPTION_KEYRING_PATH="${FIELD_ENCRYPTION_KEYRING_PATH}"
  cd /workspace/backend
  php bin/phpunit
'

echo "Running frontend unit tests..."
docker compose run --rm --no-deps web bash -lc '/workspace/scripts/dev/web_test.sh'

echo "Starting runtime stack for e2e..."
docker compose up -d db api worker web

echo "Waiting for app readiness..."
ready=0
for _ in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:${WEB_HOST_PORT}/api/health/ready" >/dev/null; then
    ready=1
    break
  fi
  sleep 2
done

if [[ "$ready" -ne 1 ]]; then
  echo "Application did not become ready in time." >&2
  exit 1
fi

echo "Running Playwright e2e tests..."
docker compose --profile test run --rm --no-deps e2e

echo "All tests completed."
