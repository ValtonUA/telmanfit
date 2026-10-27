# Список покупок — T5.3

## API

`GET /api/meal-plan/shopping-list?week=1` — read-only browser API під чинним
`ClientSession`. Єдиний селектор — необов'язковий цілий `week` від 1 до 2147483647;
без нього використовується поточний авторський тиждень за
[календарем T5.2](meal-plans.md#клієнтський-календар-і-меню-t52).
Клієнт визначається через `clients.user_id`. Query з чужими client/assignment/version
ID не впливає на вибір. Використовуються лише чинне призначення та його exact
published plan/recipe versions, з перевіркою personal ownership.

Відповідь 200: `assignment` за T5.1, `weeks` за T5.2, `week`, `days` (тільки
`day_number` і `date` фактично доступних днів), `state`, `items`.
`state=ready` означає обчислений список; `no_assignment` — немає чинного доступного
призначення; `week_unavailable` — у вибраному тижні немає доступних днів. В останніх
двох станах `items=[]`. Не створюються повтори після завершення наявних днів.
У порожньому стані без призначення: `assignment=null`, `weeks=[]`, `days=[]`,
`week` дорівнює запитаному числу або NULL.

Приклад однієї позиції в `items` (ID та всі кількості — рядки):

```json
{
  "ingredient_id": "17",
  "name": "Синтетичний продукт",
  "quantity_grams": "210.000000",
  "quantity_grams_exact": "210",
  "source_quantities": [{
    "uom_id": "8",
    "unit_label": "банка",
    "quantity": "0.500000",
    "quantity_exact": "1/2",
    "grams_per_unit_exact": "420",
    "conversion_basis": "frozen_conversion"
  }]
}
```

`source_quantities` зберігає пропорційні авторські кількості та одиниці. Це потреба
у продуктах, а не рекомендація придбати половину фізичної банки або конкретний SKU.
Назва інгредієнта береться з довідника за exact ID. Для B/C label і factor беруться
з frozen `input_snapshot`; для ingredient/legacy/A, де такого snapshot немає,
label — довідниковий, а `conversion_basis=stored_ratio` і відношення виводиться
тільки зі збережених grams/quantity. Live `grams_per_uom` не застосовується повторно.
Historical ratio не є підтвердженням магазинного фасування.

Невалідний `week` — 422. За недоступного selector/UOM, чужого recipe owner,
відсутнього складу, frozen рядка чи некоректних кількостей — 409
`{"code":"shopping_list_unavailable","message":"Не вдалося сформувати повний список покупок. Зверніться до тренера."}`.
Частковий список і назви чужих страв не повертаються. Інфраструктурні помилки
проходять через штатну коротку JSON boundary; auth-статуси відповідають T5.1.
Усі відповіді мають `Cache-Control: no-store, private`.

## Розрахунок і сумісність

`ClientShoppingList` повторно використовує чинні `ClientMealPlanDays`,
`CurrentClientMealPlan` та `MealItemResolver`, не змінюючи T5.2. Склад і довідники
завантажуються пакетно; число SELECT не зростає на кожну позицію.

- Окремий ingredient: збережені `resolved_quantity_grams`, без refresh конверсій.
- Legacy: `recipe_ingredients.quantity_grams × item.quantity / servings_count`.
  Склад legacy описує весь рецепт, а не одну порцію.
- A/1: рядки `recipe_ingredients × item.quantity`.
- B/7, C/3, C/4: exact `recipe_batch_quantities` вибраного format,
  `quantity_grams × item.quantity / divisor`. C/3 та C/4 мають один format ID.

Сім B/7 дають одну B. Три C/3 та чотири C/4 дають дві C. Для довільного набору
порцій це пропорційна потреба, без виведення цілих фізичних партій чи cooking events
([Stage 3 §11](proposals/stage-3-recipe-batch-editor.md#11-контракт-stage-4-food-формат-порція-і-фізичне-приготування)).

Їстівні грами підсумовуються за ingredient ID. Однакові назви різних ID не
об'єднуються. Вихідні кількості додатково групуються за UOM ID, label, basis та
точною конверсією; несумісні конверсії залишаються окремими `source_quantities`.
Змішані грами й штуки можна сумувати в їстівних грамах того самого інгредієнта,
але не в умовних «штуках» без сумісної конверсії.

Арифметика — наявний Brick Math, точні раціональні числа з decimal strings,
без float чи проміжного округлення. Після всієї агрегації decimal поля показуються
з 6 знаками, HalfUp; поля `*_exact` зберігають точне ціле або скорочений дріб
`numerator/denominator`, включно з нескінченними десятковими розкладами.
Це точність серіалізації, а не закупівельне округлення. Правила купівлі упаковок
ще не погоджені; жодного ceil до банки, пачки чи штуки немає.

## React-екран T5.7a (Draft)

Клієнтський UI `/app/meal-plan/shopping-list` використовує цей API без змін контракту: посилання з обраного тижня `/app/meal-plan`, вибір тижня з `weeks`, стани no-assignment/week-unavailable, нечасткові 409, retry/reload, очищення списку на помилках і втраті ClientSession. Показує точні харчові грами, а дробові вихідні UOM лише довідково — **без** округлення до упаковок чи вигаданих категорій. Деталі: [контракт T5.7a](shopping-list-ui.md). До merge — заплановано, у production не активовано.

## Захист і перевірки

Новий маршрут доданий у наявні boundary та safe-read винятки з session save/GC;
ACL, MFA, restore fence, revocation, idle/absolute TTL та feature flags не змінені.
GET/HEAD не продовжують idle TTL. Немає нових таблиць, міграцій, events, записаних
списків, чекбоксів чи історії покупок. Production activation та UI не входять у T5.3.

`tests/Auth4/ClientShoppingListTest.php` входить у штатний AUTH-4 PostgreSQL suite:
сім B/7, три C/3 + чотири C/4, дробові A/B/C, legacy, mixed UOM і несумісні
конверсії, frozen банки, exact versions, однакові назви різних ID, календарні межі,
missing assignment/week, foreign owner/ID, invalid UOM/selector, revoked/expired
sessions, MFA та незмінність усіх public tables/xmin і відсутність DML після GET.
Успадковані gates також перевіряють HEAD, controlled OFF, fence та 405 для POST.

Відтворення лише на новій disposable synthetic PostgreSQL 18.6 без портів:

```bash
AUTH4_ONLY=1 bash tests/pg/run-stage1.sh
```

Тести використовують чинні SQL guards; негативні сценарії не вимикають тригери.
Стан GitHub Fast/Docs/Targeted на фінальному head і результати локальних прогонів
фіксуються в описі Draft PR. Merge та production go/no-go потребують окремого рішення.
