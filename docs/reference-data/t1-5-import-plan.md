# T1.5 — отримані довідники та контракт майбутнього імпорту

**Статус:** вихідні дані отримано, **імпорт/seed не виконано**. Канонічні статуси — [roadmap.md](../roadmap.md). Додаткові бізнес-рішення, яких немає у вихідному Excel, **не вигадувати**.

## Джерело

- Оригінал: прикріплений власником файл `T1.5 - початкові значення довідників.xlsx` (не зберігаємо binary у Git).
- **Точна текстова проєкція всіх 5 аркушів**: [t1-5-source.json](t1-5-source.json), з вихідними заголовками, даними та URL, **до будь-якої нормалізації чи імпорту**.
- Під час перенесення перевірено кількість рядків та контрольні хеші FNV-1a (Unicode codepoints, усі комірки рядка розділено U+001F, рядки — U+000A) порівняно з XLSX:

| Аркуш | Записів | Контрольне значення |
|---|---:|---|
| `ingredient_categories` | 13 | `78a049ed` |
| `recipe_categories` | 5 | `e60d87fb` |
| `uoms` | 19 | `e962ad51` |
| `kitchen_equipment` | 38 | `7bf936c4` |
| `meal_types` | 9 | `2d5139e8` |
| **Разом** | **84** | |

**Обережно:** source JSON містить джерельні значення, а не схвалену схему seed. Не імпортувати його напряму в БД, не робити авто-migration і не видаляти колонки, яких немає в БД. Збережено оригінальні коди/назви, зокрема пробіли у двох назвах категорій.

## Зіставлення з погодженою PostgreSQL-схемою

| Джерело Excel | Таблиця PostgreSQL | Очевидне зіставлення | Що ще не вирішено |
|---|---|---|---|
| `ingredient_categories`: `ingredient_category_id, name, description` | `ingredient_categories(id smallint identity, name)` | `name` → `name` | Текстові ID/slug і `description` **немає де зберігати** без зміни схеми чи іншого узгодженого зберігання |
| `recipe_categories`: `dish_category_id, name, description` | `recipe_categories(id identity, name)` | `name` → `name` | Так само: код і опис відсутні у таблиці |
| `uoms`: `uom_id, symbol, name_1, name_2_4, name_5, name_frac` | `uoms(id identity, code, label, kind)` | `name_1` може бути основним `label` | `kind` **обов'язковий**, усіх відмінкових форм/символу в схемі немає; `gram` із Excel конфліктує з **зарезервованим** кодом `g` у Laravel/SQL; обов'язковий `serving` **відсутній** у Excel |
| `kitchen_equipment`: `equipment_id, name, image_url, category, default_uom_id` | `kitchen_equipment(id identity, name, kitchen_equipment_category_id)` + `kitchen_equipment_categories(id identity, name)` | `name` → `name` | Немає колонок для slug, `image_url`, `default_uom_id`; 5 кодів категорій (`cookware`, `prepware`, `device`, `tool`, `consumable`) **не мають погоджених назв** у таблиці `kitchen_equipment_categories` |
| `meal_types`: `meal_type_id, name, order_number` | `meal_types(id identity, code, title, sort_order)` | `meal_type_id` → `code`, `name` → `title`, `order_number` → `sort_order` | Без очевидної структурної втрати; перевірити унікальність кодів і порядок імпорту |

Інші довідники попереднього T1.5 (`metrics`, `fitness_equipment`) відсутні в цьому Excel. **Не заповнювати їх вигаданими значеннями.** Можна погоджувати пізніше, коли з'явиться їхній функціонал.

## Рішення, потрібні власнику перед повним імпортом

1. **UOM-коди й форми:** затвердити `gram` з Excel → `g` у БД (без семантичної зміни), додати `serving`, узгодити `kind` для кожного коду. Визначити, де зберігати `symbol`, `name_1`, `name_2_4`, `name_5`, `name_frac`: змінити схему або зберігати окремий незмінний UI-словник; **не відкидати їх без рішення**.
2. **Категорії та обладнання:** чи потрібні в БД стабільні машинні коди, описи, URL зображень, default UOM; чи достатньо збереження лише `name` у БД плюс окремі UI-дані? Якщо потрібні — спочатку окремо затвердити схему.
3. **Категорії обладнання:** погодити людські назви для 5 вихідних category-кодів та їхній зв'язок з `kitchen_equipment_categories`.
4. **Границі seed:** застосовувати тільки нову ізольовану PostgreSQL 18 після погодження; наявні чи production дані не чіпати, не створювати автоматичних truncate/delete/upsert по text label без review.

## Автономна підготовка Codex (дозволена до вирішення питань)

- Підготувати **read-only validator** source JSON: перевірка колонок, унікальності slug і назв, `default_uom_id` ↔ `uoms.uom_id`, `category` ↔ множина категорій, контрольні кількості.
- Зробити **dry-run normalization report** за реальною схемою `docs/01_nutrition_schema.sql` з окремими `mapped`, `unmapped`, `needs_owner_decision`. Без створення нових колонок або довільних `kind`.
- Лише після погодження явної таблиці mappings створювати idempotent seed, transaction, negative tests/rollback на чистому PG18. SQL/DBML та історичні міграції змінювати **тільки** за окремим схваленням.

**Заборона:** не трактувати це отримання вихідних даних як «T1.5 виконано»; до імпорту та погодження mapping T1.5 залишається частковою задачею.
