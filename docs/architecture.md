# Технологічна архітектура TelmanFit

**Призначення:** стабільні технічні рішення, шари та їхні контракти. **Не ведемо тут поточні статуси Stage, хронологію PR або список уже реалізованих функцій** — [roadmap](roadmap.md) єдиний поточний план, [project-status](project-status.md) містить фактичну реалізацію та докази. Для агента діють [AGENTS.md](../AGENTS.md).

**Джерела істини щодо схеми:** [database.dbml](database.dbml), SQL `01–04` (бізнес), `05–09` (auth/security), [Entity Catalog](entity-catalog.md), [business-rules](business-rules.md); порядок інтеграції — Laravel-міграції. [Карта технічних файлів](#31-карта-технічних-файлів).

## 1. Огляд

TelmanFit — застосунок онлайн-фітнес-супроводу з двома інтерфейсами:

- **адмін-панель тренера** (Filament, `/admin`) — ведення клієнтів, каталогу продуктів, рецептів, планів харчування, метрик і комунікації;
- **кабінет клієнта** (React/TypeScript SPA, `/app`) — перегляд призначеного плану, внесення метрик і звітів, фотографії прогресу.

Обидва інтерфейси працюють з одним Laravel-застосунком і однією базою PostgreSQL. Telegram (Mini App, повідомлення) є **додатковим каналом**, а не основою архітектури: доменні правила не залежать від Telegram (див. [../AGENTS.md](../AGENTS.md), розділ 7).

Схема нижче описує **архітектурні шари**, а не факти готовності кожного модуля. Рівень реалізації й умови production визначаються [project-status.md](project-status.md) і [roadmap.md](roadmap.md).

### Реєстрація та оболонки клієнта — затверджено 21.10.2026

Доступ нового клієнта — виключно за персональним запрошенням, створеним admin у Filament; відповідальний тренер обирається явно. Одна людина має один `users` і максимум один `clients`; ім’я/прізвище зберігаються в `users.first_name/last_name`, `full_name` обчислюється в Laravel (legacy fallback — [контракт профілів](client-profiles.md)), без `clients.display_name`. Реєстрація не активує платний супровід.

PWA і Telegram Mini App використовують максимально один React-застосунок у `client/`, один Laravel API і PostgreSQL. Клієнт може користуватися будь-якою оболонкою або обома зі спільними даними; можливості Mini App можуть бути обмежені. Це цільова архітектура оболонок; ступінь реалізації окремих клієнтських сценаріїв визначається кодом та [поточними доказами](project-status.md).

У PWA передбачено «Підключити Telegram» з підтвердженням володіння Telegram-акаунтом. Зворотний сценарій також має підключати PWA до того самого облікового запису. Автоматичне ототожнення людей за ім’ям, username чи email заборонене. Вимоги перевірки підтвердження та спільного MFA gate — [auth-architecture.md](auth-architecture.md), §9; фактичну реалізацію перевіряти у відповідних AUTH-звітах.

**Правила входу:** PWA email + одноразовий OTP без пароля, optional client TOTP/резервні коди, обов'язкова admin MFA; доступ нових клієнтів лише за запрошенням. Дизайн і межі — [auth-architecture.md](auth-architecture.md), [AUTH-3](auth3.md), [AUTH-4](auth4.md), [AUTH-5](auth5.md); не припускати production readiness без окремого review.

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
| Мова backend | PHP | `~8.3.36 \|\| ~8.4.27 \|\| ~8.5.12` у Composer; цільовий runtime — PHP 8.5 із security patch | `composer.json`, [D8.1](auth6-d8-preflight.md) |
| Фреймворк | Laravel | `^13.17` | `composer.json` |
| Адмінка | Filament | `~5.0` | `composer.json` |
| Реактивні компоненти адмінки | Livewire | `4.4.8` у lock; виправлення GHSA-mhf7-xp6r-6qrc, без зміни Filament чи audit policy | `composer.lock`, [advisory](https://github.com/advisories/GHSA-mhf7-xp6r-6qrc) |
| Розробка | Laravel Tinker, Pint, Pail, PAO | `^3.0`, `^1.27`, `^1.2.5`, `^1.0.6` | `composer.json` |
| Тести backend | PHPUnit | `^12.5.12`; **11 XML-конфігурацій окремих тестових наборів** | `composer.json`, `phpunit*.xml`; [пояснення](#31-карта-технічних-файлів) |
| БД | PostgreSQL: **01–04 бізнес-схема**, **05–09 auth/security** | 18 — цільовий runtime і перевірений тестовий PG18.6 | `docs/01–15_*.sql`, `database/sql/`, `database/migrations/`; [пояснення](#31-карта-технічних-файлів) |
| SPA | React + TypeScript + Vite | React `^19.3`, TS `~6.0.3`, Vite `^8.3.4` | `client/package.json` |
| Стилі SPA | CSS + Tailwind (Laravel-конвеєр) | Tailwind `^4.0` | `package.json`, `vite.config.js` |
| Лінт SPA | ESLint + typescript-eslint | `^10.13`, `^8.71` | `client/eslint.config.js` |
| Хостинг (обраний, ще не розгорнутий) | **Hyperhost VPS Старт** | 2 CPU, 3 ГБ RAM, 45 ГБ NVMe; сумісність PHP/PG на VPS ще не доведена | [D8](auth6-d8-infrastructure.md), [D8.1](auth6-d8-preflight.md) |

### 3.1. Карта технічних файлів

**У `docs/` є тринадцять SQL-файлів — це модулі та forward-проєкції однієї БД:**

| Рівень | Файли | Для чого |
|---|---|---|
| Бізнес-схема | `01_nutrition_schema.sql`, `02_users_clients.sql`, `03_progress_tracking.sql`, `04_fitness_messaging.sql` | Продукти/рецепти, клієнти, прогрес, інвентар/повідомлення — 37 бізнес-таблиць |
| Рецепти A/B/C | [11_recipe_batches.sql](11_recipe_batches.sql), [12_recipe_equipment.sql](12_recipe_equipment.sql), [13_recipe_legacy_conversion.sql](13_recipe_legacy_conversion.sql) | Forward-схема та guard-функції; виконувані джерела — `database/sql/recipes/` |
| Презентація та медіа каталогу | [15_plan_presentation.sql](15_plan_presentation.sql), [16_catalog_media.sql](16_catalog_media.sql) | Forward SQL15: immutable weekday plan і nullable covers; SQL16: nullable photo URL кухонного обладнання; `database/sql/presentation/` |
| Профілі | `10_client_profiles.sql` | Forward-ревізія типізованих профілів; доповнює незмінні знімки Stage 1 |
| Кухонні шаблони | [17_cooking_templates.sql](17_cooking_templates.sql), [18_cooking_selection.sql](18_cooking_selection.sql), [контракт](cooking-templates.md) | Нормалізовані templates/versions/sessions/outputs/allocations, explicit selections, JSONB v1; виконуване джерело `database/sql/cooking/` |
| Auth-таблиці | `05_auth_schema.sql` | Запрошення, сесії, MFA, резервні коди |
| Auth recovery | `06_auth_recovery_schema.sql` | Технічна схема assisted recovery; **D3 operational із друзями відкладено** |
| Security notices | `07_auth_security_notice_outbox.sql` | Черга повідомлень про події безпеки |
| Retention/restore | `08_auth_retention_restore.sql` | Історія та захист після відновлення БД |
| Rate budgets | `09_auth_rate_budgets.sql` | Серверні ліміти частоти запитів |

Це **еталонні SQL-проєкції**; SQL 10 — forward-доповнення до Stage1, його виконуване джерело — `database/sql/profiles/00_profiles.sql`. Реальна Laravel-інтеграція описана у `database/migrations/` та `database/sql/stage1/`, `database/sql/auth2/`, `database/sql/auth5/`, `database/sql/auth6b*/`; див. [Stage 1](stage-1.md) та [AUTH-2](auth-schema.md)–[B6](auth6-b6.md). `docs/05_auth_schema.sql` передбачає вже узгоджену Laravel `users`, тому **не запускати docs/01–09 SQL вручну на робочій БД**.

**11 файлів `phpunit*.xml` у корені — лише конфігурації тестів:**
- `phpunit.xml` — `tests/Unit` і `tests/Feature`, базові SQLite-тести;
- `phpunit.pgsql.xml` — `tests/Postgres`, бізнес-схема;
- `phpunit.auth2.xml` – `phpunit.auth5.xml` — `tests/AuthPostgres`, `tests/Auth3`, `tests/Auth4`, `tests/Auth5`;
- `phpunit.auth6.xml`, `phpunit.auth6b.xml`, `phpunit.auth6b4.xml`, `phpunit.auth6b5.xml`, `phpunit.auth6b6.xml` — `tests/Auth6`, `tests/Auth6B`, `tests/Auth6B4`, `tests/Auth6B5`, `tests/Auth6B6`.

Їх використовує [`tests/pg/run-stage1.sh`](../tests/pg/run-stage1.sh) і [GitHub Actions](../.github/workflows/stage1.yml). Розділення забезпечує окремі test-only конфігурації session/cache/auth. **Без перегляду залежностей не об'єднувати та не видаляти.**

**`docs/evidence/` і `docs/screenshots/` — архів технічних доказів, не вміст застосунку.** Там JSON-виміри та скриншоти браузерних перевірок MFA/Filament і UX-1. На них посилаються [UX-1](ux-1.md), [AUTH-3](auth3.md), [AUTH-5](auth5.md), [AUTH-6B/B6](auth6-b6.md) та workflow. Заміна на тимчасові GitHub Actions artifacts можлива лише з перевіркою CI, посилань, маскування секретів і потрібного строку збереження доказів.

**Навігація для Codex:** [AGENTS.md](../AGENTS.md) → [roadmap.md](roadmap.md) → [project-status.md](project-status.md) → потрібна частина [архітектури](architecture.md) та відповідні SQL/бізнес-правила. Немає потреби сприймати кожен `evidence`/XML як документ для планування функціоналу.

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
| `app/Filament/Resources/Catalog/`, `app/Models/Catalog/` | Штатні Filament ресурси й моделі існуючих харчових довідників; conversions у формі ingredient |
| `app/Services/Catalog/CatalogWriter.php`, `app/Policies/CatalogPolicy.php` | Транзакційний writer food/ingredient, decimal validation, CAS/locks та admin ACL; [предметний контракт](food-catalog.md) |
| `app/Models/Meal*.php`, `app/Models/ClientMealPlanAssignment.php`, `app/Models/Food.php` | Eloquent чинної nutrition-схеми, явні FK та decimal casts |
| `app/Filament/Resources/MealPlans/`, `app/Services/MealPlans/`, `app/Policies/MealPlan*` | Адміністративний foundation раціонів, atomic create та exact-version read-only views; [контракт](meal-plans.md) |
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
- **Контракти SPA:** React/TypeScript відповідає за UI та клієнтські виклики лише `/api`; auth/security — окремий шар ([AUTH-5](auth5.md)), бізнес-сценарії визначаються модульними правилами. Поточна реалізація — у [project-status.md](project-status.md). Нові frontend-залежності погоджувати окремо.

## 9. Дані: PostgreSQL, схема, міграції

- **Два рівні однієї PostgreSQL-схеми:** 37 бізнес-таблиць у `docs/01–04_*.sql` (порядок 01 → 02 → 03 → 04), додаткові auth/security об'єкти у `docs/05–09_*.sql`. Файли 05–09 не є окремими БД і не означають, що production auth увімкнено. [карта SQL](#31-карта-технічних-файлів).
- Обов'язкове розширення `btree_gist` (потрібне для `EXCLUDE`-обмеження `no_overlapping_client_plan_dates`).
- PG-специфіка, яку не можна втратити в міграціях: `EXCLUDE`, часткові UNIQUE-індекси, складені FK `(food_id, food_type)`, `DEFERRABLE` constraint trigger, тригери незмінності/валідації, `text + CHECK` замість enum.
- Службові таблиці Laravel (`migrations`, `sessions`, `jobs`, `failed_jobs`, `cache`, `cache_locks`, `password_reset_tokens`; за потреби `job_batches`, `personal_access_tokens`) не входять до Entity Catalog і створюються Laravel-міграціями.
- **G3 затверджено 21.10.2026: B + R1.** Стратегія B — інтеграція перевірених SQL-модулів через Laravel-міграції; R1 — розширення стандартної `users` без перестворення, зі збереженням BIGINT `users.id`. Повторного погодження G3 не потрібно. Це рішення не дозволяє самовільно змінювати SQL/DBML чи запускати міграції.
- **Stage 1 перевірено на схемі Laravel-міграцій:** ізольований PG18.6, 37 бізнес-таблиць, `btree_gist` 1.8, SQL 76 PASS/0 FAIL/0 GAP, PG PHPUnit/CI та перевірки BIGINT/FK; [відтворення](stage-1.md). AUTH-2–6B мають окремі міграції й контрольовані тести, production activation відсутня.
- **Міграція `users` реалізована:** `2026_10_21_000000_align_users_with_business_schema.php` доповнює стандартну міграцію: nullable `email`/`password`, унікальний nullable `telegram_user_id`, `role` з default `client` і CHECK. За R1 також збережено `remember_token`, `email_verified_at` та `created_at`/`updated_at` як nullable timestamp без DB-default. Ці погоджені відмінності від SQL/DBML не потребують перестворення `users`; сумісність майбутніх FK слід перевірити при перенесенні модулів.
- Міграція `users` **не призначає `admin` автоматично**. Створення або зміна ролей у конкретному середовищі потребує окремої авторизованої операції; попередні локальні результати — у [stage-1.md](stage-1.md).
- Схема у `docs/` — контракт; факти міграцій/CI та статус довідників — у [stage-1.md](stage-1.md), [project-status.md](project-status.md) і [roadmap.md](roadmap.md). Публікація та призначення мають бути реалізовані як транзакційні сервіси з тестами, не лише тригери.
- Заборонено запускати міграції, виконувати SQL-модулі або використовувати production-БД без прямого дозволу ([../AGENTS.md](../AGENTS.md), розділ 4).

## 10. Асинхронність: черги, планувальник, Telegram

- Черги Laravel (`jobs`, `failed_jobs`) використовуються для відкладених дій: відправка повідомлень, нагадування, важкі розрахунки. `QUEUE_CONNECTION=database` у `.env.example`.
- Планувальник — `routes/console.php` (єдине місце реєстрації задач).
- Доменна модель повідомлень: `message_templates` (шаблон + токени кнопок) і `outbound_messages` (відрендерений текст і `rendered_markup`, статуси `pending | sending | sent | failed | cancelled`). Рендеринг відбувається при постановці в чергу, а не при відправці.
- Сервісний контракт (БД його не забезпечує): ідемпотентність, єдине захоплення рядка (`FOR UPDATE SKIP LOCKED`), повторні спроби, обробка лімітів Telegram, редагування/ретрай помилок, маскування чутливих даних в `client_events.event_data`.
- Telegram є окремим каналом: перевірка `initData`, прив'язка `users.telegram_user_id`, відправка — в інтеграційному шарі (див. [../AGENTS.md](../AGENTS.md), розділ 7).
- Перед запуском на обраному Hyperhost VPS Старт перевірити PHP-FPM, PostgreSQL 18, scheduler, постійний worker і конфігурацію HTTPS; реальний статус — у [D8](auth6-d8-infrastructure.md)/[D8.1](auth6-d8-preflight.md).

## 11. Файли та фотографії прогресу

- У БД зберігається лише шлях: `client_progress_photos.storage_path`; бінарні дані — у сховищі Laravel (`FILESYSTEM_DISK=local`, приватна тека), фотографії не робляться публічними.
- Доступ до фото, ретенція, перевірка завантажень і безпечне приватне зберігання — сервісні правила, не DDL ([03_progress_tracking.sql](03_progress_tracking.sql), блок «Application-side contracts»).
- Інші медіа (зображення кроків рецепта — `recipe_directions.image_url`) посилаються на URL/шлях, а не на бінарні дані.
- Публічні фото інгредієнтів, рецептів і кухонного обладнання зберігаються як `image_url`; Laravel перевіряє точний HTTPS host allowlist. R2 служить джерелом об'єктів, а CLI-зіставлення описано в [звіті й інструкції](r2-image-matching.md).

## 12. Конфігурація та середовища

| Файл | Роль |
|---|---|
| `.env` / `.env.example` | Локальна конфігурація; `.env` не комітиться. Змінювати — лише з дозволу |
| `config/*` | Конфігурація Laravel; `config/database.php` має `pgsql`, `sqlite` тощо |
| `phpunit.xml` | Налаштування тестів: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync` |
| `client/vite.config.ts` | `base`, `build.outDir`, dev-проксі `/api` |
| `vite.config.js` | Конвеєр Laravel для `resources/*` |

Вимоги до ізольованої PostgreSQL-БД й безпечного запуску тестів — [tests/pg/README.md](../tests/pg/README.md) і [stage-1.md](stage-1.md). Жодних міграцій на реальних клієнтських БД без дозволу.

## 13. Локальний запуск і типові команди

Постійне середовище Windows 11 / Docker Desktop: [Docker Compose](local-compose.md),
включно з PowerShell coordinator-ом оновлення локального коду/схеми.
`compose.yaml` збирає PHP 8.5 / Laravel, React/Vite та nginx у контейнерах,
створює лише нову ізольовану PostgreSQL 18 і зберігає дані, ключі, storage та
незалежний RestoreFence epoch у named volumes. Local-only adapter не реєструється
у production; міграції на непорожній БД при рестарті заборонені.

Нижче — альтернативний запуск із runtime на хості.

Для перевірки Filament та клієнтської анкети використовуйте [ізольований локальний профіль](local-demo.md): окрема PostgreSQL 18, незалежний local epoch, штатні MFA/OTP, loopback-only. Він явно opt-in і не змінює production; звичайний `composer dev` сам по собі не налаштовує security runtime.

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

- **Unit/Feature:** `phpunit.xml` для стандартних Laravel тестів.
- **PostgreSQL:** `phpunit.pgsql.xml`, `tests/pg/run-stage1.sh` та ізольовані SQL/PHPUnit регресії для PG-специфічних інваріантів; [Stage 1](stage-1.md).
- **Auth:** окремі `phpunit.auth*.xml` й контекстні browser/HTTP тести; guard-режими не є production activation.
- **SPA:** `client/` lint/type-check/build; browser перевірки UI на синтетичних даних.
- [CI](ci.md) має Fast на кожному commit і явний Full на фінальному head; після merge виконується дешевший Fast + docs. Full не дублюється автоматично на master; можливий ризик merge-resolution знімається вимогою включити актуальний master у перевірений head.
- Для кожного PR перевірити CI саме на його SHA. Минулі результати та покриті/непокриті випадки наведено в [project-status.md](project-status.md) і модульних звітах, **не дублювати тут датовані PASS-лічильники**.

## 15. Стан реалізації

**Єдине джерело поточних статусів задач** — [roadmap.md](roadmap.md). **Факти коду та тести** — [project-status.md](project-status.md). Архітектура описує компоненти незалежно від того, на якому Stage зараз реалізація.

## 16. Ризики та відкриті питання

- Погоджені й відкриті питання за конкретними етапами — [roadmap.md](roadmap.md); вихідні рішення й SQL-обмеження — [schema-audit.md](schema-audit.md), [business-rules.md](business-rules.md).
- Hosting/runtime/backup/last-admin відновлення — [D8](auth6-d8-infrastructure.md), [D8.1](auth6-d8-preflight.md), [AUTH-6 readiness](auth6-readiness.md).
- Не додавати сюди дубльований список поточних blockers — він застаріє і суперечитиме roadmap. Зміну архітектурного рішення описувати **в цьому розділі/контракті**, а виконання — у roadmap.

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




Контракт профілів: [client-profiles.md](client-profiles.md). Початкова анкета — історичний JSON-знімок; поточні характеристики зберігаються в окремих typed профілях. Час ранкового повідомлення локальний у timezone клієнта; scheduler не додано.
