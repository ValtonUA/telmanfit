> **Stage 2 (21.10.2026):** реалізовано картки клієнтів у Filament 5, доступ лише admin, явний coach/admin за рішенням G1, Eloquent-зв’язки та PostgreSQL/Livewire-тести. Деталі й межі — [stage-2.md](stage-2.md). Наведені нижче історичні описи відсутніх бізнес-ресурсів стосуються попередньої ревізії.

> **Оновлення Stage 1 (21.10.2026):** повні Laravel-міграції модулів 01–04 та окреме встановлення `btree_gist` реалізовано. На новій ізольованій PostgreSQL 18.6 підтверджено 76 SQL-сценаріїв, BIGINT/FK, відкат, каталог і HTTP-доступ; G4 для Stage 1 реалізовано в PHPUnit/CI. [Докази й межі](stage-1.md). Наведені далі попередні описи стану слід читати з цим оновленням; T1.5, G1 і цільовий хостинг залишаються окремими задачами.

# Технологічна архітектура TelmanFit

> **Оновлено:** 21.10.2026.
> **Призначення:** описати технологічний стек, шари застосунку, межі модулів і **поточний стан реалізації**.
> **Технічний стан і походження перевірок:** [project-status.md](project-status.md). Його перелік розбіжностей і наступних задач — знімок до цієї синхронізації; актуальні формулювання архітектури та етапів наведено тут і в [roadmap.md](roadmap.md).
> **Джерела істини щодо даних:** [database.dbml](database.dbml) (структура) і `01…04_*.sql` (обмеження та тригери). Призначення сутностей — [entity-catalog.md](entity-catalog.md); правила — [business-rules.md](business-rules.md); порядок робіт — [roadmap.md](roadmap.md).
> **Правила роботи агентів:** [../AGENTS.md](../AGENTS.md).

## 1. Огляд

TelmanFit — застосунок онлайн-фітнес-супроводу з двома інтерфейсами:

- **адмін-панель тренера** (Filament, `/admin`) — ведення клієнтів, каталогу продуктів, рецептів, планів харчування, метрик і комунікації;
- **кабінет клієнта** (React/TypeScript SPA, `/app`) — перегляд призначеного плану, внесення метрик і звітів, фотографії прогресу.

Обидва інтерфейси працюють з одним Laravel-застосунком і однією базою PostgreSQL. Telegram (Mini App, повідомлення) є **додатковим каналом**, а не основою архітектури: доменні правила не залежать від Telegram (див. [../AGENTS.md](../AGENTS.md), розділ 7).

Схема компонентів нижче описує цільову архітектуру. Реалізовано каркас Laravel/React, health-ендпоінт, бізнес-міграції Stage 1 та `ClientResource` для ведення наявних карток у Filament лише адміністратором. Ручне створення карток заблоковано. G3=B+R1, G4 і вибір першого модуля G5 затверджені; повний Stage 2, зокрема запрошення та клієнтська автентифікація, ще не завершений.

### Реєстрація та оболонки клієнта — затверджено 21.10.2026

Доступ нового клієнта — виключно за персональним запрошенням тренера. Одна людина має один `users` і максимум один `clients`; ім’я зберігається в `users.name`, без `clients.display_name`. Реєстрація не активує платний супровід.

PWA і Telegram Mini App використовують максимально один React-застосунок у `client/`, один Laravel API і PostgreSQL. Клієнт може користуватися будь-якою оболонкою або обома зі спільними даними; можливості Mini App можуть бути обмежені. Це цільова архітектура: зараз є лише каркас SPA, без готової PWA чи Mini App.

У PWA передбачено «Підключити Telegram» з підтвердженням володіння Telegram-акаунтом. Зворотний сценарій також має підключати PWA до того самого облікового запису. Автоматичне ототожнення людей за ім’ям, username чи email заборонене. Конкретний протокол підтвердження тут не визначається.

Механізм запрошень, Telegram-автентифікація, прив’язка Telegram і PWA-автентифікація **ще не реалізовані**; до їх реалізації нових клієнтів через UI створювати неможливо. Спосіб браузерної автентифікації PWA, деталі токенів запрошень і повний онбординг **ще не погоджено**. Цей PR не обирає сесії/токени, строки дії чи спосіб доставки запрошення.

`ClientPolicy::create()` повертає false для всіх ролей, у списку немає CreateAction, `CreateClient::authorizeAccess()` безумовно повертає 403 на mount, hydrate і create. Старий `/admin/clients/create` залишений як заблокована точка входу. Список, пошук, перегляд, редагування і статуси доступні лише `admin`; відповідальний тренер явно обирається серед `coach`/`admin`. Наявні NULL-картки залишаються перехідними записами; їх можна редагувати, схема nullable не змінюється. Подробиці — [stage-2.md](stage-2.md), правила BR-U-14…BR-U-18.

## 2. Компоненти

```
┌────────────────────┐        ┌──────────────────────────┐
│  React/TS SPA      │        │  Filament v5 (тренер)    │
│  client/ → /app    │        │  /admin                  │
└─────────┬──────────┘        └────────────┬─────────────┘
          │  JSON over /api                │  серверні сесії
          ▼                                ▼
┌──────────────────────────────────────────────────────────┐
│                 Laravel (routes/api.php, routes/web.php) │
│  HTTP-шар → доменні сервіси (бізнес-правила) → Eloquent   │
│  інтеграційний шар (Telegram / черги / планувальник)      │
└───────────────┬───────────────────────────┬──────────────┘
                │                           │
                ▼                           ▼
        PostgreSQL 15+              Private file storage
        (37 бізнес-таблиць)         (фото прогресу, експорти)
```

Клієнтський SPA не має власного сервера: у production статика з `public/app` роздається Laravel-роутом `/app/{any?}`, у розробці — Vite з проксі на `/api`.

## 3. Технологічний стек

| Шар | Технологія | Версія в репозиторії | Джерело |
|---|---|---|---|
| Мова backend | PHP | `^8.3` (локально перевірено 8.5) | `composer.json` |
| Фреймворк | Laravel | `^13.17` | `composer.json` |
| Адмінка | Filament | `~5.0` | `composer.json` |
| Розробка | Laravel Tinker, Pint, Pail, PAO | `^3.0`, `^1.27`, `^1.2.5`, `^1.0.6` | `composer.json` |
| Тести backend | PHPUnit | `^12.5.12` | `composer.json`, `phpunit.xml` |
| БД | PostgreSQL (структура — SQL-модулі 01–04) | 15+ | `docs/01…04_*.sql`, `.env` (`DB_CONNECTION=pgsql`) |
| SPA | React + TypeScript + Vite | React `^19.3`, TS `~6.0.3`, Vite `^8.3.4` | `client/package.json` |
| Стилі SPA | CSS + Tailwind (Laravel-конвеєр) | Tailwind `^4.0` | `package.json`, `vite.config.js` |
| Лінт SPA | ESLint + typescript-eslint | `^10.13`, `^8.71` | `client/eslint.config.js` |
| Хостинг (цільовий) | CloudLinux/cPanel, shared | — | [schema-audit.md](schema-audit.md) |

`laravel/boost` у проєкті **не встановлено і не встановлюється** (див. [../AGENTS.md](../AGENTS.md), розділ 4).

## 4. Шари застосунку та правила залежностей

| Шар | Відповідальність | Заборонено |
|---|---|---|
| HTTP / Presentation | Контролери `app/Http/Controllers/Api/*`, Filament Resources/Pages/Widgets, маршрути | Тримати бізнес-правила у контролерах; рендерити Blade для `/api` |
| Application / Domain | Сервіси та дії: публікація рецепта/плану, розрахунок КБЖВ і грамів, призначення плану, генерація списку покупок, життєвий цикл статусів | Звертатися до Telegram API, HTTP-запитів, `request()` |
| Persistence | Eloquent-моделі `app/Models`, транзакції, блокування рядків | Обходити обмеження БД; покладатися лише на тригери як на реалізацію правила |
| Integration | Канал Telegram, черги, планувальник, файлове сховище | Містити доменні правила, які потрібні веб-кабінету |

**Ключове правило:** доменний сервіс — єдине місце, де живе бізнес-правило. І веб-кабінет, і Telegram викликають той самий сервіс. Тригери БД — остання лінія захисту, а не заміна сервісу (див. `docs/01_nutrition_schema.sql`, коментарі до тригерів, і [schema-audit.md](schema-audit.md), розділ 5.1).

## 5. Структура репозиторію

| Шлях | Призначення |
|---|---|
| `app/Http/Controllers/Api/` | JSON-контролери (наразі лише `HealthController`) |
| `app/Providers/Filament/AdminPanelProvider.php` | Конфігурація панелі `admin` |
| `client/` | Окремий Vite-проєкт React/TS; `npm run build` → `public/app` |
| `database/migrations/` | Стандартні Laravel-міграції, узгодження `users`, `btree_gist` і модулі 01–04; див. stage-1.md |
| `docs/` | Entity Catalog, DBML, SQL-модулі, аудит, цей документ, правила й план |
| `routes/api.php`, `routes/web.php` | API та веб-маршрути (включно з SPA-фолбеком) |
| `resources/js/app.js`, `resources/css/app.css` | Каркасні ресурси Laravel-конвеєра (Blade-сторінки) |
| `tests/Feature`, `tests/Unit` | PHPUnit-тести |

## 6. Маршрути та точки входу

| Метод і шлях | Обробник | Призначення |
|---|---|---|
| `GET /` | анонімний роут → `resources/views/welcome.blade.php` | Каркасна Blade-сторінка Laravel |
| `GET /up` | `health: '/up'` у `bootstrap/app.php` | Службова перевірка працездатності застосунку |
| `GET /api/health` | `App\Http\Controllers\Api\HealthController` | Публічна перевірка доступності API для SPA |
| `/admin/*` | Filament v5 | Адмін-панель тренера (логін + dashboard) |
| `GET /app/{any?}` | замикання у `routes/web.php` | Роздача зібраного SPA (`public/app/index.html`), підтримує client-side маршрути |

Правила:

- `/api` — stateless JSON; `/admin` і `/app` не перехоплюються SPA-фолбеком (перевірено тестом `tests/Feature/SpaFallbackTest.php`).
- Якщо збірка SPA відсутня, `/app/*` повертає 404 з підказкою запустити `npm run build` у `client/`.
- Нові API-маршрути додаються лише в `routes/api.php` і мають бути під `auth`/`throttle`-обмеженнями, коли з'явиться автентифікація.

## 7. Адмін-панель Filament

- Панель `admin` (path `/admin`), `->login()`, primary-колір Amber; автентифікація — стандартна Laravel-модель `App\Models\User` зі сторінки логіну Filament.
- Ресурси/сторінки/віджети виявляються автоматично з `app/Filament/{Resources,Pages,Widgets}` (`AdminPanelProvider`). Реалізовано `ClientResource` (список, перегляд, редагування, статуси; створення заблоковано), `Dashboard`, `AccountWidget`, `FilamentInfoWidget`.
- Middleware панелі: `EncryptCookies`, `StartSession`, `AuthenticateSession`, `PreventRequestForgery`, `SubstituteBindings`, …; auth-middleware — `Filament\Http\Middleware\Authenticate`.
- Реалізовано `User::canAccessPanel()`: лише `admin` має доступ; `coach`, `client`, відсутня або невідома роль відхиляються. `role` виключено з масового призначення. Це перевіряє ізольований `UserPanelAccessTest`, без БД та HTTP-входу.
- Майбутнє (за [roadmap.md](roadmap.md)): бізнес-ресурси Filament, Policy/Gate для прав тренера та ізоляції клієнтських даних. Поточний доступ лише для `admin` не визначає майбутніх прав `coach` і не закриває G1.
- Тренер працює **лише** через Filament; окремого тренерського SPA не планується.

## 8. Клієнтський SPA

- Окремий npm-проєкт у `client/` (React 19 + TypeScript + Vite 8), не частина Laravel-конвеєра Vite (`vite.config.js` обслуговує лише Blade-ресурси `resources/css/app.css`, `resources/js/app.js`).
- У production: `base = '/app/'`, `build.outDir = '../public/app'`, `emptyOutDir: true` → статика роздається Laravel-роутом `/app/{any?}`. Тека `public/app` у `.gitignore`.
- У розробці: Vite-сервер із проксі `/api` → `http://127.0.0.1:8000`, тому CORS не потрібен; запити йдуть відносними URL (`client/src/components/HealthStatus.tsx`).
- TypeScript: `noEmit`, `noUnusedLocals`, `noUnusedParameters`, `verbatimModuleSyntax`, `erasableSyntaxOnly` (`client/tsconfig.app.json`); ESLint flat-config із `typescript-eslint` і `react-hooks`.
- Наразі SPA містить лише каркас (`App.tsx` + `HealthStatus`): роутера, клієнта API, стейт-менеджера й UI-кіта немає. Їх додавання — окреме архітектурне рішення, яке потребує погодження.

## 9. Дані: PostgreSQL, схема, міграції

- Структура — 37 бізнес-таблиць у чотирьох SQL-модулях; порядок застосування `01 → 02 → 03 → 04` (модулі 02–04 додають cross-module FK, які 01 навмисно не створює).
- Обов'язкове розширення `btree_gist` (потрібне для `EXCLUDE`-обмеження `no_overlapping_client_plan_dates`).
- PG-специфіка, яку не можна втратити в міграціях: `EXCLUDE`, часткові UNIQUE-індекси, складені FK `(food_id, food_type)`, `DEFERRABLE` constraint trigger, тригери незмінності/валідації, `text + CHECK` замість enum.
- Службові таблиці Laravel (`migrations`, `sessions`, `jobs`, `failed_jobs`, `cache`, `cache_locks`, `password_reset_tokens`; за потреби `job_batches`, `personal_access_tokens`) не входять до Entity Catalog і створюються Laravel-міграціями.
- **G3 затверджено 21.10.2026: B + R1.** Стратегія B — інтеграція перевірених SQL-модулів через Laravel-міграції; R1 — розширення стандартної `users` без перестворення, зі збереженням BIGINT `users.id`. Повторного погодження G3 не потрібно. Це рішення не дозволяє самовільно змінювати SQL/DBML чи запускати міграції.
- **SQL-схему попередньо перевірено:** модулі `01→04` виконано в ізольованій PostgreSQL 18.6 (`btree_gist` 1.8), 37 таблиць; 76 перевірок, `PASS=76 FAIL=0 GAP=0` ([schema-audit.md](schema-audit.md), §2/§6). Повторний фактичний прогін Codex Cloud у G4 дав той самий результат, успішні PDO та cleanup — [докази](../tests/pg/G4.md). Це не перевірка схеми, створеної Laravel-міграціями.
- **Міграція `users` реалізована:** `2026_10_21_000000_align_users_with_business_schema.php` доповнює стандартну міграцію: nullable `email`/`password`, унікальний nullable `telegram_user_id`, `role` з default `client` і CHECK. За R1 також збережено `remember_token`, `email_verified_at` та `created_at`/`updated_at` як nullable timestamp без DB-default. Ці погоджені відмінності від SQL/DBML не потребують перестворення `users`; сумісність майбутніх FK слід перевірити при перенесенні модулів.
- **Підтверджено локально користувачем:** міграцію застосовано до `telmanfit_dev` (`Ran`, batch 2), наявний користувач збережений, `users` має 10 колонок; роль `admin` явно призначено та перевірено. У Codex Cloud ці операції не виконувалися. Міграція сама не призначає `admin`; інші середовища потребуватимуть окремого погодженого призначення.
- **Залишається реалізувати:** повні Laravel-міграції модулів 01–04 з урахуванням R1, погоджені сіди та PG-регресію саме на створеній ними схемі. Захищений відкат `users` і HTTP-вхід з реальною БД ще не підтверджені наведеними результатами.
- Заборонено запускати міграції, виконувати SQL-модулі або використовувати production-БД без прямого дозволу ([../AGENTS.md](../AGENTS.md), розділ 4).

## 10. Асинхронність: черги, планувальник, Telegram

- Черги Laravel (`jobs`, `failed_jobs`) використовуються для відкладених дій: відправка повідомлень, нагадування, важкі розрахунки. `QUEUE_CONNECTION=database` у `.env.example`.
- Планувальник — `routes/console.php` (єдине місце реєстрації задач).
- Доменна модель повідомлень: `message_templates` (шаблон + токени кнопок) і `outbound_messages` (відрендерений текст і `rendered_markup`, статуси `pending | sending | sent | failed | cancelled`). Рендеринг відбувається при постановці в чергу, а не при відправці.
- Сервісний контракт (БД його не забезпечує): ідемпотентність, єдине захоплення рядка (`FOR UPDATE SKIP LOCKED`), повторні спроби, обробка лімітів Telegram, редагування/ретрай помилок, маскування чутливих даних в `client_events.event_data`.
- Telegram є окремим каналом: перевірка `initData`, прив'язка `users.telegram_user_id`, відправка — в інтеграційному шарі (див. [../AGENTS.md](../AGENTS.md), розділ 7).
- Ризик середовища: на CloudLinux/cPanel потрібно перевірити можливість постійного queue-worker і cron до розгортання.

## 11. Файли та фотографії прогресу

- У БД зберігається лише шлях: `client_progress_photos.storage_path`; бінарні дані — у сховищі Laravel (`FILESYSTEM_DISK=local`, приватна тека), фотографії не робляться публічними.
- Доступ до фото, ретенція, перевірка завантажень і безпечне приватне зберігання — сервісні правила, не DDL ([03_progress_tracking.sql](03_progress_tracking.sql), блок «Application-side contracts»).
- Інші медіа (зображення кроків рецепта — `recipe_directions.image_url`) посилаються на URL/шлях, а не на бінарні дані.

## 12. Конфігурація та середовища

| Файл | Роль |
|---|---|
| `.env` / `.env.example` | Локальна конфігурація; `.env` не комітиться. Змінювати — лише з дозволу |
| `config/*` | Конфігурація Laravel; `config/database.php` має `pgsql`, `sqlite` тощо |
| `phpunit.xml` | Налаштування тестів: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync` |
| `client/vite.config.ts` | `base`, `build.outDir`, dev-проксі `/api` |
| `vite.config.js` | Конвеєр Laravel для `resources/*` |

За [project-status.md](project-status.md) локальне середовище використовує PostgreSQL. Окрема ізольована `telmanfit_test` задокументована в [README PG-тестів](../tests/pg/README.md); G4 виконано окремим Docker/Bash-прогоном із тимчасовим з'єднанням; `phpunit.xml`/CI не змінювалися. Тимчасові паролі не зберігаються в репозиторії. Після cleanup постійної тестової БД у Cloud немає; наступний запуск створить нову.

## 13. Локальний запуск і типові команди

```sh
composer install                 # залежності PHP
npm install --ignore-scripts     # залежності Laravel-конвеєра (vite.config.js)
composer dev                     # php artisan dev: сервер + черга + Vite + Pail
cd client && npm install && npm run dev   # SPA на Vite з проксі /api
php artisan test                 # PHPUnit (composer test)
vendor/bin/pint                  # форматування PHP
cd client && npm run lint        # ESLint для SPA
cd client && npm run build       # збірка SPA у public/app
```

Міграції, сіди та будь-які команди, що змінюють БД, запускаються лише з дозволу ([../AGENTS.md](../AGENTS.md), розділ 4).

## 14. Тестування

| Рівень | Інструмент | Що покриває |
|---|---|---|
| Feature (API/HTTP) | PHPUnit, `tests/Feature` | Наразі health і SPA-фолбек; бізнес-авторизація та JSON-контракти заплановані |
| Unit | PHPUnit, `tests/Unit` | Наразі доступ `User` до Filament; заплановано чисті розрахунки порцій, грамів, КБЖВ і списку покупок |
| Схема БД | Наявні SQL-сценарії, PowerShell- і Bash-раннери; G4 — окремий Docker/Bash-прогін | Обмеження, `EXCLUDE`, тригери, негативні сценарії публікації |

Наразі реалізовано: `HealthEndpointTest`, `SpaFallbackTest`, `UserPanelAccessTest`, `ExampleTest` (Feature/Unit) — без звернень до бізнес-схеми. `UserPanelAccessTest` не перевіряє міграцію чи HTTP-вхід із реальною БД; два SPA-тести пропускаються без `public/app/index.html`. **PG-тести схеми — окремо в `tests/pg`** (6 SQL-скриптів + `run-pg-tests.ps1`), запускаються вручну проти ізольованої `telmanfit_test`: задокументований результат від 21.10.2026 — `PASS=76 FAIL=0 GAP=0` на PostgreSQL 18.6. Багатосесійна конкурентність, приватність фото й повторна доставка цим прогоном не підтверджені; `GAP=0` не означає готовність застосунку.

**Історична перевірка документаційної задачі 21.10.2026, до G4:** `php` і `composer` не знайдено в стандартному PATH без ручного `activate.sh`. Конфігурацію не змінювали; PHPUnit/Pint не запускали. Міграції, сіди та PG-тести в цій документаційній задачі не запускалися. Попередні результати з [project-status.md](project-status.md) не є новим прогоном. Пізніше G4 фактично виконано в цьому Cloud: PHP 8.4.26/pdo_pgsql, PostgreSQL 18.6, 76 PASS, PDO через приватний Unix socket та cleanup; [докази](../tests/pg/G4.md).

**Обмеження:** `phpunit.xml` примусово використовує SQLite in-memory, який не підтримує `btree_gist`, `EXCLUDE`, часткові індекси в потрібному вигляді та PL/pgSQL-тригери, тому тести схеми не можуть виконуватися через PHPUnit у поточній конфігурації. Вони виконуються окремим раннером `tests/pg/run-pg-tests.ps1` (вимоги до середовища та спосіб передачі пароля — `tests/pg/README.md`). G4 виконано окремим `run-isolated-pg.sh`: тимчасові креденшели, образ із digest pin, без мережі та відкритих портів. Інтеграція з PHPUnit/CI — наступний окремий етап. Мінімальний набір сценаріїв — [schema-audit.md](schema-audit.md), розділ 6 (реалізовано).

## 15. Стан реалізації

| Область | Запроєктовано | Реалізовано | Доказ |
|---|---|---|---|
| Каркас Laravel | так | так | `bootstrap/app.php`, `routes/*`, `config/*` |
| Адмін-панель Filament | так | частково (логін, dashboard і наявні клієнтські картки, лише `admin`) | `AdminPanelProvider`, `User::canAccessPanel()`, `UserPanelAccessTest` |
| SPA React/TS | так | частково (каркас + health-check) | `client/src/App.tsx`, `client/src/components/HealthStatus.tsx` |
| API | так | лише `GET /api/health` | `routes/api.php`, `app/Http/Controllers/Api/HealthController.php` |
| Бізнес-схема (37 таблиць) | так | SQL DDL попередньо перевірено в ізольованій БД | Аудит: `docs/01–04` виконано в `telmanfit_test`; `PASS=76 FAIL=0 GAP=0` |
| Міграція узгодження `users` | G3: B + R1 | реалізована; застосування й призначення `admin` підтверджені локально користувачем | `2026_10_21_000000_align_users_with_business_schema.php`, [project-status.md](project-status.md) |
| Повні міграції модулів 01–04 | G3 затверджено | реалізовано й перевірено в ізольованому PostgreSQL 18 | [stage-1.md](stage-1.md), `database/migrations`, `database/sql/stage1` |
| Eloquent-моделі домену | так | частково: `User`, `Client` і зв’язки | `app/Models`, [stage-2.md](stage-2.md) |
| Сервіси публікації/призначення | так | ні | — |
| Запрошення, Telegram/PWA-автентифікація та прив’язка | так; деталі PWA, токенів і онбордингу відкриті | ні; UI-створення клієнтів заблоковане | [stage-2.md](stage-2.md) |
| Telegram-канал повідомлень | так (пізній етап) | ні | — |
| Тести схеми на PostgreSQL | так | так (окремий раннер) | `tests/pg/*.sql` + `run-pg-tests.ps1`; `phpunit.pgsql.xml`, `run-stage1.sh`, `.github/workflows/stage1.yml` |
| Тестове середовище PG | так | підтверджено в Codex Cloud, G4 та Stage 1 | [G4.md](../tests/pg/G4.md), [stage-1.md](stage-1.md); нові ізольовані PostgreSQL 18.6 |

## 16. Відомі ризики та технічний борг

1. **Схема перевірена на PostgreSQL 18.6** (21.10.2026): 37 таблиць, 19 тригерів, 1 `EXCLUDE`, 4 міжмодульні FK; `tests/pg` → `PASS=76 FAIL=0 GAP=0` ([schema-audit.md](schema-audit.md), §1–2, §6). **Не** перевірено на цільовому хостингу — див. пункт 4.
2. **Інтеграція `users` за G3/R1:** стратегія вже затверджена, міграція реалізована й застосована локально за підтвердженням користувача. Потрібні перевірки сумісності майбутніх FK з BIGINT PK, відтворюваності, захищеного відкату та HTTP-доступу в погодженому ізольованому PG-середовищі. Вимоги входу адміністратора й права тренера (G1) залишаються відкритими.
3. **PHPUnit працює на SQLite** і не здатен перевірити PG-специфіку; схема перевіряється окремим раннером `tests/pg` (ручний запуск). G4 виконано окремим Docker/Bash-прогоном із тимчасовими креденшелами. Майбутня інтеграція PHPUnit/CI і регресія Laravel-міграцій залишаються окремими задачами.
4. **`btree_gist` на cPanel/shared hosting** може бути недоступним — блокер розгортання.
5. **Черги й cron** на shared hosting — потрібна перевірка перед реалізацією відправки повідомлень.
6. **Відсутня автентифікація API**: `/api` наразі повністю публічний; контракт авторизації клієнта (сесія, токен, Telegram `initData`) не зафіксований.
7. **`NUMERIC(7,2)`** обмежує точність: правило округлення при масштабуванні потрібно документувати й тестувати (ризик `0.00` для дуже малих кількостей) — частково закрито: грамування перевіряється при публікації (`tests/pg`, `T3.20`/`T3.21`).
8. **Відкриті питання:** 11 пунктів у [schema-audit.md](schema-audit.md), розділ 7; №9 (метрики) закрито 21.10.2026 рішенням `BR-T-02`, №6 — частково (самопосилання заборонено CHECK-ом `assignment_no_self_replacement`); решта не вирішується агентом самостійно.
9. **Історичні формулювання в інших документах:** перелік розбіжностей у [project-status.md](project-status.md) передує цій синхронізації; стара таблиця GAP у [README PG-тестів](../tests/pg/README.md) суперечить його ж опису закриття GAP. Поточний задокументований результат — `PASS=76 FAIL=0 GAP=0`, з обмеженнями §14. Згадка стратегії `users` серед блокерів адмінського входу в [business-rules.md](business-rules.md), §12, не відкриває G3 повторно: відкритими залишаються вимоги входу, а B + R1 уже затверджено. Ці джерела не змінювалися в межах синхронізації трьох файлів.

## 17. Що свідомо поза межами v1

Повний модуль тренувань, плани завдань, збережені списки покупок (генеруються на льоту), альтернативні способи приготування, M:N-категорії, каталог раціонів для самостійного вибору клієнтом, інтеграції з фітнес-застосунками, feature toggles з окремими таблицями, самостійне ведення тренером-клієнтом самого себе. Деталі — [entity-catalog.md](entity-catalog.md), розділи 8 і 10.

## 18. Пов'язані документи

- [../AGENTS.md](../AGENTS.md) — правила для агентів.
- [project-status.md](project-status.md) — технічний стан і походження результатів перевірок.
- [business-rules.md](business-rules.md) — підтверджені бізнес-правила.
- [roadmap.md](roadmap.md) — план реалізації MVP.
- [entity-catalog.md](entity-catalog.md) — призначення сутностей.
- [database.dbml](database.dbml) — структура таблиць і полів.
- [schema-audit.md](schema-audit.md) — аудит, блокери, тести схеми, відкриті питання.


