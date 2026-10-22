# AUTH-6B/B5 — retention, archive та safe restore

22.10.2026. D5 і D6-A затверджені прямою задачею. Точна база `master`:
`265587696be0fc3adc358a7f32032603c8e1904e` (merge PR #19).
Чистий checkout `4c5c755…` безпечно синхронізовано створенням окремої гілки
`codex/auth6b-b5` від актуального origin/master; історію не переписано.
Післямерджевий master CI: [Stage 1](https://github.com/ValtonUA/telmanfit/actions/runs/39806897993)
та [PHP runtime](https://github.com/ValtonUA/telmanfit/actions/runs/39806897983) — success.

## Виправлення незалежного review P2-1/P2-2

Продовження наявного Draft PR #20 від `b879668a46cf7bdb147fbf0a4235656eeb103957`.
Історичні PHP-міграції не змінено; нова `005000` застосовує forward fix із
`database/sql/auth6b5/02_hold_scrub.sql`. CREATE OR REPLACE зберігає OID,
owner та ACL наявних функцій; migration identity потребує права змінювати їх,
нових grants чи production provisioning немає. SQL08 і виконуваний SQL00
синхронізовано. Нових таблиць/полів немає.

- **P2-1:** GC після B1 session lock → shared hold-policy lock → user lock
  повторно перевіряє TTL. За active hold перед DELETE записує append-only
  `retention.session_scrubbed`: timestamp останньої активності, subject user,
  SHA256 fingerprint ID, typed audience reason та випадковий request UUID.
  Cookie/ID/payload/IP/UA не копіюються. Audit INSERT і session DELETE/assurance
  CASCADE — одна transaction/ID. Audit/registry/delete failure відкочує item;
  за outage payload та докази лишаються до безпечного повтору, GC не відкриває auth.
  Admin15m/client12h/absolute TTL не змінено. Save перед GC подовжує тільки
  штатний last_activity; GC перед follower не дозволяє старому HTTP save
  воскресити ID або віддати Set-Cookie. Для guest sanitized event із невідомим
  subject будь-який active hold консервативно блокує audit purge.
- **P2-2:** hold exception дозволяє тільки confirmed-factor expired pending
  scrub; user → factor locks і повторний cutoff/confirmation recheck збережено.
  Очищуються pending ciphertext/key/expiry, оновлюється службовий updated_at.
  Confirmed ciphertext/key/confirmed_at, disabled state, current recovery batch
  і last_used_step не змінюються. Pending-only під hold не DELETE. Preview
  показує доступний confirmed scrub; інші hold-protected cleanup paths не послаблено.

Нові `HoldScrubTest`, upgrade fixture у `MigrationTest` і незалежний процесний
`auth6b5-hold-concurrency.php` перевіряють user/case/time holds, admin/client/guest,
TTL, sanitized metadata та її hold-protected archive purge, audit/delete rollback,
registry outage, confirmed/pending-only factors, idempotency, migration OID/ACL,
реальні policy/session lock waits і B1 HTTP follower після GC, factor confirmation race.
Наявні archive-unavailable/digest/ACL/history/recovery/restore tests лишаються обов'язковими.

Локальний fix-прогін у Windows/Herd PHP 8.5.12: `php artisan test` —
**38 tests / 62 assertions PASS** після збірки assets; Pint, client ESLint/build
і root Filament build — PASS. Перша спроба PHPUnit до збірки assets виявила
відсутній Vite manifest; після build повтор успішний. PostgreSQL/HTTP/browser/
SIGTERM/restore проганяє чинний guarded Linux GitHub Actions harness із новими
private-socket containers; Windows guard не послаблювався і наявні локальні БД
не використовувалися. Legacy AUTH-2/5 upgrade/down projection явно виключає
B5 forward migration; populated B5 upgrade перевіряється окремо.

Точні SHA/run URLs і фінальні CI-результати фіксуються в описі PR після CI.
Наведені нижче 23/208 і старі timings стосуються попереднього HEAD, а не цього fix.
PR залишається Draft для повторного незалежного review; merge/auto-merge,
production/deployment/реальні БД не входять до задачі.

### Файли P2 fix відносно b879668

```text
app/Support/Session/ContextDatabaseSessionHandler.php
database/migrations/2026_10_22_005000_fix_auth_retention_hold_scrub.php
database/sql/auth6b5/00_retention.sql
database/sql/auth6b5/02_hold_scrub.sql
docs/08_auth_retention_restore.sql
docs/auth6-b5.md
docs/database.dbml
docs/entity-catalog.md
tests/Auth6B5/HoldScrubTest.php
tests/Auth6B5/MigrationTest.php
tests/Auth6B5/pre_hold_scrub.sql
tests/AuthPostgres/AuthSchemaTest.php
tests/pg/auth2-upgrade.php
tests/pg/auth6b5-hold-concurrency.php
tests/pg/run-stage1.sh
```

## Реалізовано

Authoritative [SQL 08](08_auth_retention_restore.sql) byte-identical
`database/sql/auth6b5/00_retention.sql` → [DBML](database.dbml) →
[Entity Catalog](entity-catalog.md) → нова міграція 004000.
SQL 01–07 та історичні міграції незмінні. Нові typed таблиці:
`auth_retention_holds/runs/batches`, `auth_archive_manifests`, `auth_security_runtime`.
Нова міграція не seed-ить epoch і не створює production ролей. Порожній singleton
закриває auth. Down завжди відмовляє: потрібен gate-aware forward fix.

`RetentionService` надає read-only dry-run без створення run/receipt, start/finish,
whitelisted cleanup і exact-manifest audit apply. Немає SQL predicate від caller,
довільного table name або адміністративного SQL purge. Fixed run cutoff, bounded
500 candidates, policy lock, recheck під user lock, child-first/FK RESTRICT defer.
UNIQUE run/batch та immutable selection digest дають retry після commit; навіть
нульовий batch має receipt. Помилка batch відкочує DELETE/receipt/audit разом;
adapter записує sanitized failed окремою транзакцією після rollback; оператор також має явне finish. SQL і adapter мають
bounded lock/statement timeouts. Run transitions та batches додають typed audit.

| Дані | Фактична поведінка B5 |
|---|---|
| Challenges / flows | ≥24h після max(expiry,terminal), challenge тільки коли parent теж старий; children перед parent; будь-який recovery-grant FK залишає row online |
| Pending TOTP | Expiry recheck під user lock; clear pending у confirmed history; pending-only delete лише без watermark/codes; confirmed factor і watermark зберігаються |
| Sessions / assurance | B1 idle admin15m/client12h, absolute assurance expiry; максимум500 IDs; одна transaction/ID, B1 session advisory lock → hold-policy → user → TTL recheck; за hold sanitized metadata → payload DELETE/assurance CASCADE; busy session skip; GC outage rollback/savepoint і skip без зміни auth failure contract |
| Invitations | Активні не expire; terminal ≥365d і без child flow; holds пов'язаних users захищають row |
| Recovery codes | Used/revoked ≥365d; current factor batch не purge; hashes не змінюються |
| Recovery cases/events/approvals/grants, principals/keys/gates | **Залишаються online.** Фізичний purge не реалізований без окремого verified archive contract; чинні append-only/RESTRICT guards не послаблено |
| Audit | ≥180d online; тільки exact verified archive batch, retain_until ≥last_event+365d; за hold/недоказаного archive не видаляється |
| Outbox | Лише terminal ≥30d із уже scrubbed ciphertext. B4 terminal scrub чинний незалежно від hold; cleanup ніколи не відновлює адресу |
| Failed jobs | Bounded cleanup payload старших24h; не deserialize. За hold payload/exception/queue/connection scrub, мінімальні metadata лишаються; без hold row delete, звичайні metadata не зберігаються довше7d |
| Rate protection | Cache budgets/attempts/pauses не очищуються й не мігрують; B6 не реалізовано |
| Backups | 30d — погоджена policy; реального lifecycle/offsite/PITR немає до D8 |

Немає scheduler/cron або production activation. Holds залишають мінімальні
metadata online, але не подовжують secret payload TTL. Operational гарантія
виконання cleanup за outage/межу24h потребує D8 scheduling/monitoring; код
не видає наявність bounded pass за production retention SLA.

## Holds, archive та ACL

Holds мають рівно один user/case/half-open time selector, whitelist reason,
FK principal, paired release іншим principal. History не DELETE/TRUNCATE;
після release selector/creator незмінні. Exclusive policy advisory xact lock на
create/release проти shared physical batches визначає commit ordering. Apply/GC відмовляють при ізоляції, відмінній від
READ COMMITTED: старий REPEATABLE READ snapshot не може пропустити новий hold
після очікування policy lock. Dry-run залишається read-only. User/case
scope захищає пов'язані audit records; ephemeral flows із grant refs defer цілком.
Недоступна hold table/SELECT privilege відкочує purge, немає fallback на empty.

`ArchiveVerification` — незалежний port перевірки доступного object/receipt.
Default provider відсутній; `SyntheticArchive` існує лише в tests і порівнює
SHA256 окремих in-memory object bytes із immutable manifest. Production не може отримати
«verified» через flag. SQL receipt записує окрема archival identity; retention
executor не INSERT/UPDATE manifest. SQL повторно звіряє full-row ordered jsonb
SHA256, count, exact IDs, time/ID bounds, age, freshness≤24h і retain_until. Немає
real object store, key custody або production archival verifier до D8.

Новий audit guard дозволяє DELETE тільки `current_user=telmanfit_retention_owner`;
UPDATE/TRUNCATE завжди заборонені. Owner — synthetic NOLOGIN без membership
runtime/executor. Executor має тільки whitelisted EXECUTE та run INSERT/read;
не DELETE/UPDATE/TRUNCATE/DDL/SET ROLE owner. Додано аналогічний вузький DELETE
виняток B4 outbox guard, решта B4 transitions незмінні. Recovery append-only guards
залишаються deny-all. ACL provisioning існує **тільки** у новому isolated harness
`01_test_acl.sql`, який виконує synthetic cluster superuser. Міграція сама не
надає runtime жодних прав. Definer entrypoints додатково обмежені synthetic DB
і session identity; production API закритий навіть за помилкового GRANT. Threat boundary не включає compromised DB superuser.

## D6-A і межа D3/D8

`RestoreFence` порівнює DB singleton із незалежним `ExternalEpoch::current()`.
Відсутній adapter, outage, malformed/mismatched epoch, closed state або DB outage
закривають захищені операції. Немає `.env`/DB fallback. Інтегровано controlled
client auth/security/session, shared MFA/admin assurance, legacy admin/Livewire
boundary, recovery через ControlledAuth, notices writer/dispatcher, OTP workers,
retention operator services та GC. AuthBudget додатково перевіряє issuance deadline.
Production adapter не bind-иться; testing adapter автоматично доступний лише за
`APP_ENV=testing` і explicit isolated runner marker. Bootstrap singleton виконує
окремий guarded test script. Local integration потребує явного незалежного adapter;
production activation flags лишаються OFF.

`SyntheticRestore` виконується лише testing + guard нової private-socket PG18 +
maintenance. Test marker ізольований у приватному каталозі cluster, без
`artisan down/up` та без впливу на workspace/runtime maintenance files. Спершу durable `maintenance`, потім перевірка synthetic evidence
ports та **окрема** rotation зовнішнього epoch в orchestrator. Чинні hold rows і
principal/key revocations беруться з manifests поза DB snapshot; missing reference
або суперечність hold лишають closed. Потім атомарно: усі users version++, flows/
challenges invalidate, sessions/assurance delete після secret-free metadata audit
під чинними holds, unused codes/grants revoke,
незавершені cases cancel, pending clear, confirmed factors disable із secret scrub
і незменшеним restored watermark. Старі jobs видаляються без replay; failed payloads scrubbed зі збереженням
мінімальних metadata під holds;
старі pending/leased outbox terminal dead із B4 scrub/audit. Budgets не змінюються.

Epoch перевіряється повторно до commit. DB epoch записується лише разом із
reconciliation audit; issuance_not_before ≥reconciled+24h. Повтор під runtime lock
не bump-ить versions повторно, не скорочує deadline й не відкриває доступ.
Стан після успіху — **`recovery_required`**, не ready. Немає API його release.

B3 per-user gate має FK на реальний case/identity; без D3 не створюємо фіктивних
cases/trustees для всіх users. Натомість глобальний closed fence закриває також
restricted recovery та business access до окремого operational protocol. Це
перевірюване **безпечне ядро**, а не завершений production restore/recovery.
Evidence booleans у synthetic protocol — fault ports, не перевірка реальних
backup/WAL/keys. Реальний provider, signed restore evidence, dual-control custody,
WAL/PITR, current registry authority та reopening потребують D3/D8/review.
Per-user external revocation/watermark ledger не додавався.

## Перевірки та відтворення

Лише нові pinned PostgreSQL18.6 containers, `network=none`, без ports, приватні
random `/tmp/telmanfit-stage1.*/socket`, непривілейований test runner. Trap видаляє
лише власний container/socket/synthetic dump. Новий B5 крок у GitHub Actions —
обов'язковий, без continue-on-error. Наявні AUTH-1–6/B3/B4 steps збережені.

```bash
AUTH6B5_ONLY=1 bash tests/pg/run-stage1.sh
AUTH6B_ONLY=1 bash tests/pg/run-stage1.sh
AUTH3_BROWSER=1 AUTH4_BROWSER=1 AUTH5_BROWSER=1 bash tests/pg/run-stage1.sh
bash tests/pg/test-runner.sh
bash tests/pg/run-isolated-pg.sh
bash experiments/auth1/run.sh
vendor/bin/pint --test
npm run lint --prefix client
npm run build --prefix client
NODE_USE_ENV_PROXY=1 npm run build
```

B5 behavior suite перевіряє dry-run, eligibility, current batches/watermarks,
FK child-first, exact/stale/missing/unavailable archives, independent object
mismatch, 500 bounds, durable holds/release, missing registry privileges, deny
runtime/PUBLIC/owner membership, run immutability, epoch faults, 24h fence,
closed retry та capabilities invalidation. Незалежні процеси перевіряють hold
commit/rollback із observed lock waits, SIGTERM до/після purge commit, session
save/GC та SIGTERM на восьми етапах restore з idempotent retry.

Окремий **реальний synthetic** `pg_dump/pg_restore --clean` drill відновлює snapshot
до використання OTP/code/grant і зміни factor/auth_version. Зовнішній adapter і поточні
hold/revocation manifests лишаються поза dump. Drill доводить повернення старих DB
bytes, epoch denial до reconciliation, повторне відкликання, secret quarantine,
відсутність старих sessions/outbox recipients і reapply post-backup hold/revocation.
Це не WAL/PITR drill. Результати й timings: [restore.json](evidence/auth6b-b5/restore.json).

Інфраструктурний restore та closed reconciliation вимірюються окремо. Restricted
security service і повний business access **не відновлено**: їхні timings null,
доступ лишається закритим. Snapshot age — synthetic observed data-loss interval,
не досягнутий production RPO. RPO≤15m — майбутня acceptance ціль; RTO≤4h для всіх
users не гарантується. Немає production SLA за результатом цього стенду.

Локально на фінальному коді: [checks.json](evidence/auth6b-b5/checks.json).

| Перевірка | Фактичний результат |
|---|---|
| SQL migrations / reference | 76 PASS / 0 FAIL / 0 GAP, кожний; negative runner exit contracts PASS |
| Stage1/2 / основний PHPUnit | 41/713 + 38/62 tests/assertions |
| AUTH-1 / AUTH-2 / AUTH-3 | 15/93 + 12/131 + 25/129 |
| AUTH-4 / AUTH-5 / AUTH-6 | 24/152 + 19/131 + 5/359 |
| B3 / B4 / B5 | 22/213 + 16/128 + **23/208** |
| Process / HTTP / browser | AUTH2–5/B3/B4/B5 concurrency, 25 B1 HTTP scenarios, Chromium AUTH3/4/5 PASS |
| Pint / frontend | Pint, client ESLint/build, root Filament build PASS |

Початкові невдалі прогони виявили незалежне clock_timestamp evaluation у factor
quarantine, старі AUTH-2 audit-source/B3 downgrade fixtures та різний JSON після
cache outage в aborted transaction. CI додатково виявив lottery GC exception поза auth
boundary за assurance outage; детерміновано відтворено з GC100 (HTTP500),
виправлено transactional skip/savepoint (HTTP503). AUTH3 browser CI тепер завжди
виконується з GC100 через окремий guarded synthetic router. Виправлено; фінальні повні Stage1 та окремі
B3/B4/B5/HTTP повтори успішні. B3 historical downgrade fixture відтворює pre-B5
projection тільки видаленням порожньої нової holds table всередині transaction;
rollback повертає її FK. Production downgrade не дозволяється.

Synthetic snapshot drill: observed snapshot age **1.379s**, infrastructure restore
**0.774s**, closed reconciliation **0.036s**. Restricted service/business access
не відновлювалися; це не production SLA і не WAL/PITR acceptance.

[Draft PR #20](https://github.com/ValtonUA/telmanfit/pull/20). GitHub checks для
остаточного HEAD та точні run URLs фіксуються в описі PR після завершення.
Base master CI та локальні результати не підміняють CI поточного HEAD.

## Відкриті блокери

D3 — реальні незалежні trustees/verifier/intake/recovery/enrollment та процедура
відкриття доступу. D7/B6 — budgets cutover/load, без reset чинних counters.
D8 — hosting/privileges/cron/offsite/PITR/key custody/external epoch і archive
providers та operational drills. P5/P6/B7 — independent review і окремий go/no-go.
Recovery history purge, verified archive lifecycle та production failed-payload SLA
не оголошуються виконаними. Production recovery/delivery OFF.

Реальні БД, персональні дані, runtime/SMTP/hosting/cron, `.env`, production secrets,
ключі й trustee records не змінювалися. Усі дампи/receipts/identities синтетичні й
ізольовані. Один Draft PR; без merge, auto-merge або переписування історії.



## Повний перелік змінених / створених файлів

```text
.github/workflows/stage1.yml
app/Http/Middleware/AdminMfaBoundary.php
app/Models/User.php
app/Providers/AppServiceProvider.php
app/Services/Auth/Client/AuthBudget.php
app/Services/Auth/Client/ControlledAuth.php
app/Services/Auth/MfaPolicy.php
app/Services/Auth/Notices/NoticeDispatcher.php
app/Services/Auth/Notices/NoticeWriter.php
app/Services/Auth/Recovery/RecoveryService.php
app/Services/Auth/Restore/ExternalEpoch.php
app/Services/Auth/Restore/RestoreFence.php
app/Services/Auth/Restore/SyntheticEpoch.php
app/Services/Auth/Restore/SyntheticMaintenanceMode.php
app/Services/Auth/Restore/SyntheticRestore.php
app/Services/Auth/Retention/ArchiveVerification.php
app/Services/Auth/Retention/RetentionService.php
app/Support/Session/ContextDatabaseSessionHandler.php
database/migrations/2026_10_22_004000_create_auth_retention_restore.php
database/sql/auth6b5/00_retention.sql
database/sql/auth6b5/01_test_acl.sql
docs/08_auth_retention_restore.sql
docs/architecture.md
docs/auth6-b5.md
docs/database.dbml
docs/entity-catalog.md
docs/evidence/auth6b-b5/checks.json
docs/evidence/auth6b-b5/restore.json
docs/project-status.md
docs/roadmap.md
phpunit.auth6b5.xml
tests/Auth6B/FixtureBoundary.php
tests/Auth6B/MigrationTest.php
tests/Auth6B4/SchemaTest.php
tests/Auth6B5/MigrationTest.php
tests/Auth6B5/OperatorTest.php
tests/Auth6B5/RestoreTest.php
tests/Auth6B5/RetentionTest.php
tests/Auth6B5/SyntheticArchive.php
tests/AuthPostgres/AuthSchemaTest.php
tests/Postgres/PostgresTestCase.php
tests/browser/auth3-in-context.sh
tests/browser/auth3-router.php
tests/pg/auth2-upgrade.php
tests/pg/auth6b-concurrency.php
tests/pg/auth6b5-bootstrap.php
tests/pg/auth6b5-concurrency.php
tests/pg/auth6b5-grant-fixture.php
tests/pg/auth6b5-restore-drill.php
tests/pg/run-stage1.sh
```
