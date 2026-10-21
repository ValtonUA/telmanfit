# UX-1 — українська адміністративна панель і мобільні картки

База: `master` після merge PR #8, `a319f678f35b74dd40908152ab20cc9d36339736`.

## Реалізовано

Панель `/admin` і її Livewire-запити використовують `uk` через штатний persistent middleware Filament/Laravel. Використано вбудовані українські переклади Filament, Laravel-файли повідомлень валідації та точкові `lang/vendor`-доповнення для відсутніх перекладів Filament 5 (зокрема ARIA-підписів). Заголовки перегляду/редагування містять ID картки, назва бренду — TelmanFit. Локаль API та SPA не змінюється.

Нижче 640 px штатний `stackedOnMobile()` перетворює рядки на компактні картки: ім’я, текстовий статус, ID, тренер, посилання перегляду й редагування. Email та дата початку доступні в деталях. Desktop зберігає всі колонки; довгі імена й email переносяться, дата початку у списку — `d.m.Y H:i` (UTC), у деталях залишено повний час. Пошук, фільтр, пагінація та сортування використовують одну таблицю й спільний Livewire-стан, включно з пошуком за email, прихованим на mobile. Мобільне сортування — штатні select Filament.

Статуси в таблиці й деталях: `pending` — нейтральний сірий, `active` — зелений `success`, `paused` — жовтий `warning`, `finished` — синій `info`. Текст завжди присутній. Аватар — локальна SVG-піктограма через `AvatarProvider`; запити до `ui-avatars.com` не потрібні.

## Перевірки

Усі дані синтетичні (`example.test`). Browser-раннер створює нову PostgreSQL 18.6 за зафіксованим digest, окрему непривілейовану роль і порожню БД. Контейнер має `network=none`, без портів, підключення — приватний Unix socket. Фікстура відмовляється працювати поза власною порожньою тестовою БД. Сесії браузера та cache/notification mailbox Filament зберігаються в цій самій тимчасовій БД. Після завершення раннер прибирає власний сервер, контейнер і тимчасовий каталог.

| Команда | Фактичний результат |
|---|---|
| `PAO_DISABLE=1 bash tests/pg/run-stage1.sh` | SQL: **PASS=76 FAIL=0 GAP=0**; PostgreSQL PHPUnit: **41 tests / 713 assertions**; основний PHPUnit: **22 passed / 46 assertions**; Pint успішний, warnings/skipped немає |
| `bash tests/pg/test-runner.sh` | Усі позитивні й негативні сценарії раннера успішні |
| `npm run lint --prefix client` | Успішно |
| `npm run build --prefix client` | Успішно |
| `NODE_USE_ENV_PROXY=1 npm run build` | Успішно, зібрано тему Filament; використано налаштований Cloud proxy |
| `bash tests/browser/run-ux1.sh after` | Chromium: 1440, 390, 320 px; вхід, dashboard/меню, список, пошук за email, порожній стан, фільтр/скидання, сортування, пагінація, перегляд, редагування, збереження/повторне читання, українська notification, помилка порожнього тренера, виправлення/повторне збереження та пошук тренера — успішно |

Збірка теми потребує звичайного `npm run build` при розгортанні. У Cloud Node 24 потребує `NODE_USE_ENV_PROXY=1` для завантаження вже налаштованих Bunny-шрифтів. Плагін шрифтів повідомляє про необов’язковий `fontaine`; нових залежностей не додано, збірка завершується успішно.

CI доповнено встановленням уже оголошених кореневих npm-залежностей і збіркою теми перед PHP-тестами. Це потрібно для HTTP-рендерингу `viteTheme()` у чистому checkout.

У Chromium після змін ширина документа дорівнює viewport: **320/320, 390/390, 1440/1440**. Внутрішній контейнер таблиці також не переповнюється: **288/288, 358/358, 1056/1056** (`clientWidth/scrollWidth`). Основні видимі посилання, кнопки й поля залишаються в межах viewport; перевірено також порожній/відфільтрований список, сортування, пагінацію, перегляд, редагування та пошук тренера. Кожен статус має видимий текст і відповідний колір; картка без акаунта й довге прізвище перевірені. JavaScript-помилок — **0**, запитів до `ui-avatars.com` — **0**, локальна SVG успішно завантажена.

## Реальні скриншоти Chromium

Скриншоти «до» знято на базовій ревізії перед змінами коду; «після» — на реалізації UX-1. Це реальний Chromium **154.0.8037.57** через Python Playwright, viewport 1440×900, 390×900 і 320×900, DPR=1. Зображення повної сторінки.

| Ширина | До | Після |
|---|---|---|
| 1440 px | [Список до](screenshots/ux-1/before-clients-1440.png) | [Список після](screenshots/ux-1/after-clients-1440.png) |
| 390 px | [Список до](screenshots/ux-1/before-clients-390.png) | [Список після](screenshots/ux-1/after-clients-390.png) |
| 320 px | [Список до](screenshots/ux-1/before-clients-320.png) | [Список після](screenshots/ux-1/after-clients-320.png) |

Додатково: вхід [1440](screenshots/ux-1/after-login-1440.png), [390](screenshots/ux-1/after-login-390.png), [320](screenshots/ux-1/after-login-320.png); перегляд [1440](screenshots/ux-1/after-view-1440.png), [390](screenshots/ux-1/after-view-390.png), [320](screenshots/ux-1/after-view-320.png); редагування [1440](screenshots/ux-1/after-edit-1440.png), [390](screenshots/ux-1/after-edit-390.png), [320](screenshots/ux-1/after-edit-320.png).

Машинні результати: [до](screenshots/ux-1/before-results.json), [після](screenshots/ux-1/after-results.json). Для повторення потрібні PHP/PDO PG, Docker, встановлені залежності проєкту, Python Playwright і `/usr/bin/chromium`; вони наявні в цьому Cloud, залежності проєкту для браузерного тесту не змінювалися. Режим `before` запускають на базовій ревізії з перенесеним лише `tests/browser`.

## Межі

Схему PostgreSQL, SQL/DBML, міграції, ролі, Policy, авторизацію, реєстрацію та MFA не змінено. Правило редагування `clients.user_id` збережене; його посилення належить окремій AUTH-задачі. Зміна моделі `Client` стосується лише українського тексту помилки. Нових бізнес-правил, довідників або залежностей немає. Міграції запускалися лише в нових ізольованих тестових БД; наявні БД не використовувалися.

UX-1 не закриває Stage 2 або відкриті auth-рішення. Питань, що блокують UX-1, немає. Повний аудит доступності зі скринрідером не проводився; браузерні перевірки охоплюють текст статусів, стандартні підписи керування, перенесення й доступність основних дій.

Помилки форми: [1440 px](screenshots/ux-1/after-validation-1440.png), [390 px](screenshots/ux-1/after-validation-390.png), [320 px](screenshots/ux-1/after-validation-320.png). Окремий [viewport 390 px](screenshots/ux-1/after-clients-390-viewport.png).

## Змінені та створені файли

- `.github/workflows/stage1.yml`
- `app/Filament/AvatarProviders/LocalAvatarProvider.php`
- `app/Filament/Resources/Clients/ClientResource.php`
- `app/Filament/Resources/Clients/Pages/EditClient.php`
- `app/Filament/Resources/Clients/Pages/ViewClient.php`
- `app/Http/Middleware/UseUkrainianLocale.php`
- `app/Models/Client.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `docs/roadmap.md`
- `docs/screenshots/ux-1/after-clients-1440.png`
- `docs/screenshots/ux-1/after-clients-320.png`
- `docs/screenshots/ux-1/after-clients-390-viewport.png`
- `docs/screenshots/ux-1/after-clients-390.png`
- `docs/screenshots/ux-1/after-edit-1440.png`
- `docs/screenshots/ux-1/after-edit-320.png`
- `docs/screenshots/ux-1/after-edit-390.png`
- `docs/screenshots/ux-1/after-login-1440.png`
- `docs/screenshots/ux-1/after-login-320.png`
- `docs/screenshots/ux-1/after-login-390.png`
- `docs/screenshots/ux-1/after-results.json`
- `docs/screenshots/ux-1/after-validation-1440.png`
- `docs/screenshots/ux-1/after-validation-320.png`
- `docs/screenshots/ux-1/after-validation-390.png`
- `docs/screenshots/ux-1/after-view-1440.png`
- `docs/screenshots/ux-1/after-view-320.png`
- `docs/screenshots/ux-1/after-view-390.png`
- `docs/screenshots/ux-1/before-clients-1440.png`
- `docs/screenshots/ux-1/before-clients-320.png`
- `docs/screenshots/ux-1/before-clients-390.png`
- `docs/screenshots/ux-1/before-results.json`
- `docs/stage-2.md`
- `docs/ux-1.md`
- `lang/uk/auth.php`
- `lang/uk/validation.php`
- `lang/vendor/filament-forms/uk/components.php`
- `lang/vendor/filament-notifications/uk/notification.php`
- `lang/vendor/filament-panels/uk/global-search.php`
- `lang/vendor/filament-panels/uk/layout.php`
- `lang/vendor/filament-panels/uk/resources/pages/list-records.php`
- `lang/vendor/filament-tables/uk/table.php`
- `lang/vendor/filament/uk/components/badge.php`
- `lang/vendor/filament/uk/components/breadcrumbs.php`
- `lang/vendor/filament/uk/components/loading-section.php`
- `public/images/admin-avatar.svg`
- `resources/css/filament/admin/theme.css`
- `tests/Feature/AdminLocaleTest.php`
- `tests/Postgres/ClientResourceTest.php`
- `tests/browser/fixture.php`
- `tests/browser/run-ux1.sh`
- `tests/browser/ux1.py`
- `vite.config.js`
