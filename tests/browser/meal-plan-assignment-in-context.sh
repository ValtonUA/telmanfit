#!/usr/bin/env bash
set -euo pipefail
export AUTH3_CONTROLLED=true AUTH3_FLOW_SECONDS=600 AUTH3_SESSION_SECONDS=3600
export AUTH3_ATTEMPTS=100 AUTH3_TOTP_WINDOW=1 AUTH3_RECOVERY_COUNT=3 AUTH3_KEY_VERSION=test_v1
export SESSION_DRIVER=database CACHE_STORE=database APP_DEBUG=false APP_URL=http://127.0.0.1:8090
export PAO_DISABLE=1
export ASSIGNMENT_BROWSER_FIXTURE
ASSIGNMENT_BROWSER_FIXTURE=$(php tests/browser/meal-plan-assignment-fixture.php seed)
log=$(mktemp /tmp/telmanfit-assignment-http.XXXXXXXX)
bash tests/browser/php-server.sh 127.0.0.1:8090 -t public tests/browser/recipe-batch-router.php >"$log" 2>&1 &
server=$!
cleanup() {
    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
    rm -f -- "$log"
}
trap cleanup EXIT
python3 tests/browser/meal_plan_assignments.py
