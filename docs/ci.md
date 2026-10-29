# CI: обов’язкові PR-перевірки без Full CI

Кожен PR до `master` надходить лише з `feature/*` і виконує обов’язкові статуси: **Fast PR checks**,
**Docs links, roadmap and PR impact** і **Targeted PR checks**, а також обидві jobs **Local update PowerShell compatibility** і **Feature environment checks**. Full CI workflow
та його ручний тригер вилучені. Жоден PR, зміна критичних файлів, label, push,
schedule чи ручний запуск не запускає Full. Після bootstrap нового процесу ruleset
додатково вимагає commit status **Feature acceptance**; він не входить у preview
CI prerequisites і не створює циклу перед продуктовим прийманням.

Fast перевіряє runtime, Composer lock/platform/audit, PHP syntax, Compose config,
Pint, Unit/Feature на SQLite, обидві frontend збірки та client lint. Docs перевіряє
посилання/roadmap і docs-impact **від PR base до точного head**, включно з
видаленнями. Targeted залишає обов’язковий статус на кожному PR, але запускає PostgreSQL/browser і security набори лише за релевантним diff; документаційні, CI-only та нецільові зміни не піднімають їхнє важке оточення. Workflow має contents: read, без `pull_request_target` і secrets.

`Local update PowerShell compatibility` запускає parser-only перевірку
`scripts/local-update.ps1` та `scripts/feature.ps1` на Windows PowerShell 5.1 і PowerShell 7. Перевірка
підтверджує UTF-8 BOM і синтаксис, але coordinator не виконується та CI не
під’єднується до Docker чи бази даних. У тій самій матриці запускається suite
`tests/local-update-coordinator.ps1`: він перевіряє coordinator helpers і Git/Docker
CLI на synthetic Git repositories та read-only Docker CLI probes, не запускаючи
upgrade coordinator, Docker services або базу даних.

`scripts/ci/pre_merge.py` перевіряє Fast, Docs, Targeted, обидві Windows compatibility jobs та Feature environment checks на точному PR head,
поточну ancestry до master, найновіший run/attempt, усі jobs/steps, PR event і
відсутність auto-merge. Він не читає змінені файли чи labels для вимоги Full.
Після зміни head/base всі required checks мають пройти на новому стані. Незалежний людський `APPROVED` на exact head та продуктове приймання власником exact head/base також обов’язкові; `--purpose preview` перевіряє все, крім продуктового приймання/Draft. Формат рішення та Windows launcher — [feature-environments.md](feature-environments.md).

PostgreSQL і browser регресії запускай локальними runner-скриптами зі своїми
ізольованими disposable базами й guards. Їхній локальний PASS — це локальний доказ,
не GitHub CI на конкретному SHA. Не викликай повний CI workflow або Full suites у
GitHub Actions.

Зміни адаптерів `app/Services/Catalog/`, R2-команди, `config/r2.php` або
`R2ImageMatcherTest` обирають групу `recipe`, що містить PostgreSQL-тести
каталогів і R2 dry-run. Вони використовують лише synthetic source/HTTP mocks.

## Автоматичні перевірки агентського процесу

Новий локальний Codex `PreToolUse` hook із `.codex/hooks.json` перевіряє
очевидно руйнівні Bash-команди через `.codex/hooks/pre_tool_policy.py`.
Його stdlib-регресії в `tests/ci/test_agent_hook.py` запускає чинний
`Documentation health` (крок `CI gate regression tests`) разом з іншими
`tests/ci` — без нового workflow чи дорогого PostgreSQL/browser запуску.
Процедуру, межі та інструкцію `/hooks` описано в
[agent-workflow.md](agent-workflow.md).

Hook діє лише в Codex середовищах, де project config визнано довіреним і
конкретне правило активовано; це не GitHub branch protection, не серверний
authorization guard і не заміна `scripts/ci/pre_merge.py` та прав доступу.
Перевірка Windows runtime Codex необхідна окремо: Python unit-тести в Linux CI
не доводять працездатність локального `commandWindows`.

## Набори тестів для локального запуску

Ці suite-файли, раннери й reusable workflow-файли збережені для цільової локальної
діагностики. GitHub Actions більше не викликає Full PostgreSQL/browser, PHP matrix
чи cold Compose. На PR PostgreSQL/browser/security перевірки виконуються лише
цільовими групами workflow `targeted.yml`; Fast/Docs лишаються окремими required checks.



- Stage 1 SQL baseline 76, PostgreSQL18/ICU/btree_gist, runner fail-closed tests,
  ізольований SQL/PDO runner, Stage 1/2/3 та profiles upgrade/regression.
- Recipe A/B/C, B1/B2, historical upgrade, locks/concurrency, accepted/pending
  provenance, UOM reload, Chromium light/dark та розміри екрана.
- Харчові каталоги: atomic food/subtype, decimal/default/UOM/ACL/CAS,
  frozen history та реальні catalog writer locks; Chromium create/edit,
  CSRF/revocation, light/dark 1440/390/320; [контракт](food-catalog.md).
- Раціони T4.1/T4.2: Eloquent/atomic draft/ACL/immutable history, генерація
  семиденних блоків, редагування структури, retry/stale/PG negatives,
  UX-4D: empty/no-modal, filled confirmation/cancel, stable anchor/retry, middle-week renumber та history hashes;
  реальні delete-vs-append/delete-vs-raw-item INSERT locks і Chromium light/dark 1440/390/320; [контракт](meal-plans.md).
- T4.5: exact published-version client assignment, atomic replacement/history, fresh admin/recovery fence,
  owner/ACL/date checks, PG exclusion/locks/concurrency and confirmation/stale browser flows;
  local reproduction: `MEAL_PLAN_ASSIGNMENT_ONLY=1 MEAL_PLAN_ASSIGNMENT_BROWSER=1 bash tests/pg/run-stage1.sh`;
  [контракт](meal-plans.md).
- T4.4: publication preflight/atomic transition, exact Model I/history, SQL immutability,
  PG/Livewire, independent-session lock waits та Chromium light/dark 1440/390/320
  у чинній recipe групі; [контракт](meal-plans.md#публікація-точної-версії-t44).
- G4-I Model I: populated upgrade hashes, direct SQL selector negatives, decimal
  readonly resolver, fractional portions, frozen physical composition та root/selector
  concurrency; входять у Stage3/T4.2 runner без додаткового workflow.
- T4.3 writer: ingredient/default/decimal, legacy та explicit A/1 B/7 C/3 C/4,
  optimistic state/catalog snapshots, Livewire/ACL/fence/rollback, item-vs-week-delete,
  concurrent edit/add/publication/conversion/UOM locks; Chromium search/add/edit/move/delete
  у 1440/390/320 light/dark через штатний Stage3/4 runner.
- UX-4E: точний week/day editor, атомарне перенесення/сортування, stale/ABA та
  concurrent move/delete/week/publish/role/fence; Chromium щільний/порожній тиждень,
  mouse DnD, Pointer adapter, доступні кнопки та явні A/B/C у 1440/390/320 light/dark.
  Нові сценарії входять у чинний Stage3/4 runner без додаткових jobs/dependencies.
- AUTH-1 upstream digest/versions; AUTH-2–6 PHPUnit, HTTP, browser та concurrency;
  B3/B4 outbox/ACL, B5 retention/archive/restore faults, B6 budgets/restart/faults.
- B6 виконується на **обох** PHP 8.4/8.5. Третій ідентичний прогін на Stage 1 PHP8.4
  прибрано; фактичний runner/сценарії не змінені. Matrix зберігає Unit/Feature,
  Pint, dependency audit та frontend build на обох runtime.
- Стандартний Compose `build --pull --no-cache`, synthetic credential/database
  exclusion probes у готовому образі, MFA/OTP/CSRF/fence, persistence та cleanup.
- Docs guard із PR-base і регресійні тести CI виконуються в обов’язковому Documentation health.

Кешуються завантаження Composer і npm, прив’язані до lock hash; vendor,
node_modules, browser profiles, PG volumes і cold Docker layers не кешуються.
Chromium-тести Stage1 запускають PHP через `tests/browser/php-server.sh`: OPcache
повторно використовує скомпільований байткод лише протягом життя одного synthetic
HTTP-сервера. Laravel bootstrap, middleware, session/CSRF, SQL та бізнес-операції
виконуються на кожний запит. Немає persistent worker/application state, file cache,
preload або JIT; timestamp validation увімкнено. Launcher відмовляє поза testing /
TELMANFIT_PG_TEST, на не-loopback адресі або без активного OPcache. Зупинка сервера
знищує кеш. Кеш байткоду не експортується між jobs/PR і не торкається cold Compose.
Recipe browser orchestration чекає повний navigation `load` без зайвої network
silence; same-document оновлення мають actual Livewire render readiness через
test-only commit hooks. Відсутній hook/failed
commit блокують очікування; selector assertion доводить реальні hook events.
Повторний static Pint у PG runner прибрано: його зберігають обидва required PHP
matrix jobs і Fast. SQLite tests під synthetic PG bindings збережені.
Chromium regression тести readiness та реального cancellation cleanup запускаються у Stage1 після Playwright
install. У recipe browser mutation-сценарії планів і каталогу проходять для кожної ширини
1440/390/320 у light; усі шість width/theme комбінацій зберігають responsive render,
no-overflow та dark/light перевірки форм і станів. У dark залишаються published
read-only, create-form, catalog edit і search перевірки. Повні бізнес-операції та
cancel/DB-invariance перевірки виконуються для кожної ширини у світлій темі.
Backend PG/Unit/concurrency assertions не дублюються за темою.
T4.5 browser очікує HTTP-відповідь саме свого Livewire method
(`mountAction`, `callMountedAction`, `unmountAction`) та commit `succeed/onRender`.
Фонова `pullNotificationsFromSession` не підтверджує завершення натиснутої дії.
Окремі Chromium readiness регресії перевіряють цю кореляцію, failed action і
відмову приймати інший method; timeout та бізнес-assertions не послаблюються.
`CI_BROWSER_READINESS` рахує calls/time. Бізнес-assertions і реальні MFA/OTP timer/cooldown перевірки збережені.
`scripts/ci/stage1_groups.py` запускає максимум два workers **всередині одного
Stage1 job**: day + B5, recipe + baseline/auth, assignment + B3/B4. Це не нові billed
jobs: GitHub рахує wall time одного зайнятого runner, а не суму worker durations.
Composer/npm/Playwright і immutable Docker layers готуються один раз на job.
Workers мають різні disposable PG/socket, VIEW_COMPILED_PATH, PHPUnit cache,
HTTP ports (day/recipe/assignment 8090 у різних фазах; auth8093–8098), stdout files і process groups. PG mutable
state не ділиться; SQLite85 у synthetic context і обидва PG142/194 збережені.
Parent і workers відмовляють для remote Docker. Усі вибрані exit0/completed/success
обов'язкові; missing/failed/cancelled/pending/skipped блокує orchestrator. При
помилці/сигналі siblings завершуються; fallback перевіряє випадковий ownership
label і прибирає тільки власні контейнери. Знайдений leftover навіть після recovery
робить результат failure. AUTH1 і його GH_TOKEN лишаються окремим наступним кроком.
`tests/pg/out/groups.json` містить точний SHA, UTC/seconds/status кожного worker і
загальний час orchestration; worker seconds не видаються за billed job-min.
PG-кластери залишаються окремими для day, assignment, recipe, B5, baseline/auth, B3/B4 та G4;
міграції, SQL76, усі повторні PHPUnit-набори й concurrency/upgrade/reset докази збережені.
Деталі вимірювань і відхилені варіанти — [CI-COST-2](evidence/ci-cost-2/report.md).
`npm ci --ignore-scripts` замінює mutable install, Composer install не виконує
plugins/scripts, а package discovery та потрібний Stage1 `filament:upgrade` запускаються явно. Actions pinned до повних
commit SHA перевірених upstream tags. Тестові workflows мають contents: read,
checkout persist-credentials: false; secrets не успадковуються, pull_request_target
не використовується. GH_TOKEN лише read token для pinned AUTH-1 public blobs.
Synthetic PG/docker створюються з чинними isolation guards і власним cleanup;
`CI_STAGE1_TIMING` додає тривалості pull, cluster/setup, SQL, PHPUnit, concurrency,
Chromium і total разом із cleanup. Worker stdout і `groups.json` зберігаються
в `stage1-pg-logs` поряд із чинними SQL outputs. Exit codes перевіряються напряму,
без pipeline, що міг би приховати failure.



## Вимірювання й перевірка

[Звіт before/after і сценарії 5/10 commits](evidence/ci-cost-1/report.md).
[Виміряний baseline](evidence/ci-cost-1/before.json) містить run/job SHA, GitHub URLs,
started/completed timestamps, усі steps і історичну відповідь timing API. Відтворення:

```sh
python3 scripts/ci/measure.py <run-id> [<run-id> ...] > /tmp/ci-measurements.json
python3 -m unittest discover -s tests/ci -v
python3 -m unittest discover -s tests/docs -v
python3 scripts/docs_guard.py --pr-base <base-sha>
```

Поточний `measure.py` читає лише run і exact-attempt jobs/steps; Billing/timing API
не викликається за рішенням власника Issue #68.

Job-min = сума тривалостей **усіх** jobs; паралелізація сама не дає економії.
`rounded_ubuntu_minutes_estimate` — округлення кожного job вгору, а не підтверджений
рахунок GitHub. На baseline timing API повернуло нульові billing-поля попри ненульову тривалість jobs.
Точні billed minutes тому недоведені;
не називати оцінку списаними хвилинами. Before/after і сценарії 5/10 commits
публікуються у Draft PR після завершення реальних GitHub runs. Дані останнього
фінального SHA додаються до опису PR, щоб не створювати новий неперевірений commit.


## PR gate без Full CI

Кожен PR додатково до обох Windows compatibility jobs виконує три технічні статуси: **Fast PR checks**, **Docs links, roadmap and PR impact** і **Targeted PR checks**. Selector перевіряє checkout на точному head і бере об’єднання покриття для кожного зміненого або видаленого шляху; перейменування розглядаються як видалення та додавання. Невідомі критичні app/resources/config/routes/client/tests, кореневі конфігурації, залежності, shared auth та schema розширюють вибір до всіх наборів; широкий вибір включає окремий SQL schema suite. Документаційні та CI-only зміни залишаються дешевими, а CI unit regressions проходять у Documentation health.

| Зміна | Вибір та реальне покриття |
|---|---|
| Day/week Blade, `EditMealPlanDay`, `day_editor.py` і day PG/concurrency | `day`: PG day/writer/selectors/structure/week/publication, окремі concurrency, Chromium планів із MFA, CSRF, revocation та незмінністю історії |
| Спільні `meal_plans.py` або `recipe-batch-in-context.sh` | `day` + `recipe`: обидва browser entrypoints використовують ці залежності |
| Assigner, assignment UI та їхні тести | `assignment`: PG assignment та copy/history, AUTH4 client meal plan, assignment concurrency та Chromium із MFA/CSRF/revocation |
| Спільний Filament theme CSS | `day`, `recipe`, `baseline`: споживачі редактора, каталогу та auth/UI |
| Спільні writer/structure/publisher, каталог, cooking | `recipe` + `baseline`: пов’язані PG, concurrency, browser та AUTH4 client API; `ClientCookingTest` запускається через AUTH4, не через PG-фільтр |
| Shared auth, міграції, SQL, залежності й невідомі критичні шляхи | Усі групи та SQL schema suite; жодного silent skip |
| Auth-тести та readiness | `baseline`, `b5`, `b3` за предметним правилом; readiness окремо `baseline` |

`recipe` збережено як широкий fallback для спільних залежностей; точні day та assignment набори виконують власні сценарії на незалежних кластерах. Докази й обмеження порівняння — у [CI-SLICE-1](evidence/ci-slice-1/report.md). Кожен вибраний worker має завершитись успішно; відсутні, пропущені або невдалі набори блокують gate.

Full GitHub Actions workflow і його ручний тригер видалені. Усі критичні шляхи, `ci:full` label, ручні події, push та schedule не запускають Full; merge gate не вимагає Full. Повний PostgreSQL/browser, PHP 8.4/8.5, AUTH-1 upstream, security та cold Compose залишаються лише локальними наборами/раннерами; для локального запуску див. `tests/pg/README.md`, `tests/pg/run-stage1.sh`, `tests/pg/test-runner.sh`, `tests/pg/run-isolated-pg.sh` та відповідні предметні документи. Targeted PR workflow лишається required status, але для diff без релевантної PG/browser області завершується легким scope/gate шляхом без reusable Stage1 runner; для релевантних diff кожна вибрана група мусить успішно пройти.


## Серверний admission status

`feature-acceptance.yml` має `statuses: write` лише для publication commit status
і read-доступ до PR/comments/contents. Він checkout-ить trusted `master`, не PR
та не artifacts. Admission обчислюється `publish_feature_gate.py` через той самий
live pre-merge evaluator, із pending перед перевіркою та failure при API error.
Події CI completion/review/comments переоцінюють відкриті feature PR; окрема
серіалізована queue не виконує код PR. Нові правила сервера встановлюються лише
після прийнятого bootstrap merge через `feature_rules.py --apply` із read-back.
Деталі, права й відмінність підготовленого плану від активації —
[feature-environments.md](feature-environments.md#серверний-захист-і-встановлення-цього-процесу).

`Feature environment checks` завжди залишає required status. Регресії manager
виконуються для кожного PR; cold Docker lifecycle запускається лише при зміні
feature tooling, Docker runtime, залежностей або міграцій/SQL. Решта diff
отримує легкий scope PASS без Docker. Це цільова acceptance-перевірка двох
disposable PG18 ревізій, не повернення Full CI. Admission preflight у цьому
harness синтетичний; справжній review/CI/acceptance перевіряє live gate окремо.
