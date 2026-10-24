# C3 — технічні докази legacy conversion

Локальна перевірка 24.10.2026: PHP 8.4.27, PostgreSQL 18.6 у нових disposable
контейнерах `--network none`, без опублікованих портів, із синтетичними fixtures
та cleanup. Це не activation і не доказ зміни production/Telmanfit_dev.

Архітектурний контракт — [recipe-batches.md](../../recipe-batches.md#явна-legacy-conversion-pr-c3).
[Forward SQL 13](../../13_recipe_legacy_conversion.sql) дозволяє лише одноразовий
legacy draft→batch draft із originals у наявних snapshots; published source
створює нову окрему draft-версію того самого recipe. Старі food/КБЖВ/плани/
assignments не змінюються. Історичних ingredient nutrition snapshots не вигадано.

| Перевірка | Результат |
|---|---|
| SQL baseline | PASS=76, FAIL=0, GAP=0 |
| Увесь PostgreSQL regression | 114/114, 1943 assertions |
| Окремий recipe набір із чинним C3 guard | 62/62, 971 assertions |
| Unit/Feature | 80/80, 192 assertions |
| Concurrency | Реальні recipe locks/PG waiters: duplicate draft/published conversion, stale catalog/revision, COMMIT/reload; чинні A/B/C та C1/C2 concurrency теж PASS |
| Chromium | 17 загальних checks; C3 включає 12 draft/published × 1440/390/320 × light/dark preview/cancel, 2 confirm/negative acceptance/redirect/reload та invalid-token refusal |
| Стан теми | Перевіряється фактичний `documentElement.dark`, не лише localStorage; overflow відсутній |
| Додаткова регресія | AUTH-2–6 PHPUnit/concurrency та AUTH-6 parallel HTTP PASS |
| Інші checks | Pint, client lint, обидві frontend збірки, docs guard; CI verifier unit tests 20/20, docs tests 7/7 |

[checks.json](checks.json) містить виконані browser-перевірки й фактичні SHA-256
source snapshots до/після. Для draft hash змінюється після явного підтвердження;
для published hash однаковий, result URL має новий version ID. Preview/cancel та
невалідні tokens перевіряються на незмінність hash. PNG у цій теці — обидва
legacy сценарії у всіх шести viewport/theme комбінаціях.

[LegacyBatchEditorTest](../../../tests/Postgres/LegacyBatchEditorTest.php) перевіряє:
збережені 150 г при servings_count=4 → A=150, B=1050, C=525; зміну поточної
конверсії 30→32 та nutrition 100→120 з явним прийняттям; originals у snapshots;
Save/Refresh/pending/review/Publish; component sort/FK, image_url та remap токенів;
новий food лише під час Publish; одна чернетка; FK-захист видалення UOM.
Негативні synthetic cases включають missing conversion, g≠grams, NaN у
conversion/grams/servings/nutrition, overflow, невідомі tokens, порожні кроки,
неоднозначний snapshot, stale revision/token, client/coach/revoked/flag-off.

Late-failure trigger на INSERT resolved equipment навмисно перериває обидва
сценарії після запису headers/quantities і переходу contract. SHA-256 усіх
агрегатних, food, plan та assignment рядків до/після однаковий. Тести також
перевіряють SQL-відмову довільного/зворотного переходу та published UPDATE.

Перший загальний HTTP-прогін конфліктував із recipe browser за порт 8097.
Повторний загальний runner виконувався окремо й завершився PASS, включно з
AUTH-6 parallel HTTP. Runtime оновлено локально з 8.4.26 до підтримуваного
8.4.27; constraints/lockfile/CI не змінювалися. Адмінська збірка використовувала
`NODE_USE_ENV_PROXY=1` для погодженого мережевого proxy.

Чинні межі: закритий feature flag; історичні nutrition/factor/назви, яких legacy
не зберігав, невідомі; перевірка фізичної достатності обладнання залишається
авторською. Stage 4, timer tokens, T1.5/Excel/упаковки, backfill та production
activation не входять у цей PR. Результат GitHub CI належить точному head SHA
та перевіряється незміненими `request_full.py` / `pre_merge.py`, без обходів API.
