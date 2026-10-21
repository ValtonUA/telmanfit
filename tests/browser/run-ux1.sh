#!/usr/bin/env bash
# Виключно синтетичні дані в новому контейнері без мережі та портів.
set -euo pipefail
cd "$(dirname "$0")/../.."
phase=${1:-after}
[[ "$phase" == before || "$phase" == after ]]
umask 077
work=$(mktemp -d /tmp/telmanfit-ux1.XXXXXXXX)
container=''
server=''
cleanup() {
    code=$?; trap - EXIT
    if [[ -n "$server" ]]; then kill "$server" 2>/dev/null || true; wait "$server" 2>/dev/null || true; fi
    if [[ -n "$container" ]]; then docker rm -f "$container" >/dev/null || code=1; fi
    # Лише власний тимчасовий каталог, створений цим запуском.
    rm -rf -- "$work"
    exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
[[ -z ${DOCKER_HOST:-} && -z ${DOCKER_CONTEXT:-} && $endpoint == unix://* ]]
mkdir "$work/socket"
chmod 777 "$work/socket"
image='postgres:18.6-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc'
docker pull "$image" >/dev/null
container=$(docker create --network none --tmpfs /var/lib/postgresql \
    --mount "type=bind,src=$work/socket,dst=/run/telmanfit-pg" \
    -e POSTGRES_HOST_AUTH_METHOD=trust "$image" \
    -c 'unix_socket_directories=/var/run/postgresql,/run/telmanfit-pg')
docker start "$container" >/dev/null
ready=0
for ((i=0; i<120; i++)); do
    if [[ $(docker logs "$container" 2>&1) == *'PostgreSQL init process complete; ready for start up.'* ]] \
        && docker exec "$container" pg_isready -h /run/telmanfit-pg -U postgres >/dev/null 2>&1; then
        ready=1; break
    fi
    sleep 0.5
done
[[ "$ready" == 1 ]]
[[ $(docker inspect --format '{{.HostConfig.NetworkMode}}' "$container") == none && -z $(docker port "$container") ]]
docker exec "$container" psql -X -U postgres -v ON_ERROR_STOP=1 \
    -c 'CREATE ROLE telmanfit_test_runner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;' \
    -c "CREATE DATABASE telmanfit_ux1_test OWNER telmanfit_test_runner TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'uk-UA';"
export DB_CONNECTION=pgsql DB_HOST="$work/socket" DB_PORT=5432 DB_URL=
export DB_DATABASE=telmanfit_ux1_test DB_USERNAME=telmanfit_test_runner DB_PASSWORD=
export APP_ENV=testing APP_DEBUG=false APP_URL=http://127.0.0.1:8091
export APP_KEY=base64:dGVsbWFuZml0LXBocHVuaXQtdGVzdC1vbmx5ISEhISE=
export CACHE_STORE=database SESSION_DRIVER=database MAIL_MAILER=array QUEUE_CONNECTION=sync PAO_DISABLE=1
export BCRYPT_ROUNDS=4
php artisan migrate --force --no-interaction
php tests/browser/fixture.php
php -S 127.0.0.1:8091 -t public server.php >"$work/server.log" 2>&1 &
server=$!
python3 tests/browser/ux1.py "$phase"
