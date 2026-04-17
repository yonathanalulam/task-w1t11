#!/usr/bin/env bash
set -euo pipefail

run_container_init() {
  local include_test="${1:-0}"
  local redact_password_output="${2:-0}"
  local db_init_lock_dir="/workspace/runtime/dev/db_init.lock"

  /workspace/scripts/dev/bootstrap_runtime.sh

  set -a
  source /workspace/runtime/dev/runtime.env
  set +a

  local database_url="mysql://${DB_USER}:${DB_PASSWORD}@db:3306/${DB_NAME}?serverVersion=8.4.0&charset=utf8mb4"
  local test_db_name="${DB_NAME}_test"
  local test_database_url="mysql://${DB_USER}:${DB_PASSWORD}@db:3306/${test_db_name}?serverVersion=8.4.0&charset=utf8mb4"

  export MESSENGER_TRANSPORT_DSN="doctrine://default?queue_name=async"
  export FIELD_ENCRYPTION_KEYRING_PATH="${FIELD_ENCRYPTION_KEYRING_PATH}"

  bash /workspace/scripts/dev/ensure_backend_vendor.sh

  cd /workspace/backend

  for _ in $(seq 1 300); do
    if mkdir "${db_init_lock_dir}" 2>/dev/null; then
      trap 'rmdir "${db_init_lock_dir}" 2>/dev/null || true' RETURN
      break
    fi
    sleep 0.2
  done

  if [[ ! -d "${db_init_lock_dir}" ]]; then
    echo "Timed out waiting for DB init lock." >&2
    exit 1
  fi

  local seed_command=(php bin/console app:seed-dev-users --no-interaction)
  if [[ "${redact_password_output}" == "1" ]]; then
    seed_command+=(--redact-password-output)
  fi

  DATABASE_URL="${database_url}" APP_ENV=dev APP_DEBUG=1 php bin/console doctrine:database:create --if-not-exists --no-interaction
  DATABASE_URL="${database_url}" APP_ENV=dev APP_DEBUG=1 php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
  DATABASE_URL="${database_url}" APP_ENV=dev APP_DEBUG=1 "${seed_command[@]}"

  if [[ "${include_test}" == "1" ]]; then
    mysql -h db -u root "-p${DB_ROOT_PASSWORD}" -e "DROP DATABASE IF EXISTS ${test_db_name}; CREATE DATABASE ${test_db_name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON ${test_db_name}.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;"
    DATABASE_URL="${test_database_url}" APP_ENV=test APP_DEBUG=1 php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    DATABASE_URL="${test_database_url}" APP_ENV=test APP_DEBUG=1 "${seed_command[@]}"
  fi

  rmdir "${db_init_lock_dir}" 2>/dev/null || true
}

if [[ "${1:-}" == "--container" ]]; then
  include_test=0
  redact_password_output=1
  if [[ "${2:-}" == "--with-test" ]]; then
    include_test=1
    redact_password_output=0
  fi

  run_container_init "${include_test}" "${redact_password_output}"
  exit 0
fi

if [[ -x "$(dirname "$0")/scripts/dev/docker_preflight.sh" ]]; then
  "$(dirname "$0")/scripts/dev/docker_preflight.sh"
fi

docker compose run --rm --no-deps bootstrap
docker compose up -d db

db_ready=0
for _ in $(seq 1 60); do
  if docker compose exec -T db mysqladmin ping -h localhost --silent >/dev/null 2>&1; then
    db_ready=1
    break
  fi
  sleep 2
done

if [[ "${db_ready}" -ne 1 ]]; then
  echo "Database did not become healthy in time." >&2
  exit 1
fi

docker compose run --rm --no-deps api bash -lc "/workspace/init_db.sh --container --with-test"
