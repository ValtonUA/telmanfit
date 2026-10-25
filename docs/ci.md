# CI: Fast PR і Full PR gate

Fast PR + Documentation health запускаються для кожного оновлення PR до master
і push master без path-фільтрів. Fast перевіряє runtime, Composer lock/platform/audit,
PHP syntax, Compose config, Pint, Unit/Feature на SQLite, обидві frontend збірки та client lint. Docs перевіряє
посилання/roadmap і docs-impact **від PR base до точного head**, включно з видаленнями.
Workflow/CI scripts теж потребують canonical docs або обґрунтованого Docs-Impact.

## Запуск Full на фінальному head

1. Оновити гілку так, щоб актуальний master був її предком. Переконатися, що
   Fast PR і Documentation health успішні на потрібному SHA.
2. Запустити із довіреного checkout:

   ```sh
   python3 scripts/ci/request_full.py <PR> --repo ValtonUA/telmanfit
   ```

   Скрипт перевіряє Fast/docs на поточному SHA та завершення автоматичного Full
   gate, тоді знімає/повертає `ci:full` через REST API. Він відмовляє при незавершених
   runs або зміні head/base. Не ставити мітку відразу після push: GitHub не гарантує
   порядок доставки synchronize/labeled, тому пізня автоматична подія може скасувати
   запит. Якщо таке сталося, дочекатися Fast/docs та повторити request_full.py.
   Мітку створює maintainer один раз: `gh label create ci:full --color 1d76db`.

3. Дочекатися Full PR: PostgreSQL/browser, PHP 8.4.27 і 8.5.12, cold Compose,
   Full documentation and base та агрегатор Full PR gate мають бути success.
4. Із довіреного reviewed checkout виконати:

   ```sh
   python3 scripts/ci/pre_merge.py <PR> --repo ValtonUA/telmanfit
   ```

   Позитивний JSON `eligible: true` містить head/base SHA і посилання на runs.
   Це перевірка CI, не дозвіл merge і не зміна Draft-статусу.
5. Лише за окремою авторизацією merge: повторити перевірку безпосередньо перед
   дією й передати її `head_sha` у `gh pr merge --match-head-commit <SHA>`.
   Не використовувати auto-merge. При зміні master повторити Full після оновлення гілки.

Новий commit **не запускає важкі suites автоматично**, навіть якщо `ci:full` лишилася.
Він запускає дешевий негативний Full PR gate і скасовує попередній Full тієї самої
PR; стара зелена перевірка не годиться. На opened/reopened/synchronize та сторонню
мітку залежні Full jobs skipped, але агрегатор **failure**, тому зеленої підміни немає.
Потрібно знову явно додати `ci:full`. Concurrency ізольовано номером PR та рівнем CI;
події незалежних PR не скасовують одна одну. Скасування/помилка Full блокує gate;
найновіший run/attempt має пріоритет над попереднім success на тому самому SHA.
`workflow_dispatch` не використовується: labeled pull_request реально прив’язаний
до head PR, а reusable workflows беруть точний `inputs.head`.

## Збережене повне покриття

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
- Docs guard із PR-base і регресійні тести CI виконуються також усередині Full.

Кешуються завантаження Composer і npm, прив’язані до lock hash; vendor,
node_modules, browser profiles, PG volumes і cold Docker layers не кешуються.
Chromium-тести Stage1 запускають PHP через `tests/browser/php-server.sh`: OPcache
повторно використовує скомпільований байткод лише протягом життя одного synthetic
HTTP-сервера. Laravel bootstrap, middleware, session/CSRF, SQL та бізнес-операції
виконуються на кожний запит. Немає persistent worker/application state, file cache,
preload або JIT; timestamp validation увімкнено. Launcher відмовляє поза testing /
TELMANFIT_PG_TEST, на не-loopback адресі або без активного OPcache. Зупинка сервера
знищує кеш. Кеш байткоду не експортується між jobs/PR і не торкається cold Compose.
PG-кластери залишаються окремими для recipe, B5, baseline/auth, B3/B4 та G4;
міграції, SQL76, усі повторні PHPUnit-набори й concurrency/upgrade/reset докази збережені.
Деталі вимірювань і відхилені варіанти — [CI-COST-2](evidence/ci-cost-2/report.md).
`npm ci --ignore-scripts` замінює mutable install, Composer install не виконує
plugins/scripts, а package discovery та потрібний Stage1 `filament:upgrade` запускаються явно. Actions pinned до повних
commit SHA перевірених upstream tags. Усі workflows мають contents: read,
checkout persist-credentials: false; secrets не успадковуються, pull_request_target
не використовується. GH_TOKEN лише read token для pinned AUTH-1 public blobs.
Synthetic PG/docker створюються з чинними isolation guards і власним cleanup;
`CI_STAGE1_TIMING` додає тривалості pull, cluster/setup, SQL, PHPUnit, concurrency,
Chromium і total разом із cleanup. Recipe та baseline/auth stdout зберігаються
в `stage1-pg-logs` поряд із чинними B5/B3/B4 outputs.

## Межі merge-захисту

Read-only preflight CI-COST-2 підтвердив активний ruleset `CI-COST-1`
(id 25788875): required `Full PR gate`, `Fast PR checks`,
`Docs links, roadmap and PR impact` від GitHub Actions (integration15368), strict
up-to-date branches, PR requirement, заборона deletion/non-fast-forward.
Історична недоступність branch protection у CI-COST-1 більше не описує поточний
ruleset. Налаштування захисту не змінюються цим PR; machine checker залишається
додатковою перевіркою SHA/run-attempt/job coverage. Приватність зберігається.
Workflow-зміни потребують предметного review: PR-код не є сам собі довіреним verifier.
Навіть SHA-locked merge не дає атомарної блокади одночасної зміни base без
серверного strict protection; перед merge перевіряти base, а після конфліктів — новий Full.

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
