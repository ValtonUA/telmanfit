# AUTH-6B/B4 — transactional security notice outbox

22.10.2026. D4 затверджено прямою задачею користувача. База `master`:
`4c5c755a1242051429db6381f7edaad88b0826c7` після PR #17/#18.
Чистий checkout безпечно fast-forward синхронізовано через нову гілку
`codex/auth6b-b4`; чужі зміни не перезаписувались. Один Draft PR, без merge.

## Реалізація й атомарність

Authoritative [SQL 07](07_auth_security_notice_outbox.sql) byte-identical
`database/sql/auth6b4/00_outbox.sql`; нова міграція 003000, DBML і Entity Catalog.
SQL 01–06 та історичні міграції незмінні. CHECK/FK/partial indexes, immutable
identity/recipient, terminal scrub, attempt monotonicity, lease/fencing guards.
Порожній down під ACCESS EXCLUSIVE lock дозволений; populated down відмовляє.

`NoticeWriter::write` вимагає активної default PG transaction і local/testing.
Server UUID генерує writer або чинний domain service; UUID із HTTP не приймається.
Request advisory lock серіалізує однакові UUID, включно з missing rows.
Idempotency перевіряє subject/type/contract/case/повний набір slots і decrypted
snapshot; mismatch — sanitized exception, без `ON CONFLICT DO NOTHING`.
Replay після terminal scrub відхиляється: без PII неможливо довести identity
одержувача, старий intent не відкривається повторно.

Audit `notice.queued/claimed/sent/failed/expired/dead` додає DB trigger у тій самій
transaction. INSERT/audit fault відкочує mutation; ack audit fault відкочує ack,
зберігаючи durable leased row. Немає callback/queue як durability boundary.
Typed audit містить request/subject/case/reason, без body/provider exception/PII.

| Security mutation | Notice v1 і recipient |
|---|---|
| Client email change | `email_changed`, immutable `old` snapshot до UPDATE |
| MFA activation/recovery-code rotation/optional disable | `mfa_changed`, current |
| Client session revoke/logout-all, MFA logout-all | `credentials_revoked`, current |
| Recovery create/approval/quarantine/complete/close | started/approved/revoked/completed/cancelled, current |
| Recovery primary replacement completion | completed: окремі `old` і `current` rows |

B3 `RecoveryBoundary::notice` лишається D3 authority/fixture fault port; після нього
`RecoveryNoticeWriter::notice` **обов'язково** виконує реальний transactional B4
writer, тому no-op boundary не пропускає outbox. Немає default D3 binding або
нових mutation grants. Відсутня email-адреса/незалежний D3 destination не замінюється
вигаданим каналом: операція fail-closed. Exchanged/OTP/denied events не створюють
нових листів із секретами. Жодних змін MFA/recovery/session authorization rules.

Старий post-commit dispatch з `ClientSecurity::confirmEmail` замінено writer.
Legacy `DeliverSecurityNotice` більше не викликається security mutations; клас
залишено для сумісності й старої redaction regression, не як другий delivery path.
Старі queued payloads не імпортуються/повторно не надсилаються; operational drain
і cutover належать D8 до будь-якого rollout.

## Dispatcher, доставка й retry

`auth_notice_claim(1..50)` бере `FOR UPDATE SKIP LOCKED`, під lock перевіряє wall
clock, atomically збільшує attempts і видає новий UUID fencing token на 90 секунд.
Expired leases reclaim лише з новим token; 20 claims або expiry → dead/expired.
`auth_notice_ack` перевіряє актуальні token/state/lease після lock і expiry після
send; stale ack відхиляється. Terminal ack atomically scrub recipient/key + audit.
Send/decrypt поза transaction/locks, перед send повторний lease/expiry recheck.

`NoticeDispatcher::poll` бере по одному row: не тримає 50 послідовних sends на
одному 90s lease. За 9 секунд припиняє нові claims; claim/recheck/ack мають lock/statement timeout
2 секунди кожний, transport має timeout 10 секунд, worker `PollSecurityNotices` timeout 30 секунд. Database queue default
retry_after піднято до 120 секунд; override нижче 120 не сертифікований.
Future SMTP adapter зобов'язаний мати реальний connect/read/total timeout ≤10s;
B4 має лише fake, тому реальних SMTP sockets/timeouts не випробовує.

Retry: 30s/120s/600s/1800s/3600s плюс невід'ємний jitter <10%, максимум 20/24h.
Key/policy/permanent error → dead; transient/timeout → pending до ліміту.
Recipient key version зберігає доступ до старого key під час ротації; нема ключа
→ dead+key alarm. Ключі не пишуться до DB/queue; default використовує APP_KEY
через versioned config, production custody/rotation залишається D8/D6.

`NoticeDelivery` приймає SecretValue recipient, typed template, timestamp і stable
Message-ID з outbox UUID; не приймає JSON/evidence/OTP/grants. Незалежний канал —
окремий `IndependentNoticeDelivery`; email fallback відсутній. Fake зберігає лише
безпечні template/time/ID. Default binding відсутній; немає SMTP чи SendPulse.
Command і dispatcher відхиляють production environment.

`PollSecurityNotices` — необов'язковий queue signal без PII/recipient/body;
повторний/втрачений сигнал не впливає на intent. `php artisan auth:notices-dispatch`
є polling entrypoint, придатним для контрольованого local/testing supervisor.
Production scheduler/worker не активується B4. Scheduling frequency для D8:
≤10s polling, worker timeout 30s, retry_after ≥120s. Пропущений queue signal
покриває наступний polling pass, без повернення до afterCommit guarantee.

Гарантія — durable intent і **at-least-once attempts до 20/24h**, не exactly-once
або guaranteed inbox. Crash після provider acceptance/до DB ack допускає дубль.
Fencing захищає DB ack; SMTP не має fencing. In-flight notice може закінчити
send після expiry; ack тоді expired, secret/authority у notice немає.

## ACL, monitoring, retention і наступні gates

PUBLIC не має table/claim/ack/audit EXECUTE. Writer має тільки SELECT/INSERT;
dispatcher SELECT і narrow claim/ack EXECUTE, без direct UPDATE/DELETE/DDL,
users mutation або recovery EXECUTE. Ролі та grants створює **лише synthetic
harness**, через `01_test_acl.sql`; production runtime privileges не змінено.
SECURITY DEFINER primitives мають fixed search_path і schema-qualified relations.

`NoticeMetrics` та `auth:notices-metrics` дають лише агрегати backlog/retries/
dead/expired/stale leases/key failures/oldest age/claim heartbeat. Alarm:
oldest pending >330s (30s протягом 5m), будь-який dead/expired/key failure/stale
lease, backlog без claim heartbeat ≤120s. Exit 1/monitor unavailable сигналізує
незалежному monitor; auto-notification до зовнішнього сервісу не підключено.
Збої logger не можуть стерти rows. Claim heartbeat є активністю worker за наявності
роботи, не liveness proof на порожній черзі. Історичні dead/expired тримають alarm
до зовнішнього acknowledge/retention; B4 не вигадує dashboard silence state.

Terminal ciphertext/key scrub негайний, індекс `(finished_at,id)` готує metadata
retention 30d. DELETE/TRUNCATE заборонені; purge/restore/holds не реалізовано.
B5 має зберегти audit 180/365, key custody, FK/history і не replay старих notices
після restore. B6 не може reset attempts/intent під час budgets cutover; потрібні
load/lag/worker memory/lock measurements. Чинний global rate lock збережено.

D3 (trustees/verifier/custody/independent grant delivery), D5–D8, P5/P6 та
production activation відкриті. D4 implementation не закриває provider operational
acceptance D8. Не було змін реальних БД, production, `.env`, credentials, deployments,
registry identities чи recovery runtime grants. Merge/auto-merge не виконувались.

## Перевірки

Лише нові synthetic PostgreSQL 18.6 (network none, без published ports, приватний
Unix socket, непривілейований runner) з успішним cleanup; локально PHP 8.4.27.

| Перевірка | Результат |
|---|---|
| `AUTH6B4_ONLY=1 tests/pg/run-stage1.sh` | 16 tests / 128 assertions; concurrency і справжні process crashes PASS |
| `AUTH6B_ONLY=1 tests/pg/run-stage1.sh` | AUTH-2 12/131, B3 22/212, B4 та AUTH-3–6 PASS; B3 races і 22 parallel HTTP scenarios PASS |
| `AUTH3_BROWSER=1 AUTH4_BROWSER=1 AUTH5_BROWSER=1 tests/pg/run-stage1.sh` | SQL 76/76; Stage 1/2 41/713; основний PHPUnit 38/62; AUTH-2 12/131, AUTH-3 25/129, AUTH-4 24/152, AUTH-5 19/131, AUTH-6 5/359; усі races/browser/HTTP PASS |
| `experiments/auth1/run.sh` | 15 tests / 93 assertions PASS |
| `tests/pg/test-runner.sh`, `tests/pg/run-isolated-pg.sh` | усі fail-closed exit contracts; SQL PASS=76 FAIL=0 GAP=0, PDO, cleanup PASS |
| `vendor/bin/pint --test`, `git diff --check` | PASS |
| Root frontend build; client lint/build | PASS; Node використовував штатний proxy/CA для Bunny fonts |
| Composer validate/check-platform-reqs | PASS на 8.4.27; lock/dependencies незмінні |
| Local Composer audit | proxy відхилив `packagist.org` API (403); без обходу політики. Обов'язковий audit лишається в GitHub runtime CI |

Повторені успішні suites не підсумовуються як нові тести. Початкові fixture/schema
projection помилки виправлено; один HTTP повтор був потрібний через одночасний
локальний запуск harness із фіксованими портами, фінальний прогін послідовний.
Browser PNG із незміненого UI не входять до diff. Версія PHP 8.4.26 не
використовувалась для acceptance; policy не послаблено. Післямерджеві master CI
PR #18: Stage 1 PostgreSQL і PHP runtime security — SUCCESS на `4c5c755`.

Атомарність доведено fault injection notice/audit INSERT у email change,
quarantine та finalize; mutation/version/gate/factor/codes/audit не комітяться
частково. SIGTERM до commit відкочує mutation/notice/audit; після commit/до signal
залишає durable row. Fake acceptance до втрати ack допускає повтор, старий token
не підтверджує новий lease. Два незалежні PG PIDs пропускають заблокований row і
одержують disjoint claims; два observed advisory lock waits дають один intent.

Додатково: всі retry stages/jitter, 20 claims, expiry під час in-flight synthetic
send, transient/permanent/timeout errors, пошкоджений ciphertext і відсутній key, duplicate signal, payload/slot
mismatch, immutable snapshots/terminal, позитивні й негативні ACL, NULL batch/token/result guards, populated down,
канарки у audit/metrics/queue/failed_jobs/errors, відсутність logger calls із
provider exceptions. Реального SMTP/APM provider тут немає: їхній timeout,
redaction/quotas/TLS/DPA/retention acceptance не заявляється виконаним.

GitHub Actions обов'язково запускає B4 всередині B3/B4 step на інтегрованому
pull-request checkout, PHP 8.4.27; runtime security додатково 8.5.12. URL Draft PR,
його фінальний HEAD та фактичний CI status надаються у звіті до задачі; green
master CI не підміняє CI нового PR.


## Повний перелік змінених файлів

- `.github/workflows/stage1.yml`
- `app/Console/Commands/DispatchSecurityNotices.php`
- `app/Console/Commands/SecurityNoticeMetrics.php`
- `app/Jobs/PollSecurityNotices.php`
- `app/Services/Auth/Client/ClientSecurity.php`
- `app/Services/Auth/Client/ControlledAuth.php`
- `app/Services/Auth/MfaService.php`
- `app/Services/Auth/Notices/DeliveryResult.php`
- `app/Services/Auth/Notices/FakeNoticeDelivery.php`
- `app/Services/Auth/Notices/IndependentNoticeDelivery.php`
- `app/Services/Auth/Notices/NoticeDelivery.php`
- `app/Services/Auth/Notices/NoticeDispatcher.php`
- `app/Services/Auth/Notices/NoticeMetrics.php`
- `app/Services/Auth/Notices/NoticeType.php`
- `app/Services/Auth/Notices/NoticeUnavailable.php`
- `app/Services/Auth/Notices/NoticeWriter.php`
- `app/Services/Auth/Notices/RecipientCodec.php`
- `app/Services/Auth/Notices/RecipientSlot.php`
- `app/Services/Auth/Recovery/RecoveryNoticeWriter.php`
- `app/Services/Auth/Recovery/RecoveryService.php`
- `config/auth_notices.php`
- `config/queue.php`
- `database/migrations/2026_10_22_003000_create_auth_security_notice_outbox.php`
- `database/sql/auth6b4/00_outbox.sql`
- `database/sql/auth6b4/01_test_acl.sql`
- `docs/07_auth_security_notice_outbox.sql`
- `docs/architecture.md`
- `docs/auth6-b3.md`
- `docs/auth6-b4.md`
- `docs/database.dbml`
- `docs/entity-catalog.md`
- `docs/project-status.md`
- `docs/roadmap.md`
- `phpunit.auth6b4.xml`
- `tests/Auth5/ClientSecurityTest.php`
- `tests/Auth6B/RecoveryTest.php`
- `tests/Auth6B4/OutboxTest.php`
- `tests/Auth6B4/SchemaTest.php`
- `tests/AuthPostgres/AuthSchemaTest.php`
- `tests/pg/auth2-upgrade.php`
- `tests/pg/auth6b4-concurrency.php`
- `tests/pg/run-stage1.sh`
