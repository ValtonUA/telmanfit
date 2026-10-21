# Stage 1 — міграції та перевірки PostgreSQL

Реалізовано 21.10.2026 за прямим дорученням користувача: повна бізнес-схема через Laravel-міграції, G3=B+R1, нова ізольована PostgreSQL 18, один Draft PR до `master`. Це доручення дозволяє зміни коду, міграції й Git-операції в межах цієї задачі; наявні БД, production, секрети та еталонні SQL/DBML не змінюються.

## Виправлення PHPUnit warnings у PR #5 — 21.10.2026

Прочитано оригінальний [GitHub Actions run 39627333572](https://github.com/ValtonUA/telmanfit/actions/runs/39627333572), job `119315866879`: SQL і PG PHPUnit пройшли, але основний набір мав **9 warnings, 10 passed**, хоча job завершився успішно. Попередній опис «19 passed» не підтверджував відсутність warnings у CI.

Відтворення з повними повідомленнями: `PAO_DISABLE=1 php artisan test --display-all-issues --display-warnings`. У всіх дев’яти HTTP Feature-тестах джерело однакове: `vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73`, `file_get_contents(.../.env): Failed to open stream: No such file or directory`. Laravel завантажує dotenv під час кожного bootstrap, а в чистому checkout немає `.env`. `PAO_DISABLE=1` лише повертає повний стандартний вивід замість стислого агентського JSON.

Додано версійований `.env.testing` із самими коментарями: Laravel автоматично вибирає його для `APP_ENV=testing`. Ключ та параметри тестів залишаються в PHPUnit XML; PG-раннер явно задає лише свою нову ізольовану БД. `.env`, секрети та налаштування реальних БД не змінюються. В обох XML увімкнено `failOnWarning="true"` та `ignoreSuppressionOfPhpWarnings="true"`: навіть PHP warnings під оператором `@` показуються й завершують перевірку помилкою. Раннер виводить усі issues, а CI також запускає frontend lint.

Негативна перевірка захисту: тимчасово вилучено `.env.testing`, запущено основний PHPUnit, файл відновлено через `finally`. Отримано код **1** і warning від усіх дев’яти Feature-тестів (прямий PHPUnit групує однакові повідомлення як `Warnings: 1`). Після відновлення: основний PHPUnit **19 tests / 26 assertions**, без warnings. Нових бізнес-тестів і змін наявних тестів немає.

Повний повторний прогін у Linux Codex Cloud: `tests/pg/run-stage1.sh` → код **0**, **PASS=76 FAIL=0 GAP=0**, PG PHPUnit **12 tests / 260 assertions**, основний PHPUnit **19 passed / 26 assertions**, без warnings, Pint успішний. PostgreSQL 18.6 створено лише в новому контейнері без мережі й портів; контейнер і приватний socket прибрано раннером. `npm run lint --prefix client` та `npm run build --prefix client` → код **0**.

Перший CI-прогін виправлення [39641827524](https://github.com/ValtonUA/telmanfit/actions/runs/39641827524) виявив гонку готовності контейнера до початку тестів: `pg_isready` прийняв тимчасовий init-сервер за остаточний, після чого `psql` не знайшов socket. Раннер тепер чекає повідомлення entrypoint про завершення ініціалізації, а тоді перевіряє готовність через `/run/telmanfit-pg`. Це зберігає pin образу й ізоляцію; бізнес-схема не змінюється.

G5 затверджено користувачем: перший бізнес-модуль — **картки клієнтів у Filament (Stage 2)**. JSON-контракти, права тренера, автентифікація клієнта та інші G1-рішення залишаються відкритими; реалізація ClientResource не входить у це виправлення. T1.5 і перевірка цільового хостингу потребують окремих задач.

Файли цього виправлення: `.env.testing`, `.gitignore`, `phpunit.xml`, `phpunit.pgsql.xml`, `tests/pg/run-stage1.sh`, `.github/workflows/stage1.yml`, `AGENTS.md`, `docs/architecture.md`, `docs/project-status.md`, `docs/roadmap.md`, `docs/stage-1.md`. Еталонні SQL/DBML, міграції, бізнес-правила, наявні БД та секрети збережено. Merge не виконується.

## Відповідність джерелам

Після стандартних Laravel-міграцій і наявної `2026_10_21_000000_align_users_with_business_schema.php` застосовуються:

| Міграція `2026_10_21_*` | Джерело / знімок |
|---|---|
| `000100_enable_btree_gist.php` | Окреме встановлення `btree_gist` з перевіркою PostgreSQL |
| `000200_create_nutrition_schema.php` | `docs/01_nutrition_schema.sql` → `database/sql/stage1/01_nutrition_schema.sql` |
| `000300_create_clients_schema.php` | `docs/02_users_clients.sql` → `database/sql/stage1/02_users_clients.sql` |
| `000400_create_progress_tracking_schema.php` | `docs/03_progress_tracking.sql` → `database/sql/stage1/03_progress_tracking.sql` |
| `000500_create_fitness_messaging_schema.php` | `docs/04_fitness_messaging.sql` → `database/sql/stage1/04_fitness_messaging.sql` |

Знімки зберігаються разом із міграціями: виконання не залежить від майбутніх редагувань документації. У них прибрано зовнішні `BEGIN`/`COMMIT` (транзакцією керує Laravel), встановлення розширення винесено окремо, створення `users` пропущено за R1. Є 37 бізнес-таблиць, зокрема вже наявна `users`, і 8 службових Laravel-таблиць; загалом 45.

За збереженим BIGINT `users.id` п'ять FK-стовпців теж мають BIGINT: `clients.user_id`, `clients.coach_user_id`, `client_meal_plan_assignments.created_by`, `client_metric_assignments.created_by`, `client_events.actor_user_id`. Інакше PostgreSQL дозволяв би FK, але INTEGER не приймав би ID понад 2147483647. Інші бізнес-ключі зберігають вихідні INTEGER/SMALLINT. Laravel-типи `users`, службові та часові колонки збережено без перестворення таблиці.

Усі CHECK, складені FK, часткові індекси, EXCLUDE, відкладений constraint trigger та функції публікації збережено. PHPUnit порівнює живі PostgreSQL-каталоги колонок, обмежень, індексів, тригерів і функцій з еталонними модулями, які виконує в окремій схемі всередині тестової транзакції. Узгоджені винятки R1 перевіряються окремо.

## Відтворення перевірок

Потрібні PHP 8.4 з `pdo_pgsql`/`pdo_sqlite`, встановлені залежності з lock-файлів, Docker і Python 3. Для всіх наявних SPA-тестів потрібна збірка клієнта:

```bash
composer install --no-interaction --prefer-dist
npm ci --prefix client
npm run build --prefix client
tests/pg/run-stage1.sh
```

Раннер створює виключно новий контейнер із tmpfs на тому самому pin, що й погоджений G4: `postgres:18.6-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc`. Використано `--network none`, жодних опублікованих портів чи томів наявних БД; PHP підключається через власний Unix socket у `/tmp/telmanfit-stage1.XXXXXXXX/socket` (батьківський каталог 700). UTF8/ICU `uk-UA` збережено. Автентифікація trust використовується лише в цьому одноразовому контейнері; секрети не потрібні. Створюються `telmanfit_stage1_test` і роль `telmanfit_test_runner` без SUPERUSER/CREATEDB/CREATEROLE. Після перевірок раннер видаляє тільки власний контейнер. Логи `psql` залишаються в ігнорованому `tests/pg/out/`.

Послідовність: `artisan migrate` → незмінені шість `tests/pg/t0*.sql` через `psql` → перевірка точно `PASS=76 FAIL=0 GAP=0` → окремий PHPUnit PG-конфіг → основний `php artisan test` → Pint. `.github/workflows/stage1.yml` повторює цей прогін у GitHub Actions; публікацію PR не слід плутати з підтвердженим результатом віддаленого CI.

`phpunit.pgsql.xml` відокремлений від штатного SQLite-конфігу. Для ручного PG-запуску всередині життєвого циклу такого тестового сервера `stage1_socket` має вказувати на створений приватний socket-каталог:

```bash
TELMANFIT_PG_TEST=1 DB_HOST="$stage1_socket" DB_PORT=5432 \
DB_DATABASE=telmanfit_stage1_test DB_USERNAME=telmanfit_test_runner DB_PASSWORD= \
vendor/bin/phpunit -c phpunit.pgsql.xml --display-all-issues
```

Тести відмовляються працювати без явних параметрів ізольованого сервера, перевіряють фактичні назву БД, роль без SUPERUSER і PostgreSQL 18 до міграцій. Кожен тест відкочує свою транзакцію. Перевірка залишків виключає службові таблиці Laravel, де історія міграцій закономірно містить рядки.

## Відкат

`down()` прибирає лише об'єкти свого модуля у зворотному порядку без CASCADE; модуль 02 спочатку знімає чотири міжмодульні FK. Сторонні залежності спричинять помилку та транзакційний відкат. Розширення `btree_gist` зберігається: воно могло існувати раніше й мати сторонніх споживачів. Наявний захист відкату узгодження `users` зберігається: admin/coach, Telegram-прив'язка чи NULL email/password блокують втрату даних.

Автотести підтверджують збереження наявного користувача, успішний відкат/повторне застосування бізнес-модулів, відсутність їхніх функцій після відкату, безпечний round trip узгодження `users` та повторний `migrate` без змін. BIGINT перевірено реальними ID понад межу INTEGER в усіх п'яти FK, а відсутні користувачі й видалення тренера відхиляються. HTTP-перевірки охоплюють гостьовий redirect, сторінку входу, автентифікацію за паролем і `/admin`: admin — 200, coach/client — 403.

## Межі та наступні рішення

T1.5 не додає непогоджених довідників: перелік значень потребує окремого погодження за roadmap. Тестові `g`/`serving` та інші записи існують лише в транзакційних fixtures. Жоден admin не призначається міграціями.

Доступність `btree_gist`, cron і worker на цільовому хостингу не перевірялась: доступу до нього ця задача не надає. Це обмеження розгортання, а не блокер перевірки схеми в ізольованому PostgreSQL. G5 затверджено користувачем: перший бізнес-модуль — картки клієнтів у Filament (Stage 2). G1 та питання аудиту §7 залишаються для відповідних наступних модулів; G4 уже виконано в PR #4 ([звіт](../tests/pg/G4.md)); ця задача доповнює його перевірками Laravel-міграцій та PHPUnit/CI. Наявні G4-раннери збережено без змін; їхні контрактні тести `bash tests/pg/test-runner.sh` також проходять. Сервіси, Filament ClientResource, багатосесійна конкурентність, приватність фото та повторна доставка — поза обсягом Stage 1. Merge не виконується.

## Результат прогону та змінені файли

У Codex Cloud на нових PostgreSQL-контейнерах підтверджено PostgreSQL **18.6**, `btree_gist` **1.8**, **PASS=76 FAIL=0 GAP=0**. Повний `tests/pg/run-stage1.sh` завершився кодом 0: PG PHPUnit — **12 passed / 260 assertions**, штатний PHPUnit — **19 passed / 26 assertions**, Pint — успіх. Окремо `npm run lint --prefix client` і `npm run build --prefix client` — код 0. Виправлено наявне форматування `bootstrap/providers.php`, через яке повний Pint спочатку не проходив; поведінка провайдерів збережена. Після оновлення робочої гілки на актуальний `master` (PR #4) конвеєр узгоджено з G4: pin образу, приватний Unix socket, відсутність мережі й портів. Проміжні помилки нового тестового раннера (кількість сценаріїв у файлах, SQLSTATE RESTRICT та приведення `pg_catalog."char"`) виправлено до успішного повного прогону.

Повний перелік створених / змінених файлів:

- `AGENTS.md`
- `.github/workflows/stage1.yml`
- `bootstrap/providers.php`
- `database/migrations/2026_10_21_000100_enable_btree_gist.php`
- `database/migrations/2026_10_21_000200_create_nutrition_schema.php`
- `database/migrations/2026_10_21_000300_create_clients_schema.php`
- `database/migrations/2026_10_21_000400_create_progress_tracking_schema.php`
- `database/migrations/2026_10_21_000500_create_fitness_messaging_schema.php`
- `database/sql/stage1/01_nutrition_schema.sql`
- `database/sql/stage1/02_users_clients.sql`
- `database/sql/stage1/03_progress_tracking.sql`
- `database/sql/stage1/04_fitness_messaging.sql`
- `docs/architecture.md`
- `docs/project-status.md`
- `docs/roadmap.md`
- `docs/stage-1.md`
- `phpunit.pgsql.xml`
- `tests/Postgres/BusinessSchemaTest.php`
- `tests/pg/README.md`
- `tests/pg/run-stage1.sh`
