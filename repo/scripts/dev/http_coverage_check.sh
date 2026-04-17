#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# TRUE REAL-HTTP API COVERAGE GATE (wrapper)
# -----------------------------------------------------------------------------
# Runs scripts/dev/http_coverage_check.php in the api image so no local PHP
# install is required. Accepts the same flags as the PHP script:
#
#   --threshold=N   minimum coverage percentage (default 90)
#   --json          emit machine-readable JSON
#
# Exits non-zero when coverage is below the threshold; prints the uncovered
# METHOD + PATH list with a readable diagnostic.
# -----------------------------------------------------------------------------
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../.." && pwd)"
cd "${repo_root}"

ARGS="$*"

docker compose run --rm --no-deps api bash -lc "
  cd /workspace
  php scripts/dev/http_coverage_check.php ${ARGS}
"
