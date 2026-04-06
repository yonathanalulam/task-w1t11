#!/usr/bin/env bash
set -euo pipefail

/workspace/scripts/dev/bootstrap_runtime.sh
bash /workspace/scripts/dev/ensure_backend_vendor.sh

set -a
source /workspace/runtime/dev/runtime.env
set +a

export APP_ENV="${APP_ENV:-dev}"
export APP_DEBUG="${APP_DEBUG:-1}"
export DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@db:3306/${DB_NAME}?serverVersion=8.4.0&charset=utf8mb4"
export MESSENGER_TRANSPORT_DSN="doctrine://default?queue_name=async"
export FIELD_ENCRYPTION_KEYRING_PATH="${FIELD_ENCRYPTION_KEYRING_PATH}"

cd /workspace/backend

/workspace/init_db.sh --container

exec php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --sleep=1 -vv
