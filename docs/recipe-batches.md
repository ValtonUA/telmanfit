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
| B/C input snapshot | schema_version=1, factor decimal string scale 2, uom_label, source_hash, rounding exact/ceil/nearest, step scale 2; confirmed=true для не-exact; ≤8 KiB |
| Source hash | DB `recipe_batch_source_hash(ingredient_id)`: nutrition/семантика продукту, усі conversions/default, UOM labels і g; лише fingerprint, не credential |
| Manual | quantity/UOM та grams не губляться; manual_context=DB `recipe_batch_context(row,code)` на момент ack, review_ack_hash=md5(context::text) |
| Pending | JSON array ≤1000 rows/64 KiB, unique `pending:` ID, лише id/ingredient_id/quantity/uom_id/component_id/note/overrides; поля введення — nullable strings ≤500 chars; optional overrides week/half_week зберігає manual pair/snapshots та raw незавершене B/C введення; повний рядок нормалізувати |
| Equipment | три resolved snapshots на baseline row; nullable min_value допустиме; auto fixed або proportional ×1/7/3.5; manual має context/ack |

MD5 тут визначає рівність canonical PostgreSQL JSON, а не криптографічну авторизацію.
Права автора та publication review перевірятимуть команди PR B. `publication_review`
містить complete=true, lock_version (перед publish), actor, at. Це не аудит автентифікації.
Валідація БД перевіряє актуальність sources, auto graph, frozen grams, totals,
manual context, повноту equipment та явне підтвердження; не рендерить токени інструкцій.
Повна сервісна валідація токенів/авторизації та єдиний Save/Refresh/Publish — PR B.

## Обчислення і refresh

[BatchEvaluator](../app/Domain/Recipes/BatchEvaluator.php) — єдине прикладне decimal ядро
на вже встановленому `brick/math` (нових залежностей немає). Вхід — accepted snapshots
та explicit policy. Лише decimal strings; quantity/grams scale 2, totals scale 6,
HalfUp лише при gram conversion і показі порцій, overflow відхиляється.
B target=A×7; C target=фактична B/2; автоматичний C за exact g може округлити
0.005 до 0.01. Незавершені рядки передаються окремо й позначають результат incomplete.
Невалідний normalized input відхиляється; evaluator не перетворює його на qty=0.

Кожний B/C state містить mode, uom, policy (rounding, step, confirmed), для manual —
quantity та acknowledged_context; `reset` прибирає manual intent лише в поверненій копії.
Контекст включає A, parent grams, nutrients, ingredient/conversion fingerprints,
поточну UOM/factor і policy. Вихід містить context/review, targets, author pair,
frozen factor/grams, totals та чотири порції. GET/preview чисті, жодних I/O.
DB guards незалежно перевіряють записаний результат; це не альтернативний writer.
DB canonical context має JSON representation сховища; adapter PR B має формувати
його з accepted inputs і записувати ack лише за явною командою автора.

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

Новий application surface не підключено. `config/recipes.php` закритий; міграція
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
чотири порції, precision/nonfinite/overflow. Штатний Stage 1 runner зберігає baseline
PASS=76 і catalog regression; нові DDL встановлюються у власному тестовому transaction.
