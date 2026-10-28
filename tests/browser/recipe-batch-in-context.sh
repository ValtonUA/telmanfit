#!/usr/bin/env bash
# Тільки з disposable PG18 runner, ніколи не використовує .env defaults.
set -euo pipefail
export AUTH3_CONTROLLED=true AUTH3_FLOW_SECONDS=600 AUTH3_SESSION_SECONDS=3600
export AUTH3_ATTEMPTS=100 AUTH3_TOTP_WINDOW=1 AUTH3_RECOVERY_COUNT=3 AUTH3_KEY_VERSION=test_v1
export AUTH4_CONTROLLED=true AUTH4_OTP_KEY_VERSION=test_v1
export AUTH4_OTP_KEY='synthetic-browser-only-hmac-key-32minimum'
export AUTH4_RATE_KEY='synthetic-browser-only-rate-key-32minimum'
export SESSION_DRIVER=database CACHE_STORE=database APP_DEBUG=false APP_URL=http://127.0.0.1:8090
export QUEUE_CONNECTION=database MAIL_MAILER=auth4_synthetic PUBLIC_IMAGE_HOSTS=images.example.com
export PAO_DISABLE=1
work=$(mktemp -d /tmp/telmanfit-batch-http.XXXXXXXX)
export AUTH4_SYNTHETIC_MAILBOX="$work/mailbox"
BATCH_BROWSER_VERSION=$(php tests/browser/recipe-batch-fixture.php)
export BATCH_BROWSER_VERSION
log="$work/server.log"
bash tests/browser/php-server.sh 127.0.0.1:8090 -t public tests/browser/recipe-batch-router.php >"$log" 2>&1 &
server=$!
cleanup() {
    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
    rm -f -- "$log" "$AUTH4_SYNTHETIC_MAILBOX"
    rmdir -- "$work"
}
trap cleanup EXIT
python3 tests/browser/recipe_batch.py
