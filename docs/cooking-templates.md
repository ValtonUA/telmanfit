# T5.7b — схема авторських кухонних шаблонів v1

Погоджено D1–D5 та уточнення власника: фасування, охолодження, зберігання,
розігрівання — звичайні завершальні авторські етапи. Окремого типу `storage`
або обов'язкової секції немає. Придатність і безпеку автор явно підтверджує
для поточної редакції перед публікацією; система не встановлює термінів зберігання.

Authoritative DDL: [SQL17](17_cooking_templates.sql), його виконувана копія —
[database SQL](../database/sql/cooking/00_templates.sql), forward-only
[міграція](../database/migrations/2026_10_28_000000_create_cooking_templates.php).
Explicit selection і уточнений token result додає forward-only
[SQL18](18_cooking_selection.sql), byte-identical до
[runtime SQL](../database/sql/cooking/01_selection.sql), через окрему
[міграцію](../database/migrations/2026_10_28_010000_add_cooking_selection.php).
Історичні таблиці й рядки не змінюються. Це schema/domain етап;
Filament, API, ClientSession adapter, React і production activation — наступні PR.

## Межа D1 та DEC-5M

Власник окремо погодив **один повний фактично наявний семиденний фрагмент**
опублікованого раціону. Номер його тижня не є ідентичністю шаблону. Відносні
дні 1–7 зберігаються без ротації. Частковий доступ до тижня — unavailable;
немає скорочення, зсуву першого дня, екстраполяції або автоматичного циклу.
DEC-5M для унікальних меню клієнтського UI й автоматичних повторів лишається відкритим.

`cooking_menu_pattern(version,week)` формує JSONB schema_version=1:

- `meals`: відносний день, порядкова позиція (`sort_order,id`), meal_type_id,
  авторські sort_order/title, включно з порожніми прийомами;
- `items`: день, позиції прийому й item, food_id, quantity, uom_id, historical
  resolved_quantity_grams, sort_order, exact recipe_version/format/divisor;
- для кожного рецепта — exact published version (без created_at/published_at,
  щоб представлення timestamptz не залежало від session timezone), усі frozen ingredient rows,
  snapshots, formats і quantities. Жодного live ingredient/UOM conversion lookup.

Plan/day/meal/item ID, client, assignment, календарні дати й номер тижня
не входять до pattern. Recipe/ingredient/format/row IDs входять: незалежні нові
версії не вважаються еквівалентними. Перенумерація surrogate IDs позицій при
копіюванні меню дозволена, перестановка різних items змінює pattern. Контракт
навмисно строгий: відмінність авторського meal title/sort_order також несумісна.

SHA256 від UTF-8 PostgreSQL JSONB text — індекс пошуку кандидатів цього schema_version,
не переносимий між довільними зовнішніми JSON serializers протокол і не доказ
рівності. Lookup враховує `mode`; при застосуванні потрібні **повна JSONB рівність**
і exact published cooking version. Не шукати `latest`, не змінювати mode автоматично.

## Нормалізовані сутності та публікація

| Таблиця | Контракт |
|---|---|
| cooking_templates | Ідентичність серії, адміністративний title, created_by. Не клієнтський текст версії. |
| cooking_template_versions | Послідовний номер, previous published version тієї ж серії, максимум один draft; source published version/week, mode, revision, pattern/hash, поточний author review. |
| cooking_sessions | Належить exact cooking version; стабільний ID, день 1–7, порядок, ручні вступ/обладнання/час, versioned JSONB етапів. |
| cooking_outputs | Одне фізичне приготування exact published recipe/format; portion_divisor — скільки порцій приготовлено. Повторне приготування — інший output. |
| cooking_allocations | Одна recipe meal position → один output, exact споживані portions; composite FK не дозволяє чужу cooking version. |

Режим 7/2/1 вимагає відповідно 7/2/1 підготовлених сесій; усі сесії мають outputs.
Не потрібно створювати або публікувати всі три режими. Режим без exact сумісної
опублікованої версії unavailable. Режим не є гарантією безпеки зберігання.

Legacy output: format=NULL, divisor=збережений servings_count. A: single/1;
B: week/7; C: half_week/3 або /4. C/3 і C/4 — одна фізична кількість відповідного
frozen C, але різні output selectors. Довільного масштабування немає.
Кожна recipe позиція має рівно одну allocation; ingredient позиції повністю
враховані pattern і не видаються за приготовлений рецепт. Їхню придатність до
споживання автор враховує під час review, без вигаданих ready-to-eat enum.
Allocation не передує дню приготування; сума allocations не перевищує cooked.
View `cooking_output_balances`: cooked/allocated/left_over, без дубльованих totals.
Залишки явні й не розподіляються автоматично.

Root і descendants published версії незмінні, включно з DELETE/reparent.
Наступна редакція — новий draft із previous_version_id, новим review і явно
вибраними посиланнями; published версії та їхні читачі не перепризначаються.
Зміна source/week/mode або будь-яких sessions/outputs/allocations збільшує revision
і скидає review. Root row locks серіалізують publish/detail edits; сервіс перевіряє
expected revision. Review та publish повторно виконують повну перевірку.

## JSONB та гібридні токени

```json
{"schema_version":1,"stages":[{"id":"prepare","title":"Приготування","blocks":[
  {"id":"a1","type":"action","parts":["Візьміть ",{"metric":"grams","sources":[{"output":123,"row":456}]}]},
  {"id":"t1","type":"timer","label":"Гречка","seconds":1200,"message":"Перевірте готовність"}
]}]}
```

Числа в прикладі — синтаксис, не фактичні ID. Об'єкт `metric/sources` є
посиланням, не формулою; один source — quantity/grams, кілька — лише явна сума.
`row` завжди exact recipe_ingredients.id. `output` має належати цій сесії;
повтор output+row у сумі відхиляється. Для B/C беруться recipe_batch_quantities,
для A/legacy — frozen recipe_ingredients. Allocations не множать token quantity.
Повторний batch треба оформити окремим output і явно включити до суми.

Сума: та сама ingredient identity; quantity додатково вимагає однакових UOM,
повного frozen context та grams/quantity. Відсутній B/C рядок не fallback-иться на A.
Grams використовує лише frozen grams того самого ingredient. Numeric PostgreSQL,
без float, live conversions і закупівельного округлення; верхня технічна межа
суми 999999999.99. Результат містить provenance sources.

Інші blocks: `hint{id,type,text}`, `image{id,type,url,alt}`,
`timer{id,type,label,seconds,message}`; unknown keys/types, duplicate IDs,
невідомий schema_version, HTML/raw `{{...}}` та fractional timers відхиляються.
Stage/block IDs унікальні в межах сесії, не змінюються при reorder.
JSONB до 1 MiB; timer 1–604800 секунд — технічна межа валідації, не термін
зберігання. Етапів/дій не обмежено довільною продуктовою кількістю.
SQL перевіряє структуру/HTTPS; доменний сервіс додатково перевіряє чинний
`PublicImageUrl` host allowlist. Сервер зображення не завантажує.

Вода для технологічного процесу, обладнання, температура та час завжди plain
авторський текст/параметри. У каталозі немає затвердженого machine-readable water
classifier: зміст ingredient tokens перевіряє автор через явне підтвердження
`no_process_water_tokens`, а не ненадійна перевірка назви продукту. Автоматичних
water/equipment/time/formula token types немає. Таймер не доводить готовності їжі.

## Доступ і наступний API

### Явний вибір чинної версії (погоджений MVP)

`published` означає незмінну перевірену історичну версію, а не автоматичну
активацію. `cooking_selections` зберігає один явний вибір на
template + exact menu pattern + mode. Ключ використовує SHA256; guard перевіряє
повну JSONB рівність старої й нової версій, collision відхиляється без fallback.
Різні template є окремими серіями: майбутній caller передає явно обраний template
та exact version ID, не обирає довільний перший template серед сумісних.

Admin виконує `CookingTemplates::select(actor, version, expectedSelectionRevision)`.
Перший вибір очікує revision=0, запис отримує 1; кожна явна заміна, включно
з поверненням до старої історичної версії, збільшує revision. Повернення дозволене
лише як новий явний вибір admin, не автоматично. Publish не змінює selection.
Заміна атомарна під template lock; stale/ABA — SQLSTATE 40001. Змінювати ключ,
видаляти selection або посилатися на draft/чужий template/mode заборонено.
Відсутній вибір або недоступний обраний version — unavailable, без historical/latest
fallback. Інший pattern/mode не є заміною цього slot і має окремий явний вибір.

`cooking_compatible` — лише внутрішня історична перевірка сумісності SQL17;
вона не дозволяє клієнтську видачу. Клієнтська перевірка — `cooking_available`,
яка додатково вимагає точного selection. Старі published версії не змінюються.
Новий read snapshot після commit заміни відхиляє попередню версію; read request,
що вже почав REPEATABLE READ snapshot до commit, завершується з попереднім
цілісним станом. Немає обіцянки скасувати вже видану відповідь або offline content.
Наступний API повинен повторювати перевірку при новому запиті та скидати local
progress при зміні exact version, без неявного перенесення.

### Одиниці та labels token result

`cooking_token` повертає decimal `value`, `metric`, `uom_id`, `unit_label`,
`label_source` та exact provenance `sources`. B/C quantity бере label виключно
з `recipe_batch_quantities.input_snapshot.uom_label` (`recipe_snapshot`),
не з чинного `uoms`. A quantity за SQL11 завжди дорівнює грамам: `г`,
`canonical_grams`. Для metric=grams завжди `г`, `uom_id=null`, `canonical_grams`.
Це канонічний підпис фізичної метрики, не історична назва довідника.

Legacy не має frozen UOM label: quantity повертає `unit_label=null`,
`label_source=unavailable`. Числова quantity лишається exact, але майбутній API
мусить fail-closed відхилити інструкцію з таким quantity token; автор може явно
використати підтриманий grams token або вручну написати перевірений текст.
Live label lookup/backfill/перезапис published recipe тут не вводиться.
Теперішній T5.4 reader має live-label fallback для legacy/A; його не можна
копіювати в cooking API або називати цілком frozen. Повний cooking API відсутній.

### Конкурентні команди

Aggregate edit/review/publish беруть locks template → version → details;
`cooking_assert_revision` перевіряє exact revision/status до detail writes
у тій самій транзакції. Service review/publish використовує такий самий порядок.
Raw child-first DML може створити deadlock із root-first writer: PostgreSQL
відхиляє victim (40P01), уся команда відкочується. 40001/40P01/lock timeout
не є успіхом і не дозволяє автоматично повторювати старий intent: потрібні
новий snapshot/review та нова явна команда. Прямих editor HTTP endpoints тут немає.

`cooking_available(client,assignment,version,week,mode,server_date)` — read-only доменна
перевірка exact чинного assignment, published menu/template, ownership плану й
personal recipes, повного доступного тижня та рівності pattern. Невідповідність
повертає false. Функція не автентифікує довільно переданий client ID і не є RLS.
`CookingTemplates::review/publish` дозволені лише admin із перевіркою ролі у БД.
Прямий SQL — довірений migration/test рівень, не публічний інтерфейс.

Майбутній ClientSession adapter мусить брати client/server date із довіреного
контексту; перевірка доступу та читання content/tokens — один REPEATABLE READ
READ ONLY snapshot. Будь-який mismatch блокує всю інструкцію. HTTP 401/404/409,
no-store/private, GET/HEAD без DML, allowlist зображень при читанні та очищення
нечинного локального progress належать наступному API/React PR. Тут routes немає.
Локальний progress ізолюється user+assignment/date+version+session, не template
fingerprint; поточна схема його не зберігає і не переносить між клієнтами.

## Перевірки та пілот

[CookingTemplatesTest](../tests/Postgres/CookingTemplatesTest.php) встановлює
міграцію на вже заповнену синтетичну legacy/A/B/C схему, проходить шлях
1 template → 1 session → 6 outputs → allocations → review → publish.
Негативні PG перевірки використовують savepoints і rollback.
Окремий [concurrency runner](../tests/pg/cooking-concurrency.php) запускає три
незалежні PHP/PDO процеси з різними backend PID: edit/review/publish для кожного
типу details, rollback, double publish, create-next, selection replacement/ABA,
READ COMMITTED/REPEATABLE READ/SERIALIZABLE conflicts, MVCC read snapshot та
справжній deadlock victim. Кожна гонка перевіряє фактичний PG lock wait;
відсутній worker/lock або неочікуваний SQLSTATE завершує runner кодом 1.
CI включає цей клас у recipe-групу [Stage 1 runner](../tests/pg/run-stage1.sh).
Локально Windows: [run-cooking.ps1](../tests/pg/run-cooking.ps1), потрібні Docker
та наявні локальні PHP/PG image inputs. Нові disposable PG18/socket volume,
network=none, ports відсутні, cleanup у finally. Робочі БД/.env не використовуються.
Runner також виконує стандартний backend suite зі справжніми наявними admin
build assets image; React build не копіюється, відповідні SPA tests можуть бути
skipped. GitHub Fast PR збирає frontend і перевіряє його окремо.
Linux-команди винесено в [cooking-runtime.sh](../tests/pg/cooking-runtime.sh),
без вкладеного Windows shell quoting; він також проходить повний migration path
і перевірку всіх user FK/BIGINT із двома новими cooking references.

Read-only preflight локального профілю не підтвердив шість published страв і
раціон; тестовий пілот використовує лише синтетичні дані. Повний Filament→телефон
walkthrough залишається окремими наступними PR, не результатом schema-тестів.
