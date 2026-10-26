#!/usr/bin/env bash
# Лише disposable тести: повторно використовуємо байткод, а не Laravel/DB/session state.
set -euo pipefail
[[ ${APP_ENV:-} == testing && ${TELMANFIT_PG_TEST:-} == 1 ]]
[[ ${1:-} =~ ^127[.]0[.]0[.]1:[0-9]+$ ]]
ini=()
if ! php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then
    ini+=(-d zend_extension=opcache)
fi
ini+=(-d opcache.enable=1 -d opcache.enable_cli=1
    -d opcache.memory_consumption=256 -d opcache.max_accelerated_files=20000
    -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0
    -d opcache.file_update_protection=0 -d opcache.file_cache= -d opcache.file_cache_only=0
    -d opcache.preload=
    -d opcache.jit=0 -d opcache.jit_buffer_size=0)
# Відсутній/вимкнений модуль — помилка, а не непомітний повільний fallback.
php "${ini[@]}" -r 'exit((opcache_get_status(false)["opcache_enabled"] ?? false) ? 0 : 1);'
echo 'Synthetic PHP server: process-local OPcache enabled; timestamps validated; no file cache/JIT' >&2
exec php "${ini[@]}" -S "$@"
