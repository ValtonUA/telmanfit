#!/usr/bin/env bash
# Відтворюваний прогін лише на новому контейнері; наявні БД не використовуються.
set -euo pipefail
cd "$(dirname "$0")/../.."
for tool in docker php python3; do command -v "$tool" >/dev/null; done
php scripts/check-php-runtime.php
umask 077
phpunit_args=()
if [[ -n ${STAGE1_PHPUNIT_CACHE:-} ]]; then
    [[ $STAGE1_PHPUNIT_CACHE =~ ^/tmp/telmanfit-stage1-ci\.[a-zA-Z0-9_]+/(recipe|baseline|b5|b3)/phpunit$ && -d $STAGE1_PHPUNIT_CACHE ]]
    phpunit_args+=(--cache-directory "$STAGE1_PHPUNIT_CACHE")
fi
ci_labels=()
if [[ -n ${STAGE1_CI_GROUP:-} ]]; then
    [[ $STAGE1_CI_GROUP =~ ^[a-f0-9]{32}$ ]]
    ci_labels+=(--label "telmanfit.ci-group=$STAGE1_CI_GROUP")
fi
# Метрики orchestration; жодних credentials, fixture contents або зміни сценаріїв.
run_started=$SECONDS
phase_started=$SECONDS
stage1_mark() {
    printf 'CI_STAGE1_TIMING phase=%s seconds=%s\n' "$1" "$((SECONDS - phase_started))"
    phase_started=$SECONDS
}
endpoint=$(docker context inspect --format '{{.Endpoints.docker.Host}}')
[[ -z ${DOCKER_HOST:-} && -z ${DOCKER_CONTEXT:-} && $endpoint == unix://* ]]
work=$(mktemp -d /tmp/telmanfit-stage1.XXXXXXXX)
container=''
b6_monitor=''
cleanup() {
    code=$?; trap - EXIT
    if [[ -n "$b6_monitor" ]]; then kill -KILL "$b6_monitor" 2>/dev/null || true; wait "$b6_monitor" 2>/dev/null || true; fi
    if [[ -n "$container" ]]; then docker rm -f "$container" >/dev/null || code=1; fi
    rm -f -- "$work/socket/.s.PGSQL.5432" "$work/socket/.s.PGSQL.5432.lock"
    rm -f -- "$work/snapshot.dump" "$work/maintenance.json" "$work/b6-resources.jsonl" "$work/restart.json" "$work/recipe-failure-probe.log"
    rmdir -- "$work/socket" "$work" || code=1
    printf 'CI_STAGE1_TIMING phase=total_with_cleanup seconds=%s exit=%s\n' "$((SECONDS - run_started))" "$code"
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
stage1_mark immutable_image_pull
pg_command=(-c 'unix_socket_directories=/var/run/postgresql,/run/telmanfit-pg')
if [[ ${AUTH6B6_ONLY:-0} == 1 ]]; then
    # PID1 лишається живим під час реального postmaster restart; tmpfs не unmount.
    pg_command=(bash -c 'docker-entrypoint.sh postgres -c "unix_socket_directories=/var/run/postgresql,/run/telmanfit-pg" & wait "$!"; exec sleep infinity')
fi
container=$(docker create "${ci_labels[@]}" --network none --tmpfs /var/lib/postgresql \
    --mount "type=bind,src=$work/socket,dst=/run/telmanfit-pg" \
    -e POSTGRES_HOST_AUTH_METHOD=trust "$image" \
    "${pg_command[@]}")
docker start "$container" >/dev/null
ready=0
for ((i=0; i<60; i++)); do
    # Тимчасовий сервер entrypoint теж відповідає на pg_isready, але ще зупиниться.
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
    -c "CREATE DATABASE telmanfit_stage1_test OWNER telmanfit_test_runner TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'uk-UA';"
export DB_CONNECTION=pgsql DB_HOST="$work/socket" DB_PORT=5432
export DB_DATABASE=telmanfit_stage1_test DB_USERNAME=telmanfit_test_runner DB_PASSWORD= DB_URL=
export APP_ENV=testing TELMANFIT_PG_TEST=1
# Лише випадковий ключ цього прогону; жодного читання локальних/реальних секретів.
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
# Ролі існують лише в новому synthetic cluster; жодних production GRANT.
docker exec "$container" psql -X -U postgres -d "$DB_DATABASE" -v ON_ERROR_STOP=1 \
    -c 'CREATE ROLE telmanfit_notice_writer NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;' \
    -c 'CREATE ROLE telmanfit_notice_dispatcher NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;' \
    -c 'GRANT telmanfit_notice_writer, telmanfit_notice_dispatcher TO telmanfit_test_runner;'
stage1_mark isolated_cluster_roles_database
if [[ ${AUTH6B6_ONLY:-0} == 1 ]]; then
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    docker exec -i "$container" psql -X -U postgres -d "$DB_DATABASE" -v ON_ERROR_STOP=1 < database/sql/auth6b6/01_test_acl.sql
    # Runtime matrix має process-level array defaults; controlled PG fixtures потребують
    # database adapters уже на старті PHP ($_SERVER має пріоритет над PHPUnit <env>).
    export SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=sync MAIL_MAILER=array
    export TELMANFIT_B6_TEST=1
    docker stats --format '{{json .}}' "$container" > "$work/b6-resources.jsonl" &
    b6_monitor=$!
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth6b6.xml --display-all-issues
    php tests/pg/auth6b6-concurrency.php
    bash tests/http/auth6b6-in-context.sh
    php tests/pg/auth6b6-restart.php before > "$work/restart.json"
    docker exec -u postgres "$container" sh -c 'pg_ctl -D "$PGDATA" -m fast -w restart' > /dev/null
    php tests/pg/auth6b6-restart.php after "$work/restart.json"
    kill -KILL "$b6_monitor"
    wait "$b6_monitor" 2>/dev/null || true
    b6_monitor=''
    python3 tests/pg/auth6b6-resources.py "$work/b6-resources.jsonl"
    echo "B6 PostgreSQL telemetry and bounded monitor shutdown PASS"
    exit
fi
if [[ ${AUTH6B5_ONLY:-0} == 1 ]]; then
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    docker exec -i "$container" psql -X -U postgres -d "$DB_DATABASE" -v ON_ERROR_STOP=1 < database/sql/auth6b5/01_test_acl.sql
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth6b5.xml --display-all-issues
    php tests/pg/auth6b5-concurrency.php
    php tests/pg/auth6b5-hold-concurrency.php
    B5_SYNTHETIC_CONTAINER="$container" B5_SYNTHETIC_DUMP="$work/snapshot.dump" php tests/pg/auth6b5-restore-drill.php
    exit
fi
if [[ ${AUTH6B4_ONLY:-0} == 1 ]]; then
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth6b4.xml --display-all-issues
    php tests/pg/auth6b4-concurrency.php
    exit
fi
if [[ ${AUTH6B_ONLY:-0} == 1 ]]; then
    node tests/Auth6B/verify-vector.mjs
    docker exec "$container" psql -X -U postgres -d "$DB_DATABASE" -v ON_ERROR_STOP=1 \
        -c 'CREATE ROLE telmanfit_recovery_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;' \
        -c 'GRANT telmanfit_recovery_runtime TO telmanfit_test_runner;'
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth2.xml --display-all-issues
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth6b.xml --display-all-issues
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth6b4.xml --display-all-issues
    php tests/pg/auth6b4-concurrency.php
    if [[ ${AUTH6B_CASES_ONLY:-0} == 1 ]]; then
        php tests/pg/auth6b-concurrency.php
        exit
    fi
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth3.xml --display-all-issues
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth4.xml --display-all-issues
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth5.xml --display-all-issues
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth6.xml --display-all-issues
    php tests/pg/auth6b-concurrency.php
    bash tests/http/auth6-in-context.sh
    exit
fi
if [[ ${AUTH6_ONLY:-0} == 1 ]]; then
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth6.xml --display-all-issues
    bash tests/http/auth6-in-context.sh
    exit
fi
# UX-2: найменший browser прохід на новій ізольованій БД.
if [[ ${ADMIN_NAV_ONLY:-0} == 1 ]]; then
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth3.xml --display-all-issues
    bash tests/browser/recipe-batch-in-context.sh
    exit
fi
# AUTH-5 завжди на новій ізольованій БД із тим самим fail-closed guard.
if [[ ${AUTH5_ONLY:-0} == 1 ]]; then
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth5.xml --display-all-issues
    php tests/pg/auth5-concurrency.php
    if [[ ${AUTH5_BROWSER:-0} == 1 ]]; then bash tests/browser/auth5-in-context.sh; fi
    exit
fi
# Цільовий повтор AUTH-4: та сама нова ізольована БД, без повтору вже пройдених регресій.
if [[ ${AUTH4_ONLY:-0} == 1 ]]; then
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth4.xml --display-all-issues
    php tests/pg/auth4-concurrency.php
    if [[ ${CLIENT_RECIPE_BROWSER:-0} == 1 ]]; then bash tests/browser/recipe-client-in-context.sh; fi
    if [[ ${AUTH4_BROWSER:-0} == 1 ]]; then bash tests/browser/auth4-in-context.sh; bash tests/browser/recipe-client-in-context.sh; fi
    exit
fi
baseline_paths=()
for file in database/migrations/0001_*.php database/migrations/2026_10_21_*.php; do
    baseline_paths+=(--path="$file")
done
php artisan migrate --force --no-interaction "${baseline_paths[@]}"
stage1_mark baseline_migrations
if [[ ${UX3_ONLY:-0} == 1 ]]; then
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.pgsql.xml --filter MealPlanCreationRequestTest --display-all-issues
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    php tests/pg/meal-plan-creation-concurrency.php
    if [[ ${UX3_BROWSER:-0} == 1 ]]; then UX3_BROWSER_ONLY=1 bash tests/browser/recipe-batch-in-context.sh; fi
    exit
fi
if [[ ${MEAL_PLAN_COPY_ONLY:-0} == 1 ]]; then
    vendor/bin/phpunit -c phpunit.pgsql.xml --filter MealPlanCopyTest --display-all-issues
    php artisan migrate --force --no-interaction
    php tests/pg/auth6b5-bootstrap.php
    php tests/pg/meal-plan-copy-concurrency.php
    if [[ ${MEAL_PLAN_COPY_BROWSER:-0} == 1 ]]; then bash tests/browser/meal-plan-copy-in-context.sh; fi
    exit
fi
if [[ ${MEAL_PLAN_ASSIGNMENT_ONLY:-0} == 1 ]]; then
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.pgsql.xml --filter MealPlanAssignmentTest --display-all-issues
    php tests/pg/meal-plan-assignment-concurrency.php
    if [[ ${MEAL_PLAN_ASSIGNMENT_BROWSER:-0} == 1 ]]; then bash tests/browser/meal-plan-assignment-in-context.sh; fi
    exit
fi
if [[ ${RECIPE_BATCH_ONLY:-0} == 1 ]]; then
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.pgsql.xml --filter 'CookingTemplatesTest|ClientCookingTest|NutritionImportTest|R2ImageMatcherTest|R2ImageMatcherIsolatedTest|RecipeBatchesTest|BatchEditorTest|FoodCatalogTest|MealPlanCreationRequestTest|MealPlanFoundationTest|MealPlanStructureTest|MealPlanWeekDeletionTest|MealPlanWeekRepetitionTest|MealPlanPublicationTest|MealPlanCopyTest|MealPlanAssignmentTest|MealItemSelectorsTest|MealItemWriterTest|MealPlanDayEditorTest|MealPlanPresentationTest' --display-all-issues
    stage1_mark recipe_phpunit
    if php tests/pg/recipe-batch-concurrency.php failure-probe >"$work/recipe-failure-probe.log" 2>&1; then
        echo 'Recipe concurrency exception was reported as success' >&2
        exit 1
    fi
    grep -q 'Recipe batch failure propagation probe' "$work/recipe-failure-probe.log"
    rm -f -- "$work/recipe-failure-probe.log"
    php tests/pg/recipe-batch-concurrency.php
    php tests/pg/recipe-batch-uom-reload.php
    php tests/pg/batch-editor-concurrency.php
    php tests/pg/batch-editor-pending-source.php
    php tests/pg/legacy-conversion-concurrency.php
    php tests/pg/food-catalog-concurrency.php
    php tests/pg/meal-plan-concurrency.php
    php tests/pg/meal-plan-creation-concurrency.php
    php tests/pg/meal-plan-structure-concurrency.php
    php tests/pg/meal-plan-week-deletion-concurrency.php
    php tests/pg/meal-item-selector-concurrency.php
    php tests/pg/meal-item-writer-concurrency.php
    php tests/pg/meal-plan-day-concurrency.php
    php tests/pg/meal-plan-week-repetition-concurrency.php
    php tests/pg/meal-plan-publication-concurrency.php
    php tests/pg/meal-plan-assignment-concurrency.php
    php tests/pg/meal-plan-copy-concurrency.php
    php tests/pg/recipe-cover-concurrency.php
    php tests/pg/cooking-concurrency.php
    stage1_mark recipe_concurrency
    if [[ ${RECIPE_BATCH_BROWSER:-0} == 1 ]]; then
        bash tests/browser/recipe-batch-in-context.sh
        stage1_mark recipe_browser
        bash tests/browser/meal-plan-copy-in-context.sh
        stage1_mark meal_plan_copy_browser
    fi
    exit
fi
if [[ ${CLIENT_PROFILES_ONLY:-0} == 1 ]]; then
    vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.pgsql.xml --filter ClientProfilesTest --display-all-issues
    exit
fi
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
stage1_mark sql_baseline
vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.pgsql.xml --display-all-issues
stage1_mark full_pg_phpunit
# Основний конфіг перемикає на SQLite; PostgreSQL-набір має окремий конфіг.
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test "${phpunit_args[@]}" --display-all-issues
# Той самий static Pint --test є в обох required PHP matrix jobs; без повторного lint тут.

# AUTH-2 окремо після незмінених Stage 1/2 перевірок каталогу.
php tests/pg/auth2-upgrade.php
php tests/pg/auth6b5-bootstrap.php
vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth2.xml --display-all-issues

php tests/pg/auth2-concurrency.php

vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth3.xml --display-all-issues
php tests/pg/auth3-concurrency.php
stage1_mark auth3_tests_concurrency
if [[ ${AUTH3_BROWSER:-0} == 1 ]]; then
    bash tests/browser/auth3-in-context.sh
    stage1_mark auth3_browser
fi

vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth4.xml --display-all-issues

php tests/pg/auth4-concurrency.php
stage1_mark auth4_tests_concurrency
if [[ ${AUTH4_BROWSER:-0} == 1 ]]; then
    bash tests/browser/auth4-in-context.sh
    bash tests/browser/recipe-client-in-context.sh
    stage1_mark auth4_browser
fi

vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth5.xml --display-all-issues
php tests/pg/auth5-concurrency.php
stage1_mark auth5_tests_concurrency
if [[ ${AUTH5_BROWSER:-0} == 1 ]]; then
    bash tests/browser/auth5-in-context.sh
    stage1_mark auth5_browser
fi
if [[ ${MEAL_PLAN_ASSIGNMENT_BROWSER:-0} == 1 ]]; then
    bash tests/browser/meal-plan-assignment-in-context.sh
    stage1_mark meal_plan_assignment_browser
fi

vendor/bin/phpunit "${phpunit_args[@]}" -c phpunit.auth6.xml --display-all-issues
bash tests/http/auth6-in-context.sh
