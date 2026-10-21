> **AUTH-3, 22.10.2026:** реалізовано спільний durable MFA, recovery та server assurance
> gate для контрольованого Filament. За замовчуванням вимкнено; production rollout
> заблоковано P3/P5/P6. Точна поведінка, контракт і перевірки — [auth3.md](auth3.md).
> Нижче збережено історичний стан попереднього етапу.

# AUTH-2 — PostgreSQL-сховище запрошень, OTP та MFA

Дата: 22.10.2026. База: master `5506358a9aad2cc1171f02b029178e4591c2cab2`
після PR #9. A1–A8 чинні; P1/P2/P4 затверджено користувачем у задачі AUTH-2.
P3/P5/P6 не затверджені. Джерело точного DDL — [05_auth_schema.sql](05_auth_schema.sql);
вісім відповідних файлів `database/sql/auth2` виконуються вісьмома новими
Laravel-міграціями `2026_10_22_*`. Історичні міграції й SQL 01–04 не змінено.

## Реалізоване сховище

| Таблиця | Призначення та ключові гарантії |
|---|---|
| `client_invitations` | Без email binding. UNIQUE SHA-256 hash випадкового bearer token; явні автор/coach; необов'язкова цільова NULL-картка. FK RESTRICT. `expires_at=NULL` означає відсутність автоматичного строку завершення до рішення P3; якщо задано — consume можливий лише строго до expiry. Парні consumed/revoked actor+time; ці стани взаємовиключні й незворотні. Один незавершений slot на target card; expired slot звільняється явним revoke, а не `now()` у partial index. |
| `auth_flows` | Випадковий public UUID, browser binding hash, audience, purpose, optional invitation, user/version snapshot, generation, restricted primary proof, capability hash, action/session binding для reauth. Один незавершений slot на browser+audience+purpose. Terminal-стан незворотний, generation не зменшується. |
| `auth_challenges` | UUID, FK flow, UNIQUE(flow,generation), адресний ключ, HMAC короткого OTP та версія зовнішнього ключа, лічильники спроб, expiry/consume/invalidate. Контекст user/invitation/purpose/binding успадковується від flow, без дублювання й суперечливих FK. |
| `user_totp_factors` | Один рядок на BIGINT user; один підтверджений та необов'язковий pending зашифровані секрети з key versions. Pending expiry; durable `last_used_step`, який не можна зменшити/очистити UPDATE. Активний recovery batch UUID. |
| `mfa_recovery_codes` | Окремі Argon2id hashes із випадковими UUID selectors/batch, FK на factor, незворотні used/revoked стани. Порівняння hash та витрачання — під спільним factor lock. Старі batch лишаються історією, перевірка активності — за factor.recovery_batch_id. |
| `auth_session_states` | FK sessions.id CASCADE, BIGINT user, окремі audience client/admin, browser binding, version snapshot, primary/MFA method+time, reauth, absolute expiry/revoke. Admin assurance вимагає password і MFA; client — email_otp. Pending login тут не зберігається. |
| `auth_audit_events` | Append-only typed events: actor/subject, outcome, request UUID, fingerprints, reason code та UUID case reference. UPDATE/DELETE/TRUNCATE заборонені тригерами. Без довільного JSON/free-text payload, email, IP/UA у відкритому вигляді чи копій документів. |

Нові внутрішні ID — BIGINT identity; усі user FK — BIGINT, `target_client_id` —
integer. Public UUID генерує застосунок криптографічним RNG, DB default відсутній.
Час auth-даних — `timestamptz`. CHECK містять форму hash, парність полів,
часовий порядок, допустимі purposes/states/methods та невід'ємні лічильники.
Індекси покривають FK, expiry, active slots, email diagnostics, user/audience,
batch і часові/actor/subject/request запити аудиту. Точний перелік — у SQL.

Замість пропонованих у AUTH-0 SET NULL для actor/subject використано RESTRICT:
видалення user не має непомітно змінювати append-only журнал. Retention та
анонімізація потребують окремої погодженої процедури P6. Це свідоме уточнення
точного DDL у дозволеному AUTH-2, а не готова retention-політика.

`ON DELETE RESTRICT` свідомо обмежує фізичне видалення користувача, на якого
посилаються auth-дані чи журнал безпеки. Майбутня захищена адміністративна дія
має блокувати акаунт, відкликати його сесії та незавершені auth flows і зберігати
журнал безпеки. Їй потрібні Policy, належний MFA/step-up і аудит. Це окрема
майбутня реалізація: AUTH-2 не додає такої дії чи account-status поля та не
послаблює FK. Для блокування доступу потрібна окрема дія зі збереженням users
та auth-історії.

У `users` додаються тільки nullable UNIQUE `email_normalized` (bytewise COLLATE C)
і BIGINT `auth_version NOT NULL DEFAULT 0 CHECK >=0` з забороною зменшення.
Не потрібні boolean-прапорці MFA: обов'язковість admin визначає роль, optional
client — наявність підтвердженого factor; ці перевірки належать AUTH-3.
Немає дублювання secrets у `users` чи штатних Filament JSON-полях.

## Секрети та межі захисту

`AuthSecretCodec` — реалізований codec сховища, без авторизації: Laravel Crypt
(AEAD/MAC згідно з чинним cipher/key config) для TOTP, Argon2id для recovery,
SHA-256 для invitation token з **256+ випадкових бітів**. Ключі шифрування та
OTP HMAC зберігаються поза БД; `key_version` — посилання на керований keyring,
не ключ. Його production rotation/backup ще не реалізовано. Для короткого OTP
не можна використовувати звичайний SHA-256: потрібен HMAC із зовнішнім ключем,
version та однозначним domain/context binding (flow/public ID/generation/email/purpose).
Issuance, генерація кодів/QR та інтеграція keyring — AUTH-3/4.

CHECK довжини ciphertext/формату hash відсікає очевидний plaintext, але не доводить
криптографічну коректність. Безпечний запис забезпечує codec і майбутній сервіс.
Сховище не має публічних Eloquent/JSON-серіалізаторів секретів. Майбутні моделі
зобов'язані приховати ciphertext/MAC/hash та не логувати QR, OTP, tokens, secrets.
DB owner може вимкнути тригери, видалити рядки або схему: це не захист від
скомпрометованого owner/superuser. Production grants/окремі runtime/migration
ролі та retention-доступ мають бути затверджені й перевірені перед розгортанням.

## Email preflight та блокер активації

`php artisan auth:email-preflight` відкриває **REPEATABLE READ, READ ONLY**
транзакцію з timeout, читає один snapshot і повертає лише агрегати:
NULL/unverified email, кандидатні колізії й число залучених рядків, адреси для
ручного розгляду, NULL-картки, email-акаунти без clients. Немає ID, імен або
адрес у звіті; помилка не друкує SQL bindings. Діагностичний кандидат —
`lower(btrim(email) COLLATE "C")`, це **не затверджена нормалізація**.
Unicode/неприпустима форма/довжина відносяться до manual review; це не повний
RFC/IDNA validator. Команда завжди повертає exit 1 й `application_blocked=true`,
навіть за нульових колізій, бо не може затвердити продуктову політику.

Міграції нічого не заповнюють і не змінюють у старих email, `email_verified_at`,
password, role, Telegram ID або clients. Nullable normalized-key дозволяє
безпечне additive-розгортання сховища, але **не дозволяє вмикати нову auth**.
UNIQUE захищає явно записані канонічні ключі; NULL старих записів не резервує
їхніх адрес. DB CHECK поки перевіряє лише форму/наявність delivery email, без
непогодженої lower/IDNA-політики чи зміни старого Filament email writer.

Блокер застосування normalized-key як ідентифікатора входу / backfill:
затвердити нормалізацію (case-sensitive local-part, IDNA/SMTPUTF8, довжина),
розглянути всі колізії та походження unverified, узгодити контрольований перехід
NULL-карток/акаунтів без профілю. Після цього потрібна окрема міграція backfill,
dual-write та DB-перевірка відповідності delivery/canonical email. Заборонено
автоматичні merge/verify/relink; OTP не є дозволом підтвердити старий admin.
Preflight тут виконувався лише на синтетичних даних; реальну БД не читали.

## Транзакційний контракт AUTH-3/4

Це необхідний контракт майбутнього сервісу. Перевірені SQL-прототипи нижче
не є production MFA/OTP-сервісом, HTTP gate чи готовими login actions.

Порядок блокувань для операцій із user:
**users → invitation (якщо потрібне) → flow → challenge → factor → recovery → session**.
Кілька users блокуються за ID. Реєстрація без user: invitation → flow → challenge,
потім новий user/profile; не блокувати наявний user після цих рядків.
Не тримати locks під час mail/network або password/Argon hashing: hash можна
перевірити попередньо, але під lock повторно звірити незмінний hash/selector/batch.

1. Після user lock перечитати role, `auth_version`, factor та Policy; snapshot
   cookie/React/старої транзакції не достатній. Для sensitive mutations цей lock
   залишається до commit бізнес-зміни, усуваючи TOCTOU з revoke.
2. Flow lock: перевірити binding, audience, purpose, action/session binding,
   version, state, expiry за актуальним часом; challenge generation = flow generation.
   Resend збільшує generation та invalidates старі challenges у тій самій транзакції.
   Expired slots явно invalidated; partial index свідомо не містить `now()`.
3. Витрачати лише умовним UPDATE `... WHERE consumed_at IS NULL AND
   invalidated_at IS NULL AND expires_at > clock_timestamp() AND attempts < max_attempts
   RETURNING ...`; вимагати рівно один рядок. На невдалий OTP — atomic increment
   attempts; max_attempts і TTL явно задаються майбутньою погодженою P3, без default.
4. Після криптографічної перевірки TOTP оновити `last_used_step` тільки якщо
   він NULL або **строго менший** за прийнятий step; вимагати один змінений рядок.
   UPDATE на той самий step без умови не є прийняттям TOTP. Навіть cache flush
   не змінює watermark; один factor використовують обидва контексти.
5. Recovery: factor lock, звірка активного batch та hash; conditional used_at update,
   рівно один результат. Rotation змінює batch під тим самим lock; старі коди
   не стають активними повторно. Factor change/recovery піднімають auth_version.
6. Consume invitation лише разом із успішним створенням/явним прив'язуванням
   users+clients, complete flow, server assurance та audit в **одному commit**.
   Відкриття URL/OTP не споживає invitation. Consume використовує predicate
   `consumed_at IS NULL AND revoked_at IS NULL AND (expires_at IS NULL OR
   expires_at > clock_timestamp())` під тим самим row lock. `NULL` дозволяє
   безстрокове запрошення; задане expiry має бути пізнішим за created_at і
   строго пізнішим за consumed_at. Після consume або revoke стан незворотний.
   Це не послаблює обов'язковий TTL OTP, flows і recovery capabilities, які
   зберігаються в короткоживучих flows. NULL-card policy ще відкрита.
7. Logout-all/recovery: user lock → version bump → invalidation pending flows
   та revoke assurance → audit. Session version — snapshot, FK не робить її
   актуальною автоматично. На кожному HTTP/Livewire запиті gate звіряє current
   version, audience, role, expiry/revoked, factor та потрібний MFA proof.
8. Після commit виконати безпечну ротацію Laravel session ID+CSRF; не переносити
   старий assurance на новий session ID автоматично. Видалення старої session
   CASCADE прибирає її assurance, audit лишається. При збої — fail closed.

SQL CHECK/тригери забезпечують структурні інваріанти, UNIQUE та незворотні
UPDATE-стани. Вони не перевіряють OTP, TOTP-криптографію, Policy, актуальність
flow/challenge generation або чинність session snapshot автоматично. Відсутній
майбутній gate не можна компенсувати просто наявністю цих таблиць.

## Відкат і збереження даних

`down()` транзакційний у Laravel PostgreSQL. Кожна міграція перед DROP бере
ACCESS EXCLUSIVE lock і перевіряє порожність власної таблиці; для users —
відсутність normalized-key і nonzero version. Це прибирає race між перевіркою
порожності й concurrent INSERT. За наявності даних кидок винятку залишає
таблицю/секрети/журнал і запис Laravel migrations на місці. DROP без CASCADE:
сторонній FK також блокує відкат. Порожні таблиці можна відкочувати у зворотному
порядку. Немає прапорця force для руйнівного видалення auth-історії.

Після появи auth-даних потрібен окремий погоджений recovery/retention/backup plan;
за замовчуванням rollback застосунку зберігає additive-схему. Старі бізнес-міграції
не переписано. Auth FK навмисно блокують видалення users/clients із історією.
Збережено BIGINT PK, nullable email/password, усі ролі/defaults, nullable UNIQUE
clients.user_id, Laravel-службові поля та бізнес-обмеження Stage 1/2.

## Відтворення та докази

```bash
npm run build
npm run lint --prefix client
npm run build --prefix client
tests/pg/run-stage1.sh
bash tests/pg/run-isolated-pg.sh
bash tests/pg/test-runner.sh
bash experiments/auth1/run.sh
```

Runner створює **новий** postgres:18.6-bookworm за погодженим digest,
`--network none`, без портів, data на tmpfs, приватний socket під випадковим
`/tmp/telmanfit-stage1.*`, non-superuser runner, випадковий app key лише прогону.
Перевіряються фактичні DB/role/version; ніякого fallback до .env чи telmanfit_dev.
EXIT trap видаляє лише власний container/socket. Stage 1/2 спочатку тестуються
на історичних міграціях (через явний список paths), потім AUTH-2 перевіряє upgrade
й власний каталог. Це зберігає значення старих inventory-тестів «37 таблиць».
CI виконує той самий runner та lint/build; `pgsql` extension потрібний лише для
асинхронних тестів двох незалежних connections, не є новою залежністю застосунку.

`auth2-upgrade.php`: порожня та populated synthetic Stage 1, nullable/unverified/
case-collision email, NULL-картка, Telegram ID >32 bit, бізнес-профіль; up/down і
повне порівняння **всіх старих рядків** до/після. PHPUnit: FK/UNIQUE/CHECK,
BIGINT, normalized collision, immutable terminal states, ciphertext roundtrip,
Argon2id, audit append-only, session cleanup, блокування кожного populated down;
живий PG-каталог порівнюється з виконаним docs/05 у тимчасовій схемі.

`auth2-concurrency.php`: два різні backend PID, B надсилає SQL асинхронно;
тест спочатку доводить `pg_stat_activity.wait_event_type='Lock'`, і лише потім
відпускає A. 14 сценаріїв: challenge/recovery з одноразовим тестовим grant,
shared TOTP step, invitation consume, generation CAS, revoke до/після sensitive
request, rollback TOTP acceptance, invitation revoke/consume, resend/old generation,
recovery batch rotation/consume та rollback precheck/concurrent INSERT; invitation consume/consume й revoke/consume перевірені окремо з NULL та заданим expiry. Це реальна PG-конкурентність транзакційних
примітивів; немає твердження про готовий production login або браузерний MFA.

Конкретні результати локального прогону та GitHub CI — у Draft PR; фінальний
SHA й CI належать саме його ревізії. AUTH-1 і нові concurrency tests мають різні
межі: перший перевіряє ізольовану HTTP-інтеграцію, другі — PostgreSQL storage races.

## Готовність до AUTH-3

Storage primitives готові до інтеграції shared durable MFA service. AUTH-3 має
реалізувати enrollment/rotation, secure secret serialization, TOTP-window
verification, recovery use, Filament adapter та server gate на HTTP/Livewire;
admin primary email+password + mandatory TOTP, client OTP + optional TOTP.
Чинний Filament login, guards, routes, UI карток і бізнес-правила не змінено.
P2 затверджує native Laravel session guards без Sanctum/Fortify з окремими
контекстами; їх production wiring/dual-cookie E2E тут ще відсутні.

P3 (TTL/ліміти/normalization), P5 (recovery identity/last-admin procedure), P6
(retention/rollout/grants) залишаються відкритими; schema permits timestamps та
case references, але не обирає ці політики. До рішень і відповідних acceptance
тестів нову авторизацію не вмикати. Public register/login API, paid activation,
Telegram, Passkeys, production migrations і merge не виконувалися.

## Результати локальної перевірки цієї реалізації

| Перевірка | Результат |
|---|---|
| PostgreSQL 18.6, SQL на Laravel Stage 1 | PASS=76 FAIL=0 GAP=0 |
| Stage 1/2 PostgreSQL PHPUnit | 41 passed / 713 assertions |
| Основний PHPUnit зі зібраним SPA | 22 passed / 46 assertions |
| AUTH-2 PHPUnit | 12 passed / 130 assertions |
| AUTH-2 empty/populated upgrade/down | PASS; усі старі рядки збігаються |
| AUTH-2 дві PG connections | 14 concurrent scenarios PASS |
| Еталонні SQL 01–04 в іншому новому PG18 контейнері | PASS=76 FAIL=0 GAP=0; PDO OK; cleanup OK |
| Fail-closed SQL runner contracts | 10 очікуваних exit-кодів PASS |
| AUTH-1 повторний isolated SAT/HTTP spike | 15 passed / 93 assertions; нові пакети не встановлювались |
| Pint, frontend ESLint, React build, Laravel assets build | PASS |

Для локального Laravel fonts build використано Node 24 із `NODE_USE_ENV_PROXY=1`,
з чинним проксі й TLS; відсутній optional `fontaine` дає лише штатне повідомлення,
залежності не додавались. GitHub CI використовує наявну Node 22 матрицю; його
результат перевіряється окремо після push. Тимчасові PG-контейнери/дані очищено.

## Повний перелік файлів AUTH-2

- `.github/workflows/stage1.yml`
- `AGENTS.md`
- `app/Console/Commands/AuthEmailPreflight.php`
- `app/Support/Auth/AuthSecretCodec.php`
- `database/migrations/2026_10_22_000000_extend_users_auth.php`
- `database/migrations/2026_10_22_000100_create_client_invitations.php`
- `database/migrations/2026_10_22_000200_create_auth_flows.php`
- `database/migrations/2026_10_22_000300_create_auth_challenges.php`
- `database/migrations/2026_10_22_000400_create_user_totp_factors.php`
- `database/migrations/2026_10_22_000500_create_mfa_recovery_codes.php`
- `database/migrations/2026_10_22_000600_create_auth_session_states.php`
- `database/migrations/2026_10_22_000700_create_auth_audit_events.php`
- `database/sql/auth2/00_users.sql`
- `database/sql/auth2/01_client_invitations.sql`
- `database/sql/auth2/02_auth_flows.sql`
- `database/sql/auth2/03_auth_challenges.sql`
- `database/sql/auth2/04_user_totp_factors.sql`
- `database/sql/auth2/05_mfa_recovery_codes.sql`
- `database/sql/auth2/06_auth_session_states.sql`
- `database/sql/auth2/07_auth_audit_events.sql`
- `docs/05_auth_schema.sql`
- `docs/architecture.md`
- `docs/auth-architecture.md`
- `docs/auth-compatibility-spike.md`
- `docs/auth-schema.md`
- `docs/database.dbml`
- `docs/entity-catalog.md`
- `docs/project-status.md`
- `docs/roadmap.md`
- `phpunit.auth2.xml`
- `tests/AuthPostgres/AuthSchemaTest.php`
- `tests/Postgres/PostgresTestCase.php`
- `tests/pg/auth2-bootstrap.php`
- `tests/pg/auth2-concurrency.php`
- `tests/pg/auth2-guard.php`
- `tests/pg/auth2-upgrade.php`
- `tests/pg/run-stage1.sh`

## Уточнення архітектурного рев’ю PR #10

22.10.2026, після початкового head `f337a2b`: `client_invitations.expires_at`
став nullable через SQL-джерело ще не об’єднаної міграції. Омітований expiry
зберігається як NULL без DB default TTL. SQL snapshot, DBML та Entity Catalog
синхронізовано. Перевірено одноразовий consume й revoke безстрокових запрошень,
конкурентні consume/revoke для обох варіантів expiry, межі заданого строку та
збереження NOT NULL TTL OTP/flows/recovery capabilities. Фізичне видалення
користувачів і майбутня захищена дія блокування розмежовані вище; FK не змінено.

Файли цього уточнення: `database/sql/auth2/01_client_invitations.sql`,
`docs/05_auth_schema.sql`, `docs/database.dbml`, `docs/entity-catalog.md`,
`docs/auth-architecture.md`, `docs/auth-schema.md`,
`tests/AuthPostgres/AuthSchemaTest.php`, `tests/pg/auth2-concurrency.php`.
Файл Laravel-міграції не змінювався: він виконує оновлене SQL-джерело.
