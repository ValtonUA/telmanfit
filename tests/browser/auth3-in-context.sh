#!/usr/bin/env bash
# Викликається лише з уже перевіреним новим PG18 runner; власних DB defaults немає.
set -euo pipefail
php tests/pg/auth2-bootstrap.php >/dev/null
export AUTH3_CONTROLLED=true AUTH3_FLOW_SECONDS=600 AUTH3_SESSION_SECONDS=3600
export AUTH3_ATTEMPTS=100 AUTH3_TOTP_WINDOW=1 AUTH3_RECOVERY_COUNT=3 AUTH3_KEY_VERSION=test_v1
export SESSION_DRIVER=database CACHE_STORE=database APP_DEBUG=false APP_URL=http://127.0.0.1:8093
export PAO_DISABLE=1
php tests/browser/auth3-fixture.php
AUTH3_SERVER_LOG=$(mktemp /tmp/auth3-http.XXXXXXXX)
export AUTH3_SERVER_LOG
php -S 127.0.0.1:8093 -t public tests/browser/auth3-router.php >"$AUTH3_SERVER_LOG" 2>&1 &
server=$!
cleanup() {
    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
    rm -f -- "$AUTH3_SERVER_LOG"
}
trap cleanup EXIT
python3 tests/browser/auth3.py
