#!/usr/bin/env bash
# Conductor setup script — runs once when a workspace is created.
# https://www.conductor.build/docs/reference/scripts
set -euo pipefail

# PHP dev deps (PHPCS, PHPUnit, Brain Monkey).
composer install --no-interaction --prefer-dist

# JS deps — npm ci for deterministic installs from package-lock.json.
# --include=dev forces devDependencies even if NODE_ENV=production is set
# in Conductor's environment (wp-scripts lives in devDependencies).
npm ci --include=dev

# Build the block. build/ is gitignored and required for the plugin to run.
npm run build

# Install the Playwright browser binary used by E2E and smoke tests.
npx playwright install --with-deps chromium
