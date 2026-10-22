> **AUTH-6B/B4, 22.10.2026:** D4 затверджено; реалізовано transactional security
> notice outbox, encrypted snapshot, lease/fencing, bounded retry і terminal scrub.
> Лише synthetic/local/testing adapters; production delivery/recovery OFF.
> D3/D5–D8/P5/P6 відкриті; [контракт і докази](auth6-b4.md).

# AUTH-6B/B3 — захищене recovery

22.10.2026. База `master` `d9ff62f8fddae20ecbe666d600c132496d10f75b`:
PR #16 merged; локальний checkout перед роботою чистий на `9d27e0e`, потім
`fetch origin master` та окрема гілка `codex/auth6b-b3-recovery`.
D1/D2 затверджено прямою задачею, включно з explicit admin password replacement.
D3 затверджує незалежність двох trustees, але не identities/keys/custody/verifier.
D4–D8, P5/P6 та production activation відкриті.

Реалізовано перевірюване domain ядро B3 та gate-aware чинні auth paths. Operational
recovery не активовано: production середовище завжди відхиляється; немає default
`RecoveryBoundary`, production registry rows або mutation grants для runtime.
Точна схема — [authoritative SQL](06_auth_recovery_schema.sql), логічна модель —
[DBML](database.dbml), призначення — [Entity Catalog](entity-catalog.md).
Історичні міграції й SQL 01–05 незмінні.

## Схема та переходи

Сім additive таблиць: principals, keys, cases, approvals, grants, gates, events.
Ed25519 keys мають перевірений SHA-256 fingerprint, validity та registry revision;
identity/key material immutable, revoke незворотний. Approvals/events append-only,
DELETE/TRUNCATE заборонено. Один active case на user, один grant на case; replay
не перевидає token, pending secret або codes. Новий reviewed case потрібний після
втрати grant. SQL procedures мають fixed `pg_catalog,public` search_path,
qualified relations та `REVOKE EXECUTE FROM PUBLIC`.

Case підписує role/version, action, replacement email MAC, окремий
`replace_admin_password`, deployment/epoch, nonce, policy/registry version і TTL.
Поле password scope додане відповідно до D1; `target_role` зберігає перевірюваний
snapshot. `deployment_id`/`restore_epoch` — лише signed context від D3 port,
не D6 singleton, restore fencing чи реалізація B5.
Canonical JSON має фіксований порядок/types/UTC microseconds; IDs/version bigint
підписуються decimal strings для сумісності мов. Approval envelope також включає
principal/key, evidence digest, receipt UUID та expiry. PHP Sodium перевіряє exact
bytes; Python/PHP/Node vector є в tests. PostgreSQL перевіряє контекст/aggregate,
але довіряє окремому verifier щодо криптографічно перевіреного receipt write.
Runtime не має INSERT approvals і не отримує recovery mutation EXECUTE.

Два різні live principals обов'язкові. Target, initiator/operator, alias через
linked user та два keys однієї особи не рахуються. Client потребує admin approver
і незалежного другого reviewer; admin — двох admin approvers/trustees. Перевіряються
поточні admin roles, revoked principals/keys, registry version, intent та expiry.
D3 port має перевірити evidence/custody і fresh password+TOTP ≤5 хв для admin approval.
Синтетичний receipt fixture не доводить жодних реальних human identity checks.

Строки D2: case≤72h, approval≤48h, hold24h від другого approval, grant≤15m,
pending≤10m та grant expiry, primary proof≤5m. Час — PG wall clock після locks.
Ліміти assisted exchange: 5/case/15m і 10/IP/min; failed TOTP: 5/flow,
10/user/15m та 20/IP/15m. Використано наявний database-cache AuthBudget під чинним
`4040404`, без B6 cutover. Exchange admission комітиться перед domain transaction;
TOTP failures комітяться разом із denied event, успішний finalize їх не витрачає.
Primary OTP повторно використовує auth_challenges, P3 загальні/email/IP budgets,
5 failures сумарно через resends, MAC/key version та encrypted delivery job.
Нова адреса перевіряє канал, не особу. Історичні email не backfill/verify автоматично;
collision/preflight rules та відсутність account merge зберігаються.

Quarantine атомарно піднімає auth_version, invalidates flows/challenges, revokes
codes/assurance, очищує pending material, disable confirmed factor зі збереженням
watermark/history, ставить gate та створює hash grant. Session payloads не беруть
чужих B1 advisory locks; старі assurance/version не дають доступу після пізнього save.
Case ready → restricted exchange → shared MFA enrollment. Звичайний MfaService
не приймає `assisted_recovery`; restricted adapter щоразу перевіряє case/gate/grant,
binding, version, approvals, primary freshness та TTL. Нової MFA-системи немає.

Finalize використовує чинні TOTP installer, monotonic step, Argon2id code batch,
flow consume та version/session revocation. Explicit password scope встановлює
Argon2id hash; звичайний admin login читає і чинні bcrypt, і нові Argon2id hashes.
Password validation повторно використовує Laravel `Password::defaults` та наявний
input cap 1024. Gate release, completed case, email/password mutation, audit/events
і mandatory notice port — та сама transaction/connection. Жодного login proof/session
у відповіді; після завершення потрібний новий повний login. Втрата final response
не дозволяє повторно показати codes.

Cancel/reject/expiry invalidates exchanged flow, очищує pending і revokes
unexchanged grant, але не release gate. Exchanged row незмінний; authority припиняє
terminal case. Повторний reviewed case може переприв'язати gate; старий фактор не
повертається. Down бере ACCESS EXCLUSIVE locks і відмовляє за будь-якої recovery
історії; після quarantine дозволений лише gate-aware artifact/forward fix.

Порядок locks: B1 HTTP session (якщо є) → registry shared → чинний budget lock →
sorted linked users/target → case/gate/grants → flows/challenges → factor/codes →
assurance → audit/events/notice. Registry revocation у synthetic race бере exclusive
registry lock до write. Recovery не бере чужих session locks. Lock timeout 5s,
міграція також має statement timeout 5m. Registry user set навмисно консервативний
(усі linked registry users); load/оптимізація та зняття global lock — тільки B6.

Gate працює незалежно від recovery flags у client eligibility/session/protected
paths, admin assurance, MFA, DB flow/session guards і Filament. Legacy Filament
password-only context при вимкненому MFA заборонений для акаунта з recovery history
навіть після release: старий legacy cookie не може воскресити authority. Для таких
акаунтів потрібен controlled повний MFA login. Missing gate schema/DB не є bypass.

## Fail-closed межа, blockers та ризики

1. **D3:** не визначені реальні principals/keys, зовнішній актуальний registry,
   незалежні evidence/callback methods, verifier/operator/incident authority,
   bootstrap/custody/rotation/revocation та delivery channel для grant. Не створено
   production identities або CLI/Filament/HTTP дію, яка підміняє ці рішення довільним
   actor ID. `RecoveryBoundary::authorize/receipt/context` — обов'язкові ports без
   default binding. Реальні operational adapters заблоковані цим рішенням.
   Зокрема §3 B2 має одночасно target/request intake у prose та target≠initiator
   у field contract: реалізація зберігає строгий field contract; D3 має назвати
   незалежний intake principal, а не приховувати target під чужою identity.
2. **B4/D4:** durable transactional notice outbox відсутній. Немає no-op production
   fallback або dispatch-after-commit як гарантії security notice. Порожній port
   відхиляє операцію до mutation; помилка notice у fixture відкочує quarantine/finalize.
   Fixture notice лише тестує межу, не є delivery/outbox implementation.
   B4 має реалізувати transactional writer та перевірити ACL/executor path перед
   наданням mutation grants; простого binding/GRANT для production недостатньо.
   Самі SQL mutation procedures є privileged primitives без окремого outbox writer,
   тому їхній прямий EXECUTE лишається owner-only. Власник DB/verifier/runtime compromise
   поза заявленою dual-human threat boundary, як у B2.
3. **D4–D8/B4–B6:** production role provisioning, external registry/epoch custody,
   restore reconciliation, physical retention/archive, provider acceptance/load
   та PG rate cutover не реалізовано. Read-only runtime ACL template не створює
   ролей і не надає recovery mutations; негативні ACL тести використовують окрему
   synthetic NOLOGIN role. Production grants/secrets/config/providers не змінювалися.
4. **Deployment/rollback:** gate-aware artifact і нова схема мають бути сумісні.
   Старий artifact після появи gates не можна повертати. B3 production env закрита
   навіть при помилковому DI binding; rollout можливий лише наступною reviewed зміною.
5. **Перевірки:** elapsed hold/expired TTL моделюються historical owner-only fixtures
   з реальним PG clock, без очікування 24h та без скорочення production constants.
   Одночасна змова двох trustees, скомпрометований verifier/owner або реальні канали
   особи не доведені цими тестами. Live production drills і hosting performance не заявляються. Для single-admin fixture попередні synthetic admins тимчасово
   переведені в coach із відновленням у finally; тест стверджує рівно одного admin
   під час recovery. Other-admin fixture має окремого admin approver та trustee.

## Відтворення та фактичні перевірки

`AUTH6B_ONLY=1 bash tests/pg/run-stage1.sh` створює новий PostgreSQL
18.6 Bookworm за pin `sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc`,
`network=none`, без published ports, приватний `/tmp/.../socket`, окремий
непривілейований runner та synthetic fixtures. Контейнер/socket видаляються trap.
Guard перевіряє DB name/user/PG major/socket до міграцій і тестів.
`AUTH6B_CASES_ONLY=1` разом з `AUTH6B_ONLY=1` обмежує повтор AUTH-2/B3 наборами;
повний `bash tests/pg/run-stage1.sh` також виконано. HTTP harness має фіксовані порти:
одночасний запуск двох harness спочатку спричинив 401/barrier failure, тому
підсумковий повний повтор виконано послідовно.
Попередні невдалі прогони виявили missing Vite manifest, неточні timestamps/повторний
browser binding у fixtures та потребу транзакційного lock у direct down test;
їх виправлено. Повний Stage 1 прогін також виявив старі fixtures, що не встановлювали
нову auth schema для HTTP, та AUTH-2 projections із wildcard на всі майбутні міграції.
Fixtures отримали transactional auth setup; AUTH-2 зберігає точну AUTH-2/5 проєкцію,
нові FK/guards/down перевіряє окремий B3 набір. AUTH-2 запускається до committed B3
fixtures, щоб preflight cleanup не порушував RESTRICT recovery history.
Підсумкові результати — [checks.json](evidence/auth6b-b3/checks.json).

HTTP набір B1 запускається двома незалежними servers/PG PIDs із реальними encrypted
cookies/CSRF і перевіреним lock wait. B3 HTTP тест додатково відхиляє legacy admin
під quarantine з MFA flag off; recovery UI/public endpoints не додавалися через D3/B4.
Основний PHPUnit, Pint, React lint/build, root Filament build і cross-language vector
виконано окремо. Для root build потрібен `NODE_USE_ENV_PROXY=1`: перший запуск без
proxy завершився EAI_AGAIN fonts.bunny.net, успішний повтор зберіг TLS verification.
Production deployment, merge/auto-merge/force-push та зміни реальних БД не виконувалися.

## Підсумкові результати

| Перевірка | Фактичний результат |
|---|---|
| Останні B3 fixtures | 21 passed / 203 assertions; AUTH-2: 12 / 131 |
| Повний B3 harness | AUTH-2: 12/131; B3: 21/202 до доданої single-admin assertion; AUTH-3: 25/129; AUTH-4: 24/152; AUTH-5: 19/132; AUTH-6: 5/359; exit 0 |
| B3 concurrency | Duplicate approval, exchange, finalize/finalize, cancel/cancel, finalize/cancel, registry revoke/finalize; два blocked PG PIDs; PASS |
| Повний Stage 1 runner | SQL 76 PASS / 0 FAIL / 0 GAP; PG PHPUnit 41/713; основний 22/46; AUTH-2–6 та їхні concurrency/HTTP — PASS; exit 0 |
| HTTP | 22 впорядковані сценарії + bounded timeout, unlock after CSRF exception, parallel session save; два servers/PG PIDs, real encrypted cookies/CSRF; PASS |
| Pint / React / Filament | Pint, client lint/build, root build із proxy — exit 0 |
| Canonical / Ed25519 | Python/PHP/Node vector, exact bytes та tamper rejection — PASS |
| Документація | SQL byte equality, local Markdown file links, diff whitespace — PASS |

## Повний перелік змінених файлів

- `app/Http/Middleware/AdminMfaBoundary.php`
- `app/Http/Middleware/RequireAdminMfa.php`
- `app/Models/User.php`
- `app/Services/Auth/AdminAssurance.php`
- `app/Services/Auth/Client/ClientAuth.php`
- `app/Services/Auth/Client/ClientSession.php`
- `app/Services/Auth/MfaService.php`
- `app/Services/Auth/Recovery/RecoveryBoundary.php`
- `app/Services/Auth/Recovery/RecoveryDenied.php`
- `app/Services/Auth/Recovery/RecoveryGate.php`
- `app/Services/Auth/Recovery/RecoveryService.php`
- `app/Services/Auth/Recovery/RecoverySignatures.php`
- `database/migrations/2026_10_22_002000_create_auth_recovery.php`
- `database/sql/auth6b/00_recovery.sql`
- `database/sql/auth6b/01_empty_down.sql`
- `database/sql/auth6b/02_runtime_acl.sql`
- `docs/06_auth_recovery_schema.sql`
- `docs/auth6-b3.md`
- `docs/database.dbml`
- `docs/entity-catalog.md`
- `docs/evidence/auth6b-b3/checks.json`
- `docs/project-status.md`
- `docs/roadmap.md`
- `phpunit.auth6b.xml`
- `tests/Auth6B/FixtureBoundary.php`
- `tests/Auth6B/Fixtures/signature-vector.json`
- `tests/Auth6B/MigrationTest.php`
- `tests/Auth6B/PrivilegesTest.php`
- `tests/Auth6B/RecoveryTest.php`
- `tests/Auth6B/SignaturesTest.php`
- `tests/Auth6B/verify-vector.mjs`
- `tests/AuthPostgres/AuthSchemaTest.php`
- `tests/Postgres/BusinessSchemaTest.php`
- `tests/Postgres/ClientResourceTest.php`
- `tests/Postgres/PostgresTestCase.php`
- `tests/pg/auth2-upgrade.php`
- `tests/pg/auth6b-concurrency.php`
- `tests/pg/run-stage1.sh`
