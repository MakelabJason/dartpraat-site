#!/bin/sh
# Lokaal draaien met de echte feed: http://127.0.0.1:${PORT:-8080}/ (cache in ./cache).
cd "$(dirname "$0")/.."
exec "${PHP:-php}" -S "127.0.0.1:${PORT:-8080}" -t public test/router.php
