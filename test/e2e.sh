#!/bin/sh
# Browsertests (WebKit). Installeert @playwright/test als dat nog niet is gebeurd en gebruikt werk-webkit als die er is
# (de OVH mist de systeembibliotheken van WebKit).
set -e
cd "$(dirname "$0")/.."
[ -x node_modules/.bin/playwright ] || npm install --no-audit --no-fund --no-package-lock >/dev/null
if command -v werk-webkit >/dev/null 2>&1; then
  exec werk-webkit node_modules/.bin/playwright test -c test/playwright.config.mjs "$@"
fi
exec node_modules/.bin/playwright test -c test/playwright.config.mjs "$@"
