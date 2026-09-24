#!/usr/bin/env bash
# Run every check CI runs, locally. Usage: scripts/check.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Frontend"
cd "$ROOT/frontend"
npm ci
npm run lint
npm run typecheck
npm test
npm run build

echo "==> WordPress plugin"
cd "$ROOT/wordpress/plugins/lexranked-core"
composer install --no-interaction --no-progress
composer lint
composer test

echo "==> All checks passed"
