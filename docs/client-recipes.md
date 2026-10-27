# Перегляд призначеного рецепта — T5.4

## API та доступ

`GET|HEAD /api/meal-plan/items/{meal_item_id}/recipe` використовує чинний
`ClientSession` AUTH-4/5. Клієнт визначається через `clients.user_id`; URL містить
ID позиції, а не рецепта. Query parameters не вибирають client/assignment/version,
формат чи divisor. Допустимі лише позиції поточного доступного exact published
призначення й дні у включних календарних межах T5.2. Перевіряються Model I,
exact published recipe version і власник personal recipe.

HTTP controller читає призначення, календар і деталі в одному REPEATABLE READ
snapshot під чинним session/user gate. Наступне читання після заміни призначення
відмовляє для старого item ID. GET/HEAD не запускають session GC, не записують
session payload/last_activity, не змінюють TTL або дані домену. Відповіді мають
`Cache-Control: no-store, private` і `Referrer-Policy: no-referrer`.

Недоступна позиція, ingredient замість recipe, чужий owner, неповний склад,
відсутній frozen рядок або невідомий/пошкоджений токен повертають однаковий 404:

```json
{"code":"recipe_unavailable","message":"Рецепт недоступний. Поверніться до раціону або зверніться до тренера."}
```

401 — session expired/revoked або недостатнє MFA assurance; 503 — restore fence
чи недоступність auth/DB; controlled OFF — 404. Авторизація не обходить чинні
глобальні gates. Технічний збій не перетворюється на вигадані дані рецепта.

## Кількості та текст

Відповідь містить `assigned_servings`/`assigned_servings_label` окремо від
`preparation_servings`. Фізичний склад **не множиться** на спожиті порції:

| Варіант | Склад приготування | `preparation_servings` |
|---|---|---|
| Legacy | Exact published `recipe_ingredients.quantity/uom_id/quantity_grams` | Збережений `servings_count` |
| A/1 | База A, `recipe_ingredients` | 1 |
| B/7 | Весь frozen склад вибраного B | 7 |
| C/3 | Весь frozen склад C | 3 |
| C/4 | Той самий фізичний склад C | 4 |

Для B/C UOM label береться з frozen `input_snapshot`; live conversions не
застосовуються, немає закупівельного округлення DEC-5S. Назва batch ingredient —
з `ingredient_snapshot`. Legacy ingredient name, A/legacy UOM labels та назви/UOM
обладнання — наявні каталоги: схема не зберігає окремого immutable display snapshot
для всіх цих значень. Збережені quantity/grams і resolved equipment
min_value/UOM/tip не перераховуються. Для batch обладнання читається саме
`recipe_batch_equipment` вибраного формату, включно з A і manual overrides;
для legacy — baseline обладнання. NULL означає «Без числової вимоги».

`RecipeTokens::render` отримує selected physical quantities/equipment. У JSON
повертається plain text, React екранує його як текст; HTML renderer відсутній.
Невідомі/пошкоджені токени закривають **увесь** рецепт без часткового вмісту.
Час і температура залишаються авторським текстом; таймерів DEC-3T немає.
Компоненти й інгредієнти мають авторський порядок, кроки — `direction_number`.
За відсутності кроків UI явно пропонує звернутися до тренера.

Синтетичний приклад B/7 (одна позиція інгредієнта; ID — рядки):

```json
{
  "meal_item_id": "42",
  "assignment_id": "8",
  "week": 2,
  "day_number": 9,
  "recipe_version_id": "17",
  "title": "Синтетичний рис",
  "image_url": null,
  "assigned_servings": "1.00",
  "assigned_servings_label": "1 порція",
  "format": "B/7",
  "preparation_servings": "7",
  "ingredients": [{"id":"6","name":"Рис","quantity":"1050.00","unit_label":"г","quantity_grams":"1050.00","component":null}],
  "equipment": [],
  "directions": [{"number":1,"text":"Промийте 1050.00 г рису. Готуйте 20 хвилин.","image_url":null}]
}
```

## Межі реалізації

Нових таблиць, міграцій, залежностей чи router немає. T5.3 shopping-list API не
змінено; кухонні сесії, DEC-5M, таймери, production flags і merge поза scope.
Фото перевіряються через `PublicImageUrl::safe`; сервер не завантажує зображень.
Результати й скриншоти: [звіт перевірок](evidence/client-recipe/report.md).
