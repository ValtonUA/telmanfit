# AUTH-6B/B6 — атомарні PostgreSQL rate budgets

D7 затверджено прямою задачею. База GitHub `master`:
`c63d9c6a3622ba8181a6b89180aa590aeef88578`, merge PR #20. Початковий checkout
чистий на `2655876`; `fetch origin master` і нова гілка `codex/auth6b-b6` від
актуального master без reset/rebase/перезапису чужих змін.

Післямерджеві CI PR #20: [Stage 1 SUCCESS](https://github.com/ValtonUA/telmanfit/actions/runs/39833527262),
[PHP runtime security SUCCESS](https://github.com/ValtonUA/telmanfit/actions/runs/39833527238).
Це докази бази; CI остаточного B6 HEAD окремо фіксуються в описі Draft PR.

## Межа реалізації

PostgreSQL 18 — єдиний новий durable budget store. Redis не додається.
SQL API, Laravel adapter та чинні domain/HTTP entry points інтегровані у явному
`TELMANFIT_B6_TEST=1` режимі лише `testing` + guard нового isolated cluster:
private random `/tmp/telmanfit-stage1.*/socket`, PG18, непривілейований runner,
`network=none`, без published DB ports. У local/production цей marker відмовляє.
Production activation/cutover API, feature flag для живих writers і scheduler
відсутні. Legacy writers лишаються на своєму store; synthetic dual-observation
не є live dual-write. `4040404` **не вилучено**.

Перевірено `AuthBudget`, `ControlledAuth`, `ClientAuth`, `ClientSecurity`,
`MfaService`, `RecoveryService`, `AdminMfaBoundary`, client/admin HTTP controllers,
`ClientAuthBoundary`, Laravel RateLimiter, B1 session handler/middleware та
B3/B4/B5 gates. ClientSecurity/client HTTP admission проходить bridge AuthBudget;
MFA failure accounting — shared MfaService. Legacy Filament password-only path
не активується B6 і не обходить чинний B3/B5 boundary.

## Schema contract

[SQL 09](09_auth_rate_budgets.sql) byte-identical
`database/sql/auth6b6/00_budgets.sql` → [DBML](database.dbml) →
[Entity Catalog](entity-catalog.md) → нова forward міграція `006000`.
SQL01–08, історичні PHP-міграції, dependencies/config/.env незмінні.
`down()` завжди відмовляє, щоб rollback artifact не скинув активні protections.

`auth_rate_budgets`: PK `(scope,key_hash)`, whitelist 25 typed scopes,
HMAC-SHA256 із domain/scope framing і стабільним чинним `auth4.rate_key`, без
raw email/IP/token/OTP/recovery code. Resend/new flow не змінює email keys;
all-purpose scope спільний. Admin exact email semantics збережено без backfill.
HMAC key rotation вимагає окремого переходу, не є способом reset.

`events timestamptz[]`: finite, sorted, без NULL, 1-dimensional/lower-bound1,
не future відносно `last_clock_at`, cardinality≤scope cap≤1000.
`blocked_until`, finite monotonic `last_clock_at`, `gc_after`, index `auth_rate_gc`.
Trigger перевіряє window/pause horizon+1s, malformed rows відхиляються також
при consume/check. Clock rollback не prune-ить історію і дає closed failure.
Policy numbers містяться в immutable SQL function; HTTP не задає limit/window.

`auth6b6_group(text[],text[],mode)` приймає лише whitelisted hashed keys, група1–32,
без duplicate pairs. Modes `consume` для admission та `check/record` для failures
несумісні зі scopes іншого класу. `auth6b6_events` — та сама exact boundary
primitive для engine й deterministic microsecond vectors.
`SECURITY DEFINER`, fixed `pg_catalog,public`, schema-qualified relations,
PUBLIC EXECUTE/table privileges revoked. Production runtime GRANT не додається.
Synthetic ACL відділяє NOLOGIN function owner від runtime EXECUTE; runtime не
INSERT/UPDATE/DELETE/TRUNCATE і не SET ROLE owner. Catalog/reference SQL і ACL
перевіряються автоматично. Старі AUTH2/B3 projection tests лише виключають новий
additive artifact зі своєї історичної projection, не послаблюють guards.

## Матриця старих і нових лімітів

Усі нові windows — `timestamp > PG now-window`; на рівності event випадає.

| Область | Старий шлях | Новий synthetic B6 | Відмінність |
|---|---|---|---|
| OTP email+purpose | 1/60s,3/900s,10/86400s | Ті самі 3 scopes | Group append разом з all-purpose |
| OTP all purposes | 15/86400s | 15/86400s | Без reset на resend/new flow |
| Issuance IP/global | IP20/900s→100/86400s→global1000/3600s, short-circuit | Той самий порядок окремих commits | Раніший admission лишається витраченим після пізнішої відмови/domain rollback |
| OTP verify IP | 10/60s | 10/60s | Committed до перевірки challenge |
| OTP flow | Challenge attempts total5 через generations | Чинний domain barrier5 | Не дублюється новим resettable flow store |
| OTP email/user | Cache4 events; failure5 ставить pause900s (cache TTL901s) | 5 timestamps; failure5 ставить exact pause900s | Представлення4→5 events; +1s лише GC safety, не extension pause; новий typed denied audit атомарний |
| Invitation exchange | IP10/60s→token5/900s | Ті самі scopes/order | Незалежні committed admissions |
| Registration complete | IP10/60s→flow5/900s | Ті самі scopes/order | Domain rollback не повертає quotas |
| Client MFA | Audit flow5/300s,user10/900s; cache pause900(+TTL1)s; IP20/900s в HTTP/security | Typed shared flow5/300s,user10/900s,pause900s; той самий IP20/900s | Audit залишається, failure timestamps+pause атомарні; denied не подовжує pause |
| Admin MFA | Configurable user audit `mfa.attempts/flow_seconds`, HTTP fixed-window RateLimiter | Додано shared flow5/300s,user10/900s,pause900s і HTTP IP20/900s | Чинні configurable audit cap/flow TTL і HTTP throttle збережено як додаткові barriers; раніше parity не було |
| Admin password | Exact email lookup; configurable HTTP IP throttle, без окремого email5/900s | IP20/900s→exact email5/900s, pause900s | Додано погоджені D7 budgets; legacy throttle збережено. Argon/bcrypt після commit; cap→429 |
| Invitation step-up | IP20/900s→user5/900s | Ті самі scopes, плюс shared password verification policy | Domain 403 збережено; infrastructure budget fault→503 |
| Assisted exchange | IP10/60s та case5/900s; цикл рахує всі scopes навіть за попередньої відмови | Ті самі межі й accounting order | Не підмінено client short-circuit |
| Assisted primary | P3 email/IP/global, email take по черзі | P3, email group all-or-none | Усунено часткове списання email scopes при suppression/error |
| Assisted TOTP | Failures flow5/900s,user10/900s,IP20/900s | Ті самі3 scopes, atomic group | Не додано нових pauses/D3 authority; enrollment/case TTL чинний |

[Dual observation](evidence/auth6b-b6/dual-observation.json): 19 чинних cache
policies на однакових synthetic histories L−1→L→denied; global1000 перевірено
окремо під concurrency. Відмінності representation/pause TTL, durability,
recovery partial charging та відсутніх admin budgets наведено в таблиці,
а не оголошено безумовною старою parity. Exact boundaries усіх25 policies,
mixed purposes, unknown/decoy, resend/new flow та pauses мають окремі тести.

## Admission, failures та lock order

INSERT missing rows `ON CONFLICT DO NOTHING` і row locks — у C-порядку
`(scope,key_hash)`. PostgreSQL wall clock береться після останнього lock.
Prune/check усіх keys перед append; denied не додає понад cap.
Email group all-or-none також при SQL/mode fault. Threshold pause починається
на останній дозволеній failure; наступний denied не змінює `blocked_until`.
Decision typed, Retry-After bounded1..86400; чинний client HTTP contract900
збережено, admin новий budget denial використовує sanitized decision.

Admission factory створює окремий непостійний PG connection, перевіряє відмінний
PDO/backend PID та відсутність transaction, закриває connection у finally.
Не змінює/purge-ить B1 session connection. Persistent/read-write split connections
відхиляються. READ COMMITTED, `synchronous_commit=on`, lock timeout2s,
statement timeout3s. Admission не отримує user/flow/factor locks і комітиться
незалежно від зовнішньої Laravel transaction; rollback domain не повертає quota.
Timeout/deadlock/outage/неоднозначний commit → `BudgetUnavailable` без previous
exception, без автоматичного retry/domain operation/local fallback.

Domain lock order: B1 session → чинний global/registry → user → flow →
challenge/factor → failure budgets. OTP failure: attempts + budget/pause + typed
audit в одній default transaction. MFA: factor/flow/code state + failure group +
audit в одній transaction; denial як value, exception лише після transaction.
Recovery denied event має той самий commit. Argon precheck/batch/password hashing
поза budget locks; під factor lock повторно звіряються hash/batch/unused/version.
Pause-only issuance inspection використовує короткий окремий connection; чинний
4040404 зберігає ordering з OTP failure writers до окремого lock-removal review.

## Restore та cleanup

B5 `RestoreFence::assertOn` дозволяє перевірити незалежний external epoch на
admission connection до/перед commit і перед поверненням. Missing/mismatch/closed
state/fence закриває операцію, не очищує timestamps/attempts/pauses. Production
provider не bind-иться. B5 reconciliation не змінює нові budgets, стан
`recovery_required` не відкривається B6. Restored counters не є доказом вільного
budget; issuance≥24h без verified checkpoint лишається обов'язковим.

Cleanup≤500, sorted row locks `FOR UPDATE SKIP LOCKED`, fresh clock і повторний
`gc_after` check; тільки після longest window/pause+1s. Consume проти cleanup
не отримує free attempt. Cleanup не seed/reset і не background activation.

## Перевірки й виміри

Лише isolated synthetic PostgreSQL18.6 pinned digest, PHP8.4.27 у disposable
runtime, жодних наявних БД. B6 додано обов'язковим Stage1 кроком і до обох
PHP runtime matrix8.4.27/8.5.12, без continue-on-error.

- B6 PHPUnit: **44 tests / 1689 assertions**; усі25 scopes, HMAC/invalid policy,
  malformed/future rows, clock rollback, catalog/ACL, group fault, pauses,
  committed admission проти outer rollback, real independent commit + lost result,
  unavailable socket, real statement timeout, failure audit rollback, MFA/OTP parity.
- Independent processes: existing/absent/group commit+rollback, observed lock waits;
  OTP/TOTP/recovery-code — один consume й один denial; concurrent failure5 —
  рівно5 attempts/budget/audit, без6-ї failure. SIGKILL до/після commit, timeout,
  реальний PostgreSQL40P01 від synthetic порушника lock order, cleanup vs consume.
- Два HTTP servers: known/decoy202, CSRF419, sanitized503 store fault, IP20/21→429,
  Retry-After і log canaries. Чинні B1 25 HTTP/session races також PASS.
- Локальні legacy suites: SQL76/76; Stage1/2 41/713; основний PHPUnit38/62;
  AUTH2 12/131, AUTH3 25/129, AUTH4 24/152, AUTH5 19/131, AUTH6 5/359;
  B3 22/213, B4 16/128, B5 41/392; process/restore/hold drills PASS.
- Pint, client lint/build та root build PASS. Browser/AUTH1/full PHP8.5.12
  acceptance — обов'язковий GitHub CI фінального HEAD, не підміна локальними фактами.

[Benchmark](evidence/auth6b-b6/benchmark.json), [resources](evidence/auth6b-b6/resources.json),
[concurrency](evidence/auth6b-b6/concurrency.json), [restart](evidence/auth6b-b6/restart.json).
4 independent PHP workers ×300 requests до одного global key: рівно1000 allowed,
200 denied,0 infrastructure503,0 incidental deadlocks. Benchmark відокремлений
від intentional deadlock/timeout faults. p95/p99 latency, lock acquisition,
CPU/RSS/connections, WAL/IO/size записані у JSON; числа є одним synthetic run,
а не SLA. Lock acquisition містить INSERT/lock overhead і wait, не чистий wait;
PG activity додатково sampling10ms. WAL stats cumulative можуть мати затримку;
IO totals — cluster totals, не per-query. RSS — max окремого PHP worker, Docker
memory — cgroup sample цілого PG container; це різні показники.

| Synthetic показник | Зафіксований результат |
|---|---|
| Throughput4 workers /1200 requests | 132.31 requests/s;1000 allowed,200 denied |
| Latency p95 /p99 | 48.33 /56.92 ms |
| Lock acquisition p95 /p99 | 22.27 /30.72 ms, включно INSERT overhead |
| PG connections / sampled lock waits | max9;507 waiter samples у641 polls |
| PHP worker RSS / сумарний user CPU | max132.15 MiB /2.00s |
| PostgreSQL CPU / cgroup memory | max sampled187.34% /142.40 MiB за весь B6 run |
| WAL delta / budgets relation із indexes/TOAST/bloat | 3845493 bytes /2539520 bytes |
| Cluster IO reads / writes /extends | 973 /4767 /1285 operations |
| Incidental deadlocks / benchmark timeouts /503 | 0 /0 /0 |

Реальний postmaster restart із тим самим synthetic data directory зберігає
committed sentinel і відмовляє наступному запиту; це не power-loss/PITR drill.
Перші прогони виявили synthetic fixture method-name collision, active-flow
uniqueness fixture, історичний B3 LIKE wildcard та ANSI escape Docker stats;
виправлено. Перший CI HEAD також підтвердив PASS усіх B6 tests/races/HTTP/restart,
але завис на очікуванні завершення Docker stats після SIGTERM; загальний run
скасовано. Read-only monitor тепер завершується SIGKILL із bounded step timeout.
Benchmark додатково drain-ить stdout під час роботи й перевіряє payload>pipe buffer,
має120s deadline; це окреме hardening, не підтверджена причина CI зависання.
Проміжні/скасовані runs не зараховано як успішний загальний acceptance.

## Cutover, ризики та відкриті рішення

Дозволені майбутні стратегії **лише після окремого review/drain усіх writers**:
точне перенесення всіх активних windows/pauses із незмінним HMAC key contract;
або повністю закрите issuance щонайменше24h і повні залишкові pauses. Порожній
новий store не можна активувати поряд із реальними старими writers. Перехід
також має врахувати audit-derived MFA і configurable/fixed-window admin guards;
самого копіювання cache arrays недостатньо. У B6 перенесення/cutover не реалізовано.

`4040404` лишається до окремого доказу concurrency всіх writers, canonical-email
uniqueness, registration reservation та інвентаризації решти global invariants.
Global1000/hour — одна hot row, її bottleneck не оголошується усуненим.
Arrays/no-op validation updates мають WAL/write amplification; потрібні D8
autovacuum/bloat/I/O/connection measurements. Новий connection на admission також
має вартість. Connection pooling/production VPS2CPU/3GB не сертифіковані.

D8 peak/2×peak30m, два hosts, VPS/SMTP/offsite/WAL/PITR/custody/monitoring та реальні
external epoch/checkpoint providers не погоджені й не замінюються цим benchmark.
Немає твердження, що Redis не знадобиться за будь-якого майбутнього масштабу.
D3/D8/P5/P6/B7 залишаються відкритими. Production path закритий; жодного reopening
API, SMTP/recovery activation, deployment, merge або auto-merge.

Реальні БД, production, користувацькі дані, .env/config/secrets не змінювалися.
Усі нові rows/keys/processes/epochs/dumps — disposable synthetic fixtures.

## Повний перелік змінених / створених файлів

```text
.github/workflows/php-runtime.yml
.github/workflows/stage1.yml
app/Http/Controllers/Auth/AdminMfaController.php
app/Http/Middleware/AdminMfaBoundary.php
app/Services/Auth/Budgets/BudgetDecision.php
app/Services/Auth/Budgets/BudgetKey.php
app/Services/Auth/Budgets/BudgetLimited.php
app/Services/Auth/Budgets/BudgetScope.php
app/Services/Auth/Budgets/BudgetUnavailable.php
app/Services/Auth/Budgets/LegacyBudgetMap.php
app/Services/Auth/Budgets/PostgresBudgets.php
app/Services/Auth/Budgets/SyntheticBudgets.php
app/Services/Auth/Client/AuthBudget.php
app/Services/Auth/Client/ClientAuth.php
app/Services/Auth/MfaService.php
app/Services/Auth/Recovery/RecoveryService.php
app/Services/Auth/Restore/RestoreFence.php
database/migrations/2026_10_22_006000_create_auth_rate_budgets.php
database/sql/auth6b6/00_budgets.sql
database/sql/auth6b6/01_test_acl.sql
docs/09_auth_rate_budgets.sql
docs/architecture.md
docs/auth6-b6.md
docs/database.dbml
docs/entity-catalog.md
docs/evidence/auth6b-b6/benchmark.json
docs/evidence/auth6b-b6/checks.json
docs/evidence/auth6b-b6/concurrency.json
docs/evidence/auth6b-b6/dual-observation.json
docs/evidence/auth6b-b6/resources.json
docs/evidence/auth6b-b6/restart.json
docs/project-status.md
docs/roadmap.md
phpunit.auth6b6.xml
tests/Auth6B/MigrationTest.php
tests/Auth6B6/BudgetsTest.php
tests/Auth6B6/FaultTest.php
tests/Auth6B6/IntegrationTest.php
tests/Auth6B6/SchemaTest.php
tests/Auth6B6/policies.json
tests/AuthPostgres/AuthSchemaTest.php
tests/http/auth6b6-fixture.php
tests/http/auth6b6-in-context.sh
tests/http/auth6b6-router.php
tests/http/auth6b6.py
tests/pg/auth2-upgrade.php
tests/pg/auth6b6-concurrency.php
tests/pg/auth6b6-resources.py
tests/pg/auth6b6-restart.php
tests/pg/run-stage1.sh
```
