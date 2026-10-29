# CI-SLICE-1: локальні перевірки й межі вимірювання

База: `7e3d374cec1536e76e067a40414c2bf6d04c92ff`. Усі прогони використовують нову disposable PostgreSQL 18 без мережі й портів та синтетичні дані. HTTP PHP-процес отримує очищене оточення. Локальний час не є GitHub billed invoice; кеш, навантаження і версія runner впливають на результат.

## Матриця залежностей

| Набір | PHPUnit / PG | Незалежна concurrency | Chromium / security |
|---|---|---|---|
| `day` | DayEditor, ItemWriter, ItemSelectors, Structure, WeekDeletion, WeekRepetition, Publication | day, writer, selectors, structure, deletion, repetition, publication | Реальний meal-plan acceptance: MFA, CSRF, revocation, published history, week/day, mouse/touch/keyboard на 1440/390/320 у light; окрема responsive перевірка day у dark |
| `assignment` | MealPlanAssignment, MealPlanCopy та AUTH4 ClientMealPlan | assignment, включно з role/fence та незалежними клієнтами | assignment: MFA, CSRF/revocation, history, 1440/390/320 light/dark |
| `recipe` | Наявні recipe, catalog, import, cooking і meal-plan PG-набори | Наявні recipe, catalog, cooking і meal-plan concurrency | Наявні recipe і copy browser acceptance; широкий fallback для shared коду |
| `baseline` | AUTH3/4/5, зокрема ClientCooking та ClientMealPlan | auth concurrency | AUTH3/4/5, client recipe, assignment |
| `b5`, `b3` | AUTH6B5 та AUTH6B/B4 | recovery/hold/restore | Контрольні auth HTTP сценарії |

У широкому `recipe` збережено таку предметну карту:

| Функція | PHPUnit / concurrency | Browser |
|---|---|---|
| Recipe batches, editor, legacy conversion, cover | `RecipeBatchesTest`, `BatchEditorTest`; `recipe-batch-*`, `batch-editor-*`, `legacy-conversion-concurrency`, `recipe-cover-concurrency` | `recipe_batch.py`: authoring, legacy, publication і history |
| Каталог, R2, XLSX import | `FoodCatalogTest`, `R2ImageMatcherTest`, `R2ImageMatcherIsolatedTest`, `NutritionImportTest`; `food-catalog-concurrency` | `food_catalog.py` із `recipe_batch.py`; shared client залежності — AUTH4 baseline |
| Cooking | `CookingTemplatesTest`, `cooking-concurrency`; `ClientCookingTest` у AUTH4 baseline | `cooking.py` у `recipe_batch.py`, client recipe browser у baseline |
| Foundation, створення, структура й тижні | `MealPlanFoundationTest`, `MealPlanCreationRequestTest`, `MealPlanStructureTest`, `MealPlanWeekDeletionTest`, `MealPlanWeekRepetitionTest`; відповідні `meal-plan-*-concurrency` | `meal_plans.py`, `day_editor.py`, `week_repetition.py`, presentation та UX-3 в `recipe_batch.py` |
| Позиції, публікація, assignment, copy | `MealItemSelectorsTest`, `MealItemWriterTest`, `MealPlanDayEditorTest`, `MealPlanPublicationTest`, `MealPlanAssignmentTest`, `MealPlanCopyTest`; відповідні item/day/publication/assignment/copy concurrency | `meal_items.py`, `day_editor.py`, `plan_publication.py`, окремий `meal_plan_copy.py`; assignment browser у baseline і assignment slice |

`ClientCookingTest` фізично лежить у `tests/Auth4`; згадка імені у фільтрі `phpunit.pgsql.xml` не запускає його, оскільки цей XML включає лише `tests/Postgres`. Тому shared cooking/catalog/writer зміни включають `baseline`. CSS підключає `day`, `recipe` і `baseline`. Невідомі критичні шляхи й залежності вибирають усі групи та SQL schema suite.

## Selector до та після

| Diff | До | Після | Примітка |
|---|---|---|---|
| `day.blade.php` | немає груп | `day` | Раніше browser acceptance був пропущений, тому економію часу порівнювати некоректно |
| `theme.css` | немає груп | `day,recipe,baseline` | Відновлено перевірку спільних споживачів |
| `day_editor.py` | `recipe,baseline,b5,b3` | `day` | Предметні сценарії та залежності збережено у новому наборі |
| `MealItemWriter.php` | `recipe` | `recipe,baseline` | Додано client/auth регресії для shared залежності |
| `MealPlanAssigner.php` | `recipe` | `assignment` | Додано AUTH4 client read та окремий assignment browser |

Історичний GitHub before для UX-3, не тотожний diff і не контрольований benchmark: [Targeted run 40814316116](https://github.com/ValtonUA/telmanfit/actions/runs/40814316116), head `0562037c649c79411a3f8682897a2e4cb28163a7`, success. За timestamps API: scope 5 с, regression job 931 с, gate 4 с; сума 940 с = 15,67 job-хв, оцінка округлення кожного job — 18 runner-хв. Час від створення до завершення run 949 с включає очікування. Це оцінка за timestamps, а не invoice; інший diff/cache робить її непридатною для прямого відсоткового порівняння.

## Локальні результати

- Окремий ізольований SQL schema suite: `PASS=76 FAIL=0 GAP=0`, 6/6 файлів; негативні перевірки runner для identity, missing, duplicate, gap, fail, summary, inventory, residue та missing-file пройшли; власний контейнер прибрано.

- `day`: 443 с wall з cleanup, з окремими dark assertions на 1440/390/320, 66 тестів / 1176 assertions, concurrency та Chromium PASS; worker завершився успішно, сторонніх контейнерів не знайдено. Оцінка округлення окремого job: 8 хвилин без setup.
- `assignment`: 57 с wall з cleanup, 22 PG / 328 assertions, 27 AUTH4 / 236 assertions, дев’ять concurrency випадків та Chromium PASS; окремий worker ізолюється та прибирається. Оцінка округлення окремого job: 1 хвилина без setup.
- `baseline`: локальний PASS за 516 с (паралельно виконувались інші локальні перевірки): PG 293/4957, AUTH2 12/137, AUTH3 25/129, AUTH4 167/1678, AUTH5 19/131, AUTH6 5/359, concurrency та Chromium. `--list-tests` підтверджує 14 `ClientCookingTest` у AUTH4.
- `b5`: локальний PASS, 41 тест / 392 assertions та concurrency/restore; B3 перший раз завершився з помилкою через відсутній Node у очищеному PATH, повтор із виправленим PATH пройшов за 111 с, включно з AUTH2/3/4/5/6, B3/B4 та HTTP concurrency; cleanup підтверджено.
- Docs-equivalent: 11 docs і 45 CI unit tests, T1.5 source та docs guard PASS. Chromium readiness: 7 тестів PASS.
- Fast-equivalent: PHP syntax, Pint, runtime 8.4.27, Composer validate/platform/audit, Compose config і 126 Unit/Feature / 522 assertions PASS. Дві npm lock установки, обидві Vite збірки та client lint PASS на локальному Node 24; GitHub використовує Node 22. Початковий root build без мережевого доступу не знайшов fonts.bunny.net, повтор із штатним proxy і CA пройшов. Composer install без scripts/plugins і package discovery PASS.
- `recipe` before: 680 с wall із cleanup, 240 PG-тестів / 3949 assertions, concurrency (зокрема 37 cooking-сценаріїв), 20 основних browser checks та окремий copy browser PASS. PHP 58 с, concurrency 30 с, browser 559 + 26 с; округлення окремого job без setup — 12 хвилин. Вимір зроблено на тій самій базі та машині з теплим кешем; runner `recipe` збережений, у тестовому helper додано лише поле стану для нового dark smoke.
- Для assignment це 680 → 57 с у локальних окремих workers (11,33 → 0,95 worker-хв; 12 → 1 округлених хвилин без setup). Набори мають різний scope: новий прицільно додає copy/history, AUTH4 client read та assignment browser, а не повторює сторонні recipe історії.
- Для Blade/editor старий selector або пропускав Blade, або для `day_editor.py` вимагав `recipe,baseline,b5,b3`; 680 с — лише вимір `recipe`, а не повний before job. Локальний `day` після зміни зайняв 443 с (7,38 worker-хв; округлено 8 хв без setup). Проти одного лише recipe worker до зміни це 680 → 443 с; повний старий job також включав auth-групи, тому це не вимір фактичної економії GitHub. Для shared writer новий вибір додає baseline, тому зменшення не заявляється.

Не порівнювати ці оцінки зі списаними хвилинами GitHub та не додавати worker minutes як витрати окремих jobs: вибрані workers ділять один job. Паралельне навантаження, cache і setup не виміряні для цих окремих прогонів.

Команди відтворення для окремих локальних прогонів: `RECIPE_BATCH_ONLY=1 RECIPE_BATCH_BROWSER=1 bash tests/pg/run-stage1.sh` і `python3 scripts/ci/stage1_groups.py --groups day` / `--groups assignment`; процеси запущені з очищеним оточенням і необхідними PATH/HOME. Відмінності тестового обсягу навмисні й перелічені вище.

Фінальні local Fast/Docs та GitHub checks прив’язуються до фінального SHA в описі Draft PR; попередні результати не є доказом CI на цьому SHA.
