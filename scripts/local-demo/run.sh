#!/usr/bin/env bash
# Windows: WSL2 + Docker Desktop integration. Усі дані належать лише цьому запуску.
set -euo pipefail
set +x
cd "$(dirname "$0")/../.."
for tool in docker php node npm; do command -v "$tool" >/dev/null; done
php scripts/check-php-runtime.php
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
php -r '$socket = @stream_socket_server("tcp://127.0.0.1:8098"); if (! $socket) { fwrite(STDERR, "Port 8098 busy; stop the conflicting server first.\n"); exit(1); } fclose($socket);'
[[ -d vendor && -d node_modules && -d client/node_modules ]] || { echo 'Спочатку composer install і (cd client && npm ci)' >&2; exit 1; }
# Не використовувати збережений remote Docker context.
[[ -z ${DOCKER_HOST:-} && -z ${DOCKER_CONTEXT:-} && $(docker context inspect --format '{{.Endpoints.docker.Host}}') == unix://* ]]
umask 077
demo_work=$(mktemp -d /tmp/telmanfit-auth4-XXXXXXXX)
container=''
server=''
cleanup() {
    code=$?; trap - EXIT
    if [[ -n $server ]]; then kill "$server" 2>/dev/null || true; wait "$server" 2>/dev/null || true; fi
    if [[ -n $container ]]; then docker rm -fv "$container" >/dev/null || code=1; fi
    # Тільки власний каталог, згенерований mktemp; жодної наявної БД/storage.
    rm -f -- "$demo_work/epoch" "$demo_work/env.sh" "$demo_work/postgres.env" "$demo_work/access.txt" "$demo_work/mailbox" "$demo_work/server.log" "$demo_work/packages.php" "$demo_work/services.php"
    rmdir "$demo_work" || code=1
    exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
export DEMO_WORK="$demo_work"
php scripts/local-demo/settings.php
set -a
source "$demo_work/env.sh"
set +a
image='postgres:18.6-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc'
docker pull "$image" >/dev/null
container=$(docker create --label telmanfit.local-demo=temporary --env-file "$demo_work/postgres.env" \
    --tmpfs /var/lib/postgresql:rw -p 127.0.0.1::5432 "$image")
docker start "$container" >/dev/null
ready=0
for ((i=0; i<60; i++)); do
    if [[ $(docker logs "$container" 2>&1) == *'PostgreSQL init process complete; ready for start up.'* ]] \
        && docker exec "$container" pg_isready -h 127.0.0.1 -U postgres >/dev/null 2>&1; then ready=1; break; fi
    sleep 0.5
done
[[ $ready == 1 ]] || { echo 'PostgreSQL startup failed' >&2; exit 1; }
export DB_PORT
DB_PORT=$(docker port "$container" 5432/tcp | sed -n 's/^127\.0\.0\.1://p')
[[ $DB_PORT =~ ^[0-9]+$ ]]
printf 'export DB_PORT=%s\n' "$DB_PORT" >> "$demo_work/env.sh"
# Ідентифікатор/паролі — виключно згенеровані hex; БД у новому власному cluster.
docker exec -i "$container" psql -X -U postgres -v ON_ERROR_STOP=1 <<SQL
CREATE ROLE telmanfit_demo_runner LOGIN PASSWORD '$DB_PASSWORD' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE DATABASE $DB_DATABASE OWNER telmanfit_demo_runner TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'uk-UA';
REVOKE ALL ON DATABASE $DB_DATABASE FROM PUBLIC;
SQL
php artisan migrate --force --no-interaction
php scripts/local-demo/seed.php
php artisan filament:assets
npm run build
(cd client && npm run build)
php -S 127.0.0.1:8098 -t public server.php > "$demo_work/server.log" 2>&1 &
server=$!
sleep 0.2
kill -0 "$server"
echo "Локальний стенд: http://127.0.0.1:8098/admin/login"
echo "Приватні реквізити та запрошення: $demo_work/access.txt"
echo "OTP: source $demo_work/env.sh; php scripts/local-demo/mail.php"
echo 'Ctrl+C: зупинити сервер і видалити тільки новий demo-контейнер та його тимчасові дані.'
if [[ ${TELMANFIT_DEMO_BROWSER:-0} == 1 ]]; then
    python3 tests/browser/local_demo.py
    exit
fi
wait "$server"
