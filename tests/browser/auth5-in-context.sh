#!/usr/bin/env bash
set -euo pipefail
php tests/pg/auth2-bootstrap.php >/dev/null
export AUTH3_CONTROLLED=true AUTH3_FLOW_SECONDS=300 AUTH3_SESSION_SECONDS=28800
export AUTH3_ATTEMPTS=10 AUTH3_TOTP_WINDOW=1 AUTH3_RECOVERY_COUNT=3 AUTH3_KEY_VERSION=test_v1
export AUTH4_CONTROLLED=true AUTH5_CONTROLLED=true AUTH4_OTP_KEY_VERSION=test_v1
export AUTH4_OTP_KEY='synthetic-browser-only-hmac-key-32minimum'
export AUTH4_RATE_KEY='synthetic-browser-only-rate-key-32minimum'
export SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database MAIL_MAILER=auth4_synthetic
export APP_DEBUG=false APP_URL=http://127.0.0.1:8095 PAO_DISABLE=1
umask 077
auth5_work=$(mktemp -d /tmp/telmanfit-auth4-XXXXXXXX)
export AUTH4_SYNTHETIC_MAILBOX="$auth5_work/mailbox" AUTH5_SERVER_LOG="$auth5_work/server.log"
php tests/browser/auth5-fixture.php
bash tests/browser/php-server.sh 127.0.0.1:8095 -t public server.php >"$AUTH5_SERVER_LOG" 2>&1 &
server=$!
cleanup() {
    kill "$server" 2>/dev/null || true
    wait "$server" 2>/dev/null || true
    rm -f -- "$auth5_work/mailbox" "$auth5_work/server.log"
    rmdir -- "$auth5_work"
}
trap cleanup EXIT
python3 tests/browser/auth5.py
