#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# REAL-HTTP BACKEND SMOKE LAYER
# -----------------------------------------------------------------------------
# Boots the composed stack (db + api) and runs tests/Smoke/* against
# http://api:8000 from inside a sibling api container. Covers two suites:
#
#   - ApiHttpSmokeTest:      core auth/session/CSRF/cookie + CSV download
#   - ApiWorkflowSmokeTest:  one endpoint per critical family
#                            (practitioner profile, practitioner credentials,
#                             reviewer queue, scheduling configuration,
#                             question-bank catalog, governance audit logs)
#
# Exits non-zero on failure. Uses only public Docker images already declared
# in docker-compose.yml / infra/mysql/Dockerfile / backend/Dockerfile.
# -----------------------------------------------------------------------------
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../.." && pwd)"
cd "${repo_root}"

if [[ -x "${script_dir}/docker_preflight.sh" ]]; then
  echo "[smoke] running docker preflight..."
  "${script_dir}/docker_preflight.sh"
fi

echo "[smoke] ensuring database is initialised..."
bash ./init_db.sh

echo "[smoke] starting api service..."
docker compose up -d db api

echo "[smoke] waiting for api readiness..."
ready=0
for _ in $(seq 1 60); do
  if docker compose exec -T api php -r '
    $ok = @file_get_contents("http://127.0.0.1:8000/api/health/ready");
    exit($ok !== false ? 0 : 1);
  ' >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done

if [[ "${ready}" -ne 1 ]]; then
  echo "[smoke] api service did not become ready in time." >&2
  docker compose logs --tail=80 api >&2 || true
  exit 1
fi

echo "[smoke] running tests/Smoke against http://api:8000 via sibling api container..."
docker compose run --rm --no-deps api bash -lc '
  set -a
  source /workspace/runtime/dev/runtime.env
  set +a
  TEST_DB_NAME="${DB_NAME}_test"
  export APP_ENV=test
  export APP_DEBUG=1
  export APP_SECRET="${APP_SECRET}"
  export DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@db:3306/${TEST_DB_NAME}?serverVersion=8.4.0&charset=utf8mb4"
  export MESSENGER_TRANSPORT_DSN="sync://"
  export FIELD_ENCRYPTION_KEYRING_PATH="${FIELD_ENCRYPTION_KEYRING_PATH}"
  export SMOKE_BASE_URL="http://api:8000"
  cd /workspace/backend
  php bin/phpunit --testdox tests/Smoke
'

echo "[smoke] enforcing true real-HTTP API coverage gate (>= 90%)..."
docker compose run --rm --no-deps api bash -lc '
  cd /workspace
  php scripts/dev/http_coverage_check.php --threshold=90
'

echo "[smoke] real-HTTP smoke layer passed."
