# Технологічна архітектура TelmanFit

> **Оновлено:** 20.10.2026.
> **Призначення:** описати технологічний стек, шари застосунку, межі модулів і **поточний стан реалізації**.
> **Джерела істини щодо даних:** [database.dbml](database.dbml) (структура) і `01…04_*.sql` (обмеження та тригери). Призначення сутностей — [entity-catalog.md](entity-catalog.md); правила — [business-rules.md](business-rules.md); порядок робіт — [roadmap.md](roadmap.md).
> **Правила роботи агентів:** [../AGENTS.md](../AGENTS.md).

## 1. Огляд

TelmanFit — застосунок онлайн-фітнес-супроводу з двома інтерфейсами:

- **адмін-панель тренера** (Filament, `/admin`) — ведення клієнтів, каталогу продуктів, рецептів, планів харчування, метрик і комунікації;
- **кабінет клієнта** (React/TypeScript SPA, `/app`) — перегляд призначеного плану, внесення метрик і звітів, фотографії прогресу.

Обидва інтерфейси працюють з одним Laravel-застосунком і однією базою PostgreSQL. Telegram (Mini App, повідомлення) є **додатковим каналом**, а не основою архітектури: доменні правила не залежать від Telegram (див. [../AGENTS.md](../AGENTS.md), розділ 7).

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
| `database/migrations/` | Лише службові таблиці Laravel (бізнес-схема ще не реалізована) |
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
- Ресурси/сторінки/віджети виявляються автоматично з `app/Filament/{Resources,Pages,Widgets}` (`AdminPanelProvider`). Наразі каталоги не створені — реалізовано лише `Dashboard`, `AccountWidget`, `FilamentInfoWidget`.
- Middleware панелі: `EncryptCookies`, `StartSession`, `AuthenticateSession`, `PreventRequestForgery`, `SubstituteBindings`, …; auth-middleware — `Filament\Http\Middleware\Authenticate`.
- Майбутнє (за [roadmap.md](roadmap.md)): Filament-ресурси для каталогу продуктів, рецептів, планів, клієнтів, метрик і шаблонів повідомлень; розмежування ролей `admin` / `coach` / `client` (`users.role`).
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
- **Поточний стан:** бізнес-схеми в міграціях немає; у `database/migrations` лише `users`, `cache`, `jobs`. Схема існує виключно як SQL-модулі та DBML і **жодного разу не виконувалась** ([schema-audit.md](schema-audit.md), розділ 5.1).
- Рішення, яке потрібно ухвалити перед етапом 1 roadmap: як саме відтворювати схему міграціями (сирі SQL-інструкції vs Blueprint + `DB::statement` для PG-only конструкцій) і що робити з наявною міграцією `users`.
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

Поточний `.env` використовує `DB_CONNECTION=pgsql` (локальний PostgreSQL). Окремої тестової БД для PG-специфічних перевірок у репозиторії не налаштовано.

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
| Feature (API/HTTP) | PHPUnit, `tests/Feature` | Маршрути, авторизація, JSON-контракти, SPA-фолбек |
| Unit | PHPUnit, `tests/Unit` | Чисті розрахунки: масштабування порцій, округлення грамів, КБЖВ, список покупок |
| Схема БД | PHPUnit на тестовій PostgreSQL + SQL-сценарії | Обмеження, `EXCLUDE`, тригери, негативні сценарії публікації |

Наразі реалізовано: `HealthEndpointTest`, `SpaFallbackTest`, `ExampleTest` (Feature/Unit) — усі без звернень до бізнес-схеми.

**Обмеження:** `phpunit.xml` примусово використовує SQLite in-memory, який не підтримує `btree_gist`, `EXCLUDE`, часткові індекси в потрібному вигляді та PL/pgSQL-тригери. Тому тести схеми мають виконуватися на окремій тестовій PostgreSQL-БД; перемикання тестового середовища — окреме погоджене рішення. Мінімальний набір сценаріїв уже описано в [schema-audit.md](schema-audit.md), розділ 6.

## 15. Стан реалізації

| Область | Запроєктовано | Реалізовано | Доказ |
|---|---|---|---|
| Каркас Laravel | так | так | `bootstrap/app.php`, `routes/*`, `config/*` |
| Адмін-панель Filament | так | частково (логін + dashboard, без Resource) | `app/Providers/Filament/AdminPanelProvider.php` |
| SPA React/TS | так | частково (каркас + health-check) | `client/src/App.tsx`, `client/src/components/HealthStatus.tsx` |
| API | так | лише `GET /api/health` | `routes/api.php`, `app/Http/Controllers/Api/HealthController.php` |
| Бізнес-схема (37 таблиць) | так | ні | `docs/01…04_*.sql` (не виконувались) |
| Міграції бізнес-схеми | так | ні | `database/migrations` містить лише службові таблиці |
| Eloquent-моделі домену | так | ні | в `app/Models` лише `User` |
| Сервіси публікації/призначення | так | ні | — |
| Telegram-канал | так (пізній етап) | ні | — |
| Тести схеми на PostgreSQL | так | ні | `phpunit.xml` → SQLite |

## 16. Відомі ризики та технічний борг

1. **Схема не перевірена на PostgreSQL** — усі висновки статичні; тригери й обмеження можуть поводитися інакше на живій БД ([schema-audit.md](schema-audit.md), розділ 5.1).
2. **Розбіжність `users`**: міграція створює `name NOT NULL`, `email NOT NULL UNIQUE`, `password NOT NULL`, тоді як запроєктована таблиця має nullable `email`/`password` і додаткові `telegram_user_id`, `role`. Потрібне рішення до першої ж міграції домену.
3. **Тести на SQLite** не здатні перевірити PG-специфіку — потрібна тестова PostgreSQL-БД.
4. **`btree_gist` на cPanel/shared hosting** може бути недоступним — блокер розгортання.
5. **Черги й cron** на shared hosting — потрібна перевірка перед реалізацією відправки повідомлень.
6. **Відсутня автентифікація API**: `/api` наразі повністю публічний; контракт авторизації клієнта (сесія, токен, Telegram `initData`) не зафіксований.
7. **`NUMERIC(7,2)`** обмежує точність: правило округлення при масштабуванні потрібно документувати й тестувати (ризик `0.00` для дуже малих кількостей).
8. **11 відкритих питань** ([schema-audit.md](schema-audit.md), розділ 7) впливають на реалізацію і не вирішуються агентом самостійно.

## 17. Що свідомо поза межами v1

Повний модуль тренувань, плани завдань, збережені списки покупок (генеруються на льоту), альтернативні способи приготування, M:N-категорії, каталог раціонів для самостійного вибору клієнтом, інтеграції з фітнес-застосунками, feature toggles з окремими таблицями, самостійне ведення тренером-клієнтом самого себе. Деталі — [entity-catalog.md](entity-catalog.md), розділи 8 і 10.

## 18. Пов'язані документи

- [../AGENTS.md](../AGENTS.md) — правила для агентів.
- [business-rules.md](business-rules.md) — підтверджені бізнес-правила.
- [roadmap.md](roadmap.md) — план реалізації MVP.
- [entity-catalog.md](entity-catalog.md) — призначення сутностей.
- [database.dbml](database.dbml) — структура таблиць і полів.
- [schema-audit.md](schema-audit.md) — аудит, блокери, тести схеми, відкриті питання.


