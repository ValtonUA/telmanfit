> **Стан і рішення власника — 23.10.2026:** нижче збережений **попередній план AUTH-6B/P5/P6**, а не новий підтверджений production protocol. B1–B6 уже мають реалізовані guarded/synthetic частини, [B3](auth6-b3.md), [B4](auth6-b4.md), [B5](auth6-b5.md), [B6](auth6-b6.md); **B7/P5/P6/production OFF**, жодного реального operational D3 verifier/trustees немає. **D3 із двома друзями відкладено**, його згадки в цьому документі, зокрема §7 пункт 5, **не є чинним затвердженим варіантом запуску**: для останнього admin після B5 disaster restore потрібні окремий проєкт, review, тести та рішення власника **до production**, без bypass MFA, 24h fence або `4040404`. Обрано Hyperhost VPS Старт, SendPulse, зовнішні Hyperhost backups; допустимий RPO до 24 год. D8.1 = docs preflight; D8.2 = [Draft PR #25](https://github.com/ValtonUA/telmanfit/pull/25) (staging-kit **без VPS**), «D8.3» = **неофіційна назва**, не погоджений recovery implementation. Продуктовий пріоритет — [Stage 3](roadmap.md#актуальний-стан-і-наступний-крок--23102026); D8/B7 — **перед запуском**. Наявні security-гарантії B5/B6 не послаблюються цією зміною.

# AUTH-6A — готовність auth до production та пакет рішень P5/P6

Дата: 22.10.2026. **Висновок: до production не готово.** Це аудит і пропозиції,
а не затвердження P5/P6, реалізація AUTH-6B чи дозвіл активації.
Перевірена база GitHub master після merge PR #13:
`7b38445ed2ab6a7656499e81f12fc5585d3a4d3b`.
GitHub API та `git ls-remote` дали однаковий SHA; чистий локальний checkout
`57b257f` безпечно замінено окремою гілкою від fetched `origin/master`.
Чужі зміни й історію не перезаписано.

## 1. Межі та матриця доказів

Прочитано AGENTS, status/roadmap, архітектуру/бізнес-правила, auth-документи,
AUTH-2–5 SQL/міграції, сервіси, middleware, routes, jobs, React і відповідні тести.
При розбіжностях історичних описів факти звірено з кодом базового SHA.
P1/P2/P4 затверджені в AUTH-2; P3 — в AUTH-4, включно з нормалізацією та TTL.
Погоджені параметри не відкриваються повторно. Production hosting, SMTP,
backup-сервіс і відповідальні оператори не підтверджені: cPanel/CloudLinux у
старій архітектурі — цільова гіпотеза, не доказ придатного розгортання.

«Перевірено» нижче розрізняє статичний аудит цього етапу та попередні synthetic
прогони. Джерела останніх: [AUTH-3](auth3.md), [AUTH-4](auth4.md),
[AUTH-5](auth5.md), [checks.json](evidence/auth5/checks.json) і
[browser results](evidence/auth5/results.json). Це не новий прогін і не live acceptance.

| Область | Реалізовано на базовому SHA | Перевірено / межа доказу | Потребує рішення або виконання |
|---|---|---|---|
| Сховище AUTH-2 | Additive auth, constraints, version, append-only audit, safe down | SQL/міграції прочитано; попередні PG18 catalog/upgrade/races | Runtime grants, retention і restore |
| Shared MFA AUTH-3/5 | Один TOTP factor; watermark; single-use recovery; disabled history | Код і негативні тести; попередні AUTH-3 25 tests, AUTH-5 16 tests | P5 assisted recovery, production keyring |
| Filament | Password + MFA assurance, user lock до business commit, Livewire gate | Попередні HTTP/Livewire/race/Chromium | Незалежний bootstrap чинних admin; legacy login не є rollout |
| OTP/реєстрація | Invitation, HMAC, generation, budgets; новий client pending | Попередні AUTH-4 24 tests і 5 races; дефект D1 нижче | Enumeration fix, реальна доставка, історичні email |
| Client security | Fresh OTP+MFA, action proof, own sessions, email change | Попередні races/Chromium; D2 та R1 нижче | Повний HTTP concurrency/GC acceptance |
| Cookies/SPA | Різні guards/cookies, CSRF, no-store, CSP для SPA entry | Код; попередній React E2E з CSP, synthetic HTTP | HTTPS/proxy/web-server acceptance; encrypted admin sessions |
| Пошта | Encrypted OTP/notice jobs, synthetic transport, tries=1 | Код; synthetic queue перевірена раніше | Провайдер, retry, durable notices, SLO |
| Rate store | Database cache, PG advisory lock 4040404, lock_timeout 5 s | Код і попередні races | Прибрати глобальну серіалізацію, load/fault tests |
| Keys/backup | Crypt(APP_KEY), OTP HMAC versions; recovery hashes | Codec/policy прочитано; production rotation відсутня | Custodians, key escrow, PITR, drills |
| Recovery іншого/останнього admin | Немає | Немає реалізації чи drills | Увесь P5; схема approval/state потребує окремого review |
| Retention/operations | Немає auth cleanup schedule/runbooks реалізації | `routes/console.php` має лише inspire | P6, моніторинг, cron/workers, deploy/restore rehearsal |

## 2. Security review: факти, ризики, невиконані вимоги

### Підтверджені дефекти за трасуванням коду

**D1 — account enumeration через login flow_id (високий пріоритет до rollout).**
`ClientAuthController` дозволяє optional UUID `flow_id` для login.
У `ClientAuth::request()` невідомий/непридатний email повертає нейтральний 202
до `lockedFlow()`, а чинний verified client доходить до нього й отримує 422,
якщо UUID відсутній/чужий/прострочений. Передумови: controlled auth увімкнений,
CSRF bootstrap пройдено, budgets ще не вичерпані, немає історичної колізії.
Відтворення на синтетичному стенді: однакові POST
`/api/auth/email-otp/request` з `{purpose:"login", email, flow_id:<random UUID>}`
для придатної та невідомої адреси → відповідно 422 і 202. Знання OTP не потрібне.
Це статично встановлена різниця гілок, HTTP reproduction у AUTH-6A не запускався.
Виправлення AUTH-6B: однакова зовнішня відповідь для всіх невалідних login flows,
без authority decoy, з однаковим enqueue/error contract. Перевірити також
foreign/completed/version-stale flow, suppression, queue outage і статистику часу.
Наявний `test_verification_failures_do_not_enumerate_real_and_unknown_login_email`
перевіряє verify, а не цей request-path.

**D2 — передчасне видалення client sessions admin GC (порушення доступності).**
`AdminSessionContext` встановлює lifetime=15, client boundary — 720, але обидва
використовують одну `sessions`. Lottery `[2,100]` у `config/session.php` активна.
Laravel `StartSession::collectGarbage()` передає поточний lifetime у
`DatabaseSessionHandler::gc()`, який видаляє всі рядки за last_activity без audience.
Отже, admin request, що виграв lottery, видаляє client session з idle 16 хв,
хоча вона ще чинна за client policy 12 год; FK CASCADE прибирає assurance.
Це не обхід MFA, але випадковий logout і можливе переривання auth flow.
Виправлення: вимкнути request lottery для спільного store та впровадити
контекстний scheduled cleanup; не змінювати погоджені idle/absolute TTL.
Негативний тест: примусове GC, client idle 16 хв залишається, admin idle 16 хв
відхиляється, client idle 12 год видаляється; також guest/restricted sessions.
Upstream, звірений з локальним vendor:
[StartSession v13.38.0](https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Session/Middleware/StartSession.php),
[DatabaseSessionHandler v13.38.0](https://github.com/laravel/framework/blob/v13.38.0/src/Illuminate/Session/DatabaseSessionHandler.php).

**D3 — неточна подія збою notice (операційна коректність).**
У catch dispatch у `ClientSecurity::confirmEmail()` записується
`client.notice_failed`, але `ControlledAuth::audit()` завжди ставить outcome=success,
без subject цього повідомлення. Це ускладнює кореляцію й alarm за outcome=error.
Відмова самого audit у цьому catch може повернути 503 після вже committed email
change. Зміна і revoke не відкочуються. Потрібні коректний typed outcome,
стабільний correlation ID та незалежне спостереження за post-commit failure.

### Потенційні ризики, які ще треба виміряти або відтворити

| ID | Спостереження та межа висновку | Необхідна перевірка AUTH-6B |
|---|---|---|
| R1 | StartSession читає/зберігає payload поза domain locks; немає session blocking. Два запити з однієї cookie можуть перезаписати proofs/delivery state або повернути стару cookie після rotation. Version/revoked/assurance не дозволяють автоматично воскресити доступ; bypass не доведено | Два повні HTTP workers з однією cookie: reauth/verify/logout/revoke/enroll, зупинка перед session save та обидва порядки відповіді; гарантія відсутності stale write. За потреби окремий session lock до read і до save, однаковий порядок locks, bounded wait |
| R2 | Real OTP request виконує більше DB-роботи, зокрема повний scan email, ніж decoy; `sync` допускається. Admin dummy bcrypt має cost 04, який може відрізнятися від чинного hash | Виміряти known/unknown/admin/unverified/conflict/error розподіли без throttling-артефактів; async-only production, dummy hash з відповідним cost, обмежений бюджет дорогих перевірок. Не вважати random sleep доказом усунення enumeration |
| R3 | Глобальний lock разом із full users scan та повторними DB roundtrips створює спільну точку DoS. `RequireAdminMfa` навмисно серіалізує бізнес-запити одного admin. MFA-транзакції не всі мають локальний lock timeout | Load profile і lock graph, p95/p99 wait, CPU/Argon memory, pool saturation; per-user locks лишити для revoke/write, усунути глобальний lock за §4 |
| R4 | Boundary відновлює config/locale, але SessionManager кешує driver/name/encryption/handler lifetime, AuthManager — guards; singleton proofs живуть довше request у persistent runtime | Не заявляти Octane/RoadRunner/Swoole готовими. Для першого релізу PHP-FPM із новим application lifecycle на HTTP request; будь-який persistent runtime потребує окремих міжкористувацьких alternating/concurrent tests і scoped state |
| R5 | ShouldBeEncrypted шифрує serialized command, не всі поля failed_jobs. Laravel failed-job provider окремо записує exception string; SMTP виняток може містити адреси/тіло. SensitiveParameter/debug redaction не захищає transport dumps | Fault injection SMTP/worker/decrypt + перевірка logs/exception/APM/failed_jobs/backup canary-секретами; заборонити request body, SQL bindings, mail debug, trace/HAR для auth |
| R6 | Delivery job перевіряє expiry/generation перед send, але після перевірки можливий resend/revoke/expiry під час SMTP. Відкликаний лист може запізнитися | Bounded SMTP timeout, повторна перевірка перед send, без DB lock під мережею; старий код завжди непридатний при verify. Exactly-once SMTP не гарантується; дублікати того самого OTP не дають додаткового входу |

### Вимоги, яких production ще не виконує

Default OFF і local/testing assertions працюють навмисно: `ControlledAuth` дозволяє
тільки synthetic/array mail, database cache/sessions та sync/database queue;
`MfaPolicy` не допускає production. Їх просте видалення не є hardening.
Немає P5 recovery state/двох approvals, durable security-notice outbox, key rotation,
auth retention, production grants чи доведеного restore. Admin session encryption
не примусова: default `SESSION_ENCRYPT=false`; payload містить також
`password_hash_admin`. Це невиконана вимога, не доведений витік чинної production-БД.
Security notices зараз є лише для email change на стару адресу, tries=1;
успішний queue dispatch не доводить доставку. Crash після identity commit і до
dispatch може втратити notice. Database/queue retries не виправляють цю щілину.

Позитивні гарантії збережено: current role/version/factor перевіряються під user
lock; revoked assurance і disabled factor відхиляються; recovery code вимагає
primary, не вимикає MFA, одноразовий під factor lock; disabled ciphertext очищається,
watermark не скидається. Store/codec/gate faults дають відмову/503. Нові business API
також повинні тримати user lock до commit; guard login сам по собі недостатній.
React має explicit retry після 419, same-origin fetch, HttpOnly session, окрему
readable encrypted CSRF cookie, не використовує localStorage/SW для auth.
No-store/CSP не стирають уже показану інформацію чи копії рядків у JS/PHP пам'яті:
потрібні очищення UI, обмежений час життя workers і заборона dumps, без обіцянки
гарантованого zeroization керованих рядків.

## 3. P5 — рекомендована політика до погодження

Незалежність означає канал і докази, погоджені **до** інциденту, поза втраченою
скринькою/сесією. Знання імені, даних анкети, платежу або контроль email окремо
не є доказом особи. Case містить UUID, target, причину, перевірені джерела,
перевіряльників, час і рішення; копії документів/відео не потрапляють у auth audit.
За суперечливих доказів справа зупиняється й переходить відповідальному за інцидент.

| Рішення | Рекомендований варіант і підтвердження | Альтернатива / ризик / потрібні ролі |
|---|---|---|
| P5.1 Client MFA reset | Admin з чинною MFA + fresh password/TOTP ≤5 хв; особиста перевірка або live video через заздалегідь відомий канал і callback на контакт із попереднього незалежного обліку; зафіксувати два незалежні джерела звірки. Reviewer окремо затверджує case; target/ініціатор не reviewer | Для малого штату: один admin-перевіряльник + окремий призначений власником reviewer без доступу до бізнес-даних. Повна відсутність незалежних доказів → відмова, а не email reset. Ризик social engineering/змови; ролі admin, reviewer, client |
| P5.2 Інший admin | Дві різні уповноважені особи незалежно перевіряють target і підтверджують однакові case/target/action/version/expiry; target не може бути однією з них. Звичайний шлях — два інші MFA-admin з fresh step-up | Один MFA-admin + зовнішній recovery trustee з попередньо зареєстрованим ключем/окремою сильною автентифікацією. Один approval не достатній; без двох approvers — restricted/відмова. Ризик змови; ролі два approvers, target, виконавець сервісу |
| P5.3 Останній/єдиний admin | До rollout призначити **двох незалежних від target recovery trustees**, наприклад уповноваженого бізнес-представника та зовнішнього IT/security custodian. Вони не мусять мати роль admin. Обидва окремо перевіряють особу/право володіння за попереднім реєстром, підписують один короткоживучий recovery intent. Обмежена operator command перевіряє обидва підписи й викликає той самий recovery service | Другий штатний admin зменшує ймовірність цього сценарію, але не замінює два approvals. Якщо власник сам є єдиним admin, його власний підпис не рахується approval. Немає двох доступних trustees/попереднього trust registry → відновлення заблоковане; спочатку організаційне рішення, жодного прихованого bypass. Ризик втрати trustee keys/змови; operator не може замінити їхню згоду |
| P5.4 Втрачено email, TOTP і всі codes | Та сама незалежна case-перевірка; окремо затвердити заміну primary на нову адресу. OTP нової адреси доводить тільки її контроль. Recovery capability не дає даних; новий email commit і новий factor confirmation завершуються лише в обмеженому recovery workflow | Якщо primary ще доступний, повторити його звичайно; якщо лишився TOTP, використати як додатковий доказ, не як самодостатній login. Ризик одночасної заміни ідентичності; для admin завжди два approvers. Не створювати нового user/client та не merge |
| P5.5 Час та оповіщення | Після approval: 24 год hold для assisted recovery; повідомлення старому та незалежно підтвердженому каналу, без секретів. Заперечення зупиняє справу для повторного незалежного review; саме повідомлення зі старої скриньки не вирішує спір. Потім capability 15 хв, одноразова | Hold 48 год — більше часу на виявлення; коротший строк — більший ризик takeover. Терміновий інцидент може негайно відкликати сесії без видачі доступу, але не скорочує hold непомітно. Потрібні incident lead і два approvers для admin |

### Контракт recovery, який належить реалізувати в AUTH-6B

1. Окремі стани case/approvals/hold/restricted recovery; ACL і trust registry,
   незалежний від recovery target. Поточні `users`/factor flags не описують цей
   процес: не виводити право відновлення з `disabled_at` чи голого user ID.
   Потрібен окремий additive schema proposal/approval; тут DDL не додається.
2. Кожен approval прив'язаний до case UUID, target ID, action (окремо зміна email),
   current auth_version, hash нової адреси за потреби, nonce, expires_at і версії
   policy/trust registry. Рекомендація: approvals 48 год; зміна target/обсягу,
   завершення строку чи version bump скасовує обидва. Operator command не приймає
   «force», plaintext secrets чи підтвердження від target. Підписи не замінюють
   незалежну перевірку особи; реєстрація/заміна trustees теж потребує dual control.
3. Після hold під узгодженими user/factor locks атомарно: version bump,
   revoke всіх assurance/flows/challenges/codes, quarantine старого фактора,
   restricted recovery state та audit з actor/approvers/subject/case/outcome/count.
   Audit fault → rollback. Admin MFA лишається обов'язковою. Старий TOTP не діє.
   Цю зміну стану не підміняти наявним optional client disable.
4. Capability: 256 random bits, лише hash у БД, bound до case/target/version/
   purpose/browser після exchange, TTL 15 хв, одна активна generation. Вручити
   через незалежно перевірений канал; token не в query/log/лист до втраченої email.
   Capability дозволяє лише restricted enrollment, жодного guard/business access.
   Обмін одноразовий, а finalize під lock одноразово витрачає enrollment flow;
   retry не перевидає секрет/коди. Старт іншого browser/generation відкликає старий.
5. Target підтверджує доступний primary або погоджений replacement primary,
   потім **новий** TOTP. Лише final commit встановлює новий factor/batch,
   завершує case, знову підвищує version і відкликає все попереднє. Повний вхід
   виконується заново. Втрачений final response не повертає старий grant/codes.
   Expiry/cancel залишає restricted state; нова видача — новий approved intent.
6. Заборонити assisted self-reset і self-approval на Policy та service рівнях,
   включно з operator path. Власна зміна optional client MFA через AUTH-5 із fresh
   primary+чинним фактором залишається дозволеною, це інший сценарій.
7. Notices — на початок case, approval/revoke, завершення/відмову; без capability,
   codes чи документів. Щокварталу synthetic drill: client, інший admin,
   єдиний admin, недоступний trustee, втрачений primary, expiry/replay,
   одночасний revoke/write, audit outage. Зберігати лише case IDs/час/результат.

## 4. P6 — рекомендована архітектура та технічні вимоги

У цій секції «потрібно» — технічна умова готовності; конкретні послуги, бюджет,
відповідальні й числові цілі нижче залишаються пропозиціями власнику (§5).

### Пошта, queue та повідомлення безпеки

Рекомендація: transactional SMTP із TLS і перевіркою сертифіката, окремим
відправним доменом/subdomain, SPF/DKIM/DMARC, журналом delivery/bounce без OTP,
мінімальними credentials. Кандидати: Amazon SES (низька ціна, складніший IAM та
sandbox exit) або Postmark (простіше супроводження, інший тариф/географія).
Це кандидати, провайдера не обрано; self-hosted SMTP не рекомендовано через
репутацію, deliverability та навантаження на оператора. Перевірити регіон, DPA,
строк збереження тіла листа, квоти й доставку на типові поштові домени.

Database queue — базова рекомендація без нової інфраструктури; окремі auth-otp
та security-notice queues, гарантовані workers під supervisor/systemd або
еквівалентом хостингу. Production sync заборонити: SMTP latency/errors не повинні
визначати HTTP latency/існування акаунта. Зберегти encrypted real/decoy jobs.
Рекомендовані OTP retries: до 3 спроб, backoff 5/15/30 s, тільки в межах
challenge/flow expiry, той самий код/generation, не новий challenge. SMTP timeout
10 s, worker timeout 30 s, retry_after 90 s як початковий узгоджений набір;
перед send не надсилати лист із залишком TTL <30 s. Це запропоновані параметри
доставки, не зміна погодженого OTP TTL 300 s чи resend 60 s.

Потрібен durable outbox для security notices у тій самій транзакції, що security
mutation; dispatcher із retry до 24 год та дедуплікацією event ID. Exact-once SMTP
неможливий після timeout: допустимий дублікат без секретів. Повідомляти про зміну
email на стару й нову підтверджену адресу, MFA enable/replace/disable, recovery use/
rotation, assisted recovery та важливі revocations. Notice failure не відкочує
захисний commit, але має alarm і повтор. Outbox потребує окремо погодженого DDL;
не використовувати непогоджені business messaging JSON-контракти як обхід.

### Ключі та резервування

Потрібні окремі secrets поза Git/DB/образом/CI artifacts: APP_KEY encryption,
OTP HMAC keyring, стабільний rate/handle HMAC key, SMTP/DB credentials і trustee
keys. Рекомендація — secrets manager/KMS або захищене сховище хостингу з журналом
доступу; альтернатива — root-owned encrypted secret files і два custodians,
але складніші ротація/відновлення. DB backup і ключі резервуються окремо,
у двох контрольованих місцях; не один незашифрований `.env` поруч із dump.

`AuthSecretCodec` використовує Laravel Crypt/APP_KEY, а `mfa.key_version` зараз
лише strict equality з config, не вибір ключа. Зміна label без міграції відріже
чинні фактори; APP_PREVIOUS_KEYS сам не є TOTP rotation protocol. Потрібен
version→key adapter із read-old/write-new; спочатку всі instances/workers читають
обидві версії, потім нові writes, re-encrypt контрольованими batches зі звіркою,
drain jobs/sessions/pending flows, decrypt/restore drill, лише тоді retire key.
OTP старі ключі тримати до завершення всіх bounded challenges/jobs; rate key
не змінювати разом з OTP, інакше скинуться budgets/handles. Його rotation потребує
overlap/перенесення budgets або контрольованої паузи та invalidation handles.

Пропозиція: плановий review ключів щокварталу, rotation щороку і негайно після
компрометації; drill перед кожним retirement. Старий decrypt key для дозволених
архівних backups зберігати до їх expiry. Після витоку APP_KEY просте re-encrypt
не повертає таємність TOTP: incident plan має вимагати нового enrollment,
revocation sessions/capabilities і credentials rotation.

### PostgreSQL, grants, backup/restore

Потрібні окремі runtime, migration owner, backup, audit-reader та retention ролі.
Runtime — без superuser/CREATEROLE/CREATEDB/DDL/TRUNCATE, без membership owner,
без можливості disable triggers; лише потрібні schema/table/sequence privileges.
Для audit: INSERT, обмежений SELECT (MFA budgets його потребують), без UPDATE/
DELETE; migration/retention доступ тимчасовий і журналюється. Перевірити effective
grants/default privileges/PUBLIC і negative tests під реальною runtime role.
Тригери append-only не захищають від DB owner: потрібен незалежний append-only
export із контрольними сумами/підписаними manifests у сховище, де app не видаляє.

Рекомендація: керований PostgreSQL із PITR/WAL, щоденний encrypted base backup,
30 днів backup retention, окрема offsite/immutable копія. Альтернатива — власний
PG з WAL/base backup tooling і черговим DBA; hosting-only pg_dump без доведеного
RPO/restore не достатній. Початкові цілі: **RPO ≤15 хв, RTO ≤4 год**.
Перевірити PostgreSQL major/patch, `btree_gist`, collation, timezone UTC,
приватний доступ/TLS, pool limits, disk/WAL alerts, backup roles/файли/ключі.

Restore щомісяця на ізольований стенд: schema/constraints/counts, TOTP decrypt,
recovery hash, audit completeness, workers/SMTP вимкнені. PITR повертає і вже
витрачені codes/grants/revocation state! До відкриття трафіку потрібен reviewed
application reconciliation: invalidate всі sessions/flows/challenges/recovery
batches й незавершені cases/invitations, що могли бути витрачені після restore
point; звірити незалежний audit/revocation checkpoint. За неповної історії —
консервативне відкликання всіх потенційно відновлених capabilities, fresh login
та контрольоване перевидання recovery codes. Не зменшувати watermark/version,
не розсилати відновлені queue jobs, не вважати старі approvals чинними.
План безпечного відновлення admin доступу через P5 має пройти цей drill.

### Retention та cleanup

Нижче початкова політика до погодження; TTL чинного proof не подовжується через
retention. Зараз cleanup команд немає. Упровадити dry-run, bounded batches,
FK-порядок, legal hold, власні timeout/lock order, метрики й повторюваність.

| Дані | Пропонований строк / безпечна дія |
|---|---|
| Auth flows/challenges | Після terminal/expiry +24 год; challenges до flows з урахуванням FK. Не видаляти active, evidence/hold чи потрібні recovery cases |
| Pending TOTP material | Одразу після expiry/invalidation очистити ciphertext/key/expiry узгоджено з trigger; незавершений setup не стає чинним фактором |
| Sessions/assurance | Авторизація за погодженими idle/absolute limits; scheduled GC за audience, без request lottery. Сирий session payload видаляти після expiry; revoked metadata до 7 днів лише за мінімально потрібного складу, без продовження зберігання proofs. FK CASCADE нині прибирає assurance разом із session |
| Invitations | Active без auto-expiry за P3, лише explicit revoke/consume; terminal metadata 365 днів, далі окремий FK-aware review, без видалення потрібних cases/audit references |
| Recovery/factor history | Used/revoked hashes та disabled history мінімум 365 днів, активні hashes і durable watermark не очищати generic cleanup. Видалення factor row може скинути replay barrier і зараз блокується FK; окремий дизайн архівування |
| Security audit/cases | 180 днів online, 365 днів total із захищеним архівом; legal hold призупиняє expiry. Поточні immutable triggers/FK не дозволяють звичайний DELETE/анонімізацію: потрібна окремо reviewed retention процедура/схема, не disable trigger у runtime |
| Queue/failed_jobs/provider logs | Успішний OTP job видаляється після виконання; expired job не відправляється; failed auth payload ≤24 год, лише redacted metadata до 7 днів; provider body/log retention мінімізувати договором |
| Backups | 30 днів із перевіреним expiry offsite copies; online deletion не видаляє миттєво immutable backup. Restore знов застосовує retention/holds |

Retention не є дозволом фізично видалити users, скинути `last_used_step`,
відновити used/revoked код або очистити audit. Аргументація й мінімальний DDL
для archiving/retention мають окреме погодження перед AUTH-6B implementation.

### Rate store та навантаження

Рекомендація першого релізу: зберегти PostgreSQL як джерело атомарних budgets,
замінити read-modify-write cache arrays на окремо reviewed per-key atomic rows
із window/expiry; lock кількох keys у стабільному порядку. Це потребує additive
schema proposal, не простого видалення advisory lock. Global delivery cap також
має бути атомарним, але коротка операція його counter не повинна серіалізувати
всю auth transaction. User→flow→factor→code→session порядок і recheck під lock
зберегти; crypto hashing/network — поза locks. При недоступності store — 503,
без process-local fallback або скидання failures після retry/deadlock.

Альтернатива: dedicated Redis із Lua atomic windows/locks. Плюси — latency і
ізоляція від PG; мінуси — нова залежність, eviction/restart durability та відсутність
спільного commit з auth. Без persistence/noeviction/fail-closed та перевіреного
failure accounting це послаблює захист. Вибір — після load benchmark і бюджету.
Повні scans `eligible()`/registration/email change замінити лише після доказаного
canonical backfill/invariant усіх writers; до того вони fail-closed резервують
історичні адреси. Не прибирати перевірку колізій заради throughput.

### HTTP runtime, cron, monitoring та інциденти

Потрібні HTTPS всюди, `Secure`/HttpOnly/host-only/Lax session cookies, правильні
trusted proxies/hosts, APP_DEBUG=false, encrypted **обидва** session contexts,
тільки public document root, закриті `.env`, backups, vendor/storage dumps.
HSTS вмикати після перевірки доменів/HTTPS; не вмикати preload без окремого рішення.
SameSite/Path не є межею від XSS; для API зберегти CSRF і same-origin, для SPA CSP
без сторонніх scripts. Filament/Livewire CSP перевірити окремо з їхніми assets,
не переносити SPA policy навмання.

На nginx location для `/app`, `/app/`, `/app/index.html` та nested SPA routes
має йти через Laravel/front controller з CSP/no-store/no-referrer; звичайний
`try_files` може віддати існуючий index.html напряму без цих headers. На Apache
перевірити фактичний AllowOverride/mod_rewrite та чинні `.htaccess` правила.
Assets можна кешувати за hash, auth/private API/HTML — не можна у CDN/proxy.
Acceptance: browser/curl заголовки всіх entry variants, `/api`, `/admin`, Livewire,
419, expired cookie, дві незалежні сесії; cross-origin POST відхиляється.

NTP на app/DB/worker hosts: alert за drift >2 s, за >10 s зупинити нову MFA issuance
до перевірки часу; це запропоновані operational thresholds. TOTP час береться з PG,
не з браузера. Не розширювати window через NTP інцидент і не зменшувати watermark.
Workers restart/drain після deploy/config/key change, memory/max-jobs/max-time,
private tmp, без core dumps. Cron щохвилини запускає scheduler, одна активна
cleanup/dispatch задача на кластер; stale scheduler/worker heartbeat — alarm.
Хостинг без надійних workers, cron, backups і privileges — blocker; тоді потрібен
інший тариф/VPS/managed runtime, а не sync OTP як production workaround.

Метрики без PII/секретів: sanitized 401/403/419/422/429/503 counts, issuance/verify
latency, queue age, delivery/bounce/dead letters, PG lock waits/deadlocks/pool/disk,
audit write/export failures, recovery approvals, key version errors, NTP та backup
age/restore result. Не перетворювати метрики на oracle для стороннього користувача.
Початкові SLO/alarms: queue age >30 s 5 хв; p95 OTP inbox <60 s, p99 <120 s;
auth 503 >1% за 5 хв; lock wait p99 >250 ms; audit/export/backup/key failures —
негайно відповідальному. Provider acceptance не доводить inbox delivery: потрібні
контрольні скриньки без реальних клієнтів і synthetic canaries без збереження OTP.

Призначити incident lead і backup operator. Runbook: ідентифікувати компонент,
зберегти sanitized evidence, обмежити issuance/трафік, application revoke при
підозрі takeover, перевірити audit, відновити компонент і negative acceptance,
повідомити постраждалих через погоджені канали. DB/audit/key/rate-store outage
закриває auth; mail outage не створює альтернативного входу. Закриття інциденту —
запис причин/дій/drill, без секретів чи копій документів у PR/logs.

## 5. Пакет питань власнику — один review

Можна відповісти «погоджую рекомендації Q1–Q6» і зазначити винятки, але Q1/Q3/Q4
потребують конкретних осіб/послуг/потужності. Їх відсутність не трактується як згода.
Технічні security invariants із §2–4 не є опцією для послаблення.

| ID | Що потрібно погодити | Рекомендована відповідь / що уточнити |
|---|---|---|
| Q1 — люди й P5 | P5.1–5, два approvers для admin, два trustees незалежні від target навіть за одного admin; identity evidence, hold, строки approvals, канали вручення; хто підтримує trust registry | Погодити §3, hold 24 год, approvals 48 год, capability 15 хв; назвати двох trustees, reviewer/incident lead та резервних осіб/канали (без ключів і документів у Git) |
| Q2 — scope AUTH-6B | Дозволити окремі implementation PR/синтетичні перевірки та спочатку review additive-дизайну recovery/outbox/rate/retention | Погодити план §6; кожна зміна SQL/DBML/нової міграції лише після review конкретного schema proposal; production/реальні дані залишаються окремою задачею |
| Q3 — послуги/вартість | Хостинг/runtime, SMTP/provider region/DPA/quota, key storage, backup/PITR/offsite, постійні workers і відповідальний за них | Managed PG+PHP-FPM+database queue; порівняти SES/Postmark за бюджетом/регіоном, обрати secrets/backup service. Підтвердити доступні можливості та місячний бюджет, не надавати credentials у review |
| Q4 — операційні цілі | RPO/RTO, retention, delivery SLO, ключова rotation, drills і очікуваний peak | Погодити §4: RPO 15 хв/RTO 4 год, backup 30 днів, audit 180/365 днів, решту retention таблиці, щомісячний restore/щоквартальний recovery drill; надати users/admin count, peak requests/s та одночасні logins для sizing |
| Q5 — історичні дані | Незалежний review collisions/unverified, NULL-карток і акаунтів без clients; порядок admin bootstrap | Погодити §7: без auto-merge/verify/relink; спірні записи залишаються закритими. Визначити відповідального за ручний розгляд та контрольоване вікно; P3 normalization/TTL повторно не затверджуються |
| Q6 — go/no-go | Хто підписує final acceptance, час maintenance і допустиме закриття доступу при fault | Власник + технічний reviewer після доказів §7; без password-only/email-only fallback для MFA-account, без destructive rollback. Зараз погоджується план, дата активації ще не визначається |

## 6. AUTH-6B — незалежні перевірювані частини

Це майбутні невеликі PR, не виконані задачі. Schema changes спершу проєктуються
й погоджуються, історичні міграції не переписуються. B1 можна розглядати до
вибору провайдера; B3/B4/B5 розробляти після відповідних рішень Q1–Q4.

| Частина | Результат | Обов'язковий доказ виходу |
|---|---|---|
| B1 — дефекти/HTTP | D1/D2/D3, перевірка R1, encrypted admin sessions | Known/unknown flow matrix без oracle; forced GC mixed contexts; повні concurrent HTTP read/save/rotate/revoke; notice error correlation |
| B2 — contracts/schema review | Recovery state/approvals/trust, durable outbox, atomic budgets/cleanup; лише потрібні additive зміни | Діаграма переходів/ACL/lock order, SQL→DBML→EC review, empty/populated upgrade, safe down plan; окрема згода до реалізації |
| B3 — P5 | Один domain recovery service, Filament action і restricted operator adapter для останнього admin | Два незалежні approvals; self/target/one signer/replay/expiry/wrong-action/version/registry відхиляються; audit fault rollback; lost-primary та single-admin drills; до нового TOTP нуль business access |
| B4 — доставка/observability | Обраний SMTP adapter, encrypted queues, retry/expiry, transactional notices | Реальна sandbox доставка; crash commit→enqueue, provider timeout, duplicate/reordered/expired jobs, queue/store outage, no-secret failed_jobs/logs/APM; delivery SLO |
| B5 — keys/DB/retention | Grants, key adapter/rotation, backup+PITR reconciliation, scheduled cleanup | Runtime role негативні grants tests, immutable external audit, decrypt старих/нових versions, restore без воскресіння codes/sessions, cleanup FK/hold/race/idempotency |
| B6 — scalability/runtime | Atomic per-key budgets без global auth lock, bounded user locks, FPM/HTTPS/web-server config | Погоджений peak і 2× peak 30 хв, p95/p99, два app instances, deadlock/timeout/cache outage, R2/R3/R4/R6 acceptance; без bypass при fault |
| B7 — transition/release rehearsal | Dry-run історичних email/NULL-карток/admin bootstrap, final release/incident runbooks | Синтетична populated копія, reviewer sign-off, усі AUTH-2–5 regressions + B1–B6, browser/Livewire/CSRF/CSP, restore/rollback/forward-fix drill |

## 7. Production go/no-go, перехід та rollback/forward-fix

**Критичні блокери:** D1; непридатна session GC policy D2; відсутній P5 шлях
останнього admin; непідтверджені SMTP/notice reliability, key recovery й PITR;
відсутні least-privilege/audit retention процедури та production HTTP/load acceptance.
Просте ввімкнення controlled flags не усуває жодного з них.

До go/no-go потрібні всі наведені умови й докази на release SHA:

1. Q1–Q6 зафіксовані власником; відсутні high/critical defects; D1/D2/D3 закриті,
   R1–R6 мають результати й прийняті межі. B1–B7 пройшли, CI green саме release SHA,
   secrets scan evidence без секретів; reviewer перевірив негативні й позитивні сценарії.
2. Hosting/provider/grants/keys/backup/NTP/HTTPS/cron/workers перевірені на цільовому
   staging. Негативні fault tests не повертають business response; контрольні mail
   та security notices доходять у межах SLO. Drills вкладаються у погоджені RPO/RTO.
3. Історичний перехід — окремо авторизована data operation: read-only
   `auth:email-preflight` дає агрегати, потім захищений ручний review collisions,
   invalid/mismatch/unverified/NULL email та users без profiles. Exit 0 preflight
   перевіряє структуру, не готовність rollout. Synthetic backfill не запускати live.
   Після review — новий idempotent production backfill/dual-write та canonical
   invariant усіх writers, dry-run/change manifest, snapshot і контроль колізій
   під lock; жодного автоматичного winner/verify/merge. Не міняти роль чи IDs.
4. NULL-картки лишаються редагованими й nullable. До окремого approved target-card
   flow — жодної прив'язки; звичайний invitation відхиляє target_client_id.
   Майбутнє explicit binding: незалежна identity check, fresh admin step-up,
   target lock `user_id IS NULL`, UNIQUE і аудит, без дубля картки/втрати історії
   чи зміни платного статусу. Невирішені картки залишаються закритими для login.
5. Кожен чинний admin проходить незалежний bootstrap case, не лише password/OTP.
   Заздалегідь зареєстровані trustees і перевірені offline recovery codes.
   У maintenance/restricted setup: новий TOTP confirmation, одноразові codes,
   новий повний password+TOTP login і негативний password-only/old-cookie/Livewire
   тест; потім version bump/revoke legacy sessions. Повторити для кожного admin,
   не створювати тимчасового доступу до бізнес-даних без MFA. Єдиний admin проходить
   той самий контроль з двома trustees, останній recovery drill до закриття legacy.
6. Лише окремий reviewed activation PR/операція замінює local/testing policy на
   production policy з перевіркою обов'язкових залежностей. Трафік відкривається
   після canary fresh login/revoke, notifications, locks та monitors; відповідальний
   має право зупинити rollout. Цей документ нічого не активує.

**Rollback:** перед розгортанням зафіксувати release artifact/config manifest,
сумісний попередній **MFA-enforcing** artifact, backups і обидва decrypt keysets.
Зупинити issuance/mutations, drain workers, повернути лише перевірений сумісний
код/config зі збереженням additive schema, audit, factor history, version і
revocations; потім restart workers та negative smoke tests. Якщо такого artifact
ще немає (перша активація), залишити auth закритим і виконувати forward-fix.
Не повертатися до default-OFF legacy password login як rollback.

**Forward-fix:** виправити новим reviewed commit/адитивною міграцією з повтором
релевантних tests; populated auth down навмисно блокується. Не видаляти історію,
не скидувати watermark/версії й не робити SQL MFA bypass. DB restore — disaster
recovery із reconciliation вище, не штатний rollback: він може повернути
витрачені capabilities та втратити audit. Відкриття доступу — після повторного
go/no-go; DDL, restore, accounts і production activation потребують окремої задачі.

## 8. Перевірка саме AUTH-6A

Виконано read-only code/vendor review та звірку базового GitHub SHA, diff scope,
whitespace і локальних Markdown file links. Дефекти D1/D2/D3 встановлено
трасуванням коду, без нового HTTP/PG експерименту. Попередні PASS не видаються
за повторні тести цього PR. PHPUnit/Playwright/build/PG runners не запускалися:
код не змінено; ціль етапу — документація, чинні PG runners запускають міграції.
Під час AUTH-6A жодні БД, SQL/DBML, міграції, credentials, accounts, gates,
production або merge не змінювалися.
