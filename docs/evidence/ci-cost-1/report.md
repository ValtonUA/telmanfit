# CI-COST-1: аудит і реальні вимірювання

Основний [Draft PR #42](https://github.com/ValtonUA/telmanfit/pull/42), issue #41.
Preflight: актуальний master `075a58f807ebe83e6f12a5c28f135c03aa85df1d`, PR #40 merged;
checkout чистий, інших CI optimization PR немає. Прочитано AGENTS, roadmap/status,
усі чотири первісні workflow, PG/SQL runner-и, AUTH-1 source resolver та Compose.
Продуктовий PHP, схема/міграції й auth business logic основного PR не змінені.

## Before: фактичний PR #40

[Raw jobs/steps/timing](before.json): 2 PR commits плюс push master = **11 workflow runs,
14 jobs, 56.18 job-min**, включно зі скасованим Compose першого commit.

| Commit/event | Stage1 | Compose | PHP8.4+8.5 | Docs | Разом job-min |
|---|---:|---:|---:|---:|---:|
| `4960bb7` PR, Compose cancelled | 11.87 | 1.88 | 4.23 | 0.10 | 18.08 |
| `46ff880` фінальний PR | 12.60 | 6.17 | 3.80 | 0.08 | 22.65 |
| `075a58f` push master | 11.57 | — | 3.78 | 0.10 | 15.45 |

4 workflows / 5 jobs на кожний PR commit; push master — ще 3 workflows / 4 jobs.
Stage1: основний auth/browser runner 432s, B3/B4 96s, recipe 62s, зайвий B6 61s.
Compose: cold build 151s, browser 157s. Matrix B6 57s/67s: ці cross-version докази
залишено, третій ідентичний Stage1 PHP8.4 прибрано. Основні suites не визнані
застарілими: AUTH-1 pins, SQL76 та auth boundaries лишаються обов’язковими.

Кожний PG runner повторює pull immutable digest; Docker повторно використовує
layers у межах job, але між hosted jobs потрібне нове завантаження. Ці disposable
runners з їхніми isolation/cleanup не змінено. Основна економія — менше важких
запусків на проміжних commits, а не додаткова паралелізація.

## After: перший позитивний Full на реальному SHA

[Raw jobs/steps/timing](first-full.json), [позитивний verifier](first-full-gate.json),
[мінімальний реальний replay fixture](gate-fixture.json).
SHA `2f6376aa4b0df1d1ae86f487f8bde41b8cf6420c`:

| Перевірка | Run | Реальні job-min | Округлення job вгору, оцінка |
|---|---|---:|---:|
| Full: PG/browser + Compose + PHP8.4/8.5 + docs + gate | [40105750052](https://github.com/ValtonUA/telmanfit/actions/runs/40105750052) | 19.18 | 22 |
| Fast | [40105600427](https://github.com/ValtonUA/telmanfit/actions/runs/40105600427) | 1.05 | 2 |
| Documentation health | [40105600424](https://github.com/ValtonUA/telmanfit/actions/runs/40105600424) | 0.10 | 1 |
| Автоматичний негативний Full gate до явного запиту | [40105600480](https://github.com/ValtonUA/telmanfit/actions/runs/40105600480) | 0.067 | 1 |

Full: PG588s, Compose344s, PHP100s+108s, docs7s, gate4s.
Один зразок не доводить гарантоване прискорення кожної suite: на час впливають
runner/мережа/cache. Усі довгі тести збережено; Composer/npm кешують лише downloads,
не vendor/node_modules/PG/browser state. Cold Compose завжди `--pull --no-cache`.

На цьому SHA verifier дав `eligible: true`. Наступний commit містить launcher,
додаткові regression tests та ці докази. Результат перевірки старого success після
зміни head і повний прогін на остаточному SHA наведено в описі PR #42, щоб запис
фінальних вимірів не породжував новий неперевірений commit.

## Прогноз на основі виміряних samples

Один автоматичний update: Fast+docs+негативний gate = 1.217 job-min; один явний Full
19.183 job-min. Post-merge модель: лише Fast+docs 1.15 job-min (це прогноз, бо PR
не мерджився); baseline post-merge 15.45 виміряно реально. N включає фінальний commit.

| Сценарій | Before job-min | After job-min | Економія job-min | Округлені хвилини, оцінка |
|---|---:|---:|---:|---:|
| 5 commits + post-merge | 128.70 | 26.42 | 79.5% | 148 → 45 (69.6%) |
| 10 commits + post-merge | 241.95 | 32.50 | 86.6% | 278 → 65 (76.6%) |

Модель: before = N×22.65+15.45, after = N×1.217+19.183+1.15.
Орієнтир ≥60% досягається на 5 commits і в оцінці округлення. Це прогноз steady-state
за послідовних commits/одного позитивного Full, не вимір майбутнього рахунку і не
обіцянка для cancelled/retried runs. Одноразові витрати експериментів, failed/cancelled
прогонів, probes та двох Full для доказу stale-head не приховуються як steady-state
економія; фінальний опис PR містить їх окремо.

GitHub timing API повертає `billable.UBUNTU.total_ms=0` попри ненульову тривалість.
Тому **точні billed minutes недоведені**: наведено реальні job timestamps і окремо
арифметичне округлення. Skipped placeholders іноді мають completed_at раніше
started_at; скрипт рахує їх як нуль, а не віднімає час від реальних jobs.

## Перевірені негативні сценарії та межі

[Probes і відмови verifier](probes.json), [реальна PR-base docs відмова](docs-impact-negative.json):

- Чотири тимчасові Draft PR #43–46. Після початкових overlay-перевірок використано
  isolated validation base: diff кожного PR містить лише один docs/PHP/SQL/Compose
  файл. У test base додано тільки його назву до PR base filters; основний PR лишає master.
- Docs-only: Fast/docs success; SQL Stage3 path: success (лише коментар, без зміни
  схеми/міграцій); invalid PHP і invalid Compose: Fast failure. Full gate в усіх
  випадках failure без явного Full. Це не дозвіл пропускати Full для docs-only.
- Valid PHP без canonical docs/waiver: Fast success, PR-base docs guard failure.
- Скасований Full 40105209411: verifier false. Pending Full 40105750052: false;
  request_full.py відмовляє без зміни labels. Позитивний Full на тому самому SHA: true.
- Незалежні PR не скасували основний Full. Усі probes закрито без merge.
- Повторний Full потрібно просити після завершення автоматичних checks:
  synchronize/labeled можуть доставлятися не в порядку push/label. Це перевірено
  реально й оброблено launcher, а не приховано зеленим skipped check.

GET master protection повернув 403 `Resource not accessible by integration`,
master protected=false, rulesets порожні; repository private збережено.
AGENTS + reviewed machine checker — доступний fail-closed fallback для агентів,
але не серверне блокування людини/admin. Auto-merge заборонено. Остаточний позитивний
verifier не означає дозволу merge: усі PR лишаються без merge за прямою задачею.
Позитивний merge перевірено як рішення verifier; фактичний GitHub merge не виконувався.

Повний збережений набір і безпечний запуск — [контракт CI](../../ci.md).
Actions: [перевірені upstream commit pins](action-pins.json).

## Повний перелік файлів основного PR

- `.github/workflows/docs-health.yml`
- `.github/workflows/fast.yml`
- `.github/workflows/full.yml`
- `.github/workflows/local-compose.yml`
- `.github/workflows/php-runtime.yml`
- `.github/workflows/stage1.yml`
- `AGENTS.md`
- `docs/architecture.md`
- `docs/ci.md`
- `docs/evidence/ci-cost-1/action-pins.json`
- `docs/evidence/ci-cost-1/before.json`
- `docs/evidence/ci-cost-1/docs-impact-negative.json`
- `docs/evidence/ci-cost-1/first-full-gate.json`
- `docs/evidence/ci-cost-1/first-full.json`
- `docs/evidence/ci-cost-1/gate-fixture.json`
- `docs/evidence/ci-cost-1/probes.json`
- `docs/evidence/ci-cost-1/report.md`
- `docs/project-status.md`
- `docs/roadmap.md`
- `scripts/ci/measure.py`
- `scripts/ci/pre_merge.py`
- `scripts/ci/request_full.py`
- `scripts/docs_guard.py`
- `tests/ci/test_gate.py`
- `tests/ci/test_measure.py`
- `tests/ci/test_request.py`
- `tests/docs/test_docs_guard.py`
