# Раціони — контракт T4.1–T4.6 та Model I

[Issue #56](https://github.com/ValtonUA/telmanfit/issues/56) визначає foundation;
[readiness gate #54](https://github.com/ValtonUA/telmanfit/issues/54) визначає залежності
наступних кроків. Поточні статуси — лише в [roadmap](roadmap.md), докази — у
[project-status](project-status.md).

## Доступні сторінки

- `/admin/meal-plans`: штатна Filament 5 таблиця, пошук назви, фільтри
  універсального/персонального власника, статуси та кількість версій.
- `/admin/meal-plans/create`: непорожня назва, необов’язковий наявний клієнт та щонайменше один тип прийому з чинного `meal_types`, упорядкований за `sort_order, id`. Якщо довідник порожній, створення недоступне. Непідтверджених стандартних типів не припускаємо.
- `/admin/meal-plans/{record}`: назва, власник та посилання на **кожну точну версію**.
- `/admin/meal-plan-versions/{record}`: ID, номер публікації, статус, дата та
  [семиденний огляд UX-4E](#редактор-тижня-й-дня-ux-4e) із compact meals/items та day CTA.
- `/admin/meal-plan-versions/{record}/days/{day}`: exact-day editor усіх прийомів,
  каталог/порції/DnD та серверний Model I preview; published — readonly.

Після створення відкривається тижневий редактор єдиної нової чернетки із сімома днями та обраними прийомами без позицій. Для старої чернетки без днів доступна явна дія «Створити 7 днів». Відкриття/reload не створює наступної чернетки.
Published показуються лише для читання; наявні legacy-позиції не переписуються. Зміна назви чи власника наявного
плану також закрита: окреме правило зміни metadata published plan не затверджене.

## Серверні межі та дані

`MealPlanFoundation::create` повторно перевіряє чинного admin і recovery/restore
fence, тримає shared actor/owner locks, створює logical plan та одну `draft`
версію, сім днів, обрані прийоми й квитанцію в одній транзакції та перевіряє deferred constraints до повернення успіху. UUID запиту пов'язаний із admin, SHA-256 нормалізованих параметрів і створеним планом; повтор повертає той самий план, а інші параметри відхиляються. Унікальні обмеження PostgreSQL і rollback забезпечують конкуренцію без TTL квитанцій.
Неконтрольовані поля не використовуються: ID, lineage, publication/version/status,
food/FK і replacement links не дозволені для mass assignment. Роль, статус
супроводу, foods, рецепти та призначення не змінюються.

`MealPlanPolicy` дозволяє лише admin list/view/create. Policy нижніх сутностей
дозволяє admin читання; універсальні create/update/delete/replicate закриті. Окрема
здатності `editStructure` та `publish` дозволені лише для чинної точної draft version. Штатні middleware
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
заповнення не стерло щойно введений текст. День може тимчасово залишитися без прийомів; UI показує порожній стан,
публікація відхиляє такий день. Окремий прийом із позиціями видаляти заборонено. Окремі дні не
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

Delete/replicate/archive самих планів/версій та CRUD довідника meal types відсутні.
Персональна CoW-копія published version описана в [T4.6](#персональні-копії-t46).
Повторення заповнених тижнів у draft описано нижче; воно не публікує і не призначає раціон.
Довідник типів читається з чинних рядків, seeds не додаються.

G4-I реалізує окремий forward-only schema/readonly контракт Model I за
[Issue #63](https://github.com/ValtonUA/telmanfit/issues/63); writer T4.3 використовує цей контракт без зміни схеми.
[Proposal §11/§13](proposals/stage-3-recipe-batch-editor.md#11-контракт-stage-4-food-формат-порція-і-фізичне-приготування)
уточнено цією авторизацією; selectors не входили до T4.2. DEC-4A для T4.5 погоджено й описано нижче.
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
Вони входять у локальний Stage3/T4.2 runner та релевантні Targeted PR-перевірки; browser UI для G4-I не змінено.


## Writer позицій T4.3

`MealItemWriter` — єдині команди `save`, `move`, `delete` для точної draft version.
Filament day editor та доступне fallback меню «Позиції» на сторінці версії:
пошук наявного
інгредієнта/опублікованої версії, додавання, редагування, явне перенесення між
прийомами/днями цієї версії та видалення з confirmation/cancel. Порожній каталог
не генерує seed. Поля редагування позиції заблоковані до завантаження її optimistic token;
повторний вибір також блокує взаємодію до відповіді Livewire. Продукт наявної
позиції незмінний; його заміна потребує явних
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
Edit/move/delete порівнюють expected стан item і PG MVCC revision точної версії; stale форми не стирають новішу правку.
Move зберігає точний food/selector/grams; delete видаляє лише item. Кожен успішний item command виконує no-op UPDATE лише draft root: дані статусу
не змінюються, а xmin оновлюється. Tokens і week snapshots включають цю MVCC revision,
тому навіть add→delete чи edit A→B→A не оживляють стару форму/confirmation; нових колонок немає.
Week snapshots UX-4D включають актуальні items, тому add/edit/move/delete роблять старе підтвердження
невалідним. Published history, legacy NULL selectors, recipes, assignments і каталоги
ці команди не переписують. Session/MFA/CSRF межа залишається штатною Filament.

`MealItemWriterTest` перевіряє decimal/default/UOM, legacy та чотири batch порції,
preview/reload, stale/retry, forged/cross-version IDs, ACL/fence, deferred rollback,
Livewire та недійсне старе підтвердження UX-4D. `meal-item-writer-concurrency.php`
перевіряє незалежні PG-сесії й реальні lock waits для edit/double add,
item-vs-week-delete в обох порядках, write-vs-publish та conversion/UOM change.
`tests/browser/meal_items.py` проходить search/empty/add/edit/move/delete/cancel/reload
у Chromium 1440/390/320 light/dark разом із чинними MFA/CSRF/revocation регресіями.
Week/day DnD UX-4E (#62) реалізовано поверх цих команд, як описано нижче.
Повторення тижнів, публікацію й призначення описано нижче як окремі сервіси. Схема, feature flags і реальні БД не змінюються.

## Редактор тижня й дня UX-4E

Основний екран exact version — семиденний огляд `?week=N`. Він рендерить
позиції лише вибраного тижня; GET/reload не створюють днів або позицій. На desktop
від 1280 px сім колонок розташовані одночасно, на 390/320 px перемикається один
день. Показуються «День N (weekday)» за початком тижня плану, без календарної дати. У колонці лише
заповнені прийоми, компактні назви й порції та одна нижня CTA «Заповнити день» /
«Редагувати день». Порожні налаштовані прийоми приховані лише в огляді.

`/admin/meal-plan-versions/{record}/days/{day}` — єдина full-width Filament
сторінка всіх прийомів exact дня. URL перевіряється на сервері та повторно при
Livewire hydration; day ID locked. Ліворуч також видно порожні прийоми, праворуч —
серверний пошук/фільтр ingredient або exact published recipe, по 12 результатів,
без сотень зображень. Форма показує серверний Model I preview. Quantity та UOM
під час додавання з каталогу порожні; A/1, B/7, C/3, C/4 обираються явно.

Sidebar повністю згортається на desktop лише для цих двох сторінок.
Native HTML Drag API для миші та Pointer API з ручкою для touch — тонкий Alpine
адаптер. Каталог → прийом відкриває штатний Filament modal; до підтвердження немає
запису. `food_id` відокремлений від persisted `meal_item.id`. Перенесення на позицію
вставляє перед нею, на заголовок/вільне місце прийому — в кінець. DOM не переставляє
позиції оптимістично: успіх повертає канонічний серверний порядок, validation/conflict
залишає актуальний стан та notification. Каталог має окремий вибір цільового прийому;
select перенесення й кнопки «Вище»/«Нижче» розміщені в меню дій позиції «⋯», там само —
редагування та confirmation видалення. Ці команди працюють також із клавіатури й touch.

### Компактний редактор дня UX-4

На широкому desktop список прийомів і каталог мають окремі вертикальні scroll-контейнери;
заголовки панелей залишаються видимими. Каталог показує по три картки в ряд від 1280 px,
дві на середніх ширинах і одну на вузьких екранах. На мобільних панелі повертаються до
звичайного потоку сторінки, без вкладеної прокрутки. Пошук лишається серверним і зберігає
фокус після оновлення результатів.

Перед каталогом є селектор «Додати до прийому». Кнопка картки відкриває ту саму штатну
форму додавання вже з вибраним прийомом; кількість, UOM та точний формат batch-рецепта
залишаються явними, а до підтвердження запис не створюється. Клавіатура й touch натискають
цю кнопку без drag-and-drop. Меню «Керування» на сторінці дня прибране: повернення до
тижня та додавання прийому доступні в заголовку, дії persisted-позиції — на її картці.
Публікація, повторення/заміна та створення тижня винесені у видимі дії тижневого огляду;
видалення тижня лишається окремою дією цього огляду.

`MealItemWriter::place` — мінімальне розширення чинної writer boundary: exact
version → day → source/target meals та optional before item, HMAC snapshot дня
(actor/version/MVCC revision/вміст), спільні recovery/actor/version/hierarchy locks,
перевірка свіжої ролі й deferred guards. Порядок до команди стабільний за
`sort_order, id`; цільовий і початковий прийоми перенумеровуються атомарно від 0,
з перевіркою smallint. Identity food/recipe/format/divisor, quantity та UOM не
змінюються. Stale/repeated drop, чужий день/версія, видалений тиждень, ABA та
конкурентні move/delete відхиляються без часткового запису.

Усі інші дії використовують T4.3 `save/move/delete` і T4.2
`saveMeal/deleteMeal`. Сторінка дня додатково перевіряє, що аргументи кожної
команди належать саме її дню. Після save Livewire перечитує цей день без redirect, зберігаючи пошук/фільтр/сторінку каталогу, а «До огляду
тижня» повертає на тиждень його поточного порядкового номера. Генерація та
UX-4D видалення тижнів залишаються в огляді; попередні forms — доступний fallback
поверх тих самих сервісів. Другого основного редактора немає.

Header показує назву плану, а version/status — другорядно; основні тижневі команди видно
в огляді, додаткові дії зібрані в окреме меню.
Published має readonly огляд і сторінку дня, без каталогу, drag handles чи edit CTA;
прямі Livewire write calls відхиляються Policy/Service. SQL01–14 не переписані; погоджений forward SQL15 описано нижче. Залежності,
робочі БД, feature flags та призначення не змінюються. Повторення тижнів T4.3R описано нижче.
Публікацію описано нижче; assignment/CoW і production activation залишаються окремими задачами.

Перевірки: `MealPlanDayEditorTest`, незалежний
`tests/pg/meal-plan-day-concurrency.php` (доведені PG lock waits),
`tests/browser/day_editor.py` у штатному Stage3/4 runner. Chromium перевіряє
1440/390/320 light/dark, дві незалежні desktop-прокрутки, сітку каталогу 3/2/1,
довгий каталог і пошук зі збереженим фокусом, явний цільовий прийом та додавання
клавіатурою/touch без DnD, повернення до огляду, щільний і порожній тиждень, exact navigation,
copy/cancel/confirm, mouse drag, trusted Chromium touch/Pointer input, keyboard/buttons, explicit
A/B/C порції, persistence та overflow. Browser artifacts зберігаються разом із
T4.1–T4.3 у `meal-plans/`; Chromium touch emulation не замінює ручну перевірку
фізичного touchscreen пристрою. Результати й CI належать конкретному head SHA.

## Зображення та початок тижня SQL15

Останнє уточнення власника PR #67 має пріоритет: required create select
«Початок тижня» з Пн за замовчуванням зберігається в
`meal_plans.week_start_weekday` (ISO 1..7), а не у версії. DB забороняє
зміну після створення; Day 1/8 показують обраний weekday, assignment date окрема.
Forward migration `2026_10_25_020000_add_plan_presentation.php` виконує
`database/sql/presentation/00_plan_media.sql`, ідентичний `15_plan_presentation.sql`.
Existing plans default=1 погоджено явно; days/items/frozen snapshots не змінюються.

`ingredients.image_url` / `recipes.image_url` nullable. Ingredient edit та action
«Обкладинка рецепта» у «Керування» draft day editor (пошук логічного рецепта), а також на recipe editor зберігають URL; дія доступна без увімкнення batch feature flag. свіжі admin ACL, recovery
fence, locks і CAS захищають write. Cover recipe спільний для всіх exact versions.
Не використовуються images кроків. Фото не входить у nutrition source hash,
тому додавання колонки та заміна cover не змушують Refresh.

`PUBLIC_IMAGE_HOSTS` — comma-separated **точні** публічні DNS hosts, без scheme,
path, wildcard. Типово порожній: весь editor працює без фото. Реальний R2 host
невідомий і не потрібний для цього PR; не слід підставляти вигаданий R2 URL.
Після окремо погодженого налаштування дозволяються тільки HTTPS URL до цих hosts,
без userinfo, port, fragment, IP/private names або SVG/HTML/XML. Сервер не
виконує fetch/proxy/upload. Browser без referrer, lazy/async, object-fit та sizes.
Для NULL/untrusted/broken фото — компактний текст/placeholder; week не лишає
порожніх фото-прямокутників. Day thumb 56–64 px, desktop catalog 2-column grid з компактним 64px placeholder,
week photo full-width 4:3 з дворядковим підписом.

R2 key може лишатися стабільним при overwrite. Видимість нового файлу залежить
від CDN/browser TTL; власник storage окремо визначає Cache-Control/TTL та за
потреби виконує Cloudflare purge. Цей PR не змінює bucket, об'єкти, credentials,
TTL чи purge і не переносить файли у Laravel storage. У тестах
`https://images.example.com/…` перехоплюється Chromium route fixture без R2/network.

`MealItemResolver::resolveMany` виконує ті самі exact joins/decimal calculations
для всіх позицій одним SQL; labels/covers також одним lookup. Презентація
округлює HALF_UP до kcal0/macro1 без float; resolver зберігає precision.
`MealPlanPresentationTest` вимірює повний Livewire render 10/40 позицій,
перевіряє незростання query count і точну відповідність scalar/bulk.


## Повторення тижнів у чернетці T4.3R

[Issue #61](https://github.com/ValtonUA/telmanfit/issues/61): «Керування → Повторити
тиждень» доступне в огляді точної draft version та редакторі дня. Вихідний тиждень
типово 1; «Кількість тижнів загалом» — кінцева тривалість усього раціону.
Джерело S копіюється у S+1…N; джерело й попередні тижні не змінюються.
Після успіху відкривається огляд поточного тижня; редактор цільового дня не
залишається на старому ID, який щойно було замінено.
Наприклад, 1 → 4 створює три копії. Для джерела 2 та N=4 змінюються лише 3 і 4.
N має перевищувати S і бути не меншим за наявну тривалість: скорочення раціону
виконується окремою UX-4D дією. Верхня технічна межа 4681 тиждень походить із
SQL smallint day_number, нового продуктового ліміту немає.

Джерело мусить бути повним семиденним блоком у безперервній структурі 1…N та
містити хоча б одну позицію. Частково заповнений тиждень дозволений: порожні дні
й прийоми копіюються як є. Це не перевірка готовності до Publish; повністю порожнє
джерело й розірвана legacy-структура відхиляються без запису.

`MealPlanWeekRepeater` використовує `MealPlanStructure::withinDraft`: runtime
fence → fresh actor → exact version FOR UPDATE → days → meals → items. Усі
наявні дні/прийоми/позиції блокуються в стабільному порядку, а HMAC стану містить
actor, version, рядки та їхні PostgreSQL xmin revisions. Snapshot береться при
відкритті форми й не оновлюється при зміні її полів. Append, edit, delete-week,
інший repeat або ABA-правка після snapshot вимагають нового відкриття форми.
Після успіху version xmin оновлюється також для інвалідації старих editor tokens.
Повтор submit відхиляється; дублікати чи повторне затирання правок не створюються.

Відсутні цілі створюються без додаткового confirmation. Якщо хоча б один
цільовий тиждень уже існує, перший submit лише відкриває окреме «Замінити цільові
тижні» — навіть за 0 прийомів і 0 позицій. Це зберігає авторські назви, типи та
порядок прийомів без страв до явної згоди тренера. Діалог показує source, цільовий
діапазон, точний список наявних тижнів, які буде замінено, та кількість прийомів
і позицій у кожному з них. Відсутні тижні не потрапляють до списку заміни. Лише «Так, замінити цільові тижні» передає
окремий HMAC grant, прив'язаний до actor/version/source/total/snapshot.
Cancel, відкриття, reload і preview не пишуть дані. `confirmed=true`, підміна
лічильників або старий grant не дозволяють заміну; перед записом стан звіряється
повторно під locks. Нові конкурентні вставки також серіалізуються FK key-share
проти FOR UPDATE на батьківських рядках.

В одній транзакції цільові items → meals → days видаляються і створюються заново
з новими ID. Кожен день, прийом і позиція — фізично незалежний рядок; title,
meal_type_id, sort_order (включно з однаковими значеннями та стабільним ID-порядком),
food_id, exact recipe_version_id/recipe_batch_format_id/recipe_portion_divisor,
quantity, uom_id і resolved_quantity_grams копіюються без повторного resolution,
float чи переходу на latest. A/1, B/7, C/3, C/4 та legacy NULL selectors зберігаються.
Немає live-link, нової таблиці, міграції чи фонової materialization. Deferred
constraints і повторна перевірка ролі/fence виконуються до commit; пізня помилка
відкочує всі видалення й копії. Каталоги, frozen recipes, published plans та
історичні assignments не змінюються. Чинні admin/MFA/CSRF middleware збережено.

Повтор у draft — лише підготовка вмісту. Публікація T4.4 перевіряє заповненість
усього раціону; **T4.5** — призначення exact published version, дати,
заміни та DEC-4A; безстроковий повтор тут не реалізується.

Перевірки: `tests/Postgres/MealPlanWeekRepetitionTest.php` (deep copy/ID independence,
formats/decimal/history, confirmation/cancel/forgery/stale/ACL, deferred rollback),
`tests/pg/meal-plan-week-repetition-concurrency.php` (реальні lock waits для repeat,
item edit, append, UX-4D delete, raw INSERT, publication, role/fence),
`tests/browser/week_repetition.py` (1440/390/320 light/dark, copy, independent edit,
replace/cancel і stale modal). Входять у штатний Stage3/4 runner, без нових CI jobs.


## Публікація точної версії T4.4

«Керування → Опублікувати» в огляді та редакторі дня доступне лише чинному admin
для exact draft. Відкриття виконує readonly preflight усього раціону, незалежно від
вибраного тижня/дня: ≥7 днів, кратність 7, безперервні 1…N, хоча б один прийом
у кожному дні та позиція в кожному прийомі. Усі наявні прийоми обов’язкові;
довідник не визначає додаткового обов’язкового набору типів.
Повідомлення містять номер дня, назву/ID прийому та ID позиції; пропуски днів і
порожні прийоми показуються разом. Кожна позиція проходить exact Model I resolver;
для ingredient додатково перевіряється quantity × чинний factor, HALF_UP до 2 знаків,
без float, refresh чи мовчазного виправлення грамування. Помилки показує Filament
notification; confirmation не відкривається, записи не змінюються.

Успішний preflight видає окремий HMAC publication token стану actor/version,
root і всіх days/meals/items з PostgreSQL xmin. Cancel не пише дані. Зміна
структури/позицій, ABA або підміна token вимагають нового підтвердження.
`MealPlanPublisher` повторює перевірку в одній транзакції: recovery fence SHARE →
fresh actor SHARE → exact version FOR UPDATE → days → meals → items → ingredient
roots SHARE → published recipe roots SHARE → UOM SHARE. Root guards захищають
conversion phantoms; публікація бачить актуальний factor або чекає конкурентного
catalog writer. Точний version lock серіалізує editor/repeat/delete та publication;
SQL guards залишають останній захист для raw SQL. Автоматичного retry немає.

Після повторної валідації logical plan FOR UPDATE серіалізує номер max+1 у межах
цього плану (smallint overflow відхиляється). Єдиний UPDATE встановлює status,
version_number і published_at. Deferred guards та повторний actor/fence check
виконуються до повернення успіху; пізня помилка відкочує публікацію повністю.
Повторний submit уже published version відхиляється. Новий draft не створюється.

Публікація не копіює й не перераховує recipes/nutrition: ingredient grams, legacy
NULL selectors і exact batch A/1, B/7, C/3, C/4, quantity та UOM залишаються
незмінними. Нових foods, client events або assignments немає. Історія лишається
в наявних exact versions; published root і всі дочірні рядки захищають чинні
SQL01/14 immutable guards. Нова схема/міграція для цього переходу не потрібна.
Редактор дня включає `publishPlan` у header-меню «Керування» та використовує
ту саму action і `MealPlanPublisher`, що й огляд, без окремої логіки публікації.
Після успіху з будь-якої точки входу UI відкриває readonly огляд; day editor теж readonly. Прямі Livewire
writes проходять ті самі Policy/Service та штатні session/MFA/CSRF middleware.

Перевірки: `MealPlanPublicationTest` (PG/Livewire, повнота/грамування, Model I,
history, stale/forged/ACL/fence, immutability negatives та late rollback),
`tests/pg/meal-plan-publication-concurrency.php` (незалежні сесії, доведені lock
waits для duplicate, raw edit в обох порядках, ABA, writer/repeat/delete-week,
append, conversion, role/fence),
`tests/browser/plan_publication.py` (1440/390/320 light/dark, errors/cancel/stale,
окремі overview/day menu/confirmation/cancel/stale/publish/readonly redirect,
signed CSRF/revocation з редактора дня) включені в чинний Stage3/4 runner без нових CI jobs.


## Призначення клієнтам T4.5 / DEC-4A

**Погоджене DEC-4A:** admin обирає наявного клієнта, точну опубліковану версію
та дату початку сьогодні або в майбутньому. `end_date` nullable; межі включні.
Персональний раціон доступний лише `owner_client_id`, універсальний — будь-якому
наявному клієнту. Призначення не активує супровід і не змінює його статус;
окремого нового обмеження на pending/active/paused/finished DEC-4A не вводить.
«Сьогодні» визначається серверним Laravel календарем (`config('app.timezone')`),
дати зберігаються як date, без перетворення календарних днів у timestamps.

Дострокова заміна має явно вибране попереднє призначення. Початок нового —
**майбутня дата**, строго після початку попереднього. Якщо періоди перетинаються,
попереднє завершується днем перед новим початком і отримує
`replaced_by_assignment_id` нового запису. Завершення не може передувати початку.
Новий період не може зачіпати жодне інше призначення: автоматичного обрізання,
перенесення або видалення запланованих раціонів немає. Якщо вибране попереднє
не перетинається з новим періодом, створюється **окремий** запис без replacement
link. Чужа/відсутня/вже замінена ціль, перекриття іншого періоду та неузгоджені
дати відхиляються з українським поясненням. Історичні записи й published вміст
не змінюються; в старому призначенні дозволені лише погоджені дата/link.

`MealPlanAssigner::snapshot/preview/assign` — єдина доменна межа, без Telegram,
нової схеми чи feature flags. Preview лише читає. Підпис HMAC прив'язаний до
actor, client, стану картки та **всього** розкладу з PostgreSQL xmin revisions.
Окремий confirmation HMAC охоплює точну version, дати, ціль, snapshot і наслідки.
Після конкурентного запису, ABA, зміни картки, повторного submit або підміни
полів потрібне нове відкриття форми. Після успіху no-op UPDATE `updated_at`
клієнта змінює лише MVCC revision; нового поля idempotency немає. Повторні
запити відхиляються без дубля або повторного журналювання.

Одна PostgreSQL транзакція: recovery runtime SHARE → fresh actor SHARE →
exact published version SHARE → logical plan SHARE → client FOR UPDATE →
assignments FOR UPDATE за ID. Це узгоджено з publisher (version → plan) та
restore (runtime → actor). Client lock серіалізує і порожній розклад; FK guards
і EXCLUDE залишаються останнім DB-захистом. Роль/recovery fence перевіряються
до операції та перед commit; deferred guards виконуються до успіху.
Помилка будь-якого кроку, зокрема журналу, відкочує дати/link/новий запис/подію.

У `client_events` лише INSERT з локальним `event_type=meal_plan_assigned` для
створення та заміни. Глобальну таксономію/enum/retention не вводимо. Колонки
`client_id`, `actor_user_id`; `event_data` містить client/actor, old/new assignment
IDs, exact old/new version IDs, початок старого, його end до/після, нові start/end.
Для окремого призначення old-поля NULL. Імен, email та інших персональних даних
у payload немає. Публікація залишається окремою T4.4 операцією; assignment не
публікує draft автоматично.

**Filament:** «Картка клієнта → Раціони клієнта» відкриває
`/admin/clients/{record}/meal-plans`. Таблиця показує version із посиланням на
readonly огляд, включні дати, безстроковість і обидва напрямки історії заміни.
«Призначити раціон» та «Замінити раціон» відкривають вибір доступної exact
published version і дат. «Перевірити наслідки» відкриває окремий modal із назвами,
номерами версій, ID та точними датами завершення/початку. Лише
«Так, підтвердити призначення» пише дані; cancel/reload не пишуть. Порожній
каталог пояснює необхідність спочатку опублікувати відповідний раціон.
Штатні session/MFA/CSRF та persistent authorization залишаються чинними.

Цільовий прогін без повної регресії:
`MEAL_PLAN_ASSIGNMENT_ONLY=1 MEAL_PLAN_ASSIGNMENT_BROWSER=1 bash tests/pg/run-stage1.sh`.
Лише disposable PG18 без мережі/портів, synthetic fixtures і cleanup.
`MealPlanAssignmentTest` перевіряє backend/Livewire/SQL negatives/rollback;
`meal-plan-assignment-concurrency.php` — незалежні процеси й доведені lock waits;
`meal_plan_assignments.py` — релевантні Chromium light/dark 1440/390/320,
preview/cancel/confirm/history/stale та CSRF/revocation. Для PR обов’язкові
Fast, Docs та Targeted на актуальному SHA; Full CI workflow вилучено.


## Персональні копії T4.6

FEASIBILITY: PASS: чинні owner/parent, draft та Model I дозволяють глибоку копію
без міграцій. `MealPlanCopier` читає лише exact published source; створює новий
logical plan із `owner_client_id`, `parent_meal_plan_id`, назвою та незмінним
week_start_weekday, незалежний draft без номера/дати публікації. Кожен day/meal/item
отримує новий PK і переприв’язаний FK. Food, quantity, UOM, grams, порядок та всі
Model I selectors копіюються буквально, без resolution або створення рецептів.

Межа транзакції: runtime SHARE → fresh actor SHARE → source version SHARE →
lineage plans SHARE → owner FOR UPDATE. Published guards захищають дочірній вміст;
lineage перевіряється на цикли, відсутніх предків та чужого власника. Персональний
source дозволений лише тому самому власнику. HMAC confirmation охоплює actor,
source, lineage, owner/xmin, назву й увесь вміст. Успіх змінює лише xmin клієнта
через no-op updated_at, інвалідує старі confirmations; новий preview дозволяє ще
одну явну копію без глобального ліміту. Deferred guards і fresh admin/fence
перевіряються до commit. Помилка будь-якого дочірнього INSERT відкочує все.

«Керування → Створити персональну копію» у published огляді: клієнт/назва →
preview джерела, версії, днів/тижнів і власника → окреме підтвердження → штатний
редактор draft. Cancel нічого не пише. Список/картка показують owner та logical
parent. Копія підтримує чинні edit/repeat/publish і T4.5 assignment; автоматичного
призначення, client_events або зміни статусу супроводу немає.

Цільовий runner:
`MEAL_PLAN_COPY_ONLY=1 MEAL_PLAN_COPY_BROWSER=1 bash tests/pg/run-stage1.sh`.
Синтетичні дані, disposable PG18 без портів і мережі, cleanup. Перевірки:
`MealPlanCopyTest`, `meal-plan-copy-concurrency.php`, `meal_plan_copy.py`.

У required Targeted CI recipe-група виконує PG-тести, concurrency та окремий
Chromium T4.6 після штатного recipe browser; помилка кожного сценарію блокує групу.
Регресія незалежності спершу призначає source двом клієнтам, потім редагує copied
item через `MealItemWriter` і публікує копію. На кожному кроці порівнює всі поля
source plan/version/days/meals/items і попередні assignments/client_events;
окреме подальше призначення копії також не переписує збережену історію.

## Клієнтське читання T5.1

`GET /api/meal-plan` — захищений read-only browser API. Використовує той самий
`ClientSession::user()` у транзакції, cookie context, serialized session lock,
restore/recovery fence, перевірки ролі, email, binding, auth_version, idle/absolute
expiry та optional MFA, що й AUTH-4/5. Профіль читається через наявний `GET /api/me`;
нового login, bearer token чи client guard немає. Controlled/local/testing та
production OFF залишаються чинними; T5.1 не вмикає feature flags.

Клієнт визначається виключно через `clients.user_id` чинного користувача. API не
приймає селекторів клієнта, призначення або версії; query-параметри їх не змінюють.
Не існує маршруту `/api/meal-plan/{id}` і write-методів. Статус супроводу не є
додатковою умовою доступу до явно призначеного плану (DEC-4A).

`CurrentClientMealPlan` одним SELECT читає призначення, його **точну** published
версію й logical plan. `start_date <= today <= end_date`, NULL кінець відкритий;
`today` — календар `config('app.timezone')`, як у T4.5/DEC-4A. Не перетворювати ці
date на timestamps чи самовільно змінювати календар на client_settings.timezone.
`replaced_by_assignment_id` не приховує старе призначення до включного кінця його
періоду. Майбутнє/завершене призначення або проміжок між періодами не дає fallback.
Універсальний план доступний за призначенням, персональний — лише owner_client_id.
Новіші публікації, draft і персональні плани інших клієнтів не підставляються.

Успішна відповідь містить лише метадані призначення та версії (ID — рядки,
version_number — число, дати періоду — `YYYY-MM-DD`; published_at — timestamp із БД):

```json
{
  "assignment": {
    "id": "42",
    "start_date": "2026-10-27",
    "end_date": null,
    "meal_plan_version": {
      "id": "17",
      "meal_plan_id": "8",
      "version_number": 1,
      "status": "published",
      "published_at": "2026-10-26 12:00:00+00",
      "title": "Призначений раціон"
    }
  }
}
```

Без чинного доступного призначення: **200** `{"assignment":null}`. Немає картки
або чинної client session — **401** `{"code":"unauthenticated",...}`;
controlled auth вимкнено — **404**, недоступна auth-інфраструктура/fence — **503**.
Штатні короткі JSON-помилки AUTH boundary не розкривають SQL чи чужих даних.
Усі відповіді цього endpoint мають `Cache-Control: no-store, private`.

GET/HEAD не пише навіть у `sessions`: для цього маршруту `SerializedAuthSession`
пропускає save й GC; перевірка чинності та session advisory lock залишаються.
Отже читання T5.1 саме по собі **не продовжує idle TTL**; інші AUTH-4/5 endpoints
зберігають штатну поведінку. Немає business events, auto-assignment, refresh,
оновлення статусів чи snapshot writes. Цей endpoint повертає тільки метадані;
дні/позиції й React описані в T5.2 нижче, recipe API та покупки — наступні задачі.

`tests/Auth4/ClientMealPlanTest.php` входить до чинного AUTH-4 набору без змін CI.
Він виконує реальні HTTP-запити з cookie після штатного email OTP login, перевіряє
дати/заміни/gaps, exact version з newer published+draft, PG-відмову draft assignment,
personal/foreign ACL, MFA, revoked/expired/invalid sessions, anonymous/admin,
server timezone, controlled/fence та незмінність усіх public tables разом із xmin.
SQL-log додатково виключає DML/DDL; session GC lottery примусово 100%.
Linux-відтворення: `AUTH4_ONLY=1 bash tests/pg/run-stage1.sh`.

Локальний Windows preflight 27.10.2026: штатний Bash runner потребує Unix Docker
endpoint і shared Linux socket path, тож напряму у PowerShell не запускається.
Для перевірок використано еквівалентний orchestration з PowerShell: окремі PHP
8.5.12 CLI (`pdo_pgsql`) та офіційний PostgreSQL 18.6 Bookworm із pin
`sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc`.
Обидва нові контейнери — `network=none`, без портів і Docker socket. PGDATA — tmpfs,
спільний лише новий socket volume з local driver `type=tmpfs`, без ресурсів
`telmanfit-local`/Infection. `docker inspect` підтвердив ці умови до тестів.
Нова `telmanfit_stage1_test`, непривілейована роль `telmanfit_test_runner`, синтетичні
дані та чинні міграції; guards не змінювалися. Cleanup стосується лише власних
контейнерів/socket volume і тимчасових копій коду/залежностей. Робочий Compose,
Composer, PCOV, конфігурацію Docker та реальну БД не змінювали.

Локальні результати цього зрізу: AUTH-4 разом із 27 новими T5.1 сценаріями —
57 tests / 417 assertions; AUTH-5 — 19 / 131; Stage 4 assignment/publication/copy
на окремому свіжому baseline cluster — 35 / 510. `php artisan test` на SQLite —
84 passed / 356 assertions, 2 SPA-тести штатно skipped через відсутню React-збірку
в тестовій копії; backend assets взято з наявного образу, без rebuild.
Pint для шести змінених PHP-файлів, `git diff --check` та документаційний guard —
PASS. Попередній Stage4 запуск поверх повної auth-схеми відхилено через duplicate
DDL fixtures; успішний прогін вище використовував штатний baseline, без зміни tests
чи guards Stage 4. Статус GitHub required checks перевіряється окремо на head PR.

## Клієнтський календар і меню T5.2

`GET /api/meal-plan/days?week=1` — protected read-only browser API з тією самою
`ClientSession`, AUTH-4/5, serialized lock, CSRF/restore/recovery/MFA boundary,
що й T5.1. Client ID береться лише з чинної сесії; довільні query ID не впливають
на доступ. Єдиний селектор — необов'язковий `week`: ціле число 1..2147483647,
невалідне значення — 422. POST/інші write methods — 405.
Кожен запит повторно читає чинне призначення та exact published version/owner,
без fallback на новішу, минулу або майбутню версію.

### Затверджене календарне правило

`meal_plans.week_start_weekday` (ISO 1..7) задає день тижня для `day_number=1`.
Календарний початок плану — найближчий такий день, що **не пізніше**
`assignment.start_date`. `day_number = календарні дні від початку плану + 1`.
`start_date` задає лише початок доступу, не зміщує нумерацію меню.
Наприклад: Пн + старт Ср → день 3 у середу, день 4 у четвер, день 8 у наступний
понеділок; Ср + старт Ср → день 1 у середу. Дні перед стартом приховані.

API повертає лише наявні дні exact версії між включними `start_date`/`end_date`
(NULL кінець відкритий). Відлік і `today` використовують `config('app.timezone')`,
як чинне визначення активного призначення DEC-4A. Календарні дні обчислюються
Carbon, без 24-годинної арифметики й помилок DST. За замовчуванням вибирається
календарний тиждень сьогодні. Після завершення днів `days=[]`, без автоматичного
повтору першого тижня; попередні доступні дні залишаються в навігації. Вибір
неіснуючого/недоступного тижня теж дає `200 days=[]`, без чужого fallback.

### Типи та відповідь

ID — рядки; `day_number`, `week`, `weeks[].number` — цілі числа; `date`,
`today`, `plan_start_date` — `YYYY-MM-DD`. `assignment` має контракт T5.1.
`weeks` містить навігацію всіх доступних фактичних днів; `days` — тільки обраний
тиждень. Дні впорядковано за `day_number`, прийоми й позиції — `sort_order, id`.
Приклад скороченого до однієї позиції payload:

```json
{
  "assignment": {
    "id": "42", "start_date": "2026-10-28", "end_date": null,
    "meal_plan_version": {
      "id": "17", "meal_plan_id": "8", "version_number": 1,
      "status": "published", "published_at": "2026-10-26 12:00:00+00",
      "title": "Призначений раціон"
    }
  },
  "today": "2026-10-28", "plan_start_date": "2026-10-26",
  "weeks": [{"number": 1, "days": [{"day_number": 3, "date": "2026-10-28"}]}],
  "week": 1,
  "days": [{
    "day_number": 3, "date": "2026-10-28",
    "meals": [{"id": "31", "title": "Сніданок", "items": [{
      "id": "101", "kind": "recipe", "title": "Страва тренера",
      "image_url": null, "quantity": "0.50", "quantity_label": "0,5 порції",
      "recipe_version_id": "9"
    }]}]
  }]
}
```

`items` — discriminated union: `ingredient|recipe` з показаними полями або
`{"id":"101","kind":"unavailable"}` без назви/зображення для неконсистентного
selector чи чужого personal recipe. Recipe ID — **exact published version**,
для legacy визначений exact `food_id`, для batch — перевірені Model I selectors.
Ingredient має `recipe_version_id=null`; `quantity_label`, наприклад `125,5 г`
або `2 шт.`, використовує справжню UOM. `quantity` — exact decimal string;
React показує готовий серверний підпис, нічого не масштабує й не рахує nutrition.
Resolver працює batched; 7 предметних SELECT незалежно від кількості карток.
Відповідь не містить профілю, owner ID, nutrition, фізичних партій або кроків.

`image_url` — nullable logical cover ingredient/recipe через `FoodCards` і
`PublicImageUrl`. Тільки exact allowlisted HTTPS hosts; SPA CSP дозволяє той
самий перевірений перелік без wildcard. Фото кроків не є обкладинками, сервер
не завантажує URL. Немає/пошкоджене фото — текстовий placeholder.

Без активного призначення: `200 {"assignment":null,"weeks":[],"week":null,"days":[]}`
(якщо `week` передано — поле зберігає запитане значення). Помилки AUTH мають
статуси T5.1; **всі** відповіді нового endpoint — `no-store, private`.
GET/HEAD не пишуть у жодні таблиці, не запускають GC і не продовжують idle TTL;
чинні перевірки та locks залишаються. Це не стосується окремих auth-запитів входу.

### React і межі наступних задач

`/app/meal-plan` доступний із чинного кабінету, без нових router/API залежностей
та без чотирьох вкладок T5.5. Login, onboarding і security збережені. Чинний
`api` використовується з credentials/no-store. Тижні/дні, selected date, авторський
порядок, порції/UOM та logical covers походять із API, без демонстраційних страв.
Історичний зріз T5.2 зберігав exact `recipe_version_id` і показував
«Перегляд рецепта буде доступний пізніше», без 404/Filament/latest переходів.
Інгредієнт не має recipe-посилання. КБЖВ, вільні калорії й totals не показуються.

Loading приховує попереднє меню; API error очищує картки та пропонує retry.
Немає призначення/доступного дня/меню — окремі чесні empty states. 401 прибирає
приватний UI та повертає до login. Refresh відбувається кнопкою, при поверненні
фокуса/видимості та раз на 60 секунд видимого екрана; polling uses тільки цей
read-only endpoint, без `/api/me` idle refresh. Зміна assignment/version показує
повідомлення й перевибирає доступний тиждень; generation guard відкидає пізні
відповіді після новішого запиту/unmount. Push/миттєве повідомлення не реалізовано.

Визначення унікальних меню й автоматичного повторення після наявних днів —
окреме відкрите рішення DEC-5M. T5.2 не додає цикл, `cycle_length`, нові таблиці
або enum «Меню A/B/C». T5.4–T5.8, UI покупок, kitchen sessions, Telegram і метрики
цим зрізом не реалізовані. [Докази та відтворення](evidence/client-meal-plan/report.md).


## Клієнтські покупки T5.3

Read-only агрегатор призначеного тижня та захищений API описані в
[контракті списку покупок](shopping-lists.md). Використовуються чинні календар T5.2
та Model I без зміни їхньої поведінки.


## Клієнтський рецепт T5.4

Захищене читання рецепта конкретного meal item, physical composition A/B/C,
токени, обладнання та помилки — [client-recipes.md](client-recipes.md).
Клієнтський UX і повернення — [client-app.md](client-app.md#3-раціон-розклад--меню--рецепт).
