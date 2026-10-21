#!/usr/bin/env bash
# Відтворюваний прогін лише на новому контейнері; наявні БД не використовуються.
set -euo pipefail
cd "$(dirname "$0")/../.."
for tool in docker php python3; do command -v "$tool" >/dev/null; done
umask 077
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
[[ -z ${DOCKER_HOST:-} && -z ${DOCKER_CONTEXT:-} && $endpoint == unix://* ]]
work=$(mktemp -d /tmp/telmanfit-stage1.XXXXXXXX)
container=''
cleanup() {
    code=$?; trap - EXIT
    if [[ -n "$container" ]]; then docker rm -f "$container" >/dev/null || code=1; fi
    rm -f -- "$work/socket/.s.PGSQL.5432" "$work/socket/.s.PGSQL.5432.lock"
    rmdir -- "$work/socket" "$work" || code=1
    exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$work/socket"
chmod 777 "$work/socket"
# Той самий офіційний pin, що й у погодженому G4; жодних мереж чи портів.
image='postgres:18.6-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc'
docker pull "$image"
container=$(docker create --network none --tmpfs /var/lib/postgresql \
    --mount "type=bind,src=$work/socket,dst=/run/telmanfit-pg" \
    -e POSTGRES_HOST_AUTH_METHOD=trust "$image" \
    -c 'unix_socket_directories=/var/run/postgresql,/run/telmanfit-pg')
docker start "$container" >/dev/null
ready=0
for ((i=0; i<60; i++)); do
    if docker exec "$container" pg_isready -U postgres >/dev/null 2>&1; then ready=1; break; fi
    sleep 0.5
done
[[ "$ready" == 1 ]]
[[ $(docker inspect --format '{{.HostConfig.NetworkMode}}' "$container") == none && -z $(docker port "$container") ]]
docker exec "$container" psql -X -U postgres -v ON_ERROR_STOP=1 \
    -c 'CREATE ROLE telmanfit_test_runner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;' \
    -c "CREATE DATABASE telmanfit_stage1_test OWNER telmanfit_test_runner TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'uk-UA';"
export DB_CONNECTION=pgsql DB_HOST="$work/socket" DB_PORT=5432
export DB_DATABASE=telmanfit_stage1_test DB_USERNAME=telmanfit_test_runner DB_PASSWORD= DB_URL=
export APP_ENV=testing TELMANFIT_PG_TEST=1
php artisan migrate --force --no-interaction
mkdir -p tests/pg/out
for file in tests/pg/t0*.sql; do
    docker exec -i "$container" psql -X -w -U "$DB_USERNAME" -d "$DB_DATABASE" \
        -A -F ' | ' -v ON_ERROR_STOP=1 < "$file" > "tests/pg/out/$(basename "$file" .sql).out"
done
python3 - <<'PY'
from pathlib import Path
import re
counts = dict.fromkeys(('PASS', 'FAIL', 'GAP'), 0)
for path in sorted(Path('tests/pg/out').glob('t0*.out')):
    for status, count in re.findall(r'^(PASS|FAIL|GAP)\s*\|\s*(\d+)\s*$', path.read_text(), re.M):
        counts[status] += int(count)
print('SQL: ' + ' '.join(f'{key}={value}' for key, value in counts.items()))
assert counts == {'PASS': 76, 'FAIL': 0, 'GAP': 0}, counts
PY
vendor/bin/phpunit -c phpunit.pgsql.xml --display-all-issues
# Основний конфіг перемикає на SQLite; PostgreSQL-набір має окремий конфіг.
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
vendor/bin/pint --test
