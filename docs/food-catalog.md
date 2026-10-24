# Керування харчовими довідниками

Штатні Filament 5 ресурси під `/admin/catalog/`: `ingredients`,
`ingredient-categories`, `recipe-categories`, `uoms`,
`kitchen-equipment-categories`, `kitchen-equipment`.
У формі продукту редагуються також `ingredient_uoms`.
Дані — лише наявні таблиці [SQL 01](01_nutrition_schema.sql),
[DBML](database.dbml) та [Entity Catalog](entity-catalog.md).
Нових таблиць, колонок, enum, залежностей або seed немає.

## Введення та збереження

`CatalogWriter` приймає лише дозволені поля. Створення продукту створює
`foods(food_type=ingredient)` і `ingredients` в одній транзакції з перевіркою
відкладених constraints до успішної відповіді. Відмова будь-якого запису відкочує
обидва рядки. `food_id`, `food_type` та первинні ID з форми не приймаються.
Редагування зберігає food identity і всі історичні посилання.

КБЖВ — на **100 г**; чотири обов’язкові десяткові рядки 0–99999.99,
без float, експоненти, NaN/Infinity, коми, пробілів, розділювачів тисяч або
округлення введення з більш ніж двома десятковими знаками. Категорія необов’язкова.
Назва не може бути порожньою чи складатися лише з пробілів.

Грам — неявна базова одиниця кожного продукту, фактор 1; запис `ingredient_uoms`
для `g` не створюється. Додаткова конверсія має унікальну UOM і фактор
0.01–99999.99. Є максимум один default; його перенесення спочатку скидає
попередній, потім встановлює новий у тій самій транзакції. У формі можна
прибрати незбережений рядок. Відсутність раніше збереженої UOM у запиті
відхиляється: видалення/заміна збережених конверсій у v1 закриті.

Код UOM після створення незмінний. Семантика `g=mass`, `serving=serving`
перевіряється writer; label можна виправити з подальшим явним Refresh у
чернетках рецептів. Інші kind — лише чинний CHECK. Відмову зміни code додатково
гарантує наявний SQL trigger. Жодного нового DB-обмеження в цьому slice немає.

Категорії й обладнання зберігають існуючі UNIQUE/FK. Обладнання має лише назву
й необов’язкову категорію; параметри `min_value`, `scaling_mode`, UOM і tip
належать використанню в рецепті. Видалення та bulk delete усіх каталогів
заборонені через ресурс і Policy, без cascade/архівування або втрати історії.

## Авторизація і конкурентні зміни

Доступ має лише чинний admin через наявну Filament session/MFA/CSRF межу.
Policy, Resource та writer перевіряють актуального користувача з БД й чинний
panel/recovery fence. Writer утримує user shared lock під час транзакції;
підміна ролі в hydrated User не надає доступ. Coach/client не мають нових прав.
Каталоги доступні адміністратору незалежно від прапорця редактора рецептів;
це не змінює його доступність або activation-рішення.

Edit зберігає `#[Locked]` fingerprint вихідного запису й усіх його conversions.
Writer бере row lock, повторно читає стан і відхиляє stale форму до запису.
Fingerprint — перевірка рівності, не credential. SQL UNIQUE/FK залишаються
незалежними guards; конкурентний конфлікт дає повідомлення про reload/review,
без автоматичного retry чи перезапису другого автора.

Порядок: user → ingredient → conversions → UOM. UOM writer спочатку блокує
пов’язані ingredients за ID, для `g` — всі ingredients, потім сам UOM.
Він не починає із UOM lock із подальшим захопленням ingredient. Це сумісно з
наявним conversion trigger і locks [Refresh/Publish](recipe-batches.md#revision-locks-та-обмеження).

Catalog writer не торкається recipe/plan/assignment або accepted snapshots.
Новий preview бачить current/frozen diff, Save/Publish вимагають explicit
Refresh/review. Manual quantity/UOM та published totals залишаються frozen;
legacy використовує збережені поля без реконструкції історичних nutrition.
Цей slice повторно використовує чинний BatchEditor без нового writer рецептів.

## Тести та межа даних

[PG/Filament тести](../tests/Postgres/FoodCatalogTest.php) перевіряють атомарність,
late rollback, decimal, FK/subtype/default/UNIQUE/RESTRICT, immutable code,
admin/coach/client та зміну ролі вже відкритої сторінки, CAS і refresh.
[Concurrency](../tests/pg/food-catalog-concurrency.php) доводить справжнє
очікування ingredient lock, відмову stale writer, conversion phantom і порядок
UOM locks із COMMIT/reload. [Chromium](../tests/browser/food_catalog.py) проходить
MFA, ручне create/edit, validation, conversion/default, пошук, empty state,
дві вкладки та 1440/390/320 light/dark. Вони включені в чинний recipe PG/browser
runner; evidence актуального head зберігається також у Full CI artifacts.

Лише synthetic fixtures у disposable PG18; реальні каталоги порожні до ручного
погодженого введення. Статус T1.5 та наступний Stage gate — у [roadmap](roadmap.md).
Перед реальним наповненням власник окремо погоджує мінімум:

- коди, labels і kind одиниць, зокрема відповідність `gram`/`g` та `serving`;
- назви категорій продуктів, рецептів і кухонного обладнання;
- назви обладнання та його категорії;
- для кожного продукту: назву, категорію за потреби, джерело КБЖВ на 100 г,
  додаткові UOM/точні грамові фактори та вибір default.

Це перелік потрібних рішень, а не запропоновані значення чи implicit seed.
SKU, фасування, їстівна маса та імпорт Excel сюди не входять.
