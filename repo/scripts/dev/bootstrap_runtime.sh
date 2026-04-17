#!/usr/bin/env bash
set -euo pipefail

# -----------------------------------------------------------------------------
# DEV-ONLY RUNTIME BOOTSTRAP
# -----------------------------------------------------------------------------
# This script generates local runtime values for offline development:
# - database bootstrap credentials
# - application secret
# - local field-encryption keyring
#
# It is automatically invoked by Docker startup paths and test wrappers.
# It is NOT a production secret management solution.
# -----------------------------------------------------------------------------

RUNTIME_ROOT="/workspace/runtime"
DEV_DIR="${RUNTIME_ROOT}/dev"
SECRETS_DIR="${RUNTIME_ROOT}/secrets/field-encryption"
ENV_FILE="${DEV_DIR}/runtime.env"
KEYRING_FILE="${SECRETS_DIR}/keyring.json"
DEV_FIXED_PASSWORD="local-dev-password-123"

mkdir -p "${DEV_DIR}" "${SECRETS_DIR}"

random_hex() {
  local bytes="${1}"
  od -An -N "${bytes}" -tx1 /dev/urandom | tr -d ' \n'
}

random_b64_32() {
  # 32 bytes raw => 44 chars base64.
  dd if=/dev/urandom bs=32 count=1 status=none | base64 | tr -d '\n'
}

if [[ ! -f "${ENV_FILE}" ]]; then
  db_suffix="$(random_hex 3)"
  db_password="$(random_hex 20)"
  db_root_password="$(random_hex 24)"
  app_secret="$(random_hex 32)"
  dev_password="${DEV_FIXED_PASSWORD}"

  cat > "${ENV_FILE}" <<EOF
DB_NAME=regops_${db_suffix}
DB_USER=regops_user_${db_suffix}
DB_PASSWORD=${db_password}
DB_ROOT_PASSWORD=${db_root_password}
APP_SECRET=${app_secret}
DEV_BOOTSTRAP_PASSWORD=${dev_password}
FIELD_ENCRYPTION_KEYRING_PATH=/run/secrets/field-encryption/keyring.json
EOF

  chmod 600 "${ENV_FILE}"
else
  tmp_env_file="$(mktemp)"
  awk -v fixed_password="${DEV_FIXED_PASSWORD}" '
    /^DEV_BOOTSTRAP_PASSWORD=/ {
      print "DEV_BOOTSTRAP_PASSWORD=" fixed_password
      replaced = 1
      next
    }
    { print }
    END {
      if (!replaced) {
        print "DEV_BOOTSTRAP_PASSWORD=" fixed_password
      }
    }
  ' "${ENV_FILE}" > "${tmp_env_file}"
  mv "${tmp_env_file}" "${ENV_FILE}"
  chmod 600 "${ENV_FILE}"
fi

if [[ ! -f "${KEYRING_FILE}" ]]; then
  active_key_id="dev-key-$(date +%Y%m%d)-$(random_hex 4)"
  key_material="$(random_b64_32)"

  cat > "${KEYRING_FILE}" <<EOF
{
  "activeKeyId": "${active_key_id}",
  "keys": {
    "${active_key_id}": "${key_material}"
  }
}
EOF

  chmod 600 "${KEYRING_FILE}"
fi

# keep runtime file mirrored into the runtime secret mount for API/worker access
cp "${KEYRING_FILE}" /run/secrets/field-encryption/keyring.json 2>/dev/null || true

echo "Local runtime bootstrap ready."
