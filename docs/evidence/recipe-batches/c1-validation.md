# Локальна перевірка C1

Реалізація й межі — [канонічний контракт](../../recipe-batches.md#нова-версія-abc-pr-c1).
Це synthetic evidence, а не production activation або результат GitHub CI.

## Результат

Новий draft із published A/B/C зберігає frozen aggregate та manual intent,
отримує нові child IDs/FK/tokens і lineage. Review та adequacy підтверджуються
повторно. Старі published rows, food і призначений план незмінні навіть після
публікації наступної версії. Clone не читає актуальні довідники для перерахунку;
їхні зміни приймаються лише explicit Refresh у штатному редакторі.

## Виконані перевірки

- PHP 8.4.27: `php artisan test` — 80 tests / 192 assertions PASS.
- `NODE_USE_ENV_PROXY=1 npm run build` — PASS; `npm run lint --prefix client`
  та `npm run build --prefix client` — PASS.
- `RECIPE_BATCH_ONLY=1 RECIPE_BATCH_BROWSER=1 bash tests/pg/run-stage1.sh` —
  disposable PostgreSQL 18.6; 40 tests / 579 assertions PASS, усі чотири штатні concurrency/reload
  scripts PASS; Chromium 15 checks PASS, page_errors=[].
- `AUTH3_BROWSER=1 AUTH4_BROWSER=1 AUTH5_BROWSER=1 bash tests/pg/run-stage1.sh` —
  PASS із cleanup: SQL baseline 76/76, повний PG набір 92 tests / 1550 assertions,
  unit/feature 80/80, Pint, auth2–6 PHPUnit/concurrency, auth3/4/5 Chromium,
  auth6 25 паралельних HTTP сценаріїв. Після фінальної правки збереження зображень
  повторно пройдено unit/feature та весь цільовий C1 PG/concurrency/browser набір.
- Розширений `batch-editor-concurrency.php`: реальне очікування recipe lock,
  один draft із двох конкурентних clone, COMMIT/reopen/remap та CAS копії PASS.
- `BatchEditorTest`: frozen snapshots/policies та auto/manual B/C, nullable
  equipment, нові PK/component links/tokens/image_url, збереження image_url незміненого
  кроку після Save та зміни порядку, повторний review,
  наступний food/version_number і lineage chain, source immutability,
  призначений published plan/assignment, stale source/revision, draft/legacy
  boundary, duplicate draft/SQL UNIQUE, revoked role/flag, пізній rollback.
- `python3 scripts/docs_guard.py --pr-base 075a58f807ebe83e6f12a5c28f135c03aa85df1d`,
  `vendor/bin/pint --test`, `git diff --check` — PASS.

[Browser checks](checks.json), [нова чернетка після clone](clone-1440-dark.png).
Light/dark 1440/390/320 px — штатний editor без горизонтального overflow.

## Межі й наступні кроки

Перевірки виконано лише на нових synthetic PG18 containers із cleanup;
реальні БД/production/секрети/персональні дані не використовувалися.
Схема, міграції, SQL 11–12, workflows, CI scripts/config не змінювалися;
`factor` не перейменовувався. Feature flag OFF.
Зображення незмінених текстів кроків зберігаються при Save; змінений текст
трактується як новий крок без попереднього зображення (editor передає тексти,
а не ідентичності кроків). Немає нового редактора зображень.
C2 (персональні CoW), C3 (legacy conversion), Stage 4, Excel/T1.5 та activation
не виконуються. DEC-3T не блокує C1.

GitHub CI належить конкретному майбутньому PR head. До merge CI-COST-1
Draft PR C1 не відкривати; після merge синхронізувати master, перевірити
новий Full gate на фінальному SHA. Merge C1 цим завданням не дозволений.

## Повний перелік файлів C1

- `app/Services/Recipes/BatchEditor.php`
- `app/Filament/Pages/RecipeBatches.php`
- `resources/views/filament/pages/recipe-batches.blade.php`
- `tests/Postgres/BatchEditorTest.php`
- `tests/pg/batch-editor-concurrency.php`
- `tests/browser/recipe-batch-fixture.php`
- `tests/browser/recipe_batch.py`
- `docs/roadmap.md`
- `docs/project-status.md`
- `docs/recipe-batches.md`
- `docs/evidence/recipe-batches/c1-validation.md`
- `docs/evidence/recipe-batches/checks.json`
- `docs/evidence/recipe-batches/clone-1440-dark.png`
- `docs/evidence/recipe-batches/editor-1440-dark.png`
- `docs/evidence/recipe-batches/editor-1440-light.png`
- `docs/evidence/recipe-batches/editor-390-dark.png`
- `docs/evidence/recipe-batches/editor-390-light.png`
- `docs/evidence/recipe-batches/editor-320-dark.png`
- `docs/evidence/recipe-batches/editor-320-light.png`
