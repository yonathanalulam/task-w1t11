#!/usr/bin/env bash
set -euo pipefail

SEED_VENDOR_DIR="/opt/backend-seed/vendor"
TARGET_VENDOR_DIR="/workspace/backend/vendor"
REQUIRED_AUTOLOAD="${TARGET_VENDOR_DIR}/autoload.php"
REQUIRED_COMPOSER_AUTOLOAD_REAL="${TARGET_VENDOR_DIR}/composer/autoload_real.php"
REQUIRED_PACKAGE_FILE="${TARGET_VENDOR_DIR}/myclabs/deep-copy/src/DeepCopy/deep_copy.php"
LOCK_DIR="/tmp/backend_vendor_bootstrap.lock"

if [[ ! -d "${SEED_VENDOR_DIR}" ]]; then
  echo "Seed vendor directory is missing at ${SEED_VENDOR_DIR}" >&2
  exit 1
fi

acquire_lock() {
  local retries=200
  local sleep_seconds=0.1

  for _ in $(seq 1 "${retries}"); do
    if mkdir "${LOCK_DIR}" 2>/dev/null; then
      trap 'rmdir "${LOCK_DIR}" 2>/dev/null || true' EXIT
      return 0
    fi
    sleep "${sleep_seconds}"
  done

  echo "Timed out waiting for backend vendor bootstrap lock." >&2
  exit 1
}

seed_vendor_from_image() {
  rm -rf "${TARGET_VENDOR_DIR}"
  mkdir -p "${TARGET_VENDOR_DIR}"
  cp -R "${SEED_VENDOR_DIR}/." "${TARGET_VENDOR_DIR}/"
}

vendor_is_healthy() {
  if [[ ! -f "${REQUIRED_AUTOLOAD}" || ! -f "${REQUIRED_COMPOSER_AUTOLOAD_REAL}" || ! -f "${REQUIRED_PACKAGE_FILE}" ]]; then
    return 1
  fi

  php -r "require '${REQUIRED_AUTOLOAD}';" >/dev/null 2>&1
}

acquire_lock

if ! vendor_is_healthy; then
  seed_vendor_from_image
fi

if ! vendor_is_healthy; then
  if command -v composer >/dev/null 2>&1; then
    composer install --working-dir=/workspace/backend --no-interaction --prefer-dist --no-scripts
  fi
fi

if ! vendor_is_healthy; then
  echo "Backend dependencies are incomplete under /workspace/backend/vendor" >&2
  exit 1
fi
