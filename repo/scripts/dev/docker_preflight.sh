#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# DOCKER PREFLIGHT
# -----------------------------------------------------------------------------
# Detects environmental issues that otherwise surface as confusing failures
# partway through `./run_tests.sh` or `./init_db.sh`:
#
#   1. docker CLI not installed
#   2. docker daemon unreachable
#   3. docker credential helper misconfigured (common on Windows/WSL when
#      ~/.docker/config.json references a "credsStore" binary that is not
#      on PATH; breaks anonymous pulls of public images)
#   4. required public base images unavailable locally AND not pullable
#
# We intentionally do NOT paper over credential-helper issues in code: we
# detect and surface them with actionable remediation steps so the user can
# fix their environment.
#
# Exits 0 when the environment looks pullable. Exits non-zero with a readable
# diagnostic block otherwise.
# -----------------------------------------------------------------------------
set -uo pipefail

REQUIRED_IMAGES=(
  "bash:5.2"
  "php:8.4-cli-bookworm"
  "composer:2"
  "node:24-bookworm-slim"
  "mcr.microsoft.com/playwright:v1.59.1-noble"
)

fail() {
  local header="$1"
  shift
  echo
  echo "========================================================================" >&2
  echo "[docker-preflight] ${header}" >&2
  echo "========================================================================" >&2
  for line in "$@"; do
    echo "  ${line}" >&2
  done
  echo "========================================================================" >&2
  exit 1
}

if ! command -v docker >/dev/null 2>&1; then
  fail "docker CLI is not installed or not on PATH" \
    "Install Docker Desktop or the docker-ce package and re-run this script." \
    "See https://docs.docker.com/get-docker/"
fi

if ! docker info >/dev/null 2>&1; then
  fail "docker daemon is not reachable" \
    "The docker CLI is installed but cannot talk to a running daemon." \
    "If on Windows/Mac: start Docker Desktop and wait for it to finish initialising." \
    "If on Linux: ensure 'systemctl start docker' succeeded and your user is in the 'docker' group."
fi

credential_hint() {
  local stderr_text="$1"
  local config_path="${DOCKER_CONFIG:-$HOME/.docker}/config.json"

  fail "docker credential helper is misconfigured" \
    "Underlying docker CLI error:" \
    "  ${stderr_text}" \
    "" \
    "This usually means your Docker config references a credsStore helper binary" \
    "that is not available on PATH (common when Docker Desktop is uninstalled," \
    "or under WSL when 'docker-credential-desktop.exe' is referenced from Linux)." \
    "" \
    "Remediation:" \
    "  1. Open ${config_path}" \
    "  2. Remove or rename the \"credsStore\" / \"credHelpers\" entries." \
    "     Minimal working file:" \
    "         { \"auths\": {} }" \
    "  3. Re-run this script. Anonymous pulls of public images do not need a helper." \
    "" \
    "If you need to keep the helper, install the referenced binary and make sure" \
    "its directory is on PATH."
}

check_image() {
  local image="$1"

  if docker image inspect "${image}" >/dev/null 2>&1; then
    echo "[docker-preflight] image ok (cached): ${image}"
    return 0
  fi

  local tmp_err
  tmp_err="$(mktemp -t docker-preflight.XXXXXX)"
  if docker pull "${image}" >/dev/null 2>"${tmp_err}"; then
    rm -f "${tmp_err}"
    echo "[docker-preflight] image ok (pulled): ${image}"
    return 0
  fi

  local stderr_text
  stderr_text="$(cat "${tmp_err}")"
  rm -f "${tmp_err}"

  if echo "${stderr_text}" | grep -qiE 'docker-credential|error getting credentials|credsStore|credential helper'; then
    credential_hint "${stderr_text}"
  fi

  fail "failed to pull required image: ${image}" \
    "Underlying docker CLI error:" \
    "  ${stderr_text}" \
    "" \
    "If this is a transient network issue, re-run the script once your connection is" \
    "stable. If this image name looks wrong, confirm the reference in docker-compose.yml." \
    "All images used by this repo are public and do not require authentication."
}

echo "[docker-preflight] verifying base images: ${REQUIRED_IMAGES[*]}"
for image in "${REQUIRED_IMAGES[@]}"; do
  check_image "${image}"
done

echo "[docker-preflight] all checks passed."
