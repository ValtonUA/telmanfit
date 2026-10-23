# Users & Client Management: профілі та перехід

Затверджена ціль: `users.first_name/last_name text NOT NULL`, обчислюване Laravel
`full_name`; загальний, харчовий, активнісний профілі та налаштування з типізованими
колонками. Кожен профіль має `client_id integer` як PK/FK з `ON DELETE CASCADE`.
`clients`, user FK BIGINT, auth та Laravel timestamps збережено.
[DBML](database.dbml) відображає реалізовану схему разом із legacy-колонками.
[SQL 10](10_client_profiles.sql) — forward-проєкція;
[виконуваний SQL](../database/sql/profiles/00_profiles.sql) викликає тільки нова
[Laravel-міграція](../database/migrations/2026_10_24_000000_revise_client_profiles.php).
SQL 01–04 і знімки `database/sql/stage1` залишаються історичним baseline.

## Реалізований перехід

- `first_name/last_name` nullable, без UNIQUE. Старе `name` збережене точно;
  автоматичного поділу немає. Новий HTTP registration вимагає обидва непорожні поля.
  `full_name` показує складові або legacy `name`. Filament використовує той самий
  accessor; пошук/сортування враховують складові й legacy fallback.
- Після перевірки історичних записів власником окрема forward-міграція зможе
  додати NOT NULL. Цей PR не має дозволу читати реальні дані, тому кількість
  записів до уточнення не встановлюється та персональні значення не публікуються.
- `client_nutrition_profiles.goal` перенесено в `client_profiles.goal` зі збереженням
  `updated_at`; оригінал залишено як `legacy_goal`. Preferences/restrictions не змінено.
  Старих колонок калорійності не існувало: нові оцінки залишаються NULL, без розрахунку.
- Коректні масиви тренувальних днів перенесено з JSONB у `text[]` без зміни порядку.
  Оригінал — `legacy_preferred_training_days`; невідомі/повторні/NULL дні або JSON
  іншого типу залишають нове поле NULL. Виправлення потребує підтверджених кодів.
  Нові записи не дублюються в legacy JSON. SQL перевіряє обидва нові масиви:
  лише `monday`…`sunday`, без NULL-елементів, повторів або вкладених масивів.
- `reminder_settings/report_settings` збережено без змін. Невизначені JSON-ключі
  не мапуються на нові поля. Для нових налаштувань застосовуються лише typed колонки.
- `fitness_equipment/client_fitness_equipment` залишаються legacy з усіма FK,
  кількостями та нотатками. Реалізованих продуктових моделей/API запису в них немає;
  їх використовують схема й synthetic SQL-тести. Новий вільний текст зберігається
  лише в `client_activity_profiles.fitness_equipment`, без автоматичного дублювання
  чи генерації тексту зі словника. Вилучення legacy потребує окремого погодженого
  архівування/перенесення після перевірки конкретної БД.
- `client_onboardings.client_id` став PK; старе `id` та identity збережено з UNIQUE.
  У репозиторії зовнішніх FK/пошуку за onboarding `id` немає. Невідомий зовнішній FK
  до старого PK зупинить міграцію транзакційно (без CASCADE), вимагаючи review.
- `answers jsonb → json` зберігає наявне представлення JSONB як JSON. Індексів,
  JSONB-операторів і FK до answers у коді немає; читачі декодують JSON. Первісний
  порядок ключів/форматування до збереження JSONB відновити неможливо.

## Поточна анкета та майбутнє завершення

GET/POST `/api/onboarding` та Filament зберігають/редагують лише чернетку трьох
наявних відповідей (`workplace`, `meals_per_day`, `top_reasons`), зберігаючи невідомі
ключі. Вони не записують `completed_at`, не змінюють status і не оновлюють поточні
профілі з незавершеної анкети. Завершений знімок відхиляє редагування на рівні API,
Filament і SQL; повторне відкриття через скидання `completed_at` теж заборонене.
SQL відхиляє будь-який UPDATE завершеного рядка з SQLSTATE `23514`, включно зі
зміною `updated_at`, legacy `id` та присвоєнням поля самому собі. Прямий DELETE
архіву заборонено, але погоджений `ON DELETE CASCADE` при видаленні клієнта збережено.

Повний сценарій завершення та контракт решти відповідей не визначено. Endpoint,
автоматичне завершення та нові питання не додані. Коли сценарій буде погоджено,
окремий сервіс повинен під блокуванням клієнта в одній транзакції перевірити
відповіді, записати відповідні typed профілі та зафіксувати `completed_at`.
Наступні зміни характеристик пишуться лише у профілі; архів не синхронізується.
Завершення не активує супровід. `meals_per_day` зберігається лише в архіві: нового
продуктового поля для нього не вигадано.

## Валідація та час

SQL CHECK і Eloquent-валідація перевіряють sex/work_mode, цілі кроків і калорій,
дні тижня, цілий додатний зріст у межах smallint, немайбутню скінченну дату народження.
Довільних вікових/медичних меж без рішення власника немає.
`goal`, `difficulties`, `priorities`, описи обладнання й обмежень — вільний текст.
`maintenance_calories/target_calories` — актуальні добові оцінки, `weekly_free_calories`
— тижневий бюджет; жодного алгоритму чи кнопки перерахунку немає.

`morning_message_time` — локальний час у `client_settings.timezone` (IANA), без
перетворення на UTC під час запису/читання. Model приймає `HH:mm:ss`; scheduler
або бізнес-сервіс розсилок, який обробляв би цей час, у коді відсутній. Майбутній
scheduler має обчислювати конкретний момент із timezone та правилами DST.

## Перевірка перед подальшим звуженням схеми

Ці запити документують категорії записів, які потребують уточнення; запускати їх
на реальній БД можна тільки в окремому погодженому процесі, без публікації ПД:

```sql
SELECT id FROM users WHERE first_name IS NULL OR last_name IS NULL;
SELECT client_id FROM client_activity_profiles
 WHERE legacy_preferred_training_days IS NOT NULL AND preferred_training_days IS NULL;
SELECT client_id FROM client_settings
 WHERE reminder_settings <> '{}'::jsonb OR report_settings <> '{}'::jsonb;
SELECT client_id FROM client_fitness_equipment GROUP BY client_id;
```

Тести [ClientProfilesTest](../tests/Postgres/ClientProfilesTest.php) відтворюють
upgrade заповненої Stage1-схеми, порівнюють legacy-значення/кількості/FK, перевіряють
негативні CHECK/PK/FK та поточні профілі незалежно від архіву. Поточний API й auth
перевіряють набори `Auth4`, `Auth3`, `Auth5`; Filament — `ClientResourceTest`.
Runner [run-stage1.sh](../tests/pg/run-stage1.sh) створює новий PG18 із network=none,
без портів, синтетичними даними й cleanup. До production/Telmanfit_dev він не звертається.

Окремий короткий прогін upgrade/constraints: `CLIENT_PROFILES_ONLY=1 bash tests/pg/run-stage1.sh`.
Це той самий disposable PG18 runner із перевіркою ідентичності БД та cleanup.

Локальні перевірки та виявлений CI dependency blocker зафіксовані в
[checks.json](evidence/client-profiles/checks.json). Актуальний head і завершення
GitHub jobs перевіряти в Checks [Draft PR #34](https://github.com/ValtonUA/telmanfit/pull/34).
