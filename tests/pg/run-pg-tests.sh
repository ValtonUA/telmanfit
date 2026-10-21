#!/usr/bin/env bash
set -euo pipefail
umask 077

# Лише еталонна SQL-схема у спеціально підготовленій тестовій БД.
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
files=(t01_foods.sql t02_recipes.sql t03_plans.sql t04_assignments.sql t05_progress_messaging.sql t06_inventory_residue.sql)
counts=(9 16 24 12 15 0)
export PGCLIENTENCODING=UTF8 PGCONNECT_TIMEOUT=10
export PGOPTIONS='-c statement_timeout=300000 -c search_path=public,pg_catalog'
unset PGSERVICE PGSERVICEFILE PGDATABASE PGUSER PGHOST PGHOSTADDR
port=${PG_TEST_PORT:-5432}
[[ $port =~ ^[0-9]+$ ]] || exit 2
if [[ -n ${PG_TEST_CONTAINER:-} ]]; then
    client=(docker exec -i -e PGPASSWORD -e PGCLIENTENCODING -e PGOPTIONS "$PG_TEST_CONTAINER" psql)
    port=5432
else
    client=(psql)
fi
client+=(-X -w -h 127.0.0.1 -p "$port" -U telmanfit_test_runner -d telmanfit_test
    -v ON_ERROR_STOP=1 -P pager=off -P footer=off -A -F '|')
mkdir -p "$here/out"
out=$(mktemp -d "$here/out/run.XXXXXXXX")
printf 'Logs: %s\n' "$out"
die() { printf '%s\n' "$2" >&2; exit "$1"; }

# Ця перевірка виконується в КОЖНОМУ з'єднанні перед тестовим SQL.
guard=$(cat <<'SQL'
DO $guard$
BEGIN
 IF current_database() <> 'telmanfit_test'
    OR current_user <> 'telmanfit_test_runner'
    OR session_user <> 'telmanfit_test_runner' THEN
   RAISE EXCEPTION 'Unexpected database/current_user/session_user';
 END IF;
 IF EXISTS (SELECT FROM pg_roles WHERE rolname=current_user
   AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls)) THEN
   RAISE EXCEPTION 'Privileged test role';
 END IF;
END $guard$;
SQL
)
query() { printf '%s\n%s\n' "$guard" "$1" | "${client[@]}" -q -t; }
query 'SELECT current_database(), current_user, session_user;' > "$out/connection.out" || die 2 'Connection/identity check failed'
cat "$out/connection.out"
tables=$(query "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p');") || die 3 'Cannot inspect schema'
[[ $tables == 37 ]] || die 3 'Expected exactly 37 SQL business tables; Laravel migration schemas require separate tests'
extension=$(query "SELECT extversion FROM pg_extension WHERE extname='btree_gist';") || die 3 'Cannot inspect btree_gist'
[[ -n $extension ]] || die 3 'btree_gist is missing'
printf 'btree_gist=%s\n' "$extension"

# Перевіряємо всі таблиці до прогону і після кожного ROLLBACK; нічого не очищуємо.
empty_sql=$(cat <<'SQL'
DO $empty$
DECLARE t record; occupied boolean;
BEGIN
 IF (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname='public' AND c.relkind IN ('r','p')) <> 37 THEN
   RAISE EXCEPTION 'Business table inventory changed';
 END IF;
 FOR t IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname='public' AND c.relkind IN ('r','p') LOOP
   EXECUTE format('SELECT EXISTS (SELECT FROM public.%I LIMIT 1)', t.relname) INTO occupied;
   IF occupied THEN RAISE EXCEPTION 'Residual rows in %', t.relname; END IF;
 END LOOP;
END $empty$;
SQL
)
query "$empty_sql" > "$out/empty-before.out" || die 6 'Business tables must be empty before tests'
shopt -s nullglob
actual=("$here"/t0*.sql)
[[ ${#actual[@]} == 6 ]] || die 5 'Expected exactly six t01-t06 SQL files'
for file in "${files[@]}"; do
    [[ -f $here/$file ]] || die 5 "Missing $file"
done
total=0
for i in "${!files[@]}"; do
    file=${files[$i]}; log="$out/${file%.sql}.out"
    printf '%s\n' "$file"
    if ! { printf '%s\n' "$guard"; cat "$here/$file"; } | "${client[@]}" -q > "$log" 2>&1; then
        die 4 "SQL/connection error; see $log"
    fi
    # Порівнюємо кожен ID, а не лише загальну кількість PASS.
    group=$((i+1)); start=1; [[ $group != 1 ]] || start=0
    : > "$out/expected"
    for ((n=start; n<start+counts[i]; n++)); do printf 'T%d.%d|PASS\n' "$group" "$n"; done | LC_ALL=C sort > "$out/expected"
    awk -F '|' '$1 ~ /^[0-9]+$/ && $2 ~ /^T[0-9]+\.[0-9]+$/ {print $2 "|" $3}' "$log" | LC_ALL=C sort > "$out/actual"
    if ! diff -u "$out/expected" "$out/actual" > "$out/${file%.sql}.diff"; then
        die 5 "Incomplete/duplicate/unexpected/failed scenario IDs; see $log"
    fi
    summary=$(awk -F '|' '$1 ~ /^(PASS|FAIL|GAP)$/ && $2 ~ /^[0-9]+$/ {print $1 "|" $2}' "$log")
    expected_summary="PASS|${counts[$i]}"
    [[ ${counts[$i]} != 0 ]] || expected_summary=''
    [[ $summary == "$expected_summary" ]] || die 5 "Invalid PASS/FAIL/GAP summary; see $log"
    query "$empty_sql" > "$out/${file%.sql}.empty.out" || die 6 "Residual rows after $file"
    total=$((total+counts[i]))
done
[[ $total == 76 ]] || die 5 'Incomplete suite'
printf 'PASS=76 FAIL=0 GAP=0; files=6/6; business tables empty\n'
