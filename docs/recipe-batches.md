# Forward-контракт ядра рецептів A/B/C

Предметна реалізація [Issue #37, PR A](https://github.com/ValtonUA/telmanfit/issues/37)
за [погодженим §17](proposals/stage-3-recipe-batch-editor.md#17-погоджені-уточнення-власника-24102026).
Статуси T3.1–T3.5 ведуться лише в [roadmap](roadmap.md).

## Межа та специфікація зберігання

Канонічний forward SQL — [11_recipe_batches.sql](11_recipe_batches.sql), byte-identical
до [executable SQL](../database/sql/recipes/00_batch.sql). Нова forward-only міграція
додає три таблиці, metadata версії та A snapshot; не редагує SQL 01–04 і не backfill-ить
історію. `editor_contract` незмінний після INSERT: конвертація legacy — окремий PR C.
За замовчуванням усі старі версії `legacy_v1`, snapshots NULL, revision 0.

| Дані | Контракт |
|---|---|
| A | `recipe_ingredients`, quantity=grams, UOM code g, positive numeric(7,2) |
| Ingredient snapshot | object: schema_version=1, name, kcal/protein/fat/carbs як decimal strings scale 2; ≤8 KiB |
| Headers | single/week/half_week, чотири totals numeric(14,6), policy object schema_version=1; calculation_hash=md5(JSON totals::text) |
| B/C input snapshot | schema_version=1, factor decimal string scale 2, uom_label, source_hash, rounding exact/ceil/nearest, step scale 2; auto C також зберігає accepted auto_policies за UOM code; confirmed=true для не-exact; ≤8 KiB |
| Source hash | DB `recipe_batch_source_hash(ingredient_id)`: nutrition/семантика продукту, усі conversions/default, UOM labels і g; лише fingerprint, не credential |
| Manual | quantity/UOM та grams не губляться; manual_context=DB `recipe_batch_context(row,code)` на момент ack, review_ack_hash=md5(context::text) |
| Pending | JSON array ≤1000 rows/64 KiB, unique `pending:` ID, лише id/ingredient_id/quantity/uom_id/component_id/note/overrides; поля введення — nullable strings ≤500 chars; optional overrides week/half_week зберігає pair/snapshots та raw незавершене B/C введення, для AUTO — службовий envelope (опис нижче); повний рядок нормалізувати |
| Equipment | три resolved snapshots на baseline row; nullable min_value допустиме; auto fixed або proportional ×1/7/3.5; manual має context/ack |

MD5 тут визначає рівність canonical PostgreSQL JSON, а не криптографічну авторизацію.
Права автора та publication review перевіряють команди PR B1. `publication_review`
містить complete=true, lock_version (перед publish), actor, at. Це не аудит автентифікації.
Валідація БД перевіряє актуальність sources, auto graph, frozen grams, totals,
manual context, повноту equipment та явне підтвердження; не рендерить токени інструкцій.
Сервісна валідація ingredient tokens/авторизації та Save/Refresh/Publish описані нижче.

## Обчислення і refresh

[BatchEvaluator](../app/Domain/Recipes/BatchEvaluator.php) — єдине прикладне decimal ядро
на вже встановленому `brick/math` (нових залежностей немає). Вхід — accepted snapshots
та explicit policy. Лише decimal strings; quantity/grams scale 2, totals scale 6,
HalfUp лише при gram conversion і показі порцій, overflow відхиляється.
B target=A×7; C target=фактична B/2; автоматичний C за exact g може округлити
0.005 до 0.01. **Auto C завжди успадковує фактичну UOM і frozen factor B** за
[§5.1](proposals/stage-3-recipe-batch-editor.md#51-три-послідовні-операції).
Вибір виконує evaluator, а не майбутній UI: старі `half_week.uom/policy` не визначають
новий auto результат. `row.auto_policies` — прийнятий snapshot правил за UOM code;
він персистується як `half_week.input_snapshot.auto_policies`. Для успадкованих g
за відсутності явного правила — exact/0.01; для інших UOM потрібне прийняте правило
з decimal step scale 2 і confirmed=true. Відсутність правила/conversion вимагає
explicit refresh, без fallback у g. Прийняття diff виконує команда PR B1;
evaluator не читає довідники та не пише persisted дані.

Наприклад, B manual=3 шт., accepted piece ceil/1.00 → C auto=2 шт.; B=1 банка,
accepted can ceil/0.50 → C=0.50 банки; за кроку 1.00 → ціла банка. Зміна B UOM
обирає інше прийняте правило із snapshot; manual C зберігає власні quantity/UOM.
SQL перевіряє однакові UOM/factor B/C та відповідність обраного округлення snapshot
правил, включно з draft COMMIT і Publish. Самих правильних grams/totals недостатньо.
Незавершені рядки передаються окремо й позначають результат incomplete.
Невалідний normalized input відхиляється; evaluator не перетворює його на qty=0.

Кожний B/C state містить mode, uom, policy (rounding, step, confirmed), для manual —
quantity та acknowledged_context. Manual pair не обмежується auto-пресетом кроку/округлення. `reset(state, acceptedAutomatic)` явно отримує актуальну auto UOM/policy й прибирає manual intent лише в поверненій копії.
Контекст включає A, parent grams, nutrients, ingredient/conversion fingerprints,
поточну UOM/factor і policy. Вихід містить context/review, targets, author pair,
frozen factor/grams, totals та чотири порції. GET/preview чисті, жодних I/O.
Порядок ключів JSON objects канонізується: JSONB reload не скидає manual acknowledgement.
DB guards незалежно перевіряють записаний результат; це не альтернативний writer.
DB canonical context має JSON representation сховища; adapter PR B1 записує ack
лише за явною командою автора після збереження accepted inputs.

Зміна джерел дає preview diff, потім явне прийняття: auto перераховується, manual
quantity/UOM зберігаються, нові grams/nutrition потребують review. Старий snapshot
допустимий у draft, publish відхиляє stale source_hash. PR #28 не копіюється.

## Revision, locks та обмеження

Будь-яка зміна batch root піднімає lock_version рівно на 1. Деталі спершу блокують
OLD/NEW version у порядку ID, піднімають revision і скидають publication_review;
тому одна aggregate-команда може підняти revision кілька разів. PR B повинен повертати
фактичну фінальну revision, а не припускати «один Save = +1».
`recipe_batch_assert_revision(id, expected)` бере FOR UPDATE та відхиляє stale state
із SQLSTATE 40001. CAS, усі child writes і Publish мають бути однією транзакцією.
Publication бере ingredients/conversions/UOM FOR SHARE за ID; conversion trigger бере
ingredient FOR UPDATE, закриваючи INSERT phantom. Порядок майбутніх команд:
recipes → version → ingredients → conversions → details. Deadlock/40001 потребує
нового preview, без сліпого повторного застосування intent.

Application surface підключений за закритим `config/recipes.php`; міграція
сама не активує редактор. Жодних production GRANT, auth/RBAC змін, каталогів упаковок,
meal_items selectors або Stage 4 UI. Реальні migrations тут не запускалися.

## Перевірки

`RECIPE_BATCH_ONLY=1 bash tests/pg/run-stage1.sh` запускає новий PG18 container з
network none/tmpfs, synthetic role/database, перевіркою identity та cleanup.
`RecipeBatchesTest` перевіряє partial/full draft, aggregate/FK/precision guards,
публікацію/rollback, manual review та непорожній upgrade із published legacy
recipe/plan/assignment. Окремий concurrency script доводить реальне очікування PG lock,
CAS conflict/rollback та serialization nutrition/UOM UPDATE/conversion INSERT.
`BatchEvaluatorTest` перевіряє graph/reset/reload, малі зміни, UOM/rounding,
чотири порції, precision/nonfinite/overflow та inherited C UOM/policy. Спільні
`InheritedUomCases` звіряють PHP і PG на тих самих синтетичних conversions/steps;
негативні тести відхиляють stale UOM/factor/policy, відсутнє підтвердження й неправильні
кількості. `recipe-batch-uom-reload.php` додатково виконує справжній COMMIT і читає
шість auto/manual сценаріїв через нові незалежні PG connections, після чого повторно
обчислює склад із завантажених snapshots. Штатний Stage 1 runner зберігає baseline
PASS=76 і catalog regression; нові DDL встановлюються у власному тестовому transaction.

## Команди та базовий Filament editor (PR B1)

[BatchEditor](../app/Services/Recipes/BatchEditor.php) — application adapter для нових
`batch_v1` drafts. `create/open/preview/save/publish` перевіряють закритий feature flag
та актуальну admin-role в БД. Filament використовує штатні login/MFA/CSRF/assurance
middleware. Флаг не має нового env-перемикача і лишається `false`.

`open` і `preview` читають узгоджений MVCC snapshot без записів. Preview рахує
**поточні** джерела, показує diff frozen/current (грами, factor, нутрієнти), але
не змінює accepted snapshot. Серверний HMAC пов'язує actor, version, revision,
увесь input, fingerprints довідників та canonical evaluator output. Save звіряє
його повторно під recipe → version → ingredient → conversion → UOM/catalog locks.
Stale revision/source або змінений input відхиляються без автоматичного retry.
Client totals/snapshots/ack hashes не використовуються. Наявні SQL deferred guards
перевіряються до успішної відповіді; повертається фактична revision після всіх triggers.

Звичайний Save відхиляє змінені джерела; кнопка «Прийняти diff і Refresh» явно
приймає переглянутий snapshot. Ручні quantity/UOM зберігаються, factor/grams/КБЖВ
оновлюються лише після цієї згоди. Окрема кнопка «Підтвердити ручні значення й обладнання» фіксує саме поточний DB context. Незмінні acknowledgements переживають
Save/reload, зміни залежностей роблять їх stale. Немає відсоткового порогу.

Одна сторінка `/admin/recipe-batches` містить новий draft, вибір версії, склад
A/B/C, компоненти, кроки, обладнання, чотири порції та різницю B−2×C. Preview після
редагування read-only; поки він невалідний, попередній token прибирається. Pending
рядки зберігають raw незавершене введення, зокрема `1.`, `0`, очищену manual пару;
вони не потрапляють у totals. Завершені рядки нормалізуються. Ingredient PK лишається
сталим при заміні продукту, manual не скидається. Вихід із pending отримує новий PK
та атомарний remap токенів; інші рядки не перестворюються.

Перехід завершеного рядка в pending **не скасовує accepted source**, включно з
B/C AUTO. Adapter переносить із БД обидва `input_snapshot`, `source_hash`,
`ingredient_snapshot`, frozen grams і accepted `auto_policies`. У дозволеному SQL 11
`overrides.<format>.input_snapshot.pending_state` зберігається службовий object
`{schema_version: 1, mode: auto|manual, quantity_grams: decimal|null}`. Зовнішній
`overrides.<format>.mode=manual` — виключно сумісна з чинним CHECK оболонка;
це **не** manual intent автора. `open` читає справжній режим із `pending_state`,
невідому версію відхиляє; старі manual envelopes без marker читаються як раніше.
Snapshot ≤8 KiB перевіряє adapter і БД, увесь pending — як і раніше ≤64 KiB/1000 rows.
Нових JSONB root keys, SQL/міграцій або змін SQL 11 немає.

Повторні pending Save/reload і reset manual→auto зберігають попередній fingerprint,
не підставляють актуальний довідник у provenance. Client snapshots ігноруються.
Після відновлення A зміни КБЖВ/UOM/conversion дають frozen/current diff; звичайний
Save та Publish не приймають їх без explicit Refresh. Неповний рядок увесь цей час
не входить у totals. Для нового, ще ніколи не нормалізованого pending snapshot
джерела не вигадується. Уже втрачені попереднім кодом snapshots неможливо відновити
з поточного довідника; backfill не виконується.

Синтаксис кроків: `{{ingredient:<row_id>:quantity}}` або `:grams`, включно з
`pending:...` до першого Save. [RecipeTokens](../app/Services/Recipes/RecipeTokens.php)
рендерить plain text фактичного формату, Blade екранує результат. Dangling/невідомі
токени допустимі в draft, блокують Publish. Публікація вимагає збереженого preview,
окремого checkbox/confirm, відсутності pending, назви, категорій рецепта/інгредієнтів,
непорожніх кроків, актуальних snapshots та manual review. Створюється один food і
один version_number у тій самій транзакції. Повторний publish дає conflict, без
другого food; будь-яка відмова відкочує весь write.

## Завершення редактора (PR B2)

Обладнання зберігається через UPDATE/upsert за належним версії PK. Видаляються
лише явно прибрані рядки; нові equipment IDs remap-яться окремо від ingredient IDs
в одній транзакції. A — ручна база; B/C — auto або manual. Manual min_value/UOM/tip
не переписуються при зміні A, складу чи параметрів B. Reset явно повертає формат
до baseline fixed/proportional. NULL означає відсутність числової вимоги, не нуль.
Лічильні auto значення округлюються ceil до цілого; місткість лишається decimal
scale 2 (HalfUp лише до точності сховища), без округлення літрів до цілих.

[SQL 12](12_recipe_equipment.sql), byte-identical до
[01_equipment.sql](../database/sql/recipes/01_equipment.sql), — вузька forward-ревізія
функцій: helper auto/context і оновлений aggregate validator. **Нових таблиць,
полів, enum або переписування SQL 01–11 немає.** Причина міграції: старий guard
вимагав дробове proportional count й перевіряв review лише manual обладнання.
Новий guard незалежно перевіряє ceil count та достатність усіх A/B/C snapshots.
Старі published дані не оновлюються; на реальних БД міграція не запускалася.

`input_snapshot` equipment містить schema_version=1, dependencies (серверний
SHA-256 нормалізованих inputs/pending для preview), source_hash (обраний catalog/UOM)
і adequacy_ack (MD5 DB context). Сервер ігнорує client snapshots/ack. DB context
містить baseline/resolved min_value/UOM/tip/mode, catalog/UOM metadata та fingerprint
усього складу A/B/C і pending. Будь-яка зміна залежностей робить ack застарілим.
Manual додатково має manual_context/review_ack_hash. Окрема явна дія підтвердження
зберігає поточний DB context після всіх aggregate writes. Автор підтверджує фізичну
достатність обладнання: довідник не містить геометричної моделі завантаження, тому
система не вигадує формулу літрів на масу. Publish блокується до актуального ack.
Зміни catalog/UOM обладнання показуються окремим diff і потребують explicit Refresh;
звичайний Save їх не приймає. Preview/GET лишаються read-only.

Auto B має явний вибір дозволеної UOM та пресета: exact g (default), opt-in nearest
100 g (HalfUp на половині кроку) або ceil 1/0.50/0.25 для count із наявною конверсією.
Дробовий крок підтверджує автор, лише коли має погоджене правило упаковки; таблиці
довідника не містять автоматичної класифікації «штука/банка». UI не визначає SKU,
фактичну фасовку чи їстівну масу. Немає глобального округлення 100 g. Результат
видно до Save; «Змінити вручну» переносить фактичну пару в manual. Auto C успадковує
B UOM/factor й accepted auto_policies; для g за замовчуванням exact 0.01, навіть коли
B округлено до 100 g. `factor` у коді й snapshots не перейменований; UI показує
«1 <UOM> = <factor> г». Порожні довідники мають явний стан без довільних seeds.

Equipment tokens: `{{equipment:<baseline_row_id>:quantity}}` (число та UOM),
`:min_value`, `:uom`, `:tip`, `:name`. Nullable quantity відображається як
«Без числової вимоги». Renderer plain text, Blade екранує результат. Невідомі,
пошкоджені й видалені tokens дозволяють draft Save, блокують Publish для всіх
трьох форматів. Видалення показує кількість пов’язаних кроків і втрату manual
значень; видалення компонента залишає його інгредієнти без компонента.

**Відкрите рішення DEC-3T:** `recipe_directions` має лише direction_number/text;
ні duration/temperature, ні timer entity/ID/одиниць часу у погоджених таблицях немає.
Для повноцінного timer token власнику треба визначити джерело duration, його
ідентичність/формат і поведінку редагування. Нових полів/JSON у B2 не вигадано.
Час і температура залишаються авторським текстом, не масштабуються. Timer token
наразі невідомий і блокує Publish, як будь-який непідтримуваний token.

Legacy conversion, versions/CoW — PR C; Stage 4, T1.5 seeds/Excel/production activation
не входять. Feature flag лишається false.
Перевірки: [BatchEditorTest](../tests/Postgres/BatchEditorTest.php),
[command concurrency](../tests/pg/batch-editor-concurrency.php),
[реальний pending COMMIT/reload](../tests/pg/batch-editor-pending-source.php),
[Chromium](../tests/browser/recipe_batch.py). `RECIPE_BATCH_ONLY=1 RECIPE_BATCH_BROWSER=1
bash tests/pg/run-stage1.sh` створює новий PG18, перевіряє identity і прибирає container.
Chromium проходить штатні password+TOTP, перевіряє graph/manual/reset, pending/reload,
дві вкладки, stale source response, explicit Refresh, light/dark 1440/390/320 без
горизонтального overflow. [Screenshots/checks](evidence/recipe-batches/checks.json) —
локальний synthetic доказ, а результат CI завжди перевіряється на конкретному SHA.
