# TelmanFit — технічний стан реалізації

**Цей документ містить лише підтверджені факти коду й тестування, не черговість робіт.** Поточний пріоритет, статус кожного T/G/AUTH і відкриті рішення ведемо **тільки у [roadmap.md](roadmap.md)**. Правила оновлення — [AGENTS.md §16](../AGENTS.md#16-політика-актуальності-документації-обовязково-для-кожного-pr).

**Як читати:** наявна SQL-схема ≠ готовий бізнес-модуль; наявний код MFA ≠ дозвіл production. Перед твердженням звіряй відповідні `app/`, `client/`, `database/`, `tests/` та актуальний PR/CI.

**AUTH-6B/D3 design:** документує раніше обраних двох друзів як potential trustees і варіанти зовнішньої перевірки. Це лише дизайн; D3 operational readiness, trustee bootstrap/consent, реальні identities/keys, P5/P6, D8/B7 та production recovery не затверджено. Recovery через друзів наразі відкладено. [Рішення й межі](auth6-d3-design.md).

## Підтверджені факти й межі

### Зображення R2: підготовка локального застосування

Погоджені aliases охоплюють 85 реальних записів локальних каталогів: 47 ingredients,
37 kitchen_equipment і 1 recipes. Read-only перевірка виправленого matcher-а:
132 об’єкти R2, 85 confident, 0 ambiguous, 10 unmatched, 37 legacy equipment/ ignored.
Усі 85 запропонованих URL на assets.telmanfit.com.ua пройшли HTTPS HEAD 200
з image MIME та перевіркою TLS; allowlist перевірено у dry-run процесі.
Поточні 108 image_url залишилися NULL. Наявні app/worker/scheduler не
перезапускалися; перенесення PUBLIC_IMAGE_HOSTS у ці процеси — обов’язкова
передумова окремо погодженого rollout. Застосування до БД не виконувалося.

Локально: 19 Unit/Feature тестів (119 assertions), 20 ізольованих PostgreSQL18
тестів на synthetic таблицях (254 assertions), без міграцій/seeders;
Pint — 6 файлів PASS; CI selector — 5 тестів PASS. Повторне застосування, збереження URL у трьох каталогах,
канонічний префікс і відмова для недозволеного host перевірені.
Manifest тепер перевіряється автоматично за table/ID/name/key/URL перед
записом у тій самій PostgreSQL READ COMMITTED транзакції під блокуванням
трьох каталогів. Зміна набору або інший наявний URL блокує всю операцію;
реальний apply не виконувався.
[Механізм](r2-image-matching.md), [план і перевірки](r2-image-application-plan.md).

### T5.7b — schema/domain v1

FEASIBILITY PASS на master `66cb162ec343f8e03e5bd9f55f4826c30170127f`:
PG18.6, PHP8.5.12, Docker та GitHub доступні. Власник затвердив D1–D5 і
один повний наявний семиденний фрагмент без автоматичного DEC-5M.
Read-only preflight локального профілю: 2 recipes, 0 published recipe versions,
0 published meal plan versions; шість страв/раціон не підтверджено.
Робочі дані не експортувалися й не використовувалися в тестах.

[Контракт SQL17/18](cooking-templates.md) і forward-only міграції додають шість
нормалізованих таблиць, balances view, повний frozen menu pattern, immutable
published lineage, coverage/portion/selector guards, versioned JSONB blocks
та exact frozen quantity/explicit-sum resolver. Доменний admin review/publish
перевіряє revision, suitability/safety/manual parameters і чинний image allowlist.
Доменний read guard перевіряє explicit selection/assignment/owner/full week/requested mode/pattern;
Подальший T5.7b client slice додає ClientSession adapter і HTTP boundary; доказ
реалізації та стан перевірок описані в окремому рядку T5.7b нижче.

Синтетичний пілот: 1 template + 1 session + 6 recipes (legacy/A/B/C),
outputs/allocations, review/publish й обчислені залишки. Міграція застосована
до заповненої synthetic схеми; історичні recipe/menu таблиці порівнюються до/після.
PG18 тести також покривають reuse двом клієнтам і між наявними тижнями,
partial/revoked/foreign access, підготовлені режими 2/7, stale review,
immutable descendants, JSON і token відмови. Актуальні результати локальних
перевірок: PG18 **12 tests / 211 assertions PASS**; стандартний backend
**119 passed / 490 assertions**, 3 SPA tests skipped через відсутність React build
у disposable копії. Використано справжні наявні admin build assets локального
image, не нову frontend збірку. Повний migration path на disposable PG18 та
FK/BIGINT regression — **1 test / 30 assertions PASS** (17 FK, включно з двома
новими cooking user references). CI selector (5 tests),
docs guard із PR base та SQL projection equality — PASS. CI на exact head
фіксується в описі окремого Draft PR.

Незалежний process-concurrency runner: **37 scenarios PASS** у disposable PG18,
три PHP/PDO workers із різними backend PID та доведеними lock waits. Перевірено
service/SQL edit-review-publish, racing sessions/outputs/allocations, double publish,
create-next, READ COMMITTED/REPEATABLE READ/SERIALIZABLE revision conflicts,
selection CAS/replacement/ABA та MVCC read snapshot. Справжній 40P01 victim і
невдала публікація відкочують усі detail/review/revision writes; автоматичного
retry старого intent немає. Негативні probes підтверджують exit=1 для небезпечного
DB environment та standalone exception. Тести включені в чинну recipe CI group.
B/C token labels лишаються frozen після зміни UOM довідника; A/grams мають
канонічні `г`, legacy quantity label явно unavailable для наступного API.

Filament/React/API, local progress, timers/wake lock, Filament→телефон walkthrough,
production activation та реальні міграції не виконувалися. SQL-функція доступу
не є RLS або автентифікацією. Придатність інструкцій і відсутність технологічної
води в ingredient tokens підтверджує автор: machine-readable water classifier
у чинному каталозі немає. Семантична перевірка безпеки не автоматизується.

### INFECTION-1

27.10.2026, **FEASIBILITY: PASS** на master
`f2a9146ff958e0f0f1a00a6a97c2a24bc142fa81`: PR #82 merged, CLI Docker доступний,
PHP **8.5.12**, PCOV **1.0.12**, PHPUnit **12.5.40**, Infection **0.35.8**.
Початковий реальний PHPUnit/PCOV запуск: **31 tests, 85 assertions**; мінімальний
справжній mutation run довів працездатність Infection без мережі й БД.
SHA256 обох класів і початкових тестів CLI-образу звірено з актуальним checkout.
Окремий worktree/branch `test/infection-1`; паралельний T5.2 не змінювався.

Фінальний набір mutators визначено **до змін тестів**; попередній розвідувальний
прогін без integer mutators не є baseline таблиці. Обидва фінальні прогони
використовують ті самі 10 mutators, два класи, один thread, timeout 10 s та
справжній заново зібраний coverage; без skip-initial-tests і MSI threshold.
MSI нижче — точне значення `summary.json`, CLI округлює його до цілих.

| Показник | До | Після |
|---|---:|---:|
| Згенеровані мутації | 104 | 104 |
| Killed PHPUnit | 87 | 99 |
| Survived / escaped (одна категорія) | 17 | 5 |
| Uncovered / timed-out / error / syntax-error | 0 / 0 / 0 / 0 | 0 / 0 / 0 / 0 |
| Skipped / ignored / killed static analysis | 0 / 0 / 0 | 0 / 0 / 0 |
| MSI / covered MSI | 83.65% | 95.19% |
| Mutation code coverage | 100% | 100% |
| Цільові PHPUnit tests / assertions | 31 / 85 | 43 / 103 |

Line coverage не змінився: BatchEvaluator **75/77 (97.40%)**, MealItemPortion
**9/10 (90.00%)**. Це демонструє прогалини assertions навіть за високого line coverage;
100% mutation coverage стосується лише вибраних мутацій, а не всього застосунку.

**Адресні тести закрили 12 із 17 початкових survivors:**

| Реальна прогалина | Перевірка |
|---|---|
| Валідація divisor=0 та quantity=0 маскувалася відсутніми nutrients | MealItemPortion: валідні чотири nutrients, окремі zero/negative divisor та zero quantity; саме DomainException |
| Snapshot допускав третій десятковий знак без реакції тесту | BatchEvaluator: `kcal=1.001` відхиляється до розрахунку |
| Не перевіряли `single.quantity`, A/1, дільники A/1 і B/7 | Повна single identity та всі чотири nutrients для A/1 і B/7 |
| Не було повної таблиці `incomplete` | Усі чотири комбінації rows/pending, включно з complete=false та empty=true |
| Exact auto C втрачав півсотих або відхиляв валідну точність | A=0.03 → B=0.21 → target C=0.105 → quantity/grams=0.11, kcal=0.135795 |
| Занадто грубе compatibility rounding відхиляло дозволену верхню межу | A=100 g, kcal=99999.99 → single total=99999.990000 та A/1=99999.99 |

**П'ять залишків не виключено з MSI.** Усі в BatchEvaluator; класифікація —
предметний аналіз чинного контракту, а не доказ еквівалентності для майбутніх змін:

| ID (початок) / місце / mutator | Висновок |
|---|---|
| `e4591839`, line 28, IncrementInteger | Scale target C 3→4 зберігає точне B/2, але додає нуль у `target_grams` (0.105→0.1050). Це **спостережувана різниця представлення**, не повністю еквівалентний PHP output. Фіксованого scale target контракт не задає; кількості, nutrition, context/review не змінюються. Не вводили нову бізнес-вимогу заради MSI. |
| `7c5c9028`, line 58, IncrementInteger | Проміжний scale 6→7 після scale2×scale2/100; потім результат канонізується до 6. Для дозволених inputs числової чи вихідної різниці немає. |
| `1b1ca7f5`, line 66, IncrementInteger | Validation scale 2→3 після явного toScale(2); return цієї перевірки відкидається, діапазон незмінний. |
| `2a15baf4`, line 106, LogicalOr | Ослаблення раннього type guard для rounding дублюється строгим in_array policy guard. Невалідний тип усе одно дає InvalidArgumentException; текст повідомлення може відрізнятися. Додано non-string-rounding case, але текст не є контрактом. |
| `212fce86`, line 140, DecrementInteger | Негативна межа non-positive decimal 0→−1 недосяжна після regex, який пропускає лише невід'ємні числа. Positive гілка незмінна. |

**Перевірки:** цільові PHPUnit — 43/103 PASS; `php -l` обох тестів та
`vendor/bin/pint --test tests/Unit/BatchEvaluatorTest.php tests/Unit/MealItemPortionTest.php`
— PASS. Додатковий весь Unit suite — **76 tests, 283 assertions, exit 0** у
disposable `/tmp/validation` із поточними app/tests, Laravel bootstrap/config/routes/resources,
package discovery та порожнім synthetic `.env`. Початкова спроба ширшого suite в
мінімальному CLI-образі виявила відсутній bootstrap, наступна — warning відсутнього
`.env`; обидва обмеження усунено лише у disposable копії, не в репозиторії.
Docs guard із PR base — PASS. GitHub CI на фінальному head фіксується в описі Draft PR.

Composer додав **18 installs, 0 updates, 0 removals** тільки в тимчасовому похідному
образі; manifests застосунку та головний app-image незмінні. Тести/мутації працювали
як www-data, `NetworkMode=none`, `Mounts=[]`; writable disposable layer потрібний
для експорту `/tmp/results`. БД не використовувалася. Coverage/XML/JUnit, mutation
JSON/logs, tooling lock і stdout збережено лише локально в gitignored
`.phpunit.cache/infection-1/`. Власні контейнери та тимчасовий image видалено після
експорту; CLI base збережено. Compose, telmanfit-local/volumes, PostgreSQL, MFA/auth
та production flags не змінювалися. Команди й обрані mutators — [infection-env.md](infection-env.md#пілот-infection-1).

### INFECTION-ENV

Окремий [CLI Dockerfile](../docker/infection/Dockerfile) перевірено локально:
PHP 8.5.12, PCOV 1.0.12, PHPUnit 12.5.40. PHPUnit повідомив
`Runtime: PHP 8.5.12 with PCOV 1.0.12`; тільки `BatchEvaluatorTest` та
`MealItemPortionTest`: **31 tests, 85 assertions, exit 0**.
Справжній line coverage: `BatchEvaluator` — **75/77 (97.40%)**,
`MealItemPortion` — **9/10 (90.00%)**. Загальне `app` — 84/5279 (1.59%);
це результат лише цих двох наборів. Згенеровано Clover, PHP, PHPUnit XML і JUnit;
локальний експорт — `.phpunit.cache/infection-env/` (не комітиться).
Тести пройшли з `--network none --read-only --tmpfs /tmp`; окремий export-контейнер
мав `NetworkMode=none`, `Mounts=[]`, exit 0 і був видалений після копіювання звітів.

Packagist/Composer визначив стабільну Infection **0.35.8** (`php ^8.3`).
Dry-run на копії чинного lock: **18 installs, 0 updates, 0 removals**,
PHPUnit 12.5.40 збережено. Тимчасове встановлення з вимкненими scripts/plugins
і `php vendor/bin/infection --version` підтвердили запуск 0.35.8;
`composer --no-plugins check-platform-reqs` — PASS.
У межах INFECTION-ENV мутації та інтеграційний запуск PHPUnit через Infection
не виконувалися; справжній пілот і його межі наведені в INFECTION-1 вище.
Робочий app-image залишився
`sha256:97a4bc5a90e29eaa682169af5f12a2782392e2f78c7370babf40bd103af1b86b`.
Compose, робочі контейнери/volumes, PostgreSQL, MFA/auth і CI не змінено.
[Команди та межі відтворення](infection-env.md).

### Продуктові підсистеми

| Підсистема | Реалізовано / перевірено | Не реалізовано / не підтверджено | Джерело |
|---|---|---|---|
| Backend і UI | Laravel 13, Filament 5, React 19/TypeScript/Vite; health API, SPA fallback і адмінка `/admin` | Каркас ще не означає готовий MVP | `composer.json`, `client/package.json`, `routes/`, [architecture.md](architecture.md) |
| Бізнес-схема | Laravel-міграції 37 таблиць `01–04`, збереження `users` за B+R1; ізольована PG 18.6 з `btree_gist`, SQL `PASS=76 FAIL=0 GAP=0`, PG PHPUnit/CI | Це не реалізація продуктового UI; реальний Hyperhost не перевірено | [stage-1.md](stage-1.md), [schema-audit.md](schema-audit.md), [tests/pg](../tests/pg/README.md) |
| Клієнти та перша анкета | `Client`, `ClientPolicy`, Filament `/admin/clients`; React `/app/onboarding` з першими 3 реальними запитаннями, GET/POST `/api/onboarding`, PostgreSQL `client_onboardings.answers json` і перегляд у Filament | Це **частина** Google-форми, не завершений опитувальник; auth тільки controlled/local/testing. Жодного автоматичного `active` / `completed_at` | [stage-2.md](stage-2.md), [roadmap.md](roadmap.md), `app/Http/Controllers/Api/ClientOnboardingController.php`, `tests/Auth4/OnboardingSliceTest.php`, [докази](evidence/client-profiles/checks.json) |
| Профілі клієнтів | Forward-міграція додає typed профілі/CHECK, переносить goal і коректні дні, переводить answers у json та PK на client_id; нова реєстрація first_name/last_name, обчислюване full_name, адаптовані API/React/Filament | NOT NULL історичних імен відкладено до уточнення; legacy JSON/інвентар збережено, повне завершення анкети не визначено | [Контракт і міграція](client-profiles.md), `tests/Postgres/ClientProfilesTest.php`, `tests/Auth4/OnboardingSliceTest.php`, [докази](evidence/client-profiles/checks.json) |
| Авторизація | AUTH-3/4/5: Filament MFA, invitation, email OTP, client sessions, React auth/security; додаткові auth міграції та тести | **Controlled/default OFF**, не production; AUTH-6B B3–B6 лише guarded/synthetic, а не operational recovery | [auth3.md](auth3.md), [auth4.md](auth4.md), [auth5.md](auth5.md), [B3](auth6-b3.md)–[B6](auth6-b6.md) |
| Харчові довідники | Filament resources продуктів, категорій продуктів/рецептів/обладнання, UOM та кухонного обладнання; додаткові conversions/default у формі продукту. Атомарний food+ingredient, decimal strings, незмінний UOM code/службова семантика, CAS, актуальна admin Policy і чинна session/MFA межа. Додано nullable `kitchen_equipment.image_url` і CLI dry-run/apply зіставлення R2 за назвою та alias-словником; штатний binding R2ObjectSource → R2ObjectLister, контрольовані конфігураційні/мережеві/XML помилки та захист пагінації | Зіставлення актуальних R2/робочих каталогів не підтверджено: 132 ключі — історична інвентаризація від 26.10.2026. Перевірки адаптера та CLI використовують синтетичні HTTP/source fixtures; production credentials не використовуються. Apply не виконувався; Додано [local-only XLSX-v1](excel-nutrition-import.md): каталоги + одна нова batch-чернетка через штатні writers, dry-run/SHA256/private crosswalk; без автоматичних review/publish. Канонічного seed, SKU/фасувань чи production rollout немає | [Контракт](food-catalog.md), [інвентаризація R2](r2-image-matching.md), `tests/Postgres/R2ImageMatcherTest.php`, `tests/Postgres/FoodCatalogTest.php`, `tests/pg/food-catalog-concurrency.php`, `tests/browser/food_catalog.py` |
| Продукти/раціони | Additive batch-схема/guards, decimal A→B→C і чотири порції; атомарні Save/Refresh/Publish із signed preview/CAS/locks, read-only GET, pending provenance та explicit Refresh. Filament A/B/C, компоненти/ingredient та equipment tokens, stable equipment IDs, manual/review/reset, nullable min_value, ceil count/decimal volume, explicit adequacy ack, presets B exact/ceil/nearest. C1: clone published A/B/C у новий draft того самого recipe, нові child IDs/FK/tokens, frozen snapshots/policies, source lineage, скинуті review/adequacy acknowledgements; повторна публікація має новий food. C3: явний legacy preview/Refresh/confirm; draft переходить одноразово під SQL guard із originals у snapshots; GET/Save зберігають авторський порядок компонентів; published створює окремий draft того самого recipe без зміни source/food/планів. PG hash/rollback/concurrency та responsive Chromium перевірки — [докази C3](evidence/legacy-conversion/checks.json). C2: окрема personal recipe з owner/parent/source-version через спільний C1 clone; admin-only ACL, lineage, явний client/confirm, duplicate/concurrency/rollback; власна публікація без зміни history/assignments. PostgreSQL/Unit/Chromium перевірки — [контракт](recipe-batches.md) | Timer tokens потребують джерела duration (DEC-3T); G4-I selectors/readonly resolver описані окремо нижче; client/coach editor/self-service не реалізовано; feature flag OFF | [roadmap.md](roadmap.md), [business-rules.md](business-rules.md) |
| Раціони T4.1–T4.3 | Атомарний plan+draft та сім днів із вибраними чинними типами, з квитанцією повторного запиту UX-3; Filament list/create/view та exact-version week/day editor; генерація наступних тижнів, редагування прийомів, UX-4D видалення тижня, locks/retry/stale guards | Призначення — T4.5 нижче; CoW — T4.6 нижче; published history та assignments не змінюються; additive SQL20 потребує окремого погодженого плану для наявної БД | [Контракт](meal-plans.md), `tests/Postgres/MealPlanCreationRequestTest.php`, `tests/pg/meal-plan-creation-concurrency.php`, `tests/browser/meal_plans.py` |
| Публікація раціону T4.4 | `MealPlanPublisher`: preflight усіх тижнів/днів/прийомів/items, конкретні помилки та exact Model I/decimal grams; HMAC confirmation, fence/actor/version/hierarchy/catalog locks, atomic status/number/date, deferred rollback, duplicate rejection. Filament «Керування → Опублікувати» в огляді та header-меню дня через спільну action; PG/Livewire і Chromium перевіряють day confirmation/cancel/stale/ACL та redirect до readonly огляду; exact selectors, frozen recipes та історія зберігаються | Без нової схеми, assignments/CoW, зміни flags або production activation. Локальні результати та CI прив’язані до head PR | [Контракт](meal-plans.md#публікація-точної-версії-t44), `tests/Postgres/MealPlanPublicationTest.php`, `tests/pg/meal-plan-publication-concurrency.php`, `tests/browser/plan_publication.py` |
| Призначення раціонів T4.5 | `MealPlanAssigner`: exact published/owner/admin, inclusive dates, DEC-4A заміна або окремий період, атомарні end/link/new/event, runtime/actor/version/plan/client/assignment locks, HMAC/MVCC stale/replay. Картка клієнта → історія раціонів → форма → окремий preview/confirm; українські конфлікти | Без схеми, глобальної таксономії подій, зміни статусів супроводу, SPA/Telegram/flags чи production. Full CI не вимагається чинною політикою PR | [Контракт DEC-4A](meal-plans.md#призначення-клієнтам-t45-dec-4a), `tests/Postgres/MealPlanAssignmentTest.php`, `tests/pg/meal-plan-assignment-concurrency.php`, `tests/browser/meal_plan_assignments.py` |
| Персональні раціони T4.6 | `MealPlanCopier`: exact published → owner/parent + незалежний draft, deep copy days/meals/items зі збереженням Model I, HMAC/MVCC replay, lineage/admin/fence, атомарність; Filament preview/cancel/confirm/redirect. Цільові PG/Livewire перевірки: lifecycle 7/14/21, SQL rollback, Model I, publication/T4.5; редагування копії штатним writer зі збереженням повного source та історії двох клієнтів. PG, independent concurrency та Chromium T4.6 1440/390/320 light/dark включені в recipe-групу Targeted CI | Без схеми, автопризначень, зміни flags або production. Предметний review та Fast/Docs/Targeted необхідні перед merge; фізичний mobile не перевірено | [Контракт](meal-plans.md#персональні-копії-t46), `tests/Postgres/MealPlanCopyTest.php`, `tests/pg/meal-plan-copy-concurrency.php`, `tests/browser/meal_plan_copy.py` |
| Клієнт API T5.1 | `GET /api/meal-plan`: чинна `ClientSession`, exact published/owner, включні дати DEC-4A, коректний empty state; профіль через існуючий `/api/me`. HTTP GET/HEAD не зберігає session metadata й не запускає GC; PostgreSQL-тести порівнюють усі public tables/xmin та перевіряють відсутність DML | AUTH-4/5 controlled/local/testing, production OFF. Без нової схеми, React, днів/рецептів/покупок і T5.2–T5.8; цей endpoint не продовжує idle TTL. Локальні результати не замінюють required CI на head | [API-контракт і локальні перевірки](meal-plans.md#клієнтське-читання-t51), `tests/Auth4/ClientMealPlanTest.php` |
| Клієнтський раціон T5.2 | Захищений `GET /api/meal-plan/days`, календар за днем тижня тренера й межами призначення; exact published/Model I, batched SELECT, server quantities/UOM, nullable logical covers та CSP exact hosts. React `/app/meal-plan` із чинного кабінету: weeks/days, loading/empty/error/retry, assignment refresh і session expiry; responsive light/dark | Recipe-картки переходять до екрана T5.4 за meal item ID. Немає dedup меню, циклів, КБЖВ, покупок чи перебудови SPA; production OFF | [Контракт](meal-plans.md#клієнтський-календар-і-меню-t52), [перевірки та скриншоти](evidence/client-meal-plan/report.md) |
| Покупки T5.3 | `GET /api/meal-plan/shopping-list`: exact активне призначення, авторський тиждень, ingredient/legacy/A/B/C, точна агрегація без float, frozen UOM та явна відмова замість часткового списку; GET/HEAD без DML/session GC | Без UI, закупівельного округлення, нової схеми, історії чи кухонних сесій; AUTH controlled і production OFF | [Контракт](shopping-lists.md), [локальні перевірки](evidence/shopping-lists/report.md), `tests/Auth4/ClientShoppingListTest.php` |
| Перегляд рецептів T5.4 | ClientSession read-only API за meal item, exact published/owner/календар T5.2; physical legacy/A/B/C, frozen quantities й equipment, RecipeTokens plain text, безпечні фото. React екран із прямим URL/reload, поверненням до week/day і станами доступу | Без нової схеми/залежностей, router, КБЖВ, таймерів, kitchen sessions; production controlled OFF | [Контракт](client-recipes.md), [перевірки та скриншоти](evidence/client-recipe/report.md) |
| Клієнтський shell T5.5 | Чотири нижні вкладки «Сьогодні», «Раціон», «Корисне», «Прогрес» та профіль через аватар. «Сьогодні» використовує server `today` та реальні страви з T5.2, без КБЖВ; є retry/empty/revoked, exact рецепт. Чинні маршрути та `api.ts` збережені, винесено типізоване читання `mealPlanApi.ts`. AUTH5 і браузерні T5.2 сценарії адаптовано | На етапі T5.5 вкладки «Корисне» й «Прогрес» були порожніми; «Корисне» реалізовано окремо в T5.6, «Прогрес» ще очікує Stage 6; без нової схеми, router/dependencies, Telegram/тренувань/звітів. Production activation окремо; squash merge PR #89, required Fast/Docs/Targeted PASS на `40a0714c` | [UX-контракт](client-app.md), [PR #89](https://github.com/ValtonUA/telmanfit/pull/89), `client/src/components/Today.tsx`, `tests/browser/auth5.py`, `tests/browser/client_meal_plan.cjs` |
| Бібліотека «Корисне» T5.6 | Єдина таблиця `library_items` (article/video/playlist/instruction), `draft/published` із SQL CHECK; admin-only Filament CRUD без видалення; ClientSession `GET /api/library` та `GET /api/library/{id}`, приховування чернеток/знятих публікацій, `no-store, private`; безпечний Markdown/HTTPS/зображення; React каталог і деталі з retry/direct URL | Без персональних рекомендацій, upload файлів, змін auth/meal plan; client/API та Filament тести в ізольованій PG18 і Chromium. Required Fast/Docs/Targeted PASS на фінальному head `adb9c943` перед squash merge [PR #92](https://github.com/ValtonUA/telmanfit/pull/92); production і робочу БД не мігрували | [Контракт](library.md), `app/Models/LibraryItem.php`, `app/Http/Controllers/Api/ClientLibraryController.php`, `tests/Auth4/ClientLibraryTest.php`, `tests/Postgres/LibraryFilamentTest.php`, `client/src/components/Library.tsx` |
| T5.7a — екран покупок | UI над чинним [T5.3 API](shopping-lists.md): тижневий вибір, точні харчові потреби без округлення, source UOM, error/retry/empty; frontend `ShoppingList.tsx` і браузерні synthetic перевірки | Змерджено [PR #94](https://github.com/ValtonUA/telmanfit/pull/94), squash `33ad5c218da712a3c5869fa4bb0ee8e8d1f97716`, Fast/Docs/Targeted PASS на head `9f10713d`. Без нових таблиць/API, категорій, SKU/округлень, чекбоксів чи cooking sessions; production OFF | [UX-контракт](shopping-list-ui.md), `client/src/components/ShoppingList.tsx`, `tests/browser/recipe-client.py` |
| T5.7b — гібридні токени й client helper | Frozen token/physical outputs та explicit selection; авторське обладнання з каталоговим ID/frozen name+image snapshot; ClientSession read-only API, React player і browser-only cursor/timers | SQL17/18 + Filament у PR #98/#99. Локальні PG18/Auth4/no-store/HEAD/no-write та Filament→ClientSession→React Chromium перевірки пройдено; точні A/B/C quantities та physical-output aggregation захищені авторизованими JSON регресіями; Wake Lock pending/release/stale lifecycle, навігація player/галереї та dark-контраст захищені browser assertions. [Результати й межі](evidence/cooking-editor/report.md); required CI перевіряється на фінальному Draft PR head. Production activation/database operations не виконуються | [Канонічна пропозиція](proposals/t5-7b-cooking-sessions.md), [API/UX](cooking-templates.md#read-only-client-api-та-помічник) |
| Прогрес/комунікація | Відповідні таблиці запроєктовано та частково включено в бізнес-міграції | Немає реалізованих UI/API прогресу, бізнес-повідомлень і Telegram adapter | [roadmap.md](roadmap.md), [entity-catalog.md](entity-catalog.md) |
| Інфраструктура | Hyperhost VPS Старт, SendPulse і зовнішні копії **обрані**; документаційний D8.1 preflight виконано. Для Windows Docker Desktop локальні оновлення коду й allowlisted міграцій керуються `scripts/local-update.ps1`; локальні backup/restore drills не є production backup | VPS/SMTP/production backup restore не налаштовано й не доведено. Погоджений RPO до 24 год — ціль, не виміряний результат. Coordinator не змінює production та не виконує автоматичний rollback/restore | [Docker Compose](local-compose.md), [D8](auth6-d8-infrastructure.md), [D8.1](auth6-d8-preflight.md) |

## Докази й перевірки

T4.5: локальні PostgreSQL/Livewire **20 PASS / 264 assertions**, concurrency **9 PASS**
із lock waits, Chromium **8 груп PASS** (1440/390/320 light/dark, stale/CSRF/revocation),
Pint і docs guard PASS. [Предметний review, файли та межі](evidence/meal-plan-assignments/report.md).
T4.5 змерджено після Fast, Docs і Targeted; Full CI не є чинною вимогою.
QA-1 усуває помилкове приймання фонового Livewire notification response у T4.5
browser helper: очікується method відповідної дії та `succeed/onRender`, без
зміни timeout або бізнес-assertions. [Результати й межі QA-1](evidence/qa-1/report.md):
browser5/5 та окремий штатний cold infrastructure acceptance на GitHub-hosted
runner завершені; cleanup власного stack підтверджений, production activation не виконано.

CI-COST-2 додає bounded виконання по дві ізольовані PG/browser групи в одному job,
процесний OPcache лише для synthetic Stage1 Chromium HTTP-серверів,
окремі setup/group timings і збережені recipe/baseline stdout artifacts. SQL fixtures,
міграції, test methods/assertions, PG ізоляція, обидва PHP runtime та cold Compose
збережені. Legacy `week-fill` fixture явно обирає `legacy_v1`, щоб фізичний порядок
published batch/legacy rows не змінював тестовий сценарій. Browser orchestration
очікує завершення Filament overlay animations перед меню/mobile overflow assertion.
[Виміри й обмеження](evidence/ci-cost-2/report.md); статус — [roadmap](roadmap.md).
Після timeout першого Full додано navigation `load` без додаткової network silence,
actual Livewire render readiness, три Chromium negative/async тести та hook-event
assertion; прибрано лише повторний static Pint, збережений у required matrix.
Workers мають private compiled views/PHPUnit cache та окремі loopback ports;
fail-fast/signal/owned-container recovery перевірено негативними tests і реальним
PG18 cancellation probe. Нова кількість billed jobs не додається.
Full CI вилучено; для PR обов’язкові Fast, Documentation health і Targeted на актуальному head.


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
На PR виконуються Fast і Documentation health; required Targeted обирає auth/Filament/PostgreSQL/Chromium регресії за diff та не піднімає PG/browser runner для нерелевантних змін. PHP 8.4/8.5 matrix і cold Compose доступні лише для локальної діагностики; Full CI не запускається.

| Напрям | Де перевіряти | Межі |
|---|---|---|
| Бізнес-схема | `tests/pg/`, `phpunit.pgsql.xml`, [Stage 1](stage-1.md) | Підтвердження в ізольованій PostgreSQL не дорівнює production |
| Auth та browser | `phpunit.auth*.xml`, `tests/Auth*`, [AUTH-3](auth3.md), [AUTH-4](auth4.md), [AUTH-5](auth5.md), `docs/evidence/` | Тестові прапорці та синтетична доставка не означають activation |
| Локальна демонстрація | [Окремий PG18 local profile](local-demo.md), `LocalDemoEpochTest`, `OnboardingBoundaryTest`, `tests/browser/local_demo.py` та [browser checks](evidence/local-demo/checks.json) | Незалежний epoch-file лише для opt-in local; session/CSRF boundary анкети виправлено лише для local/testing. MFA/OTP обов’язкові за чинними правилами; Windows/WSL2 вручну не перевірено |
| Постійний Docker Compose | [Інструкція](local-compose.md), `LocalComposeEpochTest`, `tests/browser/local_compose.py` | Контейнерні PHP/Composer/Node, PG18, nginx; volumes для БД/ключів/epoch/storage. [CI](../.github/workflows/local-compose.yml) перевіряє точний head SHA: стандартний cold build без кешу/override, MFA/OTP/CSRF/fence та down/up. Результати попередніх перевірок — у [Compose evidence](evidence/local-compose/checks.json); Windows Desktop вручну не перевірено |
| Аудит карток клієнтів | [Stage 2 audit](stage-2-audit.md), `tests/Postgres/ClientResourceTest.php`, `tests/Auth4/Auth4Test.php` | Чотири додаткові регресійні тести: пошук за тренером, захист прив’язки/видалення та повторна перевірка ролі тренера перед реєстрацією; бізнес-поведінка незмінна |
| Вигляд Filament | [Штатні компоненти та перевірка теми](filament-theme.md), `tests/browser/filament_theme.py`; історичний [UX-1](ux-1.md) | Запрошення використовують штатні Blade-компоненти Filament 5; Chromium у disposable Compose перевіряє light/dark і 1440/390/320 px. Windows Desktop вручну не перевірено |
| Recovery/retention/budgets | [B3](auth6-b3.md), [B4](auth6-b4.md), [B5](auth6-b5.md), [B6](auth6-b6.md) | Підтверджені лише зазначені там synthetic/guarded операції |
| CI | Fast, Documentation health і Targeted на кожному PR; Full workflow і тригер вилучені, `pre_merge.py` перевіряє лише три required статуси на актуальному head; [контракт і докази](ci.md) | PHP runtime matrix та cold Compose залишаються локальними наборами; ruleset вимагає Fast/Docs/Targeted |

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
Shopping API та cooking schema/domain описані у відповідних поточних розділах вище;
writer T4.3 використовує цей контракт і описаний нижче.
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
перевіряти на head відповідного PR. Повторення тижнів — окремий T4.3R нижче;
публікація — T4.4 у таблиці вище. Assignments/CoW, seeds і production activation
не реалізовані цим writer. Схема й feature flags незмінні.


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


## Повторення тижнів T4.3R

`MealPlanWeekRepeater` та Filament week/day action створюють незалежні фізичні
копії семиденних блоків у точній draft version зі збереженням exact food/recipe
selectors, кількостей/UOM/грамів і порядку. Двоетапна заміна всіх наявних цільових тижнів, навіть за 0 позицій,
показує фактичні номери та кількість прийомів/позицій; HMAC snapshot/grant, PostgreSQL row revisions,
спільні locks/fence/fresh admin та deferred rollback захищають від retry,
конкурентних правок і часткового запису. Регресія покриває редаговані прийоми
без страв, cancel/confirm/stale та створення відсутніх цілей без зайвого діалогу.
Поведінка часткового джерела й діапазонів —
у [канонічному контракті](meal-plans.md#повторення-тижнів-у-чернетці-t43r).

Автоматизовані докази: `MealPlanWeekRepetitionTest`,
`tests/pg/meal-plan-week-repetition-concurrency.php`,
`tests/browser/week_repetition.py`; вони включені в чинний Stage3/4 runner.
Результати локального прогону та CI належать точному head SHA у PR.
Нових таблиць/міграцій/залежностей немає. Published/історичні призначення,
каталоги, feature flags, R2 та робочі середовища не змінюються. Публікація T4.4
описана в таблиці вище; призначення T4.5/DEC-4A і production activation
залишаються окремими етапами.



## UX-1 — клієнтська навігація

Виправлення UX-1 зберігає перевірені клієнтські дані в пам’яті під час фонової повторної перевірки. Кеш обмежений поточною сесією та очищується при виході, завершенні сесії й зміні користувача; серверна авторизація залишається обов’язковою. Деталі — [клієнтський контракт](client-app.md).

Реалізовано декларативний React Router у `/app`: внутрішні `Link`, постійна оболонка, query-навігація раціону/покупок/приготування та невідомий маршрут. Рання системна тема завантажується окремим CSS із чинною CSP; session/CSRF API та серверні ACL незмінні. Cleanup приватних запитів, таймерів і Wake Lock зберігається при виході з екрана. Докази: `tests/browser/recipe-client.py` (незмінні DOM-вузли, відсутність document navigation/повторної CSRF-ініціалізації, history/query, 1440/390/320 light/dark); AUTH4/AUTH5 — ізольована PG18. Локально PASS: client lint/build, root Vite build, 125 Unit/Feature, 167 AUTH4 та 19 AUTH5, auth concurrency, реальний Chromium 154. Security-gate виявив нову CVE-2026-45070: чинний `symfony/mime` оновлено до v8.1.10 без нових пакетів/constraints; audit має пройти Fast CI. Додатково підтверджено SPA cleanup Wake Lock і відновлення cooking таймера через Back, темний login, unknown route та session expiry. [Докази й review](evidence/ux1-navigation/report.md), [PR #110](https://github.com/ValtonUA/telmanfit/pull/110); required CI/merge перевіряються окремо на фінальному SHA.

## UX-2 — адміністративна SPA-навігація

Реалізовано штатний Filament SPA на Livewire 4, власні anchors/selector/redirects та локальний Alpine lifecycle. Cached history/BFCache проходить нове native server loading із MFA/ACL, admin HTML має no-store. Точкові auth/React/JSON exceptions — у [контракті](admin-navigation.md). Додано `tests/browser/filament_navigation.py` на справжніх MFA/Filament/API та disposable PG18; 126 Unit/Feature та 25 AUTH3 тестів PASS. Chromium 154 acceptance PASS: 1440/390/320 light/dark, 0 document navigation на звичайних переходах, recipe save/create (одна нова версія), modal/drag, Back/Forward/refresh, cached expiry 403, revoked SPA GET 403 і logout/recovery login. Додатково PASS: 231 PG/Livewire (3821 assertions), recipe/catalog/meal-plan/cooking concurrency, 40 CI-helper tests та Pint. [Review і докази](evidence/ux2-navigation/report.md); required CI на фінальному SHA перевіряється окремо; production/schema/бізнес-правила незмінні.
