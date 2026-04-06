#!/usr/bin/env bash
set -euo pipefail

SEED_VENDOR_DIR="/opt/backend-seed/vendor"
TARGET_VENDOR_DIR="/workspace/backend/vendor"
REQUIRED_AUTOLOAD="${TARGET_VENDOR_DIR}/autoload.php"
REQUIRED_PACKAGE_FILE="${TARGET_VENDOR_DIR}/myclabs/deep-copy/src/DeepCopy/deep_copy.php"

if [[ ! -d "${SEED_VENDOR_DIR}" ]]; then
  echo "Seed vendor directory is missing at ${SEED_VENDOR_DIR}" >&2
  exit 1
fi

if [[ ! -f "${REQUIRED_AUTOLOAD}" || ! -f "${REQUIRED_PACKAGE_FILE}" ]]; then
  mkdir -p "${TARGET_VENDOR_DIR}"
  cp -R "${SEED_VENDOR_DIR}/." "${TARGET_VENDOR_DIR}/"
fi

if [[ ! -f "${REQUIRED_AUTOLOAD}" || ! -f "${REQUIRED_PACKAGE_FILE}" ]]; then
  if command -v composer >/dev/null 2>&1; then
    composer install --working-dir=/workspace/backend --no-interaction --prefer-dist --no-scripts
  fi
fi

if [[ ! -f "${REQUIRED_AUTOLOAD}" || ! -f "${REQUIRED_PACKAGE_FILE}" ]]; then
  echo "Backend dependencies are incomplete under /workspace/backend/vendor" >&2
  exit 1
fi
