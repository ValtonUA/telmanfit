# Раціони — контракт T4.1/T4.2

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
  одиниця та збережені nullable грами. Це перегляд збережених значень без
  перерахунку nutrition або тлумачення batch food як A/1.

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
не реалізовано. Прийом із позиціями видаляти заборонено; позиції не змінюються.
Номери днів не редагуються і самі дні не видаляються, тому семиденний каркас
залишається безперервним. Розширені операції перестановки/видалення днів потребують
окремого UX-рішення, якщо знадобляться; це не блокує зміну вмісту дня.

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

## Свідомо закриті команди

Meal item writer (T4.3), Publish (T4.4), призначення/заміна
(T4.5), CoW (T4.6), Delete/replicate/archive та CRUD довідника meal types відсутні.
Довідник типів читається з чинних рядків, seeds не додаються.

Перед T4.3 потрібен окремий forward-only schema/API контракт Model I:
`recipe_version_id`, `recipe_batch_format_id`, `recipe_portion_divisor`, явні
A/1, B/7, C/3, C/4 та guards; історія без backfill, legacy/ingredient — NULL.
[Proposal §11/§13](proposals/stage-3-recipe-batch-editor.md#11-контракт-stage-4-food-формат-порція-і-фізичне-приготування)
ще містить формулювання «для погодження»; gate #54 задає напрям, але це не дозвіл
додавати selectors у T4.2. DEC-4A потрібне перед T4.5. T1.5 та DEC-3T не блокують
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
- `tests/pg/meal-plan-structure-concurrency.php`: незалежні PG-сесії з доведеним
  lock wait; double/retry, edit-vs-generate, stale edit, revoked role та runtime
  fence-vs-writer без інверсії locks.
- `tests/pg/meal-plan-concurrency.php`: окремий процес, доведене очікування locks,
  duplicate draft / immutable detail / overlap відмови; два атомарні незалежні
  створення без lost write. Входить у штатний recipe/Stage4 runner.
- `tests/browser/meal_plans.py`: справжня MFA-сесія, create/list/search/detail,
  точна published hierarchy, порожня чернетка, генерація/редагування/append/reload,
  1440/390/320 light/dark,
  overflow, CSRF 419, revoked signed Livewire/HTTP 403 та незмінна історія.
  Виконується разом із Stage 3 catalog/recipe регресіями; synthetic screenshots
  і checks зберігаються як CI artifacts у `meal-plans/`.

Локальне відтворення: `RECIPE_BATCH_ONLY=1 RECIPE_BATCH_BROWSER=1 bash tests/pg/run-stage1.sh`.
Runner використовує лише нову disposable PG18, network none, без зовнішніх портів,
синтетичні дані та cleanup. Повний CI і machine gate — [ci.md](ci.md).
