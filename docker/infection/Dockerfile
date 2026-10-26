FROM php:8.5.12-cli-bookworm@sha256:d4f6e10eb393de7e3a7a88e8b88e139072dd3ae297f6a5dada8257e6fe32c585

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libsqlite3-dev libonig-dev unzip git \
    && docker-php-ext-install -j3 intl zip bcmath pcntl pdo_sqlite mbstring \
    && pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/local/bin/composer
WORKDIR /work
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-plugins --no-autoloader
COPY app app
COPY tests tests
COPY database/factories database/factories
COPY database/seeders database/seeders
COPY phpunit.xml ./
RUN composer dump-autoload --no-scripts --no-plugins \
    && composer check-platform-reqs
ENV APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=""
USER www-data
ENTRYPOINT ["php", "-d", "pcov.directory=/work/app"]
CMD ["vendor/bin/phpunit", "--filter", "BatchEvaluatorTest|MealItemPortionTest", "--do-not-cache-result", "--coverage-text", "--coverage-clover", "/tmp/clover.xml", "--coverage-php", "/tmp/coverage.php", "--coverage-xml", "/tmp/coverage-xml", "--log-junit", "/tmp/junit.xml"]
