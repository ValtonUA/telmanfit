#!/usr/bin/env bash
set -euo pipefail
umask 077
# Паролі існують лише під час прогону; tracing заборонено.
set +x
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
root=$(cd "$here/../.." && pwd)
for cmd in docker php od mktemp; do command -v "$cmd" >/dev/null || { echo "Missing $cmd" >&2; exit 1; }; done
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || { echo 'Missing pdo_pgsql' >&2; exit 1; }
# Потрібен локальний daemon зі спільною файловою системою для приватного socket.
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
[[ -z ${DOCKER_HOST:-} && -z ${DOCKER_CONTEXT:-} && $endpoint == unix://* ]] || { echo 'Requires local Linux Docker via Unix socket' >&2; exit 1; }
docker info >/dev/null
work=$(mktemp -d /tmp/telmanfit-g4.XXXXXXXX)
container=''
cleanup() {
    code=$?; trap - EXIT
    if [[ -n $container ]]; then
        if docker rm -f "$container" >/dev/null; then echo "Removed own container: $container"; else echo 'Container cleanup failed' >&2; code=1; fi
    fi
    # Видаляємо лише власні файли; каталог-батько має права 700.
    rm -f -- "$work/bootstrap.env"
    if [[ -z $container ]] || ! docker container inspect "$container" >/dev/null 2>&1; then
        rm -f -- "$work/socket/.s.PGSQL.5432" "$work/socket/.s.PGSQL.5432.lock"
        rmdir -- "$work/socket" "$work" || code=1
    fi
    exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$work/socket"
# PostgreSQL (UID контейнера) створює socket у приватному для агента каталозі.
chmod 777 "$work/socket"
image=${PG_TEST_IMAGE:-postgres:18.6-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc}
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
container=$(docker create --network none \
    --mount "type=bind,src=$work/socket,dst=/run/telmanfit-pg" \
    --env-file "$work/bootstrap.env" --tmpfs /var/lib/postgresql:rw \
    --label telmanfit.g4=temporary "$image_id" \
    -c 'unix_socket_directories=/var/run/postgresql,/run/telmanfit-pg')
docker start "$container" >/dev/null
rm -f -- "$work/bootstrap.env"
unset admin_password
ready=0
for ((n=0;n<60;n++)); do
    if docker exec "$container" pg_isready -h 127.0.0.1 -U postgres >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[[ $ready == 1 ]] || { echo 'PostgreSQL startup timed out' >&2; exit 1; }
admin() {
    local database=$1; shift
    [[ $database == postgres || $database == telmanfit_test ]] || return 2
    {
        cat <<SQL
DO \$guard\$ BEGIN
 IF current_database() <> '$database' OR current_user <> 'postgres' OR session_user <> 'postgres' THEN
   RAISE EXCEPTION 'Unexpected bootstrap connection identity';
 END IF;
END \$guard\$;
SQL
        if [[ $# -gt 0 ]]; then printf '%s\n' "$1"; else cat; fi
    } | docker exec -i -u postgres "$container" psql -qAt -F '|' -X -w -v ON_ERROR_STOP=1 -U postgres -d "$database"
}
version=$(admin postgres 'SHOW server_version_num;')
[[ $version =~ ^18[0-9]{4}$ ]] || { echo 'Expected PostgreSQL 18' >&2; exit 1; }
printf 'server_version_num=%s; container=%s; network=none; published_ports=none\n' "$version" "$container"
admin postgres 'SELECT version();'
docker exec "$container" cat /etc/os-release
admin postgres <<SQL
CREATE ROLE telmanfit_test_runner LOGIN PASSWORD '$PGPASSWORD'
 NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE DATABASE telmanfit_test OWNER postgres TEMPLATE template0 ENCODING 'UTF8'
 LOCALE_PROVIDER icu ICU_LOCALE 'uk-UA';
REVOKE ALL ON DATABASE telmanfit_test FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE telmanfit_test TO telmanfit_test_runner;
SQL
admin telmanfit_test <<'SQL'
CREATE EXTENSION btree_gist;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO telmanfit_test_runner;
SELECT current_database(), pg_encoding_to_char(encoding), datlocprovider,
 coalesce(to_jsonb(d)->>'datlocale', to_jsonb(d)->>'daticulocale') AS icu_locale
 FROM pg_database d WHERE datname=current_database();
SELECT extname, extversion FROM pg_extension WHERE extname='btree_gist';
SELECT rolname, rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls
 FROM pg_roles WHERE rolname='telmanfit_test_runner';
DO $check$ BEGIN
 IF NOT EXISTS (SELECT FROM pg_database WHERE datname=current_database()
   AND pg_encoding_to_char(encoding)='UTF8' AND datlocprovider='i' AND datlocale='uk-UA') THEN
   RAISE EXCEPTION 'Expected UTF8/ICU uk-UA';
 END IF;
END $check$;
SELECT count(*) AS initial_business_tables FROM pg_tables WHERE schemaname='public';
SQL
[[ $(admin telmanfit_test "SELECT count(*) FROM pg_tables WHERE schemaname='public';") == 0 ]] || { echo 'New database is not empty' >&2; exit 1; }
# Дозволено лише admin через socket та runner до тестової БД з SCRAM.
docker exec -i -u postgres "$container" sh -c 'cat > "$PGDATA/pg_hba.conf"' <<'HBA'
local all postgres peer
local telmanfit_test telmanfit_test_runner scram-sha-256
local all all reject
host telmanfit_test telmanfit_test_runner 0.0.0.0/0 scram-sha-256
host telmanfit_test telmanfit_test_runner ::/0 scram-sha-256
host all all 0.0.0.0/0 reject
host all all ::/0 reject
HBA
admin postgres 'SELECT pg_reload_conf();'
for module in 01_nutrition_schema 02_users_clients 03_progress_tracking 04_fitness_messaging; do
    {
        cat <<'SQL'
DO $guard$ BEGIN
 IF current_database() <> 'telmanfit_test' OR current_user <> 'telmanfit_test_runner'
    OR session_user <> 'telmanfit_test_runner' THEN
   RAISE EXCEPTION 'Unexpected module connection identity';
 END IF;
 IF EXISTS (SELECT FROM pg_roles WHERE rolname=current_user
   AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls)) THEN
   RAISE EXCEPTION 'Privileged module role';
 END IF;
END $guard$;
SQL
        cat "$root/docs/$module.sql"
    } | docker exec -i -e PGPASSWORD "$container" psql -X -w -v ON_ERROR_STOP=1 \
        -h 127.0.0.1 -U telmanfit_test_runner -d telmanfit_test
done
export PG_TEST_CONTAINER="$container"
bash "$here/run-pg-tests.sh"
[[ $(docker inspect --format '{{.HostConfig.NetworkMode}}' "$container") == none \
    && -z $(docker port "$container") ]] || { echo 'Unsafe container networking' >&2; exit 1; }
export PG_TEST_SOCKET_DIR="$work/socket"
php "$here/check-pdo.php"
echo 'SQL suite and PDO connectivity succeeded; cleanup follows'
