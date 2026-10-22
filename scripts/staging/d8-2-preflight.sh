#!/usr/bin/env bash
# D8.2 staging-only read-only capability inventory. No sudo, no mutating commands.
set -u
export LC_ALL=C
printf 'TelmanFit D8.2 read-only VPS inventory (NO provisioning)\n'
printf '===============================================\n'
check() {
  label="$1"
  bin="$2"
  if command -v "$bin" >/dev/null 2>&1; then
    printf '%s: present (%s)\n' "$label" "$(command -v "$bin")"
  else
    printf '%s: missing\n' "$label"
  fi
}
if test -r /etc/os-release; then
  . /etc/os-release
  printf 'OS: %s\n' "${PRETTY_NAME:-unknown}"
else
  printf 'OS: unknown\n'
fi
printf 'Kernel: '
uname -sr 2>/dev/null || printf 'unknown\n'
printf 'PID1: '
if test -r /proc/1/comm; then
  head -n 1 /proc/1/comm
else
  printf 'unknown\n'
fi
if command -v nproc >/dev/null 2>&1; then
  printf 'CPU available: '
  nproc
fi
if command -v free >/dev/null 2>&1; then
  printf 'Memory: '
  free -m | awk '/^Mem:/ {printf "total=%s MiB available=%s MiB\\n", $2,$7}'
fi
if command -v df >/dev/null 2>&1; then
  printf 'Root filesystem: '
  df -hP / | awk 'NR==2 {printf "size=%s available=%s use=%s\\n",$2,$4,$5}'
fi
check PHP php
check PostgreSQL-client psql
check PostgreSQL-config pg_config
check systemctl systemctl
check cron crontab
check nginx nginx
check curl curl
check openssl openssl
if command -v php >/dev/null 2>&1; then
  printf 'PHP version: '
  php -r 'echo PHP_VERSION, PHP_EOL;' 2>/dev/null || printf 'unavailable\n'
  printf 'PDO_PGSQL extension: '
  php -r 'echo extension_loaded("pdo_pgsql") ? "yes\\n" : "no\\n";' 2>/dev/null || printf 'unknown\n'
fi
if command -v psql >/dev/null 2>&1; then
  printf 'psql version: '
  psql --version
fi
if command -v pg_config >/dev/null 2>&1; then
  sharedir="$(pg_config --sharedir 2>/dev/null || true)"
  if test -n "$sharedir" && test -f "$sharedir/extension/btree_gist.control"; then
    printf 'btree_gist extension control: present (not proof CREATE EXTENSION works)\n'
  else
    printf 'btree_gist extension control: missing/unknown\n'
  fi
fi
printf 'This inventory does not prove root privileges, running services, SMTP, PostgreSQL extension privileges or usable backups.\n'
