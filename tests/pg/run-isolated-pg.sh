#!/usr/bin/env bash
set -euo pipefail
umask 077
# Паролі існують лише під час прогону; tracing заборонено.
set +x
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
root=$(cd "$here/../.." && pwd)
for cmd in docker php od mktemp; do command -v "$cmd" >/dev/null || { echo "Missing $cmd" >&2; exit 1; }; done
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || { echo 'Missing pdo_pgsql' >&2; exit 1; }
docker info >/dev/null
# Віддалений daemon може опублікувати порт на іншій машині.
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
[[ -z ${DOCKER_HOST:-} && -z ${DOCKER_CONTEXT:-} && $endpoint == unix://* ]] || { echo 'Requires local Linux Docker via Unix socket' >&2; exit 1; }
work=$(mktemp -d)
container=''; network=''
cleanup() {
    code=$?; trap - EXIT
    if [[ -n $container ]]; then
        if docker rm -f "$container" >/dev/null; then echo "Removed own container: $container"; else echo 'Container cleanup failed' >&2; code=1; fi
    fi
    if [[ -n $network ]]; then
        if ! docker network rm "$network" >/dev/null; then echo 'Network cleanup failed' >&2; code=1; fi
    fi
    # Каталог створено mktemp, тут міститься лише власний env-файл.
    rm -f -- "$work/bootstrap.env"
    rmdir -- "$work"
    exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
image=${PG_TEST_IMAGE:-postgres:18-bookworm}
[[ $image =~ ^postgres:(18|18\.[0-9]+)-bookworm(@sha256:[a-f0-9]{64})?$ ]] || { echo 'Only official PostgreSQL 18 Debian image accepted' >&2; exit 1; }
docker pull "$image"
image_id=$(docker image inspect --format '{{.Id}}' "$image")
digest=$(docker image inspect --format '{{index .RepoDigests 0}}' "$image")
[[ $digest == postgres@sha256:* ]] || { echo 'Missing official repository digest' >&2; exit 1; }
printf 'Resolved image: %s; ID: %s\n' "$digest" "$image_id"
admin_password=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
export PGPASSWORD
PGPASSWORD=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
printf 'POSTGRES_PASSWORD=%s\nPOSTGRES_INITDB_ARGS=--encoding=UTF8 --locale-provider=icu --icu-locale=uk-UA\n' "$admin_password" > "$work/bootstrap.env"
network=$(docker network create --internal "telmanfit-g4-$(basename "$work")")
container=$(docker create --network "$network" --publish 127.0.0.1::5432 \
    --env-file "$work/bootstrap.env" --tmpfs /var/lib/postgresql:rw \
    --label telmanfit.g4=temporary "$image_id")
docker start "$container" >/dev/null
rm -f -- "$work/bootstrap.env"
unset admin_password
ready=0
for ((n=0;n<60;n++)); do
    if docker exec "$container" pg_isready -h 127.0.0.1 -U postgres >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[[ $ready == 1 ]] || { echo 'PostgreSQL startup timed out' >&2; exit 1; }
admin() { docker exec -i -u postgres "$container" psql -X -w -v ON_ERROR_STOP=1 -U postgres "$@"; }
version=$(admin -At -d postgres -c 'SHOW server_version_num;')
[[ $version =~ ^18[0-9]{4}$ ]] || { echo 'Expected PostgreSQL 18' >&2; exit 1; }
printf 'server_version_num=%s; container=%s; network=%s\n' "$version" "$container" "$network"
admin -d postgres -c 'SELECT version();'
docker exec "$container" cat /etc/os-release
admin -d postgres <<SQL
CREATE ROLE telmanfit_test_runner LOGIN PASSWORD '$PGPASSWORD'
 NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE DATABASE telmanfit_test OWNER postgres TEMPLATE template0 ENCODING 'UTF8'
 LOCALE_PROVIDER icu ICU_LOCALE 'uk-UA';
REVOKE ALL ON DATABASE telmanfit_test FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE telmanfit_test TO telmanfit_test_runner;
SQL
admin -d telmanfit_test <<'SQL'
CREATE EXTENSION btree_gist;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO telmanfit_test_runner;
SELECT current_database(), pg_encoding_to_char(encoding), datlocprovider,
 coalesce(to_jsonb(d)->>'datlocale', to_jsonb(d)->>'daticulocale') AS icu_locale
 FROM pg_database d WHERE datname=current_database();
SELECT extname, extversion FROM pg_extension WHERE extname='btree_gist';
SELECT count(*) AS initial_business_tables FROM pg_tables WHERE schemaname='public';
SQL
[[ $(admin -At -d telmanfit_test -c "SELECT count(*) FROM pg_tables WHERE schemaname='public';") == 0 ]] || { echo 'New database is not empty' >&2; exit 1; }
# Дозволено лише admin через socket та runner до тестової БД з SCRAM.
docker exec -i -u postgres "$container" sh -c 'cat > "$PGDATA/pg_hba.conf"' <<'HBA'
local all postgres trust
local telmanfit_test telmanfit_test_runner scram-sha-256
local all all reject
host telmanfit_test telmanfit_test_runner 0.0.0.0/0 scram-sha-256
host telmanfit_test telmanfit_test_runner ::/0 scram-sha-256
host all all 0.0.0.0/0 reject
host all all ::/0 reject
HBA
admin -d postgres -c 'SELECT pg_reload_conf();'
for module in 01_nutrition_schema 02_users_clients 03_progress_tracking 04_fitness_messaging; do
    docker exec -i -e PGPASSWORD "$container" psql -X -w -v ON_ERROR_STOP=1 \
        -h 127.0.0.1 -U telmanfit_test_runner -d telmanfit_test < "$root/docs/$module.sql"
done
export PG_TEST_CONTAINER="$container"
bash "$here/run-pg-tests.sh"
binding=$(docker port "$container" 5432/tcp)
[[ $binding =~ ^127\.0\.0\.1:([0-9]+)$ ]] || { echo 'Unsafe/unexpected port binding' >&2; exit 1; }
export PG_TEST_PORT=${BASH_REMATCH[1]}
php "$here/check-pdo.php"
echo 'SQL suite and PDO connectivity succeeded; cleanup follows'
