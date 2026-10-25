# TelmanFit — технічний стан реалізації

**Цей документ містить лише підтверджені факти коду й тестування, не черговість робіт.** Поточний пріоритет, статус кожного T/G/AUTH і відкриті рішення ведемо **тільки у [roadmap.md](roadmap.md)**. Правила оновлення — [AGENTS.md §16](../AGENTS.md#16-політика-актуальності-документації-обовязково-для-кожного-pr).

**Як читати:** наявна SQL-схема ≠ готовий бізнес-модуль; наявний код MFA ≠ дозвіл production. Перед твердженням звіряй відповідні `app/`, `client/`, `database/`, `tests/` та актуальний PR/CI.

## Підтверджені факти й межі

| Підсистема | Реалізовано / перевірено | Не реалізовано / не підтверджено | Джерело |
|---|---|---|---|
| Backend і UI | Laravel 13, Filament 5, React 19/TypeScript/Vite; health API, SPA fallback і адмінка `/admin` | Каркас ще не означає готовий MVP | `composer.json`, `client/package.json`, `routes/`, [architecture.md](architecture.md) |
| Бізнес-схема | Laravel-міграції 37 таблиць `01–04`, збереження `users` за B+R1; ізольована PG 18.6 з `btree_gist`, SQL `PASS=76 FAIL=0 GAP=0`, PG PHPUnit/CI | Це не реалізація продуктового UI; реальний Hyperhost не перевірено | [stage-1.md](stage-1.md), [schema-audit.md](schema-audit.md), [tests/pg](../tests/pg/README.md) |
| Клієнти та перша анкета | `Client`, `ClientPolicy`, Filament `/admin/clients`; React `/app/onboarding` з першими 3 реальними запитаннями, GET/POST `/api/onboarding`, PostgreSQL `client_onboardings.answers json` і перегляд у Filament | Це **частина** Google-форми, не завершений опитувальник; auth тільки controlled/local/testing. Жодного автоматичного `active` / `completed_at` | [stage-2.md](stage-2.md), [roadmap.md](roadmap.md), `app/Http/Controllers/Api/ClientOnboardingController.php`, `tests/Auth4/OnboardingSliceTest.php`, [докази](evidence/client-profiles/checks.json) |
| Профілі клієнтів | Forward-міграція додає typed профілі/CHECK, переносить goal і коректні дні, переводить answers у json та PK на client_id; нова реєстрація first_name/last_name, обчислюване full_name, адаптовані API/React/Filament | NOT NULL історичних імен відкладено до уточнення; legacy JSON/інвентар збережено, повне завершення анкети не визначено | [Контракт і міграція](client-profiles.md), `tests/Postgres/ClientProfilesTest.php`, `tests/Auth4/OnboardingSliceTest.php`, [докази](evidence/client-profiles/checks.json) |
| Авторизація | AUTH-3/4/5: Filament MFA, invitation, email OTP, client sessions, React auth/security; додаткові auth міграції та тести | **Controlled/default OFF**, не production; AUTH-6B B3–B6 лише guarded/synthetic, а не operational recovery | [auth3.md](auth3.md), [auth4.md](auth4.md), [auth5.md](auth5.md), [B3](auth6-b3.md)–[B6](auth6-b6.md) |
| Харчові довідники | Filament resources продуктів, категорій продуктів/рецептів/обладнання, UOM та кухонного обладнання; додаткові conversions/default у формі продукту. Атомарний food+ingredient, decimal strings, незмінний UOM code/службова семантика, CAS, актуальна admin Policy і чинна session/MFA межа. Delete заборонений; каталог не переписує accepted/published recipe history | Немає seed/Excel, SKU/фасувань чи нової схеми; початкові значення потребують окремого погодження. Каталоги незалежні від прапорця редактора, без production rollout | [Контракт](food-catalog.md), `tests/Postgres/FoodCatalogTest.php`, `tests/pg/food-catalog-concurrency.php`, `tests/browser/food_catalog.py` |
| Продукти/раціони | Additive batch-схема/guards, decimal A→B→C і чотири порції; атомарні Save/Refresh/Publish із signed preview/CAS/locks, read-only GET, pending provenance та explicit Refresh. Filament A/B/C, компоненти/ingredient та equipment tokens, stable equipment IDs, manual/review/reset, nullable min_value, ceil count/decimal volume, explicit adequacy ack, presets B exact/ceil/nearest. C1: clone published A/B/C у новий draft того самого recipe, нові child IDs/FK/tokens, frozen snapshots/policies, source lineage, скинуті review/adequacy acknowledgements; повторна публікація має новий food. C3: явний legacy preview/Refresh/confirm; draft переходить одноразово під SQL guard із originals у snapshots; GET/Save зберігають авторський порядок компонентів; published створює окремий draft того самого recipe без зміни source/food/планів. PG hash/rollback/concurrency та responsive Chromium перевірки — [докази C3](evidence/legacy-conversion/checks.json). C2: окрема personal recipe з owner/parent/source-version через спільний C1 clone; admin-only ACL, lineage, явний client/confirm, duplicate/concurrency/rollback; власна публікація без зміни history/assignments. PostgreSQL/Unit/Chromium перевірки — [контракт](recipe-batches.md) | Timer tokens потребують джерела duration (DEC-3T); G4-I selectors/readonly resolver описані окремо нижче; client/coach editor/self-service не реалізовано; feature flag OFF | [roadmap.md](roadmap.md), [business-rules.md](business-rules.md) |
| Раціони T4.1–T4.3 | Сім Eloquent-моделей, атомарний plan+draft, admin Policy/Service, штатні Filament list/create/view та exact-version week/day editor; генерація 7/14/21… днів із чинними типами, редагування прийомів; UX-4D видаляє будь-який повний draft тиждень за stable anchor/confirmation, зберігає ID та вміст наступних тижнів при перенумерації; locks/retry/stale guards, SQL/схема незмінні | Publish, призначення, CoW закриті; окремі прийоми з позиціями та published history не видаляються; копіювання тижнів (#61) не реалізовано; production не активовано | [Контракт](meal-plans.md), `tests/Postgres/MealPlanFoundationTest.php`, `tests/Postgres/MealPlanStructureTest.php`, `tests/Postgres/MealPlanWeekDeletionTest.php`, `tests/pg/meal-plan-week-deletion-concurrency.php`, `tests/pg/meal-plan-concurrency.php`, `tests/pg/meal-plan-structure-concurrency.php`, `tests/browser/meal_plans.py` |
| Прогрес/комунікація | Відповідні таблиці запроєктовано та частково включено в бізнес-міграції | Немає реалізованих UI/API прогресу, бізнес-повідомлень і Telegram adapter | [roadmap.md](roadmap.md), [entity-catalog.md](entity-catalog.md) |
| Інфраструктура | Hyperhost VPS Старт, SendPulse і зовнішні копії **обрані**; документаційний D8.1 preflight виконано | VPS/SMTP/backup restore не налаштовано й не доведено. Погоджений RPO до 24 год — ціль, не виміряний результат | [D8](auth6-d8-infrastructure.md), [D8.1](auth6-d8-preflight.md) |

## Докази й перевірки

`ClientProfilesTest` перевіряє SQL-відмову будь-якого UPDATE завершеної анкети
(зокрема `updated_at`, legacy `id`, no-op), незмінність всього рядка після відмови,
редагування/завершення чернетки та дозволений client cascade. Це доповнення PR #34;
результат прогону перевіряти в CI актуального head, попередні PASS його не замінюють.

Локальна Compose-збірка виключає host credentials та SQLite/DB-файли через
`.dockerignore`; CI перевіряє готовий образ із синтетичними маркерами виключених файлів.

`composer.lock` фіксує Livewire `4.4.8` замість `4.4.7`: patch для
[GHSA-mhf7-xp6r-6qrc](https://github.com/advisories/GHSA-mhf7-xp6r-6qrc).
Інші пакети, Composer constraints та `composer audit --locked` не змінено.
AUTH-1 зберігає точну перевірку встановлених версій; очікуваний Livewire оновлено
до `v4.4.8` разом із lock. Історичні звіти/логи попередніх прогонів не переписано.
Регресії PHP 8.4/8.5, auth/Filament/PostgreSQL та Chromium виконуються чинними
workflow; результат належить лише конкретному head SHA у Checks відповідного PR.

| Напрям | Де перевіряти | Межі |
|---|---|---|
| Бізнес-схема | `tests/pg/`, `phpunit.pgsql.xml`, [Stage 1](stage-1.md) | Підтвердження в ізольованій PostgreSQL не дорівнює production |
| Auth та browser | `phpunit.auth*.xml`, `tests/Auth*`, [AUTH-3](auth3.md), [AUTH-4](auth4.md), [AUTH-5](auth5.md), `docs/evidence/` | Тестові прапорці та синтетична доставка не означають activation |
| Локальна демонстрація | [Окремий PG18 local profile](local-demo.md), `LocalDemoEpochTest`, `OnboardingBoundaryTest`, `tests/browser/local_demo.py` та [browser checks](evidence/local-demo/checks.json) | Незалежний epoch-file лише для opt-in local; session/CSRF boundary анкети виправлено лише для local/testing. MFA/OTP обов’язкові за чинними правилами; Windows/WSL2 вручну не перевірено |
| Постійний Docker Compose | [Інструкція](local-compose.md), `LocalComposeEpochTest`, `tests/browser/local_compose.py` | Контейнерні PHP/Composer/Node, PG18, nginx; volumes для БД/ключів/epoch/storage. [CI](../.github/workflows/local-compose.yml) перевіряє точний head SHA: стандартний cold build без кешу/override, MFA/OTP/CSRF/fence та down/up. Результати попередніх перевірок — у [Compose evidence](evidence/local-compose/checks.json); Windows Desktop вручну не перевірено |
| Аудит карток клієнтів | [Stage 2 audit](stage-2-audit.md), `tests/Postgres/ClientResourceTest.php`, `tests/Auth4/Auth4Test.php` | Чотири додаткові регресійні тести: пошук за тренером, захист прив’язки/видалення та повторна перевірка ролі тренера перед реєстрацією; бізнес-поведінка незмінна |
| Вигляд Filament | [Штатні компоненти та перевірка теми](filament-theme.md), `tests/browser/filament_theme.py`; історичний [UX-1](ux-1.md) | Запрошення використовують штатні Blade-компоненти Filament 5; Chromium у disposable Compose перевіряє light/dark і 1440/390/320 px. Windows Desktop вручну не перевірено |
| Recovery/retention/budgets | [B3](auth6-b3.md), [B4](auth6-b4.md), [B5](auth6-b5.md), [B6](auth6-b6.md) | Підтверджені лише зазначені там synthetic/guarded операції |
| CI | Fast на кожному PR commit і push master; повний PostgreSQL/auth/browser/PHP matrix/cold Compose за явною `ci:full`; [контракт і докази](ci.md) | Merge лише після всіх success на поточному head через `scripts/ci/pre_merge.py`; без доступу до branch protection це агентський, не серверний захист |

## Правило підтримки

При зміні реалізації/тестів **у тому самому PR** змінюй конкретний факт або доказ у цьому файлі. Застаріле речення замінюй, **не додавай зверху ще один датований звіт**. Пріоритети та статуси тут не дублюються: [roadmap.md](roadmap.md) — єдина поточна шкала. Історія попередніх датованих звітів збережена в [Git history](https://github.com/ValtonUA/telmanfit/commits/master/docs/project-status.md) та модульних файлах `docs/auth*.md`, `docs/stage-*.md`.

## G4-I — Model I перед T4.3

Реалізовано forward-only міграцію й [SQL 14](14_meal_item_selectors.sql):
три nullable selectors, all-NULL/all-NOT-NULL CHECK, composite RESTRICT FK,
точний published/version/format/divisor guard із root lock та збереженими
measure/publication/immutability checks. Історичні SQL 01–13 не переписано,
backfill немає. `MealItemResolver` повертає типізовану readonly ідентичність
і decimal nutrition для ingredient/legacy/A/1/B/7/C/3/C/4, без mutable latest.
Unit і synthetic PG upgrade/negative/rollback/concurrency тести входять
у чинний runner. [Контракт і перевірки](meal-plans.md#model-i-g4-i-selectors-та-readonly-resolver).
Фактичний CI PASS належить SHA відповідного PR, не цьому опису.
Shopping/cooking не реалізовано; writer T4.3 використовує цей контракт, описаний нижче.
Робочі БД та feature flags не змінено; production rollout не авторизовано.


## Позиції раціону T4.3

Командний `MealItemWriter` і Filament exact-version actions використовують спільний
transaction/fence `MealPlanStructure` та готові Model I resolver/SQL guards. Доступні
ingredient із чинними conversions/default/grams, legacy serving та явні A/1 B/7 C/3 C/4,
редагування, порядок, перенесення в тій самій draft version, confirmation/cancel delete.
Readonly preview і summary мають єдине серверне decimal ядро. PG/Livewire та незалежні
concurrency сценарії входять у штатний Stage3/4 runner; Chromium перевіряє 1440/390/320
light/dark разом із чинною session/MFA/CSRF/revocation межею. Поведінка й перелік
перевірок — у [канонічному контракті](meal-plans.md#writer-позицій-t43); фактичний CI
перевіряти на head відповідного PR. Повторення тижнів, Publish/assignments/CoW,
seeds і production activation не реалізовані цим writer. Схема й feature flags незмінні.


## Огляд тижня та редактор дня UX-4E

Основний exact-version екран замінено семиденним оглядом із однією CTA на день,
видимими лише заповненими прийомами та mobile day switcher. Окрема Filament
сторінка exact дня має всі прийоми, каталог із серверним пошуком/фільтром і
пагінацією, явні порції, серверний nutrition preview, native drag/Pointer adapter
та доступні кнопки/меню. Додавання з каталогу потребує modal confirmation;
Published — readonly. Атомарний `MealItemWriter::place` з signed day snapshot
захищає перенос/порядок, stale/retry/ABA та exact-day ownership, використовуючи
чинні locks/fences/guards. T4.2/T4.3 forms залишені як fallback тієї самої boundary.
Нові PG/Livewire, independent concurrency та responsive Chromium тести входять
у чинний Stage3/4 runner. [Контракт](meal-plans.md#редактор-тижня-й-дня-ux-4e)
описує поведінку й обмеження; actual CI слід перевіряти на head PR.
Допрацювання PR #67 додає погоджений forward SQL15: create-only weekday у plan та nullable logical covers ingredients/recipes; порожній image allowlist не блокує роботу. SQL01–14, залежності, feature flags, production/Telmanfit_dev не змінені. Остаточні докази — на head PR.
