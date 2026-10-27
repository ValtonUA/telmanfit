#!/bin/sh
# Лише disposable layer run-cooking.ps1; host .env/runtime дані не копіюються.
set -eu
socket=$1
mkdir /tmp/cooking-work
tar -C /source --exclude=.env --exclude=.git --exclude=./vendor --exclude=node_modules \
 --exclude=storage --exclude=bootstrap/cache --exclude=.phpunit.cache -cf /tmp/cooking-source.tar .
tar -C /tmp/cooking-work -xf /tmp/cooking-source.tar
cp -a /var/www/html/vendor /tmp/cooking-work/vendor
cp -a /var/www/html/public/build /tmp/cooking-work/public/build
mkdir -p /tmp/cooking-work/storage/framework/cache/data /tmp/cooking-work/storage/framework/sessions \
 /tmp/cooking-work/storage/framework/views /tmp/cooking-work/storage/logs /tmp/cooking-work/bootstrap/cache
touch /tmp/cooking-work/.env
cd /tmp/cooking-work
export APP_ENV=testing DB_CONNECTION=pgsql DB_HOST="$socket" DB_PORT=5432
export DB_DATABASE=telmanfit_stage1_test DB_USERNAME=telmanfit_test_runner DB_PASSWORD= DB_URL= TELMANFIT_PG_TEST=1
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter CookingTemplatesTest --display-all-issues
php artisan migrate --force --no-interaction
php vendor/bin/phpunit -c phpunit.auth2.xml --filter test_sql_snapshot_matches_migrations_and_all_user_keys_are_bigint --display-all-issues
DB_CONNECTION=sqlite DB_DATABASE=:memory: TELMANFIT_PG_TEST=0 php artisan test --compact --display-all-issues
