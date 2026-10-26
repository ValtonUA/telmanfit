# Ізольоване середовище Infection

INFECTION-ENV готує coverage для майбутнього INFECTION-1. Мутаційний пілот
не запускається; Infection не встановлюється глобально або в залежності застосунку.

## Побудова та запуск

Із кореня репозиторію, у PowerShell із доступом до Docker Desktop:

```powershell
docker build -f docker/infection/Dockerfile -t telmanfit-infection-env:local .
docker run --rm --network none --read-only telmanfit-infection-env:local -v
docker run --rm --network none --read-only telmanfit-infection-env:local --ri pcov
docker run --rm --network none --read-only --tmpfs /tmp telmanfit-infection-env:local
```

За замовчуванням запускаються тільки `BatchEvaluatorTest|MealItemPortionTest`.
Образ використовує PHP CLI 8.5.12, PCOV 1.0.12, залежності з чинного
`composer.lock`; Composer scripts/plugins вимкнено. Окремий allowlist build context
включає лише manifests, `app`, `tests`, factories, seeders і `phpunit.xml`.
Жодні seeders не виконуються. `.env`, storage, auth.json і робочі дані не копіюються.
Немає PostgreSQL driver, мережі під час тестів, host mounts або робочих volumes.
Процес працює як `www-data`, коренева файлова система read-only; звіти пишуться в `/tmp`.

Для збереження справжніх Clover/PHP/XML/JUnit-звітів без volumes використовуйте
окремий контейнер (ім'я має бути вільним), а потім видаліть лише його:

```powershell
docker create --name telmanfit-infection-coverage --network none telmanfit-infection-env:local
docker start -a telmanfit-infection-coverage
docker inspect --format '{{.State.ExitCode}}' telmanfit-infection-coverage
docker cp telmanfit-infection-coverage:/tmp/clover.xml "$env:TEMP/telmanfit-clover.xml"
docker cp telmanfit-infection-coverage:/tmp/coverage.php "$env:TEMP/telmanfit-coverage.php"
docker cp telmanfit-infection-coverage:/tmp/coverage-xml "$env:TEMP/telmanfit-coverage-xml"
docker cp telmanfit-infection-coverage:/tmp/junit.xml "$env:TEMP/telmanfit-junit.xml"
docker rm telmanfit-infection-coverage
```

У варіанті експорту `/tmp` зберігається у writable container layer до видалення
контейнера. Контейнер однаково залишається без мережі та mounts.

## Перевірка сумісності Infection

Без змін manifests репозиторію, лише у тимчасовій копії всередині контейнера:

```powershell
docker run --rm --read-only --tmpfs /tmp --entrypoint sh telmanfit-infection-env:local -c 'mkdir /tmp/check && cp composer.json composer.lock /tmp/check/ && cd /tmp/check && COMPOSER_HOME=/tmp/composer composer show infection/infection 0.35.8 --all && COMPOSER_HOME=/tmp/composer composer require --dev infection/infection:0.35.8 --dry-run --no-scripts --no-plugins --no-interaction'
```

Ця перевірка потребує мережі до Packagist/GitHub. Solver перевіряє сумісність,
але не доводить успішність мутаційного прогону. Встановлення й запуск Infection,
вибір mutators, MSI та порогів належать окремому INFECTION-1.

## Межі

Робочий `compose.yaml`, сервіси, volumes, MFA/auth, БД та feature flags не змінюються.
Нових CI checks немає. Загальний coverage `app` від двох Unit-наборів не є
оцінкою покриття всього застосунку. PCOV надає line coverage; branch/path coverage
не перевірено. Фактичні результати — у [project-status.md](project-status.md).
