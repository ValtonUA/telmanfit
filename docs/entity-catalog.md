# Entity Catalog v3 — повний перелік таблиць PostgreSQL

> **Джерела істини:** структура таблиць і полів — `docs/database.dbml`; фактичні PostgreSQL-обмеження й тригери — SQL-модулі `docs/01_nutrition_schema.sql` … `docs/04_fitness_messaging.sql`; цей каталог фіксує призначення сутностей, зв'язки та затверджені бізнес-рішення. DBML не є повним описом CHECK, часткових індексів, EXCLUDE та тригерів — вони перелічені в кінці `database.dbml`.
>
> **Оновлено:** 20.10.2026 за результатами статичного аудиту v0.3.


За поточними домовленостями маємо 38 бізнес-таблиць у 8 групах та 7 окремих auth-таблиць AUTH-2 (нижче). Це не означає, що всі вони мають бути реалізовані одночасно: каталог описує узгоджену структуру першої повноцінної версії.

## 1. Users & Client Management

7 таблиць

| Таблиця                     | Призначення                                                              |
| --------------------------- | ------------------------------------------------------------------------ |
| `users`                     | Облікові записи користувачів, Telegram-авторизація та доступ до Filament |
| `clients`                   | Клієнтські профілі, відповідальний тренер, статус і початок супроводу    |
| `client_profiles`          | Загальні характеристики, ціль, труднощі, пріоритети та режим роботи |
| `client_onboardings`        | Історичний знімок початкової анкети клієнта                                                 |
| `client_nutrition_profiles` | Харчові цілі, уподобання, обмеження                                      |
| `client_activity_profiles`  | Ціль щодо кроків, фізичні обмеження (зокрема травми), бажані дні тренувань |
| `client_settings`           | Індивідуальні налаштування, графік нагадувань і звітів                   |

Ключові правила:

- Кожен клієнт має відповідального тренера: явний вибір серед `coach`/`admin`, без default. Filament доступний лише `admin`.
- Нові клієнти отримують доступ виключно за персональним запрошенням, створеним admin у Filament (рішення користувача 21.10.2026). Ручне створення карток адміністратором в обхід запрошення заборонене.
- Одна людина має один `users` і максимум один `clients`; ім’я та прізвище — `users.first_name/last_name`, обчислюване `full_name`; `name` — тимчасовий legacy fallback, без `clients.display_name`. Наявні картки з `user_id=NULL` зберігаються як перехідні записи; nullable у SQL не дозволяє ручного створення через UI.
- PWA та Telegram Mini App максимально використовують один React-застосунок, Laravel API і PostgreSQL; підтримуються одна або обидві оболонки, Mini App може мати менше можливостей.
- Заплановано «Підключити Telegram» у PWA з підтвердженням володіння Telegram-акаунтом і зворотне підключення PWA до того самого акаунта. Автоматичне ототожнення за ім’ям, username чи email заборонене.
- Межі реалізованого invitation/email OTP/MFA — [project-status](project-status.md); Telegram linking ще не реалізовано. Поточна анкета має лише три питання; повне завершення потребує окремого контракту.
- Типізовані профілі, семантика полів, SQL-обмеження й безпечний перехід описані в [контракті профілів](client-profiles.md). Кожен профіль/анкета має PK/FK `client_id`; архів після завершення незмінний. NOT NULL імен — ціль після ручного уточнення legacy записів.
- Реєстрація в застосунку не запускає супровід автоматично.
- Наявність клієнтського профілю не означає, що платний супровід активний.
- Один `user` потенційно може бути і тренером, і клієнтом, але самостійне ведення у v1 не реалізуємо.

## 2. Nutrition Planning

7 таблиць

| Таблиця                        | Призначення                                         |
| ------------------------------ | --------------------------------------------------- |
| `meal_plans`                   | Логічні універсальні/персональні плани; походження персональних копій |
| `meal_plan_versions`           | Чернетки та незмінні опубліковані версії планів |
| `meal_plan_days`               | Дні конкретної версії плану, послідовні тижні по 7 днів |
| `meals`                        | Прийоми їжі конкретного дня                         |
| `meal_types`                   | Довідник типів прийомів їжі                         |
| `meal_items`                   | Інгредієнти або конкретні версії рецептів та їхня кількість |
| `client_meal_plan_assignments` | Історія призначень конкретних опублікованих версій планів |

Ми домовилися, що:

- План складається з послідовних семиденних тижнів.
- Дні та стандартні прийоми їжі створюються автоматично, але їх можна редагувати.
- У клієнта відображається лише явно призначена опублікована версія.
- Нова публікація не оновлює клієнтські раціони автоматично.
- Персональні зміни плану створюють незалежний похідний план (Copy-on-Write), із посиланням `parent_meal_plan_id` на оригінал.
- Опубліковані версії та їхні дні, прийоми їжі й позиції незмінні; зміна призначення є окремою явною операцією.
- Зараз плани створюєте й призначаєте лише Ви.
- Майбутній самостійний вибір клієнтом плану з Вашого каталогу використовуватиме ту саму систему призначень.

## 3. Food Catalog & Recipes

10 таблиць

| Таблиця                 | Призначення                                        |
| ----------------------- | -------------------------------------------------- |
| `foods`                 | Супертип каталогу: інгредієнт або конкретна опублікована версія рецепта |
| `ingredients`           | Окремі продукти, харчова цінність на 100 г         |
| `recipes`               | Логічні рецепти, зокрема персональні похідні рецепти |
| `recipe_versions`       | Чернетки та незмінні опубліковані версії рецептів; КБЖВ і кількості — на 1 порцію; кроки — в `recipe_directions` |
| `recipe_ingredients`    | Склад конкретної версії рецепта із кількостями інгредієнтів |
| `recipe_batch_formats` | Три фізичні партії A/B/C, frozen totals та policy; один version/food |
| `recipe_batch_quantities` | B/C author quantity/UOM, edible grams, frozen conversion, mode/context/ack |
| `recipe_batch_equipment` | Resolved equipment snapshots трьох форматів; nullable min_value |
| `recipe_components`     | Необов'язкові групи інгредієнтів конкретної версії рецепта |
| `ingredient_categories` | Категорії продуктів                                |
| `recipe_categories`     | Категорії рецептів                                 |
| `uoms`                  | Одиниці вимірювання                                |
| `ingredient_uoms`       | Перетворення одиниць інгредієнтів у грами          |

Залишаємо вже погоджений принцип:

Інгредієнти мають КБЖВ на 100 г і не версіонуються. Published-рецепти завжди читають frozen значення: зміна довідника не змінює історичний показ. Для `batch_v1` A — `recipe_ingredients` у g; `recipe_batch_formats` зберігає три фізичні totals, `recipe_batch_quantities` — авторські B/C quantity/UOM, їстівні grams, frozen conversion, auto/manual та review context; auto C успадковує фактичну UOM/factor B і прийняте правило округлення за UOM code, manual C лишається незалежним. `recipe_batch_equipment` фіксує обладнання для кожного формату зі збереженням nullable min_value. Повний контракт — [forward Stage 3](recipe-batches.md), SQL — [11](11_recipe_batches.sql) та forward-ревізії функцій [12](12_recipe_equipment.sql) і [13](13_recipe_legacy_conversion.sql). C3 дозволяє лише явну одноразову draft legacy→batch конвертацію з originals у наявних snapshots; published legacy лише створює нову draft-версію того самого recipe, без історичного nutrition backfill. Equipment A зберігає ручну базу, B/C — auto/manual min_value/UOM/tip; стабільний baseline PK, nullable min_value, ceil count та adequacy acknowledgement поточного складу. Достатність фізично перевіряє автор; нових таблиць/полів для неї немає.

Не змушуємо кожен рецепт мати вагу готової страви. Коли Ви приготували каструлю їжі на п'ять порцій, достатньо розділити її на п'ять частин.

`foods` — супертип для пошуку/вибору в `meal_items`: кожен рядок `foods` відповідає або інгредієнту, або конкретній **опублікованій** `recipe_version`. Тип визначає `foods.food_type`, а складений FK `(food_id, food_type)` гарантує, що нащадок має відповідний тип. При збереженні `meal_items` посилання на рецепт фіксує саме `recipe_version_id` через `food_id`, а не «поточну» версію рецепта.

Персональні рецепти — окремі похідні `recipes` (із `parent_recipe_id` або еквівалентним зв'язком), без полів `custom_*` у плані. Опубліковані `recipe_versions`, їхні компоненти, склад, інструкції та обладнання незмінні.

## 4. Recipe Preparation

4 таблиці

| Таблиця                        | Призначення                                    |
| ------------------------------ | ---------------------------------------------- |
| `recipe_directions`            | Покрокова інструкція конкретної версії рецепта |
| `kitchen_equipment`            | Довідник кухонного обладнання                  |
| `kitchen_equipment_categories` | Категорії кухонного обладнання                 |
| `recipe_kitchen_equipment`     | Обладнання конкретної версії рецепта |

У `recipe_directions` передбачаємо текстові токени для кількостей інгредієнтів, обладнання та таймерів.

Типи токенів і правила масштабування обладнання реалізуємо в Laravel без додаткових довідників.

Модуль альтернативних способів приготування свідомо відкладаємо.

## 5. Progress Tracking

5 таблиць

| Таблиця                     | Призначення                                   |
| --------------------------- | --------------------------------------------- |
| `metrics`                   | Універсальний довідник показників             |
| `client_metric_assignments` | Метрики, які тренер призначив клієнту         |
| `client_metrics`            | Історія фактично внесених значень             |
| `client_weekly_reports`     | Щотижневі звітні періоди та відповіді клієнта |
| `client_progress_photos`    | Фотографії прогресу                           |

Це один із найважливіших результатів нашого обговорення.

Метрики незалежні від щотижневих звітів.

Клієнт може внести вагу, талію, сон або стрес будь-коли. Щотижнева анкета — ще один спосіб отримання цих даних, а також інструмент зворотного зв'язку.

Сирі відповіді анкети зберігатимемо в `client_weekly_reports`, а числові метрики — у `client_metrics`.

## 6. Fitness Equipment

2 таблиці

| Таблиця                    | Призначення                              |
| -------------------------- | ---------------------------------------- |
| `fitness_equipment`        | Довідник фітнес-інвентаря                |
| `client_fitness_equipment` | Обладнання, наявне у конкретного клієнта |

Повноцінного модуля тренувань у v1 не буде.

Обидві таблиці інвентаря — сумісні legacy-структури, їхні дані зберігаються. Нові довільні описи пишуться лише у `client_activity_profiles.fitness_equipment`; автоматичного підбору вправ/рецептів немає. Подальше вилучення потребує окремого перенесення.

Інформація про травми, обмеження, цільові кроки та бажані дні тренувань залишається в `client_activity_profiles`.

## 7. Messaging & Automation

3 таблиці

| Таблиця             | Призначення                                                      |
| ------------------- | ---------------------------------------------------------------- |
| `message_templates` | Шаблони Telegram-повідомлень із підстановками та токенами кнопок |
| `outbound_messages` | Заплановані та надіслані повідомлення, статуси, помилки доставки |
| `client_events`     | Історія значущих подій у житті клієнтського профілю              |

У шаблонах не потрібне окреме поле `ack_button_text`: кнопки описуватимуться токенами, а їхня логіка — реалізовуватиметься в Laravel.

Не переносимо `bot_client_views`: актуальні представлення клієнтського кабінету формуватиме Laravel.

## 8. Shopping Lists

0 нових таблиць

Списки покупок генеруватимуться на основі планів харчування, інгредієнтів рецептів, одиниць вимірювання та правил округлення.

На цьому етапі не створюємо `shopping_lists`, `shopping_list_items`, `shopping_rounding_rules` і `ingredient_purchase_rules`.

Правила округлення реалізуємо в Laravel. Сховище списків додамо, коли знадобляться збереження змін користувача, відмітки про купівлю чи історія покупок.

## 9. Технічні таблиці Laravel

Ці таблиці не включаємо до бізнес-каталогу, але врахуємо під час реалізації.

| Таблиця                 | Призначення                                |
| ----------------------- | ------------------------------------------ |
| `migrations`            | Історія міграцій PostgreSQL                |
| `sessions`              | Серверні сесії за відповідної конфігурації |
| `jobs`                  | Черга фонових завдань                      |
| `failed_jobs`           | Невдалі завдання                           |
| `cache`, `cache_locks`  | Кешування за відповідного драйвера         |
| `password_reset_tokens` | Відновлення пароля адміністратора          |

`job_batches` та `personal_access_tokens` — лише якщо виникне потреба.

Важливо: навіть якщо плануємо використовувати черги Laravel, перед розгортанням перевіримо можливості Вашого CloudLinux/cPanel-хостингу.

## 9.1. Бібліотека «Корисне» — T5.6

| Таблиця | Призначення |
| --- | --- |
| `library_items` | Єдина авторська бібліотека статей, відео, плейлістів та інструкцій. `draft`/`published`; текст Markdown або перевірений HTTPS URL; необов'язкова обкладинка. |

Матеріали керуються лише admin у Filament; усі клієнтські сесії читають однакові опубліковані записи. Персональні добірки та upload файлів не входять до T5.6. Повні правила — [контракт](library.md). Точна forward-схема — `database/sql/library/01_library_items.sql`; реалізовано в `master` через [PR #92](https://github.com/ValtonUA/telmanfit/pull/92), production-міграцію не застосовували.

## 10. Майбутні сутності — свідомо відкладаємо

| Напрям               | Що відкладаємо                                                        |
| -------------------- | --------------------------------------------------------------------- |
| Feature Toggles      | `features`, `client_feature_settings`                                 |
| Тренування           | Каталог вправ, програми, тренування, підходи, журнал виконання        |
| Завдання             | Планувальник завдань та індивідуальних вимірювань                     |
| Покупки              | Збережені списки покупок та їхнє редагування                          |
| Каталог раціонів     | Колекції опублікованих планів, доступні для самостійного вибору       |
| Способи приготування | Альтернативні способи приготування рецептів                           |
| Інтеграції           | Автоматичне отримання кроків і інших показників із фітнес-застосунків |

Для майбутніх Feature Toggles у v1 закладаємо лише спільний механізм перевірки в Laravel, який спочатку дозволятиме всі реалізовані функції. Нові таблиці поки не створюємо.


## 11. Аудит зв'язків (звірено з DBML/SQL v0.3)

Позначення: `1:N` — один до багатьох; `1:1` — один до одного; `M:N` — багато до багатьох через проміжну таблицю. Цей перелік звірено з DBML і SQL-модулями v0.3; він описує концептуальні зв'язки, а фактичні обмеження — у SQL.

### Users & Client Management

| Зв'язок | Кардинальність | Примітка |
|---|---|---|
| `users` (тренер) → `clients` | 1:N | Відповідальний тренер |
| `users` (акаунт клієнта) ↔ `clients` | 1:0..1 | `clients.user_id` залишається nullable для перехідних записів; UI-створення заборонене |
| `clients` → `client_profiles` | 1:0..1 | Загальні актуальні характеристики |
| `clients` → `client_onboardings` | 1:0..1 | Одна початкова анкета на профіль у v1 |
| `clients` → `client_nutrition_profiles` | 1:0..1 | Профіль може бути ще не заповнений |
| `clients` → `client_activity_profiles` | 1:0..1 | Профіль може бути ще не заповнений |
| `clients` → `client_settings` | 1:0..1 | Налаштування за замовчуванням можливі без рядка |

### Nutrition Planning

| Зв'язок | Кардинальність | Примітка |
|---|---|---|
| `meal_plans` → `meal_plan_versions` | 1:N | Версії належать одному логічному плану |
| `meal_plans` → `meal_plans` | 1:N (самозв'язок) | Походження персональної копії (`parent_meal_plan_id`, nullable) |
| `meal_plan_versions` → `meal_plan_days` | 1:N | Дні та послідовні 7-денні тижні фіксуються у версії |
| `meal_plan_days` → `meals` | 1:N | Прийоми конкретного дня |
| `meal_types` → `meals` | 1:N | Тип прийому їжі (сніданок тощо) |
| `meals` → `meal_items` | 1:N | Позиції прийому їжі |
| `ingredients` → `meal_items` | 1:N (через `foods`) | Для позиції типу «інгредієнт»; `meal_items.food_id` → `foods.id` |
| `recipe_versions` → `meal_items` | 1:N (через `foods`) | Для позиції типу «рецепт»; лише published; **жодних посилань на latest** |
| `clients` → `client_meal_plan_assignments` | 1:N | Історія призначень |
| `meal_plan_versions` → `client_meal_plan_assignments` | 1:N | Лише опубліковані версії |

### Food Catalog & Recipe Preparation

| Зв'язок | Кардинальність | Примітка |
|---|---|---|
| `foods` ↔ `ingredients` | 1:0..1 | Типізований зв'язок через `foods.food_type = 'ingredient'`; складений FK `(food_id, food_type)` |
| `foods` ↔ `recipe_versions` | 1:0..1 | Лише для опублікованої версії; `food_id` з'являється при публікації та не замінює version FK |
| `recipes` → `recipe_versions` | 1:N | Чернетки й опубліковані версії |
| `recipes` → `recipes` | 1:N (самозв'язок) | Походження персонального рецепта (`parent_recipe_id`) |
| `recipe_versions` → `recipe_ingredients` | 1:N | Склад опублікованої версії не редагується |
| `ingredients` → `recipe_ingredients` | 1:N | Харчові дані продукту залишаються редагованими |
| `recipe_versions` → `recipe_components` | 1:N | Необов'язкові групи складу |
| `recipe_components` → `recipe_ingredients` | 1:N (необов'язково) | Інгредієнт може не входити до групи |
| `recipe_versions` → `recipe_directions` | 1:N | Кроки інструкції версії |
| `recipe_versions` → `recipe_kitchen_equipment` | 1:N | Комплектація для версії |
| `kitchen_equipment` → `recipe_kitchen_equipment` | 1:N | У сукупності дає M:N |
| `kitchen_equipment_categories` → `kitchen_equipment` | 1:N | Категорії обладнання |
| `ingredient_categories` → `ingredients` | 1:N (попередньо) | Якщо лише одна основна категорія продукту |
| `recipe_categories` → `recipes` | 1:N (попередньо) | Якщо лише одна основна категорія рецепта |
| `uoms` → `recipe_ingredients` / `meal_items` | 1:N | З урахуванням різних одиниць інгредієнтів і порцій рецептів |
| `ingredients`, `uoms` → `ingredient_uoms` | 1:N | Унікальна пара `(ingredient_id, uom_id)`; один `is_default` на інгредієнт |

### Progress Tracking, Equipment, Messaging

| Зв'язок | Кардинальність | Примітка |
|---|---|---|
| `metrics` → `client_metric_assignments` | 1:N | Разом із `clients` реалізує M:N |
| `clients` → `client_metric_assignments` | 1:N | Призначені показники |
| `clients` → `client_metrics` | 1:N | Фактичні виміри |
| `metrics` → `client_metrics` | 1:N | Тип вимірювання |
| `clients` → `client_weekly_reports` | 1:N | Звіти незалежні від `client_metrics` |
| `clients` → `client_progress_photos` | 1:N | Історія фотографій |
| `clients` → `client_fitness_equipment` | 1:N | Обладнання клієнта |
| `fitness_equipment` → `client_fitness_equipment` | 1:N | Разом із `clients` реалізує M:N |
| `message_templates` → `outbound_messages` | 1:N (необов'язково) | Повідомлення може формуватися й без шаблону |
| `clients` → `outbound_messages` | 1:N | Надсилання клієнту |
| `clients` → `client_events` | 1:N | Історія подій профілю |

### Інваріанти та затверджені рішення (v0.3)

1. **Жодного автоматичного перепризначення:** нова версія рецепта чи плану не змінює посилання у вже призначених опублікованих планах.
2. **Повна незмінність опублікованих версій:** `recipe_versions` і `meal_plan_versions` зі статусом `published`, а також їхні дочірні рядки (`recipe_components`, `recipe_ingredients`, `recipe_directions`, `recipe_kitchen_equipment`, `recipe_batch_formats`, `recipe_batch_quantities`, `recipe_batch_equipment`, `meal_plan_days`, `meals`, `meal_items`) не можна змінювати або видаляти. Це забезпечено тригерами SQL.
3. **Один вид позиції:** `meal_items.food_id` завжди посилається на `foods`; `foods.food_type` визначає типізованого нащадка — `ingredients` або опубліковану `recipe_versions`. Обидва варіанти одночасно неможливі; складений FK і constraint-тригер це забезпечують.
4. **Copy-on-Write:** персональні рецепти й плани є окремими логічними записами з `parent_recipe_id` / `parent_meal_plan_id`; зміни копії не впливають на оригінал і на інших клієнтів.
5. **КБЖВ рецептів:** харчова цінність зберігається в `recipe_versions` на одну порцію (`kcal_per_serving`, `protein_per_serving`, `fat_per_serving`, `carbs_per_serving`). Для опублікованої версії ці значення обов'язкові та незмінні; `ingredients` не версіонуються.
6. **Кількості інгредієнтів:** `recipe_ingredients.quantity`, `quantity_grams` і КБЖВ рецепта зберігаються **на 1 порцію**; legacy масштабується через `recipe_versions.servings_count`; для batch_v1 B/C — фізичні партії, потрібен явний формат/дільник за G4-I (SQL 14).
7. **Одна чернетка:** для кожного `recipes.id` і `meal_plans.id` допускається не більше однієї чернетки; це забезпечено частковими UNIQUE-індексами `recipe_one_draft` і `meal_plan_one_draft`.
8. **Призначення планів:** клієнту можна призначити лише опубліковану `meal_plan_version`; перекриття дат для одного клієнта заборонені через `EXCLUDE`-обмеження `no_overlapping_client_plan_dates`; історія зберігається через `replaced_by_assignment_id`, самопосилання заборонене CHECK-ом `assignment_no_self_replacement`. Сервісні правила завершення/заміни погоджено в [DEC-4A](meal-plans.md#призначення-клієнтам-t45-dec-4a).
9. **Версії в плані:** один `meal_plan` може містити багато різних `recipe_versions` через `meal_items`; тексти рецептів не дублюються.

### Що зафіксовано в SQL-модулях

- `users.role` — `admin | coach | client`; `clients.status` — `pending | active | paused | finished`, активний статус вимагає `coaching_started_at`.
- `metrics.value_kind` — `decimal | integer`; `client_metrics.source` — `manual | weekly_report | coach`; `client_weekly_reports.week_start_date` — понеділок.
- `client_metric_assignments` — лише одне поточне призначення на пару `(client_id, metric_id)`.
- `outbound_messages.status` — `pending | sending | sent | failed | cancelled`; `sent_at` узгоджується зі статусом.
- `message_templates` зберігає текст із токенами; відрендерені кнопки/розмітка — у `outbound_messages.rendered_markup`.

## 12. Відкриті питання для погодження

1. **Категорії:** залишити одну nullable-категорію (`ingredients.ingredient_category_id`, `recipes.recipe_category_id`) чи перейти на M:N? Поточний SQL фіксує один FK; M:N потребує нових таблиць і не входить у v1.
2. **Травми:** `client_activity_profiles` має лише `physical_limitations` (text). Чи достатньо вільного тексту, чи потрібна структурована модель травм?
3. **Анкета та налаштування:** нові налаштування типізовані; legacy JSON збережено. Повне завершення анкети потребує контракту; [правила переходу](client-profiles.md).
4. **Адмінський вхід — частково вирішено 21.10.2026:** MFA для admin обов’язкова; PWA клієнта — email OTP без пароля. Окремий admin primary email+password, shared TOTP adapter та recovery останнього admin — пропозиції P4/P5 у [auth-architecture.md](auth-architecture.md). Nullable email/password у схемі не змінюються.
5. **Призначення тренера — вирішено 21.10.2026 (G1 для Stage 2):** адміністратор явно обирає `coach_user_id` серед користувачів з `role = coach` або `role = admin`; `client` не дозволений, типового тренера немає. БД перевіряє лише FK, роль перевіряє Laravel. Доступ до Filament залишається лише для `admin`; інші питання авторизації G1 цим не закриваються.
6. **Життєвий цикл призначень:** nullable `end_date` і CHECK `assignment_no_self_replacement` збережені; створення та дострокова заміна погоджені й реалізовані за [DEC-4A](meal-plans.md#призначення-клієнтам-t45-dec-4a). Подальші сценарії поза DEC-4A потребують окремого рішення.
7. **Перерахунок чернеток:** після зміни `ingredients` або `ingredient_uoms.grams_per_uom` опубліковані КБЖВ не змінюються; для batch_v1 прийнято явний refresh із diff; GET не записує, manual quantity/UOM зберігаються. Базові команди редактора — [PR B1](recipe-batches.md#команди-та-базовий-filament-editor-pr-b1); решта editor slice — за roadmap.
8. **Архівування `foods`:** SQL забороняє видаляти типізованого нащадка, залишаючи `foods`; окремого workflow архівування немає.
9. **Метрики — вирішено 21.10.2026 (GAP-3):** `metrics.min_value` / `max_value` — жорсткі **включні** межі рівня додатка (Laravel API + React); PostgreSQL свідомо не перевіряє `client_metrics.value`; зміна меж не потребує зміни схеми (BR-T-02, аудит §7.9).
10. **Повідомлення та події:** переходи статусів, ідемпотентність, таксономія `client_events.event_type` і retention залишаються сервісними правилами.
11. **Представлення enum:** DBML використовує логічні enum, SQL — `text + CHECK`. Чи залишаємо це розходження суто документаційним, чи колись переходимо на PostgreSQL-enum?

### Рішення реєстрації та технічні питання — 21.10.2026

Затверджено доступ лише за персональним запрошенням, створеним admin у Filament, один `users`/максимум один `clients`, спільні дані PWA/Telegram Mini App та підтверджене підключення в обох напрямках без автоматичного ототожнення за ім’ям, username чи email. Правила — [business-rules.md](business-rules.md), BR-U-14…BR-U-18. Спосіб PWA-входу вже затверджено: email OTP без пароля; invitation створює admin у Filament, без email binding, із відкликанням і використанням лише після успішної реєстрації. Optional client TOTP/резервні коди — найближчий етап, admin MFA обов’язкова. Технічні деталі P1–P6 ще потребують погодження: [auth-architecture.md](auth-architecture.md), §13. Анкета залишається окремим відкритим контрактом. Запрошення, Telegram-автентифікація, прив’язка Telegram та PWA-автентифікація ще не реалізовані; створення нових клієнтів через UI до їх реалізації недоступне. Ревізія профілів описана в [контракті](client-profiles.md); `clients.user_id` залишається nullable.

## 13. Що змінилося від v1

- Додано `recipe_versions` (+1 бізнес-таблиця).
- `recipe_steps` перейменовано на `recipe_directions` (без зміни кількості таблиць).
- Зафіксовано незмінність опублікованих версій та їхніх дочірніх записів.
- Для рецептів і планів передбачено персональні похідні копії (Copy-on-Write).
- Призначення клієнтів посилаються на конкретні версії планів; планові позиції — на конкретні версії рецептів.
- Послідовні семиденні тижні збережено; довільні цикли з Excel **не приймаємо** за базову архітектуру.
- Інгредієнти **не версіонуються**.
- Перевірено концептуальні зв'язки всіх 37 бізнес-таблиць; питання, які потребують рішення, винесено окремо.

- **v3 (20.10.2026):** таблицю конверсій перейменовано на `ingredient_uoms`; уточнено супертип `foods`; зафіксовано незмінність published-версій, Copy-on-Write, КБЖВ і кількості на 1 порцію; узгоджено зв'язки `foods` з `recipe_versions`; відкриті питання винесено окремо.
- **21.10.2026 (після інтеграційного тестування):** синхронізовано з `tests/pg` (`PASS=76 FAIL=0 GAP=0`, PostgreSQL 18.6): самопосилання в `replaced_by_assignment_id` заборонено CHECK-ом `assignment_no_self_replacement`; питання про `metrics.min_value`/`max_value` закрито як правило рівня додатка (BR-T-02, GAP-3); грамування позицій плану підтверджено перевіркою при публікації (BR-P-06, GAP-1 знято).


## 13. AUTH-2 — запрошення, passwordless OTP та MFA

Затверджено 22.10.2026: P1/P2/P4, чинні A1–A8. Реалізовано PostgreSQL-сховище
через additive-міграції; production авторизація ще відсутня. Точна структура —
DBML; фактичні обмеження — `05_auth_schema.sql`, контракти й межі —
[auth-schema.md](auth-schema.md). Історичні «auth-сутності ще не входять у схему»
вище стосуються AUTH-0/1.

| Таблиця | Призначення |
|---|---|
| `client_invitations` | Персональні відкличні одноразові запрошення від admin без email binding; consume тільки після реєстрації; nullable `expires_at` без автоматичного TTL до рішення P3, заданий строк перевіряється під час consume; необов'язкове явне посилання на перехідну картку, policy її прив'язки ще відкрита |
| `auth_flows` | Серверні pending/primary-verified/terminal стани, audience, binding, generation та version snapshot; не авторизована сесія |
| `auth_challenges` | HMAC email OTP, expiry/attempts/одноразове consume; контекст успадковується від flow |
| `user_totp_factors` | Єдине зашифроване MFA-сховище user для PWA/Filament; durable accepted step й recovery batch |
| `mfa_recovery_codes` | Argon2id hashes окремих одноразових резервних кодів, selector/batch і термінальні стани |
| `auth_session_states` | Server assurance окремих client/admin session-контекстів, version/primary/MFA/expiry/revoke; CASCADE cleanup із Laravel sessions |
| `auth_audit_events` | Незмінний typed журнал без секретів, довільного metadata чи копій документів; retention окремо за P6 |

Users розширено nullable UNIQUE normalized-key та монотонною auth_version без
перестворення. BIGINT users і всі user FK синхронізовано в DBML за чинним G3/R1;
Laravel-службові поля збережено. NULL-картки/ролі/бізнес-дані не змінено.
MFA required для admin визначає роль; client optional — підтверджений factor.
Схема не додає незалежних Filament secrets. AUTH-3 реалізує спільний durable
сервіс/adapter/gate без обходу другого фактора; PWA native session guards — P2.
P3/P5/P6, normalization/backfill і реальний rollout потребують окремих рішень.

Уточнення рев’ю AUTH-2 (22.10.2026): FK `ON DELETE RESTRICT` свідомо обмежують
фізичне видалення користувача з auth-історією. Потрібна майбутня захищена
адміністративна дія блокування акаунта з відкликанням сесій/незавершених flows і
збереженням журналу безпеки. У AUTH-2 вона не реалізується; FK не послаблюються.
Безстрокове запрошення (`expires_at=NULL`) лишається одноразовим і відкличним;
TTL тимчасових OTP, flows та recovery capabilities залишається обов’язковим.

### AUTH-5: погоджений стан вимкнення TOTP — 22.10.2026

`user_totp_factors.disabled_at` — nullable timestamptz. Активний фактор має
`confirmed_at NOT NULL AND disabled_at IS NULL`. Вимкнення доступне тільки client
після fresh primary + чинного MFA, очищає старий ciphertext/key і pending material,
деактивує recovery batch зі збереженням used/revoked рядків, confirmed_at,
last_used_step та аудиту. Повторне підключення — новий pending secret і новіший
прийнятий step; старий фактор не відновлюється. Ролі та бізнес-сутності незмінні.
Виконуваний additive DDL — `database/sql/auth5/00_disabled_factor.sql`; актуальний
SQL snapshot — `docs/05_auth_schema.sql`. Історичні міграції AUTH-2 не змінено.

## AUTH-6B/B3 — захищений recovery (22.10.2026)

D1/D2 затверджено прямою задачею; D3 лише принцип незалежності. Нові сутності
реалізовано additive міграцією, без production identities або backfill.
Джерело точних constraints/functions/ACL — [06_auth_recovery_schema.sql](06_auth_recovery_schema.sql),
логічна проєкція — [database.dbml](database.dbml), стан і blockers — [auth6-b3.md](auth6-b3.md).

| Сутність | Призначення та інваріанти |
|---|---|
| `auth_recovery_principals` | Один незалежний human principal; nullable UNIQUE linked user, authority та digest зовнішнього identity record. Identity immutable, revoke незворотний. Жодних production rows. |
| `auth_recovery_keys` | Ed25519 public keys (32 bytes), fingerprint, revision, validity; rotation новим row, не другою identity. Private keys не зберігаються. |
| `auth_recovery_cases` | Immutable exact intent, роль/version, exclusions, TTL72h, hold24h, active slot; explicit signed `replace_admin_password` лише admin. Deployment/epoch — signed context від зовнішнього port, не реалізація D6 restore. |
| `auth_recovery_approvals` | Два незалежні підписані approvals на intent, evidence digest/receipt UUID, TTL48h; одна identity рахується один раз, self/initiator/operator виключені; append-only. Verifier є окремою довіреною межею D3. |
| `auth_recovery_grants` | Один grant/intent, TTL15m; single exchange з browser binding та shared `auth_flows` enrollment, hash token; повторна видача потребує нового case. |
| `auth_recovery_gates` | Quarantine client/admin незалежно від flags. Terminal cancel/expiry не release; лише успішний новий primary+TOTP і version bump. Legacy password-only context після recovery заборонено. |
| `auth_recovery_events` | Append-only типізована історія case без секретів, паралельно з наявним security audit. Відмова mandatory notice/audit відкочує mutation. Retention не реалізовано. |

Shared MFA зберігає pending/confirmed secrets, watermark та codes у чинних таблицях.
Restricted recovery не видає login proof/session; потрібен окремий повний вхід.
D3 transports/verifier/custody та B4 durable notices відкриті; default boundary відсутня,
production env і mutation grants закриті. Production/P5/P6 не затверджено.

## AUTH-6B/B4 — transactional security notice outbox

D4 затверджено прямою задачею 22.10.2026. Реалізована additive
`auth_security_notice_outbox` відокремлена від business `outbound_messages`.
Authoritative контракт — [SQL 07](07_auth_security_notice_outbox.sql), проєкція —
[DBML](database.dbml), поведінка й перевірки — [B4](auth6-b4.md).

Один UUID row на subject/type/recipient slot і server request UUID; correlation
із case без FK, щоб B5 не створював невиправданої залежності notice metadata від
case retention. Єдиний recipient snapshot зашифрований versioned application key;
довільного JSON/body/evidence немає. Security mutation, typed audit і INSERT
атомарні на default PostgreSQL connection. Retry використовує той самий snapshot.

`pending/leased → sent/expired/dead`, максимум 20 claims і 24 години; terminal
незворотний, адреса/key очищуються в тій самій транзакції з audit. Metadata має
30-денний retention contract; фізичне видалення/restore/holds та keys custody —
B5/D5–D6, purge у B4 відсутній. Production adapters, SMTP, trustees і grants не
активовано; незалежний канал має окремий port без default provider.

## AUTH-6B/B5 — retention та restore fence

D5 та D6-A затверджено. Additive [SQL 08](08_auth_retention_restore.sql) →
[DBML](database.dbml) → нова міграція 004000; історичні DDL незмінні.

| Сутність | Призначення та інваріант |
|---|---|
| `auth_retention_holds` | Durable user/case/time-range hold; рівно один selector; release іншим principal, history не видаляється; policy advisory lock серіалізує hold commit і purge. |
| `auth_retention_runs` | Версія policy, фіксований cutoff, request idempotency; terminal state із finish; без довільного payload. Dry-run API read-only, run не створює. |
| `auth_retention_batches` | Immutable commit receipt, до 500 IDs, selection digest, UNIQUE run/batch та archive; retry того самого batch не повторює DELETE. |
| `auth_archive_manifests` | Immutable exact-content receipt, opaque object UUID, повні bounds/count/digest, незалежний verifier; online audit ≥180d, archive до ≥365d від останньої події. |
| `auth_security_runtime` | Singleton зовнішнього restore epoch, reconciliation timestamp, closed state та issuance fence. Порожня таблиця/відсутній external adapter закривають операції. |

Production provider/provisioning відсутні. Synthetic adapters не є D3/D8 доказами;
повний статус реалізованого ядра й відкладених cleanup paths — [B5](auth6-b5.md).

B5 P2 forward fix (005000): held expired sessions зберігають лише typed audit
metadata перед payload/assurance GC; невідомий guest subject захищений будь-яким
active hold. Confirmed-factor pending secret scrub не видаляє MFA history чи
watermark; held pending-only row не purge. Нових сутностей/полів немає.

## AUTH-6B/B6 — атомарні PostgreSQL rate budgets

D7 затверджено прямою задачею. `auth_rate_budgets` — один durable row на
`(scope,key_hash)`, спільний для workers/hosts; HMAC без сирих email/IP/токенів.
Authoritative [SQL 09](09_auth_rate_budgets.sql) → [DBML](database.dbml) →
нова міграція 006000; SQL01–08 та історичні міграції незмінні.

Whitelist 25 immutable policies, bounded sorted timestamps≤scope cap≤1000,
`blocked_until`, monotonic `last_clock_at`, `gc_after` після найдовшого window/pause.
Exact sliding boundary `t>now-window`. Row locks у `(scope,key_hash)` C-порядку,
ON CONFLICT для absent keys, group all-or-none append; denied не додає attempt.
Admission commit — окремий connection; failure budget/state/audit — одна domain
transaction. Cleanup≤500 під row lock/recheck, без reset активних protections.

PUBLIC/runtime direct DML не надається; grants існують тільки в isolated harness.
Новий adapter доступний лише guarded synthetic testing, production/cutover
закрито; `4040404` збережено. D6-A epoch/fence чинний, бюджети після restore не є
доказом невикористаного ліміту. [Контракт, parity та докази B6](auth6-b6.md).

## G4-I — точні selectors позиції раціону

[SQL 14](14_meal_item_selectors.sql) додає до `meal_items` лише nullable
`recipe_version_id integer`, `recipe_batch_format_id integer`,
`recipe_portion_divisor smallint`. Історія не переписується; усі старі поля
зберігають значення, нові — NULL. CHECK допускає тільки всі NULL або повну трійку
з divisor 1/3/4/7. Ingredient і published legacy — тільки NULL; batch — повна трійка.
FK `(recipe_version_id,food_id) → recipe_versions(id,food_id)` та
`(recipe_batch_format_id,recipe_version_id) → recipe_batch_formats(id,recipe_version_id)`
мають UPDATE/DELETE RESTRICT; додано відсутній UNIQUE `(id,food_id)` версії,
наявний UNIQUE формату повторно не створюється. Два partial indexes — на нові FK.

Чинний measure guard розширено перевіркою exact published version/food, contract,
належності format і відповідності `single/1`, `week/7`, `half_week/3 або 4`.
Recipe — `serving`, grams NULL; ingredient — дозволена UOM та grams >0, звірка
конверсії при Publish збережена. Root SHARE lock узгоджений із recipe writer;
child locks не додаються. Published root/descendants і планові immutable guards
залишаються чинними. Forward-only `down()` відмовляє без втрати selector-даних.

Readonly resolver і decimal scale/округлення описані в [meal-plans.md](meal-plans.md#model-i-g4-i-selectors-та-readonly-resolver).
Фізичний склад партії не змінюється від обраного divisor; shopping/cooking writer відсутній.

## Кухонні шаблони SQL17

Канонічні поля, FK й guards — [SQL17](17_cooking_templates.sql), семантика та
межа реалізації — [cooking-templates.md](cooking-templates.md).

| Сутність | Ключі й дані |
|---|---|
| CookingTemplate / cooking_templates | integer identity; title, created_by → users BIGINT; адміністративна серія без client/week identity. |
| CookingTemplateVersion / cooking_template_versions | integer identity; template_id, version_number UNIQUE; previous_version_id тієї ж серії; draft/published, source_plan_version_id/source_week, mode 7/2/1, revision, menu_pattern JSONB, trigger-derived SHA256 menu_fingerprint; reviewed_revision/by/at та published_at. Один draft; published immutable. |
| CookingSession / cooking_sessions | integer identity; composite UNIQUE(id,version_id); position і day UNIQUE у версії, day 1–7, introduction/equipment/estimated_seconds вручну, content JSONB v1 зі stable IDs. |
| CookingOutput / cooking_outputs | integer identity; composite FK session/version, exact recipe_version, nullable format composite FK, portion_divisor = cooked. Один рядок — один фізичний batch. |
| CookingAllocation / cooking_allocations | PK(version,day,meal_position,item_position); composite FK output/version, exact portions. Покриття recipe slots, selector/day/aggregate guards перед publish. |

`cooking_output_balances` — view: cooked/allocated/left_over. Ingredient slots
входять до повного pattern. Часткові тижні unavailable; автоматичний DEC-5M
не реалізовано. Runtime progress і ClientSession bindings не зберігаються
в authoring content. Нові Eloquent/Filament/API ресурси не створено.

## Презентаційні поля SQL15 (Issue #62 / PR #67)

`meal_plans.week_start_weekday SMALLINT NOT NULL DEFAULT 1 CHECK (1..7)` —
обов'язковий «Початок тижня» при створенні, ISO Пн=1 … Нд=7; UI типово Пн.
Значення незмінне після створення (DB trigger, редактора немає). Для існуючих
планів погоджено default=1; дні, позиції, призначення та nutrition не backfill-яться.
День N має weekday `((week_start_weekday + N - 2) % 7) + 1`; це не календарна дата
призначення. Поля anchor у версіях немає.

`ingredients.image_url` і `recipes.image_url` — nullable text, mutable cover metadata.
Логічна обкладинка recipe спільна для всіх його exact published versions; title
і nutrition беруться з вибраної exact version. Cover не належить recipe_versions,
foods або meal_items, не замінює `recipe_directions.image_url`. SQL15 виключає лише
новий ingredient image_url із `recipe_batch_source_hash`, зберігаючи попередній hash
і всі інші залежності. URL перевіряється Laravel exact HTTPS host allowlist;
порожній allowlist дозволяє всю роботу без фото. [Операційний контракт](meal-plans.md#зображення-та-початок-тижня-sql15).

## Зображення кухонного обладнання

`kitchen_equipment.image_url` — nullable text із публічним HTTPS URL. Воно використовує той
самий точний host allowlist та поля URL, що й обкладинки інгредієнтів/рецептів. Forward
SQL додає лише метадані зображення; ID і категорії довідника не змінюються.
