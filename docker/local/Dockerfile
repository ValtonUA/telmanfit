FROM php:8.5.12-fpm-bookworm AS php-deps
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then cp /run/secrets/proxy_ca /usr/local/share/ca-certificates/session.crt; update-ca-certificates; fi; \
    apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libsqlite3-dev unzip git \
    && docker-php-ext-install -j3 pdo_pgsql pdo_sqlite intl zip bcmath pcntl \
    && rm -rf /var/lib/apt/lists/* /usr/local/share/ca-certificates/session.crt \
    && update-ca-certificates --fresh
COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then export COMPOSER_CAFILE=/run/secrets/proxy_ca; fi; \
    composer install --no-interaction --prefer-dist --no-scripts

FROM node:24-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS assets
WORKDIR /src
COPY package*.json ./
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/proxy_ca; fi; npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources resources
COPY --from=php-deps /var/www/html/vendor vendor
COPY app app
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/proxy_ca; fi; NODE_USE_ENV_PROXY=1 npm run build
COPY client/package*.json client/
RUN --mount=type=secret,id=proxy_ca \
    if [ -f /run/secrets/proxy_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/proxy_ca; fi; npm ci --prefix client --ignore-scripts
COPY client client
RUN npm run lint --prefix client && npm run build --prefix client


FROM php-deps AS app
COPY . .
COPY --from=assets /src/public public
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-interaction && php artisan filament:assets \
    && chmod -R a+rX /var/www/html \
    && chown -R www-data:www-data storage bootstrap/cache \
    && printf "[www]\nclear_env = no\n" > /usr/local/etc/php-fpm.d/zz-local.conf \
    && ln -s /var/www/html/docker/local/entrypoint.sh /usr/local/bin/local-entrypoint \
    && chmod +x docker/local/entrypoint.sh
ENTRYPOINT ["local-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.28-bookworm@sha256:552e7481ca93ffccd046aa658dbbed22caefbc09c66fa7cd247cbb90b8a5c609 AS web
COPY docker/local/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

FROM postgres:18.6-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc AS database
COPY --chmod=755 docker/local/database-init.sh /docker-entrypoint-initdb.d/10-local.sh
