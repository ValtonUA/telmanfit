# Раціони — контракт T4.1–T4.3 та Model I

[Issue #56](https://github.com/ValtonUA/telmanfit/issues/56) визначає foundation;
[readiness gate #54](https://github.com/ValtonUA/telmanfit/issues/54) визначає залежності
наступних кроків. Поточні статуси — лише в [roadmap](roadmap.md), докази — у
[project-status](project-status.md).

## Доступні сторінки

- `/admin/meal-plans`: штатна Filament 5 таблиця, пошук назви, фільтри
  універсального/персонального власника, статуси та кількість версій.
- `/admin/meal-plans/create`: обов’язкова непорожня назва, необов’язковий наявний
  клієнт за перевіреним ID. Універсальний план має `owner_client_id=NULL`.
- `/admin/meal-plans/{record}`: назва, власник та посилання на **кожну точну версію**.
- `/admin/meal-plan-versions/{record}`: ID, номер публікації, статус, дата та
  ієрархія днів → прийомів → позицій; тип прийому, food ID/тип, decimal quantity,
  одиниця та збережені nullable грами. КБЖВ споживаної кількості читаються штатним Model I resolver; batch food не тлумачиться як A/1.

Після створення відкривається єдина нова чернетка. Порожній стан явно пояснює,
що вона ще не готова до публікації. Відкриття/reload не створює наступної чернетки.
Published показуються лише для читання; наявні legacy-позиції не переписуються. Зміна назви чи власника наявного
плану також закрита: окреме правило зміни metadata published plan не затверджене.

## Серверні межі та дані

`MealPlanFoundation::create` повторно перевіряє чинного admin і recovery/restore
fence, тримає shared actor/owner locks, створює logical plan та одну `draft`
версію в одній транзакції й перевіряє deferred constraints до повернення успіху.
Неконтрольовані поля не використовуються: ID, lineage, publication/version/status,
food/FK і replacement links не дозволені для mass assignment. Роль, статус
супроводу, foods, рецепти та призначення не змінюються.

`MealPlanPolicy` дозволяє лише admin list/view/create. Policy нижніх сутностей
дозволяє admin читання; універсальні create/update/delete/replicate закриті. Окрема
здатність `editStructure` дозволена лише для чинної точної draft version. Штатні middleware
панелі забезпечують session/MFA/CSRF, з повторною авторизацією Livewire-запитів.
Сервіс не створює сесію й не обходить ці middleware.

Сім моделей відповідають чинним SQL01/02 FK: owner/parent/copies, plan/versions,
version/days/assignments, day/meals, meal/type/items, item/food/uom,
assignment/client/version/creator/replacement/replaces. Допоміжна `Food` представляє
наявний супертип та зв’язок ingredient; нової таблиці немає. Відсутні SQL timestamps
не генеруються; `created_at`/дати незмінного типу, numeric — `decimal:2`, без float.
Наявні partial UNIQUE, immutable guards та EXCLUDE збережені без зміни SQL/міграцій.

## Редагування структури T4.2

На тій самій сторінці точної версії admin явно додає наступні 7 днів:
`1…7`, `8…14`, `15…21` тощо. Відкриття й reload лише читають дані. Типи треба
обрати з чинного `meal_types`, впорядкованого `sort_order, id`; автоматичних
припущень про коди чи ID немає. Порожній довідник показує пояснення і блокує
генерацію. Неповну/розірвану legacy-структуру сервіс не ремонтує автоматично.
Межа номера дня — чинний SQL `smallint`, без нового продуктового ліміту тижнів.

Дії «Додати прийом», «Змінити прийом», «Видалити порожній прийом» вибирають день
або прийом **цієї версії**. Доступні чинний тип, необов’язкова назва та signed
smallint порядок (однаковий порядок стабільно сортується за ID). Тип у дні унікальний.
Поля редагування недоступні до завантаження обраного прийому; під час його
повторного вибору взаємодія з полями заблокована до відповіді Livewire, щоб пізнє
заповнення не стерло щойно введений текст. День може тимчасово залишитися без прийомів; UI показує порожній стан, Publish
не реалізовано. Окремий прийом із позиціями видаляти заборонено. Окремі дні не
переставляються й не видаляються; для повних тижнів діє погоджений UX-4D нижче.

`MealPlanStructure` перевіряє Policy/свіжого admin та recovery/restore fence,
тримає runtime fence shared lock → actor shared lock → exact version FOR UPDATE
→ days → meals → type shared locks. Runtime перед actor узгоджений із restore,
який блокує runtime перед оновленням users. Після блокування повторно перевіряє draft;
після команд — deferred guards і свіжу роль до commit. Жодного переходу на latest.
Сервіс не змінює parent/owner/status/publication/assignments або історичні snapshots.
Повтор запиту явного блоку `start_day` є no-op, навіть якщо його прийоми вже
відредаговані; паралельні кліки серіалізуються на версії. Append не торкається
попередніх тижнів. Редагування/видалення порівнює hash початкових полів прийому
під lock, тому stale форма не стирає новішу правку. Повтор add з тим самим типом
відхиляється без змін. Немає нових таблиць, міграцій, довідників або feature flags.

## Видалення тижнів UX-4D

[Issue #60](https://github.com/ValtonUA/telmanfit/issues/60) погоджує видалення
**будь-якого повного семиденного тижня точної чернетки**: першого, середнього,
останнього або єдиного. Меню «Видалити тиждень» показує актуальні номери та
кількість `meal_items`. Наявність автоматичних `meals` без позицій означає
**порожній** тиждень: вибір виконує видалення відразу, без warning/confirm-модалу.
За ≥1 позиції відкривається confirmation із фактичною кількістю, попередженням
про незворотне видалення всіх позицій/прийомів і зсув наступних тижнів; кнопки
«Скасувати» / «Видалити тиждень». Cancel та перегляд не пишуть дані.

Запит містить стабільний anchor (ID першого дня) і HMAC snapshot усіх семи днів,
прийомів та позицій, прив’язаний до actor і version. Номер тижня не є ключем
команди. Заповнений тиждень додатково потребує окремого серверного confirmation
HMAC поточного snapshot; `confirmed=true`, підміна count/anchor/version або
повторення старого запиту не дають права видаляти сусідній тиждень. Під version
FOR UPDATE повторно читаються й блокуються дні → прийоми → позиції, звіряється
підпис і **фактичний** item count. Нові/змінені позиції чи прийоми, або зміщення
днів після іншого видалення, вимагають оновлення сторінки та нового підтвердження.
Read-only підписані snapshots не змінюють БД; при конкурентному читанні будь-яка
неузгодженість відхиляється повторною перевіркою під lock.

Транзакція явно видаляє лише вибрані `meal_items → meals → meal_plan_days` цієї
чернетки. Наступні дні оновлюються по одному **в зростаючому порядку**: номер мінус
7, без конфлікту з негайним UNIQUE. Їхні ID, прийоми та вміст зберігаються; після
єдиного тижня лишається 0 днів. Версія, foods, ingredients, recipes/recipe_versions,
інші плани, призначення й журнали не змінюються. Published та розірвані legacy
структури не редагуються/не ремонтуються цією командою. Recovery fence → actor →
version lock order, fresh-role check, deferred guards і rollback спільні з T4.2.
FK RESTRICT та immutable/UNIQUE guards не змінені. Конкурентний прямий INSERT
позиції серіалізується також через FOR UPDATE на meal та його FK key-share:
нова позиція вимагає fresh confirmation або INSERT відхиляється після DELETE,
а orphan/тихого видалення непідтвердженої позиції немає.

## Свідомо закриті команди

Publish (T4.4), призначення/заміна
(T4.5), CoW (T4.6), Delete/replicate/archive самих планів/версій та CRUD довідника meal types відсутні.
Повторення/копіювання заповнених тижнів (Issue #61) не реалізовано.
Довідник типів читається з чинних рядків, seeds не додаються.

G4-I реалізує окремий forward-only schema/readonly контракт Model I за
[Issue #63](https://github.com/ValtonUA/telmanfit/issues/63); writer T4.3 використовує цей контракт без зміни схеми.
[Proposal §11/§13](proposals/stage-3-recipe-batch-editor.md#11-контракт-stage-4-food-формат-порція-і-фізичне-приготування)
уточнено цією авторизацією; selectors не входили до T4.2. DEC-4A потрібне перед T4.5.
T1.5 та DEC-3T не блокують
цей synthetic foundation. Production activation — окремий go/no-go.

## Перевірки

- `tests/Unit/MealPlanModelsTest.php`: PK/timestamps/fillable boundary семи моделей.
- `tests/Postgres/MealPlanFoundationTest.php`: реальні FK/nullable/decimal/date
  relations, atomic create, deferred late fault rollback, name/owner validation,
  прямий Livewire, fresh-role ACL, SQL unique/immutable/exclusion negatives,
  populated legacy recipe/ingredient plans та hash незмінності історії.
- `tests/Postgres/MealPlanStructureTest.php`: 7/14/21 днів, типи/порядок, retries,
  rollback deferred faults, порожній довідник, stale/forged/cross-version IDs,
  SQL UNIQUE, immutable history, fresh-role/fence та прямі Filament actions.
- `tests/Postgres/MealPlanWeekDeletionTest.php`: 7/14/21/28 днів, перший/середній/останній/єдиний тиждень, stable IDs/content, signed confirmation, cancel/forgery/retry/stale, ACL/fence, deferred rollback та history hashes.
- `tests/pg/meal-plan-week-deletion-concurrency.php`: реальні delete-vs-append і delete-vs-прямий INSERT item в обох порядках, double/filled replay, stale edit, доведені lock waits та відсутність сусіднього видалення/deadlocks.
- `tests/pg/meal-plan-structure-concurrency.php`: незалежні PG-сесії з доведеним
  lock wait; double/retry, edit-vs-generate, stale edit, revoked role та runtime
  fence-vs-writer без інверсії locks.
- `tests/pg/meal-plan-concurrency.php`: окремий процес, доведене очікування locks,
  duplicate draft / immutable detail / overlap відмови; два атомарні незалежні
  створення без lost write. Входить у штатний recipe/Stage4 runner.
- `tests/browser/meal_plans.py`: справжня MFA-сесія, create/list/search/detail,
  точна published hierarchy, порожня чернетка, генерація/редагування/append/reload,
  UX-4D modal/no-modal, cancel/confirm, middle-week renumber зі stable IDs,
  1440/390/320 light/dark,
  overflow, CSRF 419, revoked signed Livewire/HTTP 403 та незмінна історія.
  Виконується разом із Stage 3 catalog/recipe регресіями; synthetic screenshots
  і checks зберігаються як CI artifacts у `meal-plans/`.

Локальне відтворення: `RECIPE_BATCH_ONLY=1 RECIPE_BATCH_BROWSER=1 bash tests/pg/run-stage1.sh`.
Runner використовує лише нову disposable PG18, network none, без зовнішніх портів,
синтетичні дані та cleanup. Повний CI і machine gate — [ci.md](ci.md).

## Model I G4-I: selectors та readonly resolver

[Issue #63](https://github.com/ValtonUA/telmanfit/issues/63) авторизує окремий
forward-only [SQL 14](14_meal_item_selectors.sql), byte-identical до
[виконуваного модуля](../database/sql/meal_plans/00_selectors.sql). Nullable поля
та composite FK/guards наведені в [Entity Catalog](entity-catalog.md#g4-i--точні-selectors-позиції-раціону).
SQL 01–13, історичні plans/assignments/foods/recipes/items не змінюються.

`MealItemResolver::resolve(int|MealItem)` — внутрішній readonly контракт, без
нового публічного endpoint або UI. Він завжди перечитує persisted item за ID,
одним SQL snapshot приєднує точні food, published version, format, UOM та ingredient.
Повертає readonly `MealItemPortion`: foodId, enum kind, recipeVersionId, formatId,
divisor, quantity та nutrition (`kcal/protein/fat/carbs` decimal strings scale 6).
Legacy version визначається унікальним food, жодного `latest`; batch — тільки
повна явна трійка. Ingredient використовує historical resolved grams і чинні
`*_per_100g`, legacy — frozen `*_per_serving`, batch — frozen format `*_total`.
Спочатку множення на quantity, потім ділення на divisor й одне HalfUp округлення
до 6 знаків. C/3 і C/4 мають один format ID та різні показники споживання.
Відсутній item, невідомий format, неповні selectors чи нечислові nutrients —
`DomainException`, без A/1 fallback. Viewer показує збережені поля та readonly КБЖВ через той самий resolver.

`recipe_batch_quantities` описує **цілу фізичну партію**: зміна спожитої
кількості/divisor не змінює її кількостей, кроків або обладнання. Майбутні nutrition
і shopping consumers масштабують за `quantity/divisor`; cooking цілої партії — ні.
G4-I не містить shopping list чи scheduler. Команди T4.3 описані нижче.

`MealItemSelectorsTest` перевіряє непорожній synthetic upgrade з SHA256 snapshots
історії, legacy nutrition, ingredient/A/1/B/7/C/3/C/4, 0.5 порції, SQL NULL matrix,
чужі й неопубліковані версії/formats, UOM/grams, immutable history, deferred rollback
та explicit reader failure. `MealItemPortionTest` перевіряє точність і скінченні
decimal strings. `meal-item-selector-concurrency.php` доводить реальні lock waits
в обох порядках selector/root і відхилення update/delete/downgrade без deadlock.
Вони входять у штатний Stage3/T4.2 runner та Full CI; browser UI для G4-I не змінено.


## Writer позицій T4.3

`MealItemWriter` — єдині команди `save`, `move`, `delete` для точної draft version.
Filament на тій самій сторінці версії має меню «Позиції»: пошук наявного
інгредієнта/опублікованої версії, додавання, редагування, явне перенесення між
прийомами/днями цієї версії та видалення з confirmation/cancel. Порожній каталог
не генерує seed. Продукт наявної позиції незмінний; його заміна потребує явних
add/delete. Однаковий sort_order стабільно впорядковується за ID.

Ingredient використовує default conversion, якщо вона є, інакше g; одиницю можна
обрати явно. Quantity і grams — positive numeric(7,2), decimal strings/Brick Math;
quantity × актуальний factor округлюється HalfUp до 2 знаків, overflow і нуль після
округлення відхиляються. Legacy — exact published food, serving і NULL selectors/grams.
Batch потребує явних version/format/divisor: A/1, B/7, C/3 або C/4. UI пояснює
частку фізичної партії й споживану quantity (зокрема 0.5). Readonly preview і
persisted summary використовують один `MealItemResolver`, без JS nutrition чи A fallback.
`preview` додає лише derived SQL row до тих самих joins/перевірок resolver, без INSERT.

Policy `editItems` та Service повторно перевіряють чинного admin і draft.
`MealPlanStructure::withinDraft` спільний з T4.2/UX-4D: runtime fence → actor →
exact version FOR UPDATE, далі days → meals → items за ID. Cross-version/plan IDs
відхиляються під lock. Рецептурний root/ingredient SHARE береться перед UOM за ID;
published formats читаються під root fence без інверсії child locks.
Ingredient root lock захищає conversion phantoms чинними SQL guards. Каталог,
default/conversions/UOM і frozen recipe metadata мають actor/version HMAC snapshot;
зміни після вибору вимагають повторного вибору/перевірки. Перед успіхом виконуються
наявні deferred SQL guards та fresh-role/fence check. Автоматичного retry немає.

Add порівнює HMAC стану прийому й усіх його items, тому подвійний submit відхиляється.
Edit/move/delete порівнюють expected стан item; stale форми не стирають новішу правку.
Move зберігає точний food/selector/grams; delete видаляє лише item. Week snapshots UX-4D
включають актуальні items, тому add/edit/move/delete роблять старе підтвердження
невалідним. Published history, legacy NULL selectors, recipes, assignments і каталоги
ці команди не переписують. Session/MFA/CSRF межа залишається штатною Filament.

`MealItemWriterTest` перевіряє decimal/default/UOM, legacy та чотири batch порції,
preview/reload, stale/retry, forged/cross-version IDs, ACL/fence, deferred rollback,
Livewire та недійсне старе підтвердження UX-4D. `meal-item-writer-concurrency.php`
перевіряє незалежні PG-сесії й реальні lock waits для edit/double add,
item-vs-week-delete в обох порядках, write-vs-publish та conversion/UOM change.
`tests/browser/meal_items.py` проходить search/empty/add/edit/move/delete/cancel/reload
у Chromium 1440/390/320 light/dark разом із чинними MFA/CSRF/revocation регресіями.
Фінальний week/day DnD UX-4E (#62), repeat week (#61), Publish і assignments
залишаються окремими задачами. Схема, feature flags і реальні БД не змінюються.
