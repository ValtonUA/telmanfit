> **Stage 1 (21.10.2026):** для схеми, створеної Laravel-міграціями, використовуйте `tests/pg/run-stage1.sh`: новий одноразовий PostgreSQL 18, усі шість еталонних SQL-файлів, перевірка точно 76 PASS, окремий PG PHPUnit та основний PHPUnit. [Відтворення й перевірений обсяг](../../docs/stage-1.md). Laravel додає службові таблиці, тому загальна кількість — 45, бізнес-таблиць — 37; історичний PowerShell-раннер за замовчуванням очікує лише 37 таблиць.

# Інтеграційні тести PostgreSQL-схеми TelmanFit

Linux/Docker G4: [інструкція та межі перевірки](G4.md). `run-pg-tests.sh` —
окремий Bash-раннер зі строгим контролем усіх 76 ID; PowerShell-раннер збережено.
G4 фактично виконано в Codex Cloud: PostgreSQL 18.6/Bookworm із digest pin,
`PASS=76 FAIL=0 GAP=0`, PHP/PDO через приватний Unix socket, cleanup успішний.
Команда: `bash tests/pg/run-isolated-pg.sh`; міграції не запускаються.
Старі описи кодів виходу нижче стосуються саме `run-pg-tests.ps1`.

Тут лежать SQL-тести, які перевіряють **фактичну поведінку** бізнес-схеми (37 таблиць із `docs/01–04`)
на живому PostgreSQL: обмеження, часткові унікальні індекси, `EXCLUDE`-обмеження перекриття дат,
тригери незмінності опублікованих версій і валідації публікації.

Тести **не змінюють схему** й **не переносять дані**: кожен файл виконується в одній транзакції,
яка завершується `ROLLBACK`, тому після прогону всі 37 таблиць залишаються порожніми.
Основна база `telmanfit_dev` не використовується взагалі.

## Склад

| Файл | Що перевіряє |
|---|---|
| `t01_foods.sql` | `foods` ↔ типізовані нащадки: рівно один нащадок, складений FK `(food_id, food_type)`, заборона відв'язки/видалення нащадка, `ingredient_uoms_one_default`, CHECK-и нутрієнтів |
| `t02_recipes.sql` | `recipe_versions`: чернетка → `published` лише через `UPDATE`, заборона прямого `INSERT published`, обов'язкові інгредієнти й КБЖВ, звірка грамів (`assert_ingredient_grams`), незмінність опублікованої версії та дочірніх рядків, нова версія не змінює старий `food_id`, `recipe_one_draft`, складений FK `(component_id, recipe_version_id)` |
| `t03_plans.sql` | `meal_plan_versions` / `meal_items`: контигуальні 7-денні блоки, кожен день з прийомом, кожен прийом з позицією, правила `validate_food_measure` (одиниця дозволена, грами для інгредієнта, `serving` + `NULL` грамів для рецепта, лише опублікована версія рецепта), незмінність опублікованого плану, `meal_plan_one_draft`, звірка грамування при публікації (`assert_ingredient_grams`: одиниця `g` та дозволені одиниці, округлення `ROUND(quantity × grams_per_uom, 2)`, зміна довідника після створення чернетки), заморожування опублікованих грамів |
| `t04_assignments.sql` | `client_meal_plan_assignments`: призначення лише опублікованої версії, `EXCLUDE USING gist` (перекриття дат), DEFERRABLE-семантика відкладеної перевірки, `end_date >= start_date`, необмежені (`NULL`) діапазони, ланцюжок `replaced_by_assignment_id`, заборона самопосилання (`assignment_no_self_replacement`, відмова `23514`) і збереження дозволу `NULL` |
| `t05_progress_messaging.sql` | модулі 03/04: тижневий звіт саме з понеділка, унікальність тижня, зв'язка `status`/`submitted_at`, складений FK `client_metrics (weekly_report_id, client_id)`, `client_metric_one_current_assignment`, CHECK-и `outbound_messages`, архітектурне рішення щодо діапазону `metrics.min/max` (перевірка на рівні додатка Laravel + React; БД свідомо приймає значення поза межами) і збереження історії вимірів після зміни меж |
| `t06_inventory_residue.sql` | Read-only інвентаризація живої схеми (таблиці, індекси, тригери, `EXCLUDE`, міжмодульні FK) і контроль залишкових рядків після прогону |

`run-pg-tests.ps1` — раннер: виконує `t01`→`t06` через `psql` під роллю `telmanfit_test_runner`,
зупиняється на першій SQL-помилці та підсумовує PASS/FAIL/GAP.

> **Чому скрипт англійською і лише в ASCII.** Windows PowerShell 5.1 читає `.ps1` без BOM як ANSI,
> тому український текст у тілі скрипта ламає парсер. Щоб раннер працював незалежно від кодування
> файлу й кодової сторінки консолі, його повідомлення та коментарі — ASCII/англійською.
> Українською лишаються документація (цей README) і звітність; тест-скрипти `t0*.sql` — UTF-8 з англійськими
> деталями результатів (українськими в них є лише тексти помилок сервера `SQLERRM`, які залежать від `lc_messages`).

## Вимоги до середовища

1. **PostgreSQL 15+** (перевірено на 18.6) з розширенням `btree_gist` (1.8), встановленим адміністратором
   у тестовій БД. `phpunit.xml` використовує SQLite in-memory і ці тести запустити не може — потрібен саме PostgreSQL.
2. **База `telmanfit_test`** (окрема, не `telmanfit_dev`), власник — `postgres`, `UTF8`, бажано `ICU uk-UA` через `template0`.
3. **Роль `telmanfit_test_runner`**: `LOGIN`, `NOSUPERUSER NOCREATEDB NOCREATEROLE`; права — `CONNECT`, `TEMPORARY`
   на БД, `USAGE`, `CREATE` на схемі `public`, **без** `CREATE ON DATABASE` (для рядка 6 модуля 01 цього не потрібно,
   бо `CREATE EXTENSION IF NOT EXISTS` для вже встановленого розширення видає NOTICE до перевірки прав).
4. **Схема застосована наперед**: `docs/01_nutrition_schema.sql` → `02_users_clients.sql` → `03_progress_tracking.sql`
   → `04_fitness_messaging.sql` (у цьому порядку, окремим погодженим кроком; раннер схему не створює
   і міграції Laravel не запускає).
5. **Облікові дані постачаються оточенням** — у репозиторії їх немає:
   - `%APPDATA%\postgresql\pgpass.conf` з рядком
     `127.0.0.1:5432:telmanfit_test:telmanfit_test_runner:<пароль>`, або
   - змінна середовища `PGPASSWORD` (для поточної сесії).

   Раннер викликає `psql -w`, тому без пароля він не «зависає», а завершується помилкою.
   `pgpass.conf`, логи прогонів і будь-які локальні облікові файли **не комітяться**.

## Запуск

```powershell
# Windows PowerShell (з кореня репозиторію)
powershell -ExecutionPolicy Bypass -File tests/pg/run-pg-tests.ps1

# з параметрами
powershell -ExecutionPolicy Bypass -File tests/pg/run-pg-tests.ps1 `
    -DbHost 127.0.0.1 -Port 5432 -Database telmanfit_test -DbUser telmanfit_test_runner `
    -PsqlPath "C:\Program Files\PostgreSQL\18\bin\psql.exe" `
    -OutputDir "$env:TEMP\telmanfit_pg_out"
```

Раннер на старті:

1. перевіряє `current_database()`, `current_user` і `session_user` — і **зупиняється з кодом 2**, якщо база або роль не збігаються;
2. перевіряє, що в схемі `public` рівно **37** таблиць — інакше **код 3** з підказкою застосувати `docs/01–04`;
3. проганяє `t01`→`t06`; SQL-помилка в тесті дає **код 4** (далі тести не виконуються), наявність FAIL — **код 5**, успіх — **код 0**.

Коди виходу: `0` успіх · `1` інша помилка · `2` невірна база/роль · `3` схема не застосована · `4` SQL-помилка в тесті · `5` є FAIL.

Логи прогонів пишуться в `tests/pg/out/*.out` і додані до `.gitignore` (`/tests/pg/out/`).

## Очікуваний результат (станом на 21.10.2026, PG 18.6)

```
PASS=76  FAIL=0  GAP=0
```

**GAP немає.** Усі три раніше задокументовані прогалини закриті:

- `T3.16` — перекласифіковано як дефект тесту: публікація раціону справді перевіряє грамування (`BR-P-06`, GAP-1);
- `T4.11` — закрито CHECK-обмеженням `assignment_no_self_replacement` (GAP-2);
- `T5.9` — закрито архітектурним рішенням: діапазон `metrics.min_value`/`max_value` перевіряється на рівні
  додатка (Laravel API + React, межі включні), а **не** в PostgreSQL; БД свідомо приймає значення поза
  межами, зміна меж не змінює схему й не переписує історію вимірів (`BR-T-02`, GAP-3).

## Оновлення вже створеної тестової БД

`docs/01_nutrition_schema.sql` містить `CONSTRAINT assignment_no_self_replacement CHECK(replaced_by_assignment_id IS DISTINCT FROM id)`
на `client_meal_plan_assignments`. База, створена **до** цієї правки, отримує те саме обмеження ідемпотентним
`ALTER TABLE` (без видалення таблиць і даних, виконується під `telmanfit_test_runner` як власником таблиці):

```sql
BEGIN;
DO $x$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid='public.client_meal_plan_assignments'::regclass
                     AND conname='assignment_no_self_replacement') THEN
    EXECUTE 'ALTER TABLE public.client_meal_plan_assignments ADD CONSTRAINT assignment_no_self_replacement CHECK (replaced_by_assignment_id IS DISTINCT FROM id)';
  END IF;
END $x$;
COMMIT;
```

Перевірка після застосування: `convalidated = true`, а `T4.11`/`T4.12` покривають самопосилання (відмова `23514`) і `NULL`.

**Політика чернеток vs публікації (BR-P-06, BR-F-14):** чернетка `meal_items` може містити проміжні
значення, але **публікація** раціону перевіряє кожну позицію: `validate_food_measure()` (тип/одиниця/грами)
і `assert_ingredient_grams()` (`resolved_quantity_grams = ROUND(quantity × grams_per_uom, 2)`, для `g`
множник 1). Це реалізовано в `validate_plan_publication()` (модуль 01, фінальна `CREATE OR REPLACE`)
і покривається `T3.16`–`T3.22`. Опубліковані грами «заморожені» (`T3.23`) і не змінюються при редагуванні
довідника одиниць.

**GAP — це задокументовані прогалини рівня БД, а не помилки тестів** (тести їх лише фіксують; схема не змінюється):

| GAP | Суть |
|---|---|
| `T4.11` | `replaced_by_assignment_id` може вказувати на сам рядок — само-заміна не заборонена CHECK-ом |
| `T5.9` | `metrics.min_value` / `max_value` не застосовуються до `client_metrics.value` (валідація лишається сервісною) |

## Відтворюваність і безпека

- Повторний запуск **не потребує очищення** ні `telmanfit_test`, ні тим паче `telmanfit_dev`: усі фікстури
  створюються всередині транзакцій і відкочуються, тому таблиці залишаються порожніми
  (контроль — секція `[7]` у `t06_inventory_residue.sql`).
- `DROP DATABASE`, `DROP ROLE`, `TRUNCATE`, `migrate:fresh`, `db:wipe` не використовуються.
- Єдине, що змінюється, — **лічильники identity-послідовностей** (PostgreSQL не відкочує `nextval`);
  на порожні таблиці це не впливає.
- Тести виконуються від непривілейованої ролі, тому заразом підтверджують, що схема працює без адміністративних прав.

## Додавання нових тестів

- новий файл називайте `tNN_<тема>.sql` (порядок виконання — за номером);
- починайте з `BEGIN;`, створюйте `CREATE TEMP TABLE _r (...)` і `CREATE TEMP TABLE _ids (k text PRIMARY KEY, val integer)`,
  беріть приклад із `t01_foods.sql`;
- результат кожного сценарію записуйте через `pg_temp.rec('<ID>','PASS'|'FAIL'|'GAP','<деталі>')`, а негативні
  кейси обгортайте у вкладений `BEGIN … EXCEPTION WHEN OTHERS THEN` — тоді один збій не зриває решту прогону;
- завершуйте файл виводом `SELECT status, count(*) AS n FROM _r GROUP BY status;` (раннер парсить саме ці рядки) і `ROLLBACK;`;
- фікстури, потрібні лише для негативної перевірки, створюйте **всередині** підтранзакції, щоб відмова не лишала слідів;
- не використовуйте `FROM (INSERT … RETURNING …)` (у PostgreSQL так не можна) — застосовуйте `WITH … AS (INSERT …)`;
- не називайте змінні PL/pgSQL так само, як колонки тимчасових таблиць (`v`, `k`, `val`) — інакше отримаєте
  помилку неоднозначності замість перевірки правила.

## Ревізія профілів

`CLIENT_PROFILES_ONLY=1 bash tests/pg/run-stage1.sh` — заповнена Stage1-схема →
нова forward-міграція, звірка даних, PK/FK/CHECK і моделей. Повний runner додатково
перевіряє Filament/OTP/MFA/sessions; baseline catalog лишається історичним Stage1.
Контракт: [client-profiles.md](../../docs/client-profiles.md).
