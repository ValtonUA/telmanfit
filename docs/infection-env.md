# Ізольоване середовище Infection

INFECTION-ENV готує окремий CLI/PCOV образ; INFECTION-1 використовує його для
вузького пілота. Infection встановлюється тільки в тимчасовому похідному образі,
не глобально та не в залежності застосунку.

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
але не доводить успішність мутаційного прогону. Справжній пілот — нижче;
результати й класифікація survivors — у [project-status.md](project-status.md#infection-1).

## Пілот INFECTION-1

Scope — тільки `app/Domain/Recipes/BatchEvaluator.php` і
`app/Domain/MealPlans/MealItemPortion.php`; PHPUnit filter —
`BatchEvaluatorTest|MealItemPortionTest`. Не використовувати production Composer
require, Compose, host mounts або робочу БД. Базовий CLI-образ від #82:
`sha256:9d04e9178129ad6cc716b71271be07e78c85faeec0c930bb769ddbe3a37d5c91`.
Для іншого checkout спочатку побудуйте CLI-образ командою вище. Під час пілота
SHA256 класів і початкових тестів цього образу збігалися з master `f2a9146`.

Обрано 10 mutators замість `@default`: LogicalAnd/LogicalOr/LogicalNot перевіряють
guards і review/incomplete; Identical/NotIdentical — mode/UOM/policy та порожні
набори; LessThanOrEqualTo — нульову межу divisor; ArrayItemRemoval — повноту
nutrition/identity/context/portions; MethodCallRemoval — втрату окремої перевірки;
IncrementInteger/DecrementInteger — множник 7, дільники, scale та числові межі.
Один thread, timeout 10 s; жодного min-msi, ignore чи виключення survivors.

У PowerShell із кореня власного worktree створіть лише gitignored tooling:

```powershell
$out = '.phpunit.cache/infection-1'
New-Item -ItemType Directory -Force $out | Out-Null
@'
FROM telmanfit-infection-env:local
USER root
RUN composer require --dev infection/infection:0.35.8 --no-scripts --no-plugins --no-interaction --prefer-dist
USER www-data
'@ | Set-Content -Encoding ascii "$out/Dockerfile"
docker build -t telmanfit-infection1:temporary $out
@'
{"source":{"directories":["app/Domain/Recipes","app/Domain/MealPlans"],"excludes":["MealItemKind.php"]},"phpUnit":{"configDir":"."},"tmpDir":"/tmp/infection","timeout":10,"mutators":{"@default":false,"LogicalAnd":true,"LogicalOr":true,"LogicalNot":true,"Identical":true,"NotIdentical":true,"LessThanOrEqualTo":true,"ArrayItemRemoval":true,"MethodCallRemoval":true,"IncrementInteger":true,"DecrementInteger":true},"logs":{"text":"/tmp/results/infection.log","summary":"/tmp/results/summary.log","summaryJson":"/tmp/results/summary.json","json":"/tmp/results/infection.json"}}
'@ | Set-Content -Encoding ascii "$out/infection.json"
$runner = @'
set -eu
cp -r /work /tmp/pilot
cd /tmp/pilot
cp /tmp/infection.json infection.json
mkdir -p /tmp/results
php -d pcov.directory=/tmp/pilot/app vendor/bin/phpunit --filter 'BatchEvaluatorTest|MealItemPortionTest' --do-not-cache-result --coverage-text --coverage-clover /tmp/results/clover.xml --coverage-php /tmp/results/coverage.php --coverage-xml /tmp/results/coverage-xml --log-junit /tmp/results/junit.xml
php -d pcov.directory=/tmp/pilot/app vendor/bin/infection --configuration=infection.json --no-interaction --threads=1 --test-framework-options='--filter=BatchEvaluatorTest|MealItemPortionTest' --show-mutations=max app/Domain/Recipes/BatchEvaluator.php app/Domain/MealPlans/MealItemPortion.php
'@
[IO.File]::WriteAllText((Join-Path $PWD "$out/run.sh"), $runner.Replace("`r`n", "`n").TrimEnd() + "`n", [Text.UTF8Encoding]::new($false))
```

Baseline виконується з початковими тестами master `f2a9146`, повторний — з тестами
цього PR. Обидва використовують незмінні класи/config/tooling image; замінюються
тільки два тестові файли. Для відтворення baseline після змін збережіть відповідні
`git show f2a9146:tests/Unit/BatchEvaluatorTest.php` та
`git show f2a9146:tests/Unit/MealItemPortionTest.php` у локальні UTF-8 файли й
копіюйте їх замість поточних тестів. Не переключайте чужий worktree.

Один прогін (вільне ім'я контейнера, `$phase` = `before` або `after`):

```powershell
$phase = 'after'
$container = "telmanfit-infection1-$phase"
docker create --name $container --network none --entrypoint sh telmanfit-infection1:temporary /tmp/run.sh
docker cp "$out/infection.json" "${container}:/tmp/infection.json"
docker cp "$out/run.sh" "${container}:/tmp/run.sh"
docker cp tests/Unit/BatchEvaluatorTest.php "${container}:/work/tests/Unit/BatchEvaluatorTest.php"
docker cp tests/Unit/MealItemPortionTest.php "${container}:/work/tests/Unit/MealItemPortionTest.php"
docker start -a $container
docker inspect --format '{{.State.ExitCode}} {{.HostConfig.NetworkMode}} {{json .Mounts}}' $container
# Обов'язково 0 none []; docker start не замінює перевірку State.ExitCode.
docker cp "${container}:/tmp/results" "$out/$phase"
docker cp "${container}:/work/composer.lock" "$out/tooling-composer.lock"
docker logs $container > "$out/$phase.stdout.log"
docker rm $container
```

Це export-варіант із writable одноразовим layer, без tmpfs, щоб після завершення
скопіювати звіти; процес www-data. Для read-only preflight можна використовувати
`--read-only --tmpfs /tmp`, як у базовій перевірці; `docker cp` у зупинений такий
контейнер не записує runtime tmpfs. Звіти не публікуються й не комітяться.
Після обох прогонів: `docker image rm telmanfit-infection1:temporary`; видаляти
лише власні контейнери за точними іменами, без prune та очищення volumes.
Пілот не додає mutation CI job/threshold. Локальні syntax/Pint, повний Unit suite
та docs guard доповнюються чинними Fast/Docs/Targeted на head Draft PR.

## Межі

Робочий `compose.yaml`, сервіси, volumes, MFA/auth, БД та feature flags не змінюються.
Нових CI checks немає. Загальний coverage `app` від двох Unit-наборів не є
оцінкою покриття всього застосунку. PCOV надає line coverage; branch/path coverage
не перевірено. Фактичні результати — у [project-status.md](project-status.md).
