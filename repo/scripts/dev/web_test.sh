#!/usr/bin/env bash
set -euo pipefail

bash /workspace/scripts/dev/bootstrap_runtime.sh

cd /workspace/frontend

if [[ ! -x /workspace/frontend/node_modules/.bin/vitest || ! -d /workspace/frontend/node_modules/happy-dom ]]; then
  mkdir -p /workspace/frontend/node_modules
  rm -rf /workspace/frontend/node_modules/*
  cp -R /opt/frontend-seed/node_modules/. /workspace/frontend/node_modules/
fi

exec npm run test:ci
