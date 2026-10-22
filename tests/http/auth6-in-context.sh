#!/usr/bin/env bash
set -euo pipefail
php tests/pg/auth2-bootstrap.php >/dev/null
export AUTH3_CONTROLLED=true AUTH3_FLOW_SECONDS=300 AUTH3_SESSION_SECONDS=28800
export AUTH3_ATTEMPTS=100 AUTH3_TOTP_WINDOW=1 AUTH3_RECOVERY_COUNT=3 AUTH3_KEY_VERSION=test_v1
export AUTH4_CONTROLLED=true AUTH5_CONTROLLED=true AUTH4_OTP_KEY_VERSION=test_v1
export AUTH4_OTP_KEY='synthetic-http-only-hmac-key-32minimum'
export AUTH4_RATE_KEY='synthetic-http-only-rate-key-32minimum'
export SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=sync MAIL_MAILER=array
export APP_DEBUG=false PAO_DISABLE=1
umask 077
AUTH6_WORK=$(mktemp -d /tmp/telmanfit-auth6-http.XXXXXXXX)
export AUTH6_WORK
php -S 127.0.0.1:8096 tests/http/auth6-router.php >"$AUTH6_WORK/server-a.log" 2>&1 &
server_a=$!
php -S 127.0.0.1:8097 tests/http/auth6-router.php >"$AUTH6_WORK/server-b.log" 2>&1 &
server_b=$!
cleanup() {
    kill "$server_a" "$server_b" 2>/dev/null || true
    wait "$server_a" "$server_b" 2>/dev/null || true
    find "$AUTH6_WORK" -maxdepth 1 -type f -delete
    rmdir "$AUTH6_WORK"
}
trap cleanup EXIT
python3 tests/http/auth6.py
