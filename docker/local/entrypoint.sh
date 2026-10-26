#!/bin/sh
set -eu
# Ніколи не читати .env Windows/WSL або змінювати ключі при рестарті.
set -a
. /tmp/telmanfit-auth4-compose/runtime.env
set +a
if [ "$(id -u)" = 0 ]; then
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private
    chown -R www-data:www-data storage
    if [ "$1" = php-fpm ]; then
        su -p -s /bin/sh -c 'php docker/local/bootstrap.php' -- www-data
        exec "$@"
    fi
    exec su -p -s /bin/sh -c 'exec local-entrypoint "$@"' -- www-data sh "$@"
fi
php docker/local/bootstrap.php
exec "$@"
