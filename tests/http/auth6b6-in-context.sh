#!/usr/bin/env bash
set -euo pipefail
php tests/pg/auth2-bootstrap.php >/dev/null
export AUTH3_CONTROLLED=true AUTH3_FLOW_SECONDS=300 AUTH3_SESSION_SECONDS=28800
export AUTH3_ATTEMPTS=100 AUTH3_TOTP_WINDOW=1 AUTH3_RECOVERY_COUNT=3 AUTH3_KEY_VERSION=test_v1
export AUTH4_CONTROLLED=true AUTH5_CONTROLLED=true AUTH4_OTP_KEY_VERSION=test_v1
export AUTH4_OTP_KEY='synthetic-b6-http-only-hmac-key-32minimum'
export AUTH4_RATE_KEY='synthetic-b6-http-only-rate-key-32minimum'
export SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=sync MAIL_MAILER=array
export APP_DEBUG=false PAO_DISABLE=1
umask 077
b6_work=$(mktemp -d /tmp/telmanfit-b6-http.XXXXXXXX)
php tests/http/auth6b6-fixture.php
php -S 127.0.0.1:8098 tests/http/auth6b6-router.php >"$b6_work/a.log" 2>&1 &
b6_a=$!
php -S 127.0.0.1:8099 tests/http/auth6b6-router.php >"$b6_work/b.log" 2>&1 &
b6_b=$!
cleanup() {
    kill "$b6_a" "$b6_b" 2>/dev/null || true
    wait "$b6_a" "$b6_b" 2>/dev/null || true
    rm -f -- "$b6_work/a.log" "$b6_work/b.log"
    rmdir "$b6_work"
}
trap cleanup EXIT
python3 tests/http/auth6b6.py
python3 - "$b6_work" <<'PYCODE'
from pathlib import Path
import sys
for log in Path(sys.argv[1]).glob('*.log'):
    text = log.read_text()
    assert not any(s in text for s in ['b6-redaction-canary', 'b6-http-known@example', 'b6-http-decoy@example'])
PYCODE
