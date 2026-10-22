# AUTH-6B/B5 — retention, archive та safe restore

22.10.2026. D5 і D6-A затверджені прямою задачею. Точна база `master`:
`265587696be0fc3adc358a7f32032603c8e1904e` (merge PR #19).
Чистий checkout `4c5c755…` безпечно синхронізовано створенням окремої гілки
`codex/auth6b-b5` від актуального origin/master; історію не переписано.
Післямерджевий master CI: [Stage 1](https://github.com/ValtonUA/telmanfit/actions/runs/39806897993)
та [PHP runtime](https://github.com/ValtonUA/telmanfit/actions/runs/39806897983) — success.

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
оператор може завершити run як failed окремою транзакцією. SQL і adapter мають
bounded lock/statement timeouts. Run transitions та batches додають typed audit.

| Дані | Фактична поведінка B5 |
|---|---|
| Challenges / flows | ≥24h після max(expiry,terminal), challenge тільки коли parent теж старий; children перед parent; будь-який recovery-grant FK залишає row online |
| Pending TOTP | Expiry recheck під user lock; clear pending у confirmed history; pending-only delete лише без watermark/codes; confirmed factor і watermark зберігаються |
| Sessions / assurance | B1 idle admin15m/client12h, absolute assurance expiry; максимум500 IDs; одна transaction/ID, B1 session advisory lock → hold-policy → user → TTL recheck; busy session skip, assurance CASCADE |
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
create/release проти shared physical batches визначає commit ordering. User/case
scope захищає пов'язані audit records; ephemeral flows із grant refs defer цілком.
Недоступна hold table/SELECT privilege відкочує purge, немає fallback на empty.

`ArchiveVerification` — незалежний port перевірки доступного object/receipt.
Default provider відсутній; `SyntheticArchive` існує лише в tests і порівнює
окремий in-memory object digest із immutable manifest. Production не може отримати
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
надає runtime жодних прав. Threat boundary не включає compromised DB superuser.

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
maintenance. Спершу durable `maintenance`, потім перевірка synthetic evidence
ports та **окрема** rotation зовнішнього epoch в orchestrator. Чинні hold rows і
principal/key revocations беруться з manifests поза DB snapshot; missing reference
або суперечність hold лишають closed. Потім атомарно: усі users version++, flows/
challenges invalidate, sessions/assurance delete, unused codes/grants revoke,
незавершені cases cancel, pending clear, confirmed factors disable із secret scrub
і незменшеним restored watermark. Старі jobs/failed jobs видаляються без replay;
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
до використання OTP/code і зміни factor/auth_version. Зовнішній adapter і поточні
hold/revocation manifests лишаються поза dump. Drill доводить повернення старих DB
bytes, epoch denial до reconciliation, повторне відкликання, secret quarantine,
відсутність старих sessions/outbox recipients і reapply post-backup hold/revocation.
Це не WAL/PITR drill. Результати й timings: [restore.json](evidence/auth6b-b5/restore.json).

Інфраструктурний restore та closed reconciliation вимірюються окремо. Restricted
security service і повний business access **не відновлено**: їхні timings null,
доступ лишається закритим. Snapshot age — synthetic observed data-loss interval,
не досягнутий production RPO. RPO≤15m — майбутня acceptance ціль; RTO≤4h для всіх
users не гарантується. Немає production SLA за результатом цього стенду.

Фінальні результати локального прогону та CI для точного PR HEAD додаються після
завершення перевірок; попередні master/B3/B4 результати не підміняють їх.

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
