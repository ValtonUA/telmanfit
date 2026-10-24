# Раціони — контракт T4.1

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
Published і legacy показуються лише для читання. Зміна назви чи власника наявного
плану також закрита: окреме правило зміни metadata published plan не затверджене.

## Серверні межі та дані

`MealPlanFoundation::create` повторно перевіряє чинного admin і recovery/restore
fence, тримає shared actor/owner locks, створює logical plan та одну `draft`
версію в одній транзакції й перевіряє deferred constraints до повернення успіху.
Неконтрольовані поля не використовуються: ID, lineage, publication/version/status,
food/FK і replacement links не дозволені для mass assignment. Роль, статус
супроводу, foods, рецепти та призначення не змінюються.

`MealPlanPolicy` дозволяє лише admin list/view/create. Policy нижніх сутностей
дозволяє admin читання; create/update/delete/replicate закриті. Штатні middleware
панелі забезпечують session/MFA/CSRF, з повторною авторизацією Livewire-запитів.
Сервіс не створює сесію й не обходить ці middleware.

Сім моделей відповідають чинним SQL01/02 FK: owner/parent/copies, plan/versions,
version/days/assignments, day/meals, meal/type/items, item/food/uom,
assignment/client/version/creator/replacement/replaces. Допоміжна `Food` представляє
наявний супертип та зв’язок ingredient; нової таблиці немає. Відсутні SQL timestamps
не генеруються; `created_at`/дати незмінного типу, numeric — `decimal:2`, без float.
Наявні partial UNIQUE, immutable guards та EXCLUDE збережені без зміни SQL/міграцій.

## Свідомо закриті команди

Генератор днів (T4.2), meal item writer (T4.3), Publish (T4.4), призначення/заміна
(T4.5), CoW (T4.6), Delete/replicate/archive та CRUD довідника meal types відсутні.
Довідник типів читається з чинних рядків, seeds не додаються.

Перед T4.3 потрібен окремий forward-only schema/API контракт Model I:
`recipe_version_id`, `recipe_batch_format_id`, `recipe_portion_divisor`, явні
A/1, B/7, C/3, C/4 та guards; історія без backfill, legacy/ingredient — NULL.
[Proposal §11/§13](proposals/stage-3-recipe-batch-editor.md#11-контракт-stage-4-food-формат-порція-і-фізичне-приготування)
ще містить формулювання «для погодження»; gate #54 задає напрям, але це не дозвіл
додавати selectors у T4.1. DEC-4A потрібне перед T4.5. T1.5 та DEC-3T не блокують
цей synthetic foundation. Production activation — окремий go/no-go.

## Перевірки

- `tests/Unit/MealPlanModelsTest.php`: PK/timestamps/fillable boundary семи моделей.
- `tests/Postgres/MealPlanFoundationTest.php`: реальні FK/nullable/decimal/date
  relations, atomic create, deferred late fault rollback, name/owner validation,
  прямий Livewire, fresh-role ACL, SQL unique/immutable/exclusion negatives,
  populated legacy recipe/ingredient plans та hash незмінності історії.
- `tests/pg/meal-plan-concurrency.php`: окремий процес, доведене очікування locks,
  duplicate draft / immutable detail / overlap відмови; два атомарні незалежні
  створення без lost write. Входить у штатний recipe/Stage4 runner.
- `tests/browser/meal_plans.py`: справжня MFA-сесія, create/list/search/detail,
  точна published hierarchy та порожня чернетка, 1440/390/320 light/dark,
  overflow, CSRF 419, revoked signed Livewire/HTTP 403 та незмінна історія.
  Виконується разом із Stage 3 catalog/recipe регресіями; synthetic screenshots
  і checks зберігаються як CI artifacts у `meal-plans/`.

Локальне відтворення: `RECIPE_BATCH_ONLY=1 RECIPE_BATCH_BROWSER=1 bash tests/pg/run-stage1.sh`.
Runner використовує лише нову disposable PG18, network none, без зовнішніх портів,
синтетичні дані та cleanup. Повний CI і machine gate — [ci.md](ci.md).
