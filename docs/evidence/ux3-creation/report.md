# UX-3 — локальна перевірка створення раціону

База — `8465f6e0417fa121b622beda59aacb108be35a08`; робочі БД не використовувалися. Користувач обирає чинні типи самостійно: непідтверджені стандартні коди й ID не припускаються. Нова чернетка відкривається з сімома днями; для старої порожньої чернетки є окрема дія з незмінним `start_day=1`.

Локальні команди:

- `UX3_ONLY=1 bash tests/pg/run-stage1.sh` — 9 UX-3 PHPUnit тестів, 128 assertions; окремий concurrent retry: два незалежні PG-процеси очікують блокування, один план і квитанція, повтор після commit повертає той самий результат.
- `RECIPE_BATCH_ONLY=1 RECIPE_BATCH_BROWSER=1 bash tests/pg/run-stage1.sh` — 240 PostgreSQL-тестів, 3949 assertions; recipe concurrency, Chromium і CoW PASS. Усі БД — нові ізольовані disposable PostgreSQL 18.
- `python3 scripts/ci/stage1_groups.py --groups baseline,b5,b3` — baseline, B5, B3 PASS; SQL baseline 76 PASS, 0 FAIL, 0 GAP.
- `php artisan test --display-all-issues` — 126 тестів, 522 assertions; `php vendor/bin/pint --test`, PHP syntax, `docker compose config --quiet`, `composer validate --strict`, `composer check-platform-reqs`, `composer audit --locked --no-interaction` — PASS; PHP 8.4.27.
- `npm run lint --prefix client`, `npm run build --prefix client`, `NODE_USE_ENV_PROXY=1 npm run build` — PASS.
- `python3 -m unittest discover -s tests/docs -p 'test_*.py'`, аналогічний набір `tests/ci`, `python3 scripts/validate_t15_source.py`, `python3 scripts/docs_guard.py` — PASS.

Chromium використовує справжню MFA/Livewire-сесію та synthetic PostgreSQL fixtures: створення → сім днів → редагування дня → список → повторне відкриття; 1440/390/320 light/dark, keyboard, touch, overflow і JS errors. Втрачена відповідь після commit і повтор використовують ту саму квитанцію. Окремо перевірені legacy-дія, CSRF, revoked access та незмінність published history. [Перелік browser-перевірок](browser-checks.json), форми: [1440](create-form-1440-dark.png), [390](create-form-390-dark.png), [320](create-form-320-dark.png).

Застосування forward-only SQL20 до будь-якої наявної БД не входить у цей дозвіл. Локальні результати не є результатами GitHub CI; незалежний review і required checks перевіряються окремо на фінальному head.
