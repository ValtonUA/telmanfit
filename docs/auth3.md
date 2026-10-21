# AUTH-3 — спільний MFA та контрольований Filament

Дата: 22.10.2026. База: `26ebad28d1032443cd2efa4d4d5b1eb94e3a7112`, merge PR #10.
P1/P2/P4 затверджені. P3/P5/P6 залишаються відкритими. Схема AUTH-2 та історичні
міграції незмінні. Усі дані перевірок синтетичні, PostgreSQL 18 новий і тимчасовий.

## Реалізована поведінка

`MfaService` використовує одну `user_totp_factors`, PostgreSQL row locks і
`last_used_step`; нативний Filament `AppAuthentication` не підключається.
Немає секретів або recovery JSON-полів у `users`, cache replay counter чи
другого enrollment. Наявна Google2FA генерує 32 base32 символи (160 випадкових
бітів), SHA-1/6 цифр/30 секунд. Допустиме вікно задається явно.
`AuthSecretCodec` шифрує секрет Laravel Crypt; key version перевіряється строго.
Невідома версія або пошкоджений ciphertext не дає доступу. Keyring/production
rotation і restore drill потребують P6; APP_PREVIOUS_KEYS не є повною процедурою.

Пароль створює лише `primary_verified` flow. Guard ще не авторизований.
Flow прив'язаний до випадкового browser binding, audience, user/version, purpose,
expiry. Pending ciphertext додатково прив'язаний hash до конкретного flow;
запуск іншого flow не дає підтвердити старий pending. Активний фактор не змінюється
до підтвердження нового TOTP. Заборонено починати заміну лише з паролем.
Після підтвердження — новий recovery batch, version bump, invalidation flows,
revocation assurance та audit в одній транзакції. Коди показуються один раз у
HTML POST response; після активації потрібен новий повний вхід.

Recovery має випадковий UUID selector + 160-бітний код, Argon2id hash.
Попередній hash check виконується до locks; під factor lock повторно звіряються
user/batch/незмінний hash, conditional UPDATE вимагає один рядок.
Ротація під тим самим lock відкликає всі невикористані старі коди; used/revoked
історія лишається. Самостійний вхід із recovery — пароль + один код як другий
фактор, version bump та відкликання попередніх сесій. Чужого reset і last-admin
recovery немає. `disableFactor` завжди відмовляє; клієнтський optional disable
потребує окремого AUTH-5 контракту й не вводиться побічно.

Порядок блокувань: users → flow → factor → recovery → session. Аудит — typed
append-only AUTH-2 events без payload. Час береться з PG `clock_timestamp()`;
записи зберігають мікросекунди, щоб негайний consume не передував created_at.
Невдалі MFA спроби комітять `denied` audit; per-user budget рахується під user
lock у PG. HTTP має додатковий IP throttle; він не замінює durable MFA budget.
Числа у тестах — фікстури, не затверджена політика P3.

## Серверна межа Filament

Controlled mode перемикає Filament на native Laravel `admin` session guard.
`web` та `client` guards не є доказом admin MFA. Cookie окрема:
`telmanfit_admin_session`, host-only, HttpOnly, SameSite=Lax, Path=/admin.
Контекст вибирається до StartSession. Livewire update перенесений під
`/admin/livewire/update`; default hashed update також захищений web boundary.
Root upload/preview endpoints не отримують admin cookie та не відкривають дані.
Для майбутнього використання uploads потрібне окреме routing/browser acceptance.
Поточні ресурси не мають file upload. Persistent Filament auth middleware
перевіряється також на прямих Livewire actions, не тільки на mount.

`AdminAssurance` перевіряє свіжі role/version, session ID, audience, binding,
primary method, MFA timestamp/method, absolute expiry, revoke та confirmed factor.
`RequireAdminMfa` тримає user lock до завершення бізнес-запиту, включно з mutation,
щоб logout-all/recovery не обігнав запис. Це свідомо серіалізує запити одного admin;
оцінка навантаження/timeout — P6. Роль admin і password hash самі доступу не дають.

MFA proof комітиться до session issuance. Issuance повторно бере user lock і
перевіряє version, знищує старий session ID, змінює CSRF, виконує guard login
без remember-me, зберігає database session і assurance в одній транзакції.
Збій між MFA commit та issuance потребує нового входу; витрачений код не
повертається. Assurance не переноситься автоматично між session IDs.
Помилка БД/codec/gate повертає відмову або 503, ніколи business response.

Restricted routes — лише login, primary, challenge, enrollment, confirm, verify,
logout. Є стандартний Laravel CSRF, no-store, no-referrer, CSP без scripts,
немає flashed password/OTP/recovery. `SecretValue` приховує JSON/debug і забороняє
PHP serialization. Controller не віддає enrollment/recovery як JSON і не пише
SQL exceptions/bindings у логи. Auth pages не містять сторонніх ресурсів.

## Backend contract для AUTH-4/5

- `beginPasswordLogin(email,password,binding)` — тільки admin primary.
- `beginEnrollment(flow,binding)` → `SecretValue`, явний одноразовий показ.
- `confirmEnrollment(flow,binding,totp)` → новий batch, revocation, без guard login.
- `finishLogin(flow,binding,code,recovery)` → внутрішній server proof для session
  adapter. Proof не приймається з HTTP, не кешується й не серіалізується.
- `replaceFactor(flow,binding,currentFactor,recovery)` → restricted replacement
  flow після нового password primary та поточного TOTP/recovery.
- `rotateRecoveryCodes(flow,binding,totp)` → новий batch після fresh primary+TOTP.
- `logoutAll(flow,binding,code,recovery)` → fresh primary+factor, version bump.

MFA enrollment/login/rotation також можуть працювати із серверним client flow
`audience=client`, `primary_verified` після email OTP, створеним майбутнім AUTH-4.
Клієнтський HTTP adapter/guard issuance ще не реалізований; admin replacement
окремо обмежений admin. Не викликати ці методи із user ID/primary flags браузера.
AUTH-4 мусить атомарно перевіряти й витрачати OTP, зберігати primary proof у flow
та застосовувати ті самі binding/version/expiry інваріанти. Публічних endpoints,
registration/invitation issuance, email OTP API та клієнтських бізнес-даних немає.

## Безпечне ввімкнення

**У робочому середовищі цей PR нічого не вмикає.** `AUTH3_CONTROLLED` default false;
при true середовище має бути лише `local` або `testing`, PostgreSQL і database
sessions. Production відхиляється, а не повертається до password-only login.
Порожні/некоректні operational параметри відхиляються. Наявний Filament login при
вимкненому controlled mode залишається поточним legacy login, це не MFA rollout.

1. Перед production rollout погодити P3 TTL/ліміти/нормалізацію; P5 незалежну
   identity-перевірку, двоособовий reset та recovery останнього admin; P6 grants,
   key backup/restore, NTP, retention, моніторинг, журналювання й rollout window.
2. На окремій синтетичній копії підготувати залежності/asset builds. Запустити
   `AUTH3_BROWSER=1 bash tests/pg/run-stage1.sh`. Runner сам створює PG18 на tmpfs
   без мережі/портів і видаляє тільки власний контейнер. Не передавати `.env`
   реальної бази, не запускати manual migration на telmanfit_dev.
3. Після P5 погодити конкретних операторів і recovery drill. Сам пароль чинного
   admin не є дозволом bootstrap у production: controlled first enrollment у PR
   призначений тільки для синтетичного стенда. Не вмикати цей прапорець на live.
4. Окремим reviewed rollout змінити environment restriction після виконання
   блокерів, перевірити Secure cookie на HTTPS, доступність database session
   store/keyring, CSRF, Livewire й backup. Визначити порядок identity proof та
   підключення кожного чинного admin до обов'язкової MFA без блокування останнього.
5. Після активації нового фактора зафіксувати одноразову видачу кодів, відкликати
   старі sessions/version, виконати новий password+TOTP login і негативний
   password-only тест. Зберігати фактори й append-only аудит при forward fix.
   Не використовувати вимкнення MFA або старий login як аварійний fallback.

Пункти 1, 3–5 — план майбутнього погодженого rollout, у цьому PR не виконуються.
P5 — явний блокер завершення production rollout AUTH-3.

## Перевірки та докази

Відтворення: `npm run build`, `npm run lint --prefix client`,
`npm run build --prefix client`, `AUTH3_BROWSER=1 bash tests/pg/run-stage1.sh`,
`bash tests/pg/test-runner.sh`, `bash tests/pg/run-isolated-pg.sh`,
`bash experiments/auth1/run.sh`. Жодної нової Composer залежності.
CI виконує ті самі набори та Chromium Playwright; artifacts — лише масковані
скриншоти/структурований результат, без HAR/trace/HTML dumps.

Фактичні результати й Chromium версія: [evidence/auth3/results.json](evidence/auth3/results.json).
Скриншоти — справжні capture із маскою sensitive DOM під час знімання.
Production, telmanfit_dev, справжні admin/ключі не використовувалися. Merge немає.

Наступний AUTH-4: погодити P3 й normalization/backfill; реалізувати invitation
issuance/consume та email OTP із HMAC/key versions, rate/attempt budgets,
серверними flows; окремий client cookie/context та stateful `/api` adapter;
інтегрувати спільний MFA, version/assurance й CSRF; провести PG races і browser
acceptance. P5/P6 залишаються блокерами production, AUTH-5 додає optional client
MFA management/UI без окремого секрету. Анкету, Telegram і Passkeys не додавати.

## Фінальне виправлення PR #11

Загальний catch у `RequireAdminMfa` більше не підміняє бізнес-винятки статусом
503. Middleware запам'ятовує саме виняток із `$next` і після rollback повторно
кидає його без зміни для штатного Laravel/Livewire handler: validation повертає
HTTP 422 JSON / redirect з errors, Livewire — 200 з field errors; authorization
лишається 403, відсутня модель — 404, звичайна помилка застосунку — 500.
MFA-denial повертає 403, збій gate чи begin/commit/rollback — fail-closed 503.
`$next` залишається всередині транзакції, user lock утримується до завершення
бізнес-операції. Це підтверджує окремий race справжнього middleware з service
logout-all: другий PG PID чекає lock, бізнес-запис комітиться до revoke,
наступний запит зі старою assurance відхиляється 403.

`AdminAssurance::issue` приймає тільки `MfaProof` від успішного `finishLogin`
спільного singleton `MfaService`. Приватна WeakMap прив'язує конкретний об'єкт
до login flow і дозволяє claim один раз; сконструйований стороннім кодом DTO
навіть правильної форми не дає сесії. Це перевірка походження внутрішнього DTO,
не cache replay protection: TOTP/recovery залишаються PostgreSQL durable.
Перед ротацією session повторно перевіряються password/admin primary, дозволений
MFA method, завершений чинний flow, browser binding, version, confirmed factor і часовий порядок.
Proof із відкоченої перевірки, client/email-OTP контекст, невідомий MFA method,
повторне використання та нетипізований payload відхиляються.

Додані справжні HTTP signed-snapshot Livewire tests і browser acceptance для
форми редагування картки: required та доменна validation залишають field errors,
видалення синтетичного record між GET та update дає 404. Наявна Policy-заборона
створення повертає 403, fault injection assurance дає HTTP/Livewire 503.
Жодних production test routes: probe зареєстрований виключно PHPUnit-фікстурою.

## Підтверджений локальний результат

| Перевірка | Результат |
|---|---|
| SQL на Laravel PG18 | 76 PASS, 0 FAIL, 0 GAP |
| Stage 1/2 PostgreSQL PHPUnit | 41 tests / 713 assertions |
| Базовий PHPUnit | 22 tests / 46 assertions |
| AUTH-2 PostgreSQL PHPUnit | 12 tests / 130 assertions |
| AUTH-2 upgrade/down і races | PASS, 14 конкурентних сценаріїв |
| AUTH-3 PostgreSQL PHPUnit | 25 tests / 129 assertions |
| AUTH-3 два service processes/PG PID | TOTP/recovery: один успіх; gate vs logout-all: lock до business commit |
| Еталонні SQL 01–04 на іншому новому PG18 | 76 PASS, 0 FAIL, 0 GAP; cleanup PASS |
| SQL runner fail-closed contracts | 10 очікуваних exit codes PASS |
| AUTH-1 regression | 15 tests / 93 assertions |
| Pint, ESLint, Laravel assets і React build | PASS |
| Chromium Playwright | PASS, деталі у results.json |

GitHub Actions виконується після створення Draft PR; висновок належить конкретному
head SHA, посилання й статус наведено в PR/фінальному звіті, не підмінено локальним прогоном.


## Змінені та створені файли

- `.github/workflows/stage1.yml`
- `AGENTS.md`
- `app/Http/Controllers/Auth/AdminMfaController.php`
- `app/Http/Middleware/AdminMfaBoundary.php`
- `app/Http/Middleware/AdminSessionContext.php`
- `app/Http/Middleware/RequireAdminMfa.php`
- `app/Http/Responses/AdminLogoutResponse.php`
- `app/Providers/AppServiceProvider.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `app/Services/Auth/AdminAssurance.php`
- `app/Services/Auth/MfaDenied.php`
- `app/Services/Auth/MfaPolicy.php`
- `app/Services/Auth/MfaProof.php`
- `app/Services/Auth/MfaService.php`
- `app/Services/Auth/SecretValue.php`
- `app/Support/Auth/AuthSecretCodec.php`
- `bootstrap/app.php`
- `config/auth.php`
- `config/mfa.php`
- `docs/architecture.md`
- `docs/auth-architecture.md`
- `docs/auth-compatibility-spike.md`
- `docs/auth-schema.md`
- `docs/auth3.md`
- `docs/business-rules.md`
- `docs/evidence/auth3/01-login.png`
- `docs/evidence/auth3/02-restricted-enrollment.png`
- `docs/evidence/auth3/03-enrollment-masked.png`
- `docs/evidence/auth3/04-recovery-masked.png`
- `docs/evidence/auth3/05-filament.png`
- `docs/evidence/auth3/06-clients.png`
- `docs/evidence/auth3/07-revoked.png`
- `docs/evidence/auth3/08-filament-validation.png`
- `docs/evidence/auth3/results.json`
- `docs/project-status.md`
- `docs/roadmap.md`
- `phpunit.auth3.xml`
- `resources/views/auth/admin-mfa.blade.php`
- `routes/web.php`
- `tests/Auth3/AssuranceBoundaryTest.php`
- `tests/Auth3/Fixtures/AssuranceProbe.php`
- `tests/Auth3/MfaTest.php`
- `tests/Auth3/MfaTestCase.php`
- `tests/browser/auth3-fixture.php`
- `tests/browser/auth3-in-context.sh`
- `tests/browser/auth3.py`
- `tests/pg/auth3-concurrency.php`
- `tests/pg/run-stage1.sh`
