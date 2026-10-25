# CI-COST-2 — вимірювання та повторне використання підготовки

## Preflight та межі

Issue #68 і останній коментар власника прочитані. PR #67 merged; перевірений
master `a4b6444dcf905e79c83551965eb5fe19bd8af587`, tree
`81099193ee8eae9246e86e2ccff61b038c3c6236` ідентичний фінальному PR #67
`67ee63675e3686191d4c09b364fedbf092718a4d`. Відкритих CI PR не виявлено;
інші відкриті PR #22/#25/#28/#55 — документаційні. Прочитані AGENTS, roadmap/status,
CI-COST-1, усі шість workflows, PG/Chromium/Auth runners; CODEOWNERS відсутній.
Read-only rulesets API показує активний `CI-COST-1` (id 25788875).
Ruleset, `full.yml`, `request_full.py`, `pre_merge.py` не змінюються.
Production, Telmanfit_dev, R2, billing settings/API, feature flags,
бізнес-код, schema/migrations/seed і залежності не змінюються.

## Фактичні GitHub before

[Raw exact-attempt jobs/steps](before.json), [витяги logs](baseline-log-excerpts.txt).
Job-min — сума timestamps усіх шести jobs, незалежно від паралелізації.
Округлення кожного job вгору наведено окремо як арифметичну оцінку, не Billing UI.

| Run / SHA | Stage1 job-min | Усі job-min | Округлення, оцінка |
|---|---:|---:|---:|
| [40183601944](https://github.com/ValtonUA/telmanfit/actions/runs/40183601944) / `bcaedb3` | 14.20 | 23.90 | 28 |
| [40187963368](https://github.com/ValtonUA/telmanfit/actions/runs/40187963368) / `26d3f34` | 20.93 | 30.78 | 34 |
| [40206685368](https://github.com/ValtonUA/telmanfit/actions/runs/40206685368) / `e2231b6` | 24.47 | 34.15 | 37 |
| [40220232725](https://github.com/ValtonUA/telmanfit/actions/runs/40220232725) / `67ee636` | 29.07 | 39.07 | 43 |

Останній Full є before для того самого дерева merged master. Нового платного
exploratory Full після merge не запускали: post-merge baseline вимірюється локально
на disposable PG18. Старші runs містять менше UX-тестів, тому їх не можна видавати
за еквівалентний after denominator. Запас останнього Stage1 до timeout 30 — лише 56 с.

Failed [40217521864](https://github.com/ValtonUA/telmanfit/actions/runs/40217521864)
окремо: 34.97 job-min / оцінка39; Stage1 тестова помилка, не доведена quota failure.
Cancelled [40205150185](https://github.com/ValtonUA/telmanfit/actions/runs/40205150185)
окремо: 16.52 job-min / оцінка19. Вони не входять у success before/after.

| Step, секунди | #65 | #66 | ранній #67 | фінальний #67 |
|---|---:|---:|---:|---:|
| Playwright Chromium + OS dependencies | 27 | 34 | 33 | 31 |
| Stage3/4 recipe + concurrency + browser | 328 | 661 | 840 | 1086 |
| B5 | 10 | 11 | 13 | 14 |
| SQL/full PG/auth/browser | 368 | 406 | 420 | 462 |
| B3/B4 | 69 | 80 | 92 | 95 |
| isolated SQL/PDO G4 | 5 | 6 | 4 | 6 |
| AUTH1 | 10 | 14 | 11 | 7 |

Logs фінального before: recipe PHPUnit142/2464 займає46.968с, повний PG194/3436 —
56.950с; recipe Chromium — приблизно1004с від закінчення concurrency до browser PASS.
Тобто інсталяція Chromium31с і повторні міграції не пояснюють основні17+хв.
Composer/npm cache hits підтверджено logs усіх чотирьох success runs.
Recipe 142 + повний PG 194 = 336 викликів per-test baseline/transaction setup;
цей повтор також збережено, включно з історичними DDL/rollback доказами. Перший PG pull cold; подальші4 Stage1
pulls того самого immutable digest — warm. У Stage1 п'ять disposable clusters
(recipe/B5/baseline-auth/B3-B4/G4), у matrix ще два B6. Chromium install один на
Stage1 і один на окремий Compose job; повторних install усередині PG runners немає.

## Рішення й збережене покриття

Першим перевірено reuse PG setup: кластери створюються за секунди, тоді як B5
спеціально перевіряє dump/restore faults, baseline — чистий SQL01–04 і upgrade,
B3/B4 — інші ролі/ACL. Спільний mutable cluster/готова schema змінили б початкові
умови цих доказів. Тому setup DB не об'єднується й жодна міграція не пропускається.
Повторне завантаження immutable PG layers уже усуває Docker у межах job.

Натомість кожний запит `php -S` заново компілював Laravel/Filament/vendor PHP.
`tests/browser/php-server.sh` вмикає процесний OPcache: reuse лише bytecode, новий
Laravel application/request на кожний HTTP. Є timestamp validation; file cache,
preload/JIT і persistent workers відсутні. Кеш живе до kill/exit HTTP-сервера,
не переноситься між PR/jobs і не містить PG/session/browser-profile snapshots.
Реальні тестові guards, PHP middleware, ACL, auth, CSRF та SQL залишаються чинними.

Чотири browser wrappers використовують той самий launcher. Додаткові тести
перевіряють справжні hits, request-state isolation, timestamp revalidation,
скидання кешу після restart і відмову для production/non-loopback.
`CI_STAGE1_TIMING` відокремлює image pull, cluster/roles/database, migrations,
SQL baseline, PHPUnit, concurrency, browser та total разом із cleanup.
Stdout recipe і baseline/auth збережено через Bash pipefail/tee; помилка не ховається.

Жодного suite/method/assertion не видалено, PHPUnit subset навіть не скорочено:
SQL76, PG Stage1–4/ModelI/T4.3/UX-4E, AUTH1–6, concurrency/locks, history/integrity,
1440/390/320 light/dark виконуються як раніше. PHP8.4/8.5 B6, frontend builds,
audit, Pint і Compose `--pull --no-cache`/credential-exclusion залишені.
Ідентичний PHPUnit subset в recipe і повному PG помічено, але його видалення дає
лише47с; задля максимального збереження початкових умов обидва запуски збережено.
Нових jobs, self-hosted, cross-PR caches або cache vendor/node_modules немає.
Browser binary cache не додано:31с before недостатньо для цілі, новий download/upload
cache має власні витрати; Composer/npm download caches уже працюють.

Локально виявлено недетермінований `week-fill`: unordered published recipe міг
виявитися batch, тоді legacy serving INSERT правильно відхиляв SQL guard.
Fixture тепер явно обирає published `legacy_v1`, якому відповідає наявний INSERT;
batch explicit A/1 B/7 C/3 C/4 перевірки лишилися окремо. Локальні before/after
також виявили race з Alpine enter/leave у вкладеному menu та modal: orchestration
тепер очікує завершення реальних animations перед menu action і mobile overflow
assertion. Не додано fixed sleeps, force clicks, retries бізнес-операцій, вимкнення
animations або видалення assertions; зокрема реальний AUTH5 resend cooldown 60с збережено. Це зміна тільки підготовки
synthetic даних, жоден business guard не послаблений. Before/after мають однаковий fixture.

## Локальні й фінальні докази

[Локальний summary](local-checks.json), [повний recipe stdout](local-recipe-after.txt).
Підсумок after: PG142/2464, усі concurrency та Chromium19 checks у1440/390/320
light/dark PASS; total з cleanup887с (shell time886.647с), browser831с.
AUTH4:30/181 + browser/concurrency PASS; AUTH5:19/131 + browser/concurrency PASS;
B5:41/392 + restore/concurrency PASS; G4 SQL76/0/0 та PDO/cleanup PASS.
Unit/Feature85/348, Pint, SPA lint, обидві frontend builds, platform checks,
23 CI regression tests, docs tests/guard PASS. Історичні tracked screenshots і
інші evidence відновлено, щоб цей PR не переписував докази старих задач.
Локальний Chromium154 Debian відрізняється від Playwright download на GitHub;
локальні wall times не порівнюються напряму з hosted job-min. AUTH4/5 та G4/B5
виконувалися паралельно частині recipe, що додає шум локальному timing.
 Неповні/failed
локальні спроби не використовуються як success baseline. [Два HTTP samples](http-comparison.json):20 GET `/admin/login` після одного warmup,
окремі свіжі HTTP процеси before/after, та сама disposable PG18, PHP8.4.27,
Laravel/Filament/vendor, без cookie reuse. Час запитів 3.249→0.568 с та 3.576→0.732 с
(82.5% та 79.5%). Це мікровимір повторної компіляції/bootstrap, а не25% доказ
для всього Stage1; background Chromium робить його шумним. Router однаковий,
MFA/CSRF/middleware не вимикаються. Контексти/профілі не кешуються.

Початкові локальні спроби з missing assets і застарілим vendor завершилися failure
та виключені з success measurements; vendor синхронізовано з чинним lock
(Livewire4.4.8), PHP локально оновлено з8.4.26 до підтримуваного8.4.27.
Незмінений master потім виявив описаний недетермінований legacy fixture;
повторний локальний before використовує старий launcher з однаковим fixture fix,
пройшов PG142/2464 і1440-light, але зупинився на timeout вкладеного меню «Позиції»
в наступному viewport. Він теж не є success baseline; не екстраполюємо його4м49с
на повний run. Успішний GitHub Full #67 залишається основним before.
Ці локальні витрати не є GitHub Actions minutes.

Фінальні GitHub run/SHA, actual job-min, оцінка округлення, after steps та read-only
trusted `pre_merge.py` публікуються **в описі цього Draft PR** після одного явного
Full на фінальному head. Це не створює нового commit після CI. Точні billed minutes
залишаються непідтвердженими; Billing UI/API не читаємо за рішенням власника.
Один GitHub sample не доводить стабільного відсотка на всіх hosted runners; додаткові
платні exploratory Full не запускаються. Merge/auto-merge не дозволені.

## Усі змінені файли

- `.github/workflows/stage1.yml`
- `docs/ci.md`
- `docs/evidence/ci-cost-2/baseline-log-excerpts.txt`
- `docs/evidence/ci-cost-2/before.json`
- `docs/evidence/ci-cost-2/http-comparison.json`
- `docs/evidence/ci-cost-2/local-checks.json`
- `docs/evidence/ci-cost-2/local-recipe-after.txt`
- `docs/evidence/ci-cost-2/report.md`
- `docs/project-status.md`
- `docs/roadmap.md`
- `scripts/ci/measure.py`
- `tests/browser/auth3-in-context.sh`
- `tests/browser/auth4-in-context.sh`
- `tests/browser/auth5-in-context.sh`
- `tests/browser/meal-plan-fixture.php`
- `tests/browser/meal_plans.py`
- `tests/browser/php-server.sh`
- `tests/browser/recipe-batch-in-context.sh`
- `tests/ci/test_measure.py`
- `tests/ci/test_php_server.py`
- `tests/pg/run-stage1.sh`
