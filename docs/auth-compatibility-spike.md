> **AUTH-3, 22.10.2026:** реалізовано спільний durable MFA, recovery та server assurance
> gate для контрольованого Filament. За замовчуванням вимкнено; production rollout
> заблоковано P3/P5/P6. Точна поведінка, контракт і перевірки — [auth3.md](auth3.md).
> Нижче збережено історичний стан попереднього етапу.

> **AUTH-2, 22.10.2026:** користувач затвердив P1/P2/P4: additive PostgreSQL-схему,
> штатні Laravel session guards без Sanctum/Fortify з окремими client/admin контекстами,
> Filament email+password+mandatory TOTP та PWA passwordless email OTP+optional TOTP,
> один durable MFA-сервіс. Реалізовано лише сховище, read-only preflight, codec і PG-тести;
> production auth/gates ще немає. P3/P5/P6 відкриті; normalized email/backfill заблоковано
> до окремого рішення. Точний стан і межі: [auth-schema.md](auth-schema.md).
> Подальші формулювання про непогоджені P1/P2/P4 — історичний дизайн AUTH-0/1.

# AUTH-1 — практична сумісність passwordless OTP, sessions та Filament MFA

**Дата:** 21.10.2026. **Статус:** ізольований spike виконано з наведеними нижче
межами; production auth не реалізовано. P1–P6 залишаються пропозиціями.
GitHub `master` перевірено через API перед початком: очікуваний і фактичний SHA
`22635780c7203d26ce37804a9ed7395a02e1cea1` (merge PR #7).
Локальний `/workspace/telmanfit` мав `76b00a5…`; актуальні змінені Markdown
отримано за SHA в окрему review-копію. Код і lock між цими SHA не змінилися.

Прочитано AGENTS, [auth-дизайн](auth-architecture.md), architecture, roadmap,
business-rules, project-status, джерела схеми, чинні auth/session/bootstrap/panel
конфігурації, lock та основні/PG-тести. Міграції не створювалися й не виконувалися;
основні залежності, конфігурації, моделі, routes, SQL/DBML і БД не змінено.
Усі користувачі синтетичні, адреси — `example.invalid`; email не надсилається.

## 1. Перевірені версії та результати Composer resolution

| Компонент | Точна версія | Що фактично перевірено |
|---|---|---|
| PHP / Composer | 8.4.26 / 2.8.12 | Реальні CLI Linux Cloud; не матриця PHP 8.3/8.5 |
| Laravel | 13.38.0 | Основний lock, фактичний runtime kernel, guards, cookies, CSRF, SQLite, sessions |
| Filament | 5.10.2 | Основний lock, AppAuthentication, required middleware та Login через Livewire |
| Livewire / Google2FA | 4.4.7 / 9.1.0 | Основний lock; реальні update handler і TOTP library |
| Sanctum | 4.3.3 | Exact release, SAT; pinned source service provider, stateful middleware і guard у runtime |
| Fortify | 1.41.0 | Exact release, SAT; pinned LoginRequest/validator у runtime; pipeline/routes — source review |

В основному lock Sanctum/Fortify відсутні. `InstalledVersions` перевіряється
тестом для чотирьох уже встановлених runtime-пакетів; нові джерела завантажено
GitHub connector у приватний каталог і перевірено за Git blob SHA з
[sources.json](../experiments/auth1/sources.json). Для AppAuthentication звірено
upstream v5.10.2 з наявним vendor (однаковий код, різниця лише фінального newline
у тимчасовій копії). Це не модифікація vendor.

**Фактично виконаний Composer SAT:** [resolve.py](../experiments/auth1/resolve.py)
створює свій `composer.json`, lock і cache під `/tmp`, бере всі root `require`
та `require-dev`, pin Laravel/Filament/Sanctum/Fortify, використовує пакетний
repository з основного lock і exact upstream manifests. Команда всередині:

```bash
composer update --no-install --no-scripts --no-plugins --no-interaction --no-audit
```

Результат: **exit 0; 165 пакетів: 132 runtime + 33 dev**. Вибрано Laravel
13.38.0, Filament 5.10.2, Sanctum 4.3.3, Fortify 1.41.0; зокрема Fortify додає
`laravel/passkeys 0.2.0` з **laravel/passkeys-server**, `web-auth/webauthn-lib 5.3.0`,
`bacon/bacon-qr-code 3.0.1`, COSE/CBOR/PKI та Symfony serializer/property packages.
Виконання Passkeys із цього не випливає; продуктово вони відкладені A7.

[manifests.json](../experiments/auth1/manifests.json) зберігає repository/tag і
вибрані незмінені runtime dependency-поля upstream composer manifests. Packagist
вимкнено **лише в ізольованому SAT**; platform requirements не ігноруються.
Доведено існування сумісного набору на цьому snapshot. Не доведено latest-choice
Packagist, повний `composer install` чотирьох пакетів, advisory audit або runtime
всіх транзитивних залежностей Fortify. Нові пакети не встановлено в застосунок.

**Окремий online resolution** (`resolve.py --online`) справді запущено: exit 100,
`curl error 7 … repo.packagist.org/packages.json … Failed to connect to proxy
port 8080`. Git shell-доступ має ту саму помилку; GitHub connector працює.
Це інфраструктурний блокер online install/resolution, не dependency conflict.
Не обходили проксі та не вимикали TLS. [Результати](../experiments/auth1/results.txt).

## 2. Фактично виконані прототипи/тести та їхні результати

Відтворюваний стенд — [experiments/auth1](../experiments/auth1/README.md).
Для кожного тесту: новий приватний Application, випадковий ключ, SQLite `:memory:`
з двома `auth1_*` таблицями й файловий серверний session store. Жодних основних
міграцій/App User/БД. HTTP виконано через справжній Laravel kernel із зашифрованою
cookie, кожний запит очищає cached guard/session instances та Livewire state.
Це HTTP integration у процесі PHP, не браузерний E2E чи публічний сервер.

**Spike PHPUnit: 15 passed, 93 assertions**, warnings/risky вважаються помилками.

| Сценарій | Виконаний доказ |
|---|---|
| OTP → pending MFA | Коректний email OTP витрачено; guard client залишається guest; `/api/me` і admin route → 401 |
| TOTP → full session | Невірний код → 422; коректний → authenticated і `/api/me` 200; client password NULL |
| Replay | Той самий OTP → 422; повтор завершення flow → 401; той самий TOTP після нового OTP в іншій browser-session → 422; рівно один успішний login |
| Серверний стан | Зміна `auth_version` у SQLite або видалення серверного session-файлу робить стару cookie непридатною; pending TTL/version також перевірено |
| CSRF / Origin / binding | Немає CSRF token → 419; сторонній Origin → 403; challenge з іншої cookie/session → 422 |
| Fixation / cookies | ID та CSRF після login змінені; стара cookie не дає доступу; перевірено Secure, HttpOnly, SameSite=Lax, host-only |
| Guard/audience | Client login не відкриває admin route; admin account не проходить client OTP; native Filament login авторизує тільки admin guard |
| Sanctum | Той самий NULL-password client: pending → 401, повний вхід → 200, mutation без CSRF → 419, version revoke → 401 |
| Спільний фактор | API acceptance → Filament adapter відхиляє код; Filament acceptance → API відхиляє код; один secret/accepted step |
| Native Filament | App TOTP replay відхилено; 8 recovery codes зберігаються хешовано; використаний код вдруге відхилено |
| Native Login через Livewire | Правильний admin password залишає guard guest до TOTP; після правильного TOTP guard admin authenticated |
| Required MFA | Без секрету — redirect на enrollment; з секретом middleware пропускає навіть без session assurance: негативний контроль потреби окремого gate |
| HTTP Livewire update | Реальний signed snapshot і `increment`: позитивний POST → counter=1; без CSRF → 419; наступний POST після version revoke → 401, HTTP admin також 401 |
| Втрата cache | Native TOTP після Cache::flush приймається знову; durable shared accepted-step відхиляє повтор після такого самого flush |
| Fortify | Його справжні LoginRequest rules через Laravel validator відхиляють email+OTP без password; стандартний Fortify pipeline не зареєстровано |

Livewire HTTP-тест використовує синтетичну **вже завершену** admin-сесію та окремий
probe component. Доведено виконання persistent gate на update, а не готову
інтеграцію запису assurance в штатний Filament Login. Native Login перевірено
окремим Livewire component-тестом. У стенді два guards мають один cookie transport;
повний стек двох cookies/stores і реальний browser Path/CORS не позначаються tested.

Додатково `APP_BASE_PATH=/workspace/auth1-review php artisan test` в ізольованій
review-копії: **17 passed, 2 skipped, 23 assertions** (без зібраного SPA).
PG-набір не запускався, PHP-код стенда проходить Pint. Frontend не змінено.
Власні тимчасові Applications/Composer-каталоги очищено; готові pinned джерела
залишено в scratch для повторення й перевірки SHA. PostgreSQL не був потрібний.

## 3. Рекомендований механізм PWA sessions і клієнтського OTP

**Запропонувати P2: штатний Laravel session guard `client`, без Sanctum і Fortify
на цьому етапі.** Same-origin React звертається тільки до browser-підгруп `/api`;
явні EncryptCookies → queued cookies → StartSession → Origin/CSRF → auth guard →
server assurance/version/MFA → role/Policy/ownership. Health залишається stateless.
Це потребує погодженої зміни поточного опису stateless API, а не вже наявна поведінка.

Sanctum 4.3.3 сумісний і може виконувати transport/session wiring без пароля,
але не додає OTP/MFA. Його guard спершу читає налаштовані session guards, а потім
може прийняти PAT. У cookie-only browser API це зайва альтернативна гілка:
не використовувати `auth:sanctum` як єдиний MFA gate. Якщо з'явиться окрема
потреба в PAT, переглянути залежність і assurance policy для окремих маршрутів;
не додавати токени до localStorage чи обходу MFA через Telegram.

Flow: серверна одноразова первинна перевірка OTP → restricted pending (user,
purpose, browser binding, generation, expiry, auth_version) → за потреби TOTP/
recovery → атомарне завершення → тільки тоді `guard('client')->login(..., false)`.
Ротувати session ID зі знищенням старого й CSRF; password клієнта не передається як credential,
не генерується і не підтверджується. SessionGuard внутрішньо читає nullable
password hash для службового session hash; NULL у виконаних тестах не заважає входу. Guest login та reauth — різні purpose/flows.
Справжній issuance/HMAC/ліміти/нейтральні відповіді/resend/mail із §5 дизайну ще
треба реалізувати: фіксований OTP стенда їх не замінює.

Зберігати sessions на сервері; assurance містить audience, auth_version,
primary/MFA method/timestamps, absolute expiry, revocation. На кожному запиті
перевіряти актуальний user/factor/role/version; React flags не є доказом.
Sensitive mutations повторюють перевірку під lock у своїй транзакції.
Для logout-all/recovery/factor change — version bump + invalidation незавершених
flows, без remember-me. `AuthenticateSession` із password hash не замінює
passwordless `auth_version` і MFA assurance.

Для P2 запропонувати окремі guards **та** session cookies/stores/audience.
Одних двох guards недостатньо для двох transport cookies: до StartSession потрібен
явний серверний вибір session-контексту для кожної групи маршрутів. Cookie names
різні, Secure/HttpOnly/host-only, SameSite=Lax; client path `/`.
Початкову пропозицію admin path `/admin` можна зберегти **лише** разом із перенесенням
Livewire update/upload/preview endpoints під відповідний admin-префікс. Інакше
потрібен погоджений admin Path `/` або окремий host; Path не є межею авторизації.
Це уточнення P2/P4, не виконана зміна production-конфігурації.

Laravel 13 `PreventRequestForgery` приймає валідний `Sec-Fetch-Site: same-origin`
або CSRF token. Тести не передають цей header і доводять token-гілку; додатковий
Origin middleware відхиляє стороннє джерело. Якщо погоджується суворе «token AND
Origin», це слід явно налаштувати й перевірити окремо, не приписувати штатному
middleware таку семантику. `/api/auth/csrf`, no-store, allowlist dev CORS,
service-worker cache exclusions та browser E2E залишаються implementation-вимогами.

## 4. Рекомендована інтеграція Filament MFA

**P4 придатний із адаптерами та окремим gate.** Зберегти запропонований admin
primary email+password (ще потребує погодження) + обов'язковий TOTP/recovery.
Це не поширює пароль на PWA. Native Filament 5.10.2 перевіряє primary і panel
access до challenge та повторно перед фінальним guard login. Required enrollment
закриває користувача без фактора; увімкнений фактор не доводить MFA цієї сесії.

Використати один доменний `FactorService` із наявною Google2FA, незалежний від
Filament/Telegram. Контракти `HasAppAuthentication`/`HasAppAuthenticationRecovery`
дозволяють читати й записувати єдине сховище. Прототип
`SharedAppAuthentication` перевизначає replay-sensitive `verifyCode` і доводить
cross-channel acceptance. Для production потрібно також адаптувати enrollment,
secret/recovery read-write, `verifyRecoveryCode`, factor replacement, аудит і
version invalidation до спільних транзакцій. Одних getters/setters недостатньо:
окремі cache-based і DB-based replay counters дозволили б повтор між каналами.

Конкретні відмінності native defaults від P1/P4:

- `codeWindow=8` (±4 хвилини), `generateSecretKey(16)` (16 base32 символів),
  8 recovery codes. Запропоновано window ±1, secret 32 base32 символи (160 біт),
  10 recovery codes; це слід налаштувати/адаптувати, не називати native defaults.
- Replay native зберігає timestep у cache під lock, якщо store його підтримує;
  fallback без lock не є distributed guarantee. Cache eviction/flush стирає
  replay history — runtime-негативний контроль це підтвердив. Потрібен durable
  accepted-step під row lock, спільний для login/reauth/enrollment усіх каналів.
- Recovery native хешує коди й перечитує user під `lockForUpdate` у транзакції;
  тести підтвердили послідовну одноразовість. Майбутня P1-модель окремих рядків
  selector/hash/batch вимагає адаптера, а не дублювання native JSON-кодів у users.
- SetUp/Disable/Regenerate actions містять password/current-password перевірки.
  Для admin password-primary це може бути первинним step-up, але PWA потребує
  власного fresh OTP+factor flow. Не переносити ці форми в passwordless UI.
- Native management не реалізує незалежну перевірку особи, двоособовий recovery,
  last-admin procedure чи необхідний аудит/revocation. A6 потребує окремих дій.
  Заборона admin disable має бути в сервісі/Policy, не лише hidden action.

Записувати admin session assurance тільки після повного native challenge або
контрольованого завершення enrollment. Legacy/remembered сесії без цього стану
не пускати до бізнес-даних. Restricted setup/logout повинні залишатися доступними
без blanket-блокування enrollment. Gate для бізнес-HTTP і persistent Livewire
middleware має перевіряти MFA/version; sensitive actions додатково перевіряють
fresh proof і Policy. Не покладатися тільки на mount або `isRequired: true`.

Livewire 4.4.7 має hashed root endpoint `/livewire-<hash>/update`, headers/JSON
checks, signed snapshots і механізм persistent middleware. Custom gate слід
зареєструвати через `authMiddleware(..., isPersistent: true)`/persistent middleware
для потрібних маршрутів; default endpoint не повинен обходити custom middleware.
Під час перенесення перевірити також uploads, preview, batching і прямі actions.
У цьому spike перевірено реальний update controller і повторну серверну перевірку
після revocation; browser/production panel rollout ще попереду.

### Fortify: чому не підключати типовий pipeline

[LoginRequest v1.41.0](https://github.com/laravel/fortify/blob/v1.41.0/src/Http/Requests/LoginRequest.php)
вимагає `password`; runtime validator це підтверджує.
[Routes](https://github.com/laravel/fortify/blob/v1.41.0/routes/routes.php) додають
password confirmation endpoints навіть із views=false; `confirmPassword` для
2FA вмикає `password.confirm`. Вимкнення цієї опції прибирає перевірку, але саме
по собі не створює безпечного passwordless step-up.
`RedirectIfTwoFactorAuthenticatable` та `AttemptToAuthenticate` можуть двічі
викликати authenticate callback: одноразовий OTP не слід витрачати там двічі.
Це source-reviewed, не runtime-тест обох pipeline actions. Не реєструвати Fortify
provider/routes/default pipeline для клієнта; його TOTP не потрібний паралельно
Filament/наявній Google2FA. Наявність Passkeys dependency не є дозволом A7 реалізувати їх.

## 5. Відомі обмеження та вектори обходу MFA

| Ризик | Необхідний бар'єр / статус доказу |
|---|---|
| OTP одразу login, старий web guard, remember-me або PAT | Відкладений login + окремі guards + assurance gate; базові негативні сценарії виконано, повний production rollout ще ні |
| Native required-MFA перевіряє enrollment | Durable session proof; негативний контроль виконано, native Login stamp adapter ще не реалізовано |
| Replay між API/Filament, cache reset, enrollment | Один durable accepted step; cross-channel/cache-loss виконано; повний enrollment/reauth та multi-worker races не виконано |
| Гонка OTP/TOTP/recovery/session issuance | PG row locks, conditional consume та один commit, ідемпотентність; SQLite не доводить PostgreSQL concurrency |
| Email takeover через disable/recovery/email change | Fresh primary+factor, Policy, аудит, auth_version; захищений admin recovery ще не реалізований |
| Прямий Livewire POST зі старим snapshot | Persistent gate + version/Policy на діях; один real HTTP update перевірено, не всі Filament resources/actions/uploads |
| Cookies Path, CORS, XSS, stolen session, SW/offline | Два session-контексти, browser E2E, CSP, no-store; HttpOnly не зупиняє дії через XSS; cookie flags у PHP перевірено, браузерна поведінка ні |
| Store outage, clock skew, абсолютний/idle timeout | Fail closed, NTP, явні TTL, fault-injection; pending expiry/version перевірено, distributed outages/idle browser expiry ні |
| Перехідні/unverified emails та NULL-картки | Контрольований preflight/identity proof, без auto-merge; реєстрація й прив'язки не входять у цей стенд |

Немає mail delivery/anti-enumeration/rate-limit/load тестів, PWA E2E, Redis/PG
multi-process тестів або production secret-management drill. Усі TTL/коди/ролі
стенда — фікстури, не нові затверджені бізнес-значення. Онлайн Composer install
та security advisory audit залишаються непідтвердженими.

## 6. Які рішення P1–P6 тепер можна запропонувати до затвердження

| ID | Конкретна пропозиція після spike | Що ще відкрите |
|---|---|---|
| P1 | Additive model §3 дизайну: BIGINT user FK, один confirmed encrypted TOTP factor/user, durable last_used_step, окремі hashed recovery rows, server flows/challenges/assurance + auth_version та audit | Точний DDL/checks/indexes, email preflight/backfill, retention і target NULL-card policy; ще не затверджено/не створено |
| P2 | Same-origin browser `/api` subgroup, штатний `client` session guard без Sanctum/Fortify; окремий admin guard/cookie-контекст, version/MFA gate | Погодити stateful виняток, cookie/Livewire routing і email normalization; dual-cookie E2E потрібен |
| P3 | Зберегти явні TTL/budgets із §10 як кандидат на operational review, без самовільного auto-expiry invitation | Spike не доводить придатність чисел для доставки/навантаження; підтвердити канал передачі URL |
| P4 | Admin password-primary + required app MFA; shared FactorService з адаптером Filament, без другого секрету та без Fortify pipeline | Погодити admin primary, pending enrollment/step-up та cookie route уточнення; реалізувати stamp/gates/rollout |
| P5 | Окремий audited recovery з version bump; ніколи email-only reset MFA | Процедура незалежної перевірки, два admin і останній admin залишаються продуктовими/організаційними блокерами |
| P6 | Shared durable session/rate/factor state, encrypted jobs, NTP, no-secret logs, backup/restore/retention | Перевірити mail/worker/locks/хостинг/failure modes; цей Cloud не є hosting certification |

У P1 не дублювати native Filament `app_authentication_secret` у users паралельно
`user_totp_factors`. `auth_session_states` може бути окремою серверною таблицею,
але FK/cleanup треба узгодити з ротацією sessions.id. Запис session payload і
assurance після auth-транзакції має бути fail-closed при частковому збої;
не видавати cookie, яка авторизує без committed state. Для факторів потрібні
pending/confirmed розділення, key version та заборона заміни активного секрету
незавершеним enrollment. Схема є пропозицією; SQL/DBML/історичні міграції незмінні.

## 7. Точні вимоги до наступного AUTH-2

1. Перед schema implementation явно погодити P1 та потрібні частини P2/P4:
   source-of-truth DDL, email normalization/старі unverified адреси, dual-cookie
   routing, єдине factor/replay storage. A1–A8 не відкривати повторно.
   P3/P5/P6 розглядати до відповідного rollout, не позначати затвердженими.
2. Підготувати **additive** schema PR: оновити Entity Catalog/DBML/SQL узгоджено
   з майбутніми міграціями, не переписувати історичні Laravel-міграції або чинні
   бізнес-модулі без окремого погодження. Зберегти G3/R1 BIGINT users.id,
   nullable password, clients.user_id nullable UNIQUE та чинні дані/ролі/статуси.
3. Read-only preflight на дозволеному середовищі: normalized email collisions,
   nullable/unverified addresses, чинні admin і NULL-картки; звіт без PII.
   Collision/unverified provenance не виправляти auto-merge чи auto-verify.
   На цьому AUTH-1 реальні дані не читалися; backfill перевіряти спочатку на фікстурах.
4. Перевірити fresh **і populated synthetic PostgreSQL 18**, створений тільки для
   AUTH-2 з гарантованим cleanup: FK/check/unique, normalized email collisions,
   additive backfill, збереження користувачів/профілів, захищений destructive down,
   rotation/cleanup server session states. Основних БД не торкатися.
5. Довести транзакційну основу на двох незалежних PG connections/processes:
   одночасне consume challenge/recovery, accepted step між каналами, generation,
   revoke/version vs живий request; рівно один переможець, без другого session grant.
   У AUTH-1 перевірені тільки послідовні запити. Рішення row-lock order/indices
   та fail-closed partial writes зафіксувати в schema/service contract.
6. Запустити Stage 1/2 PG regression і основний PHPUnit на новому PG fixture;
   не видавати історичні 76 PASS за новий результат. Додати regression для
   BIGINT усіх нових FK, захованих секретів і недопустимих state transitions.
7. Не додавати public auth endpoints, новий Filament login або залежності як
   побічний ефект AUTH-2. Якщо після review все ж обрано Sanctum/Fortify,
   спершу повторити online resolution/install/audit у тимчасовому середовищі;
   поточний мережевий блокер і неповний Fortify runtime закрити доказами.
   При рекомендованому варіанті без них основний lock не потребує зміни.

AUTH-3 має завершити shared enrollment/recovery/assurance adapter і admin rollout;
AUTH-4 — invitation/OTP/stateful API; AUTH-5 — React і optional client MFA.
Ці етапи залишаються одним найближчим релізним набором: spike не відкриває
реєстрацію й не переносить обов'язкову MFA на після production запуску.

Файли цього PR (повний перелік):

- `docs/auth-compatibility-spike.md`
- `docs/roadmap.md`
- `docs/project-status.md`
- `experiments/auth1/README.md`
- `experiments/auth1/Fixture.php`
- `experiments/auth1/SpikeTest.php`
- `experiments/auth1/bootstrap.php`
- `experiments/auth1/fetch-upstream.py`
- `experiments/auth1/manifests.json`
- `experiments/auth1/resolve.py`
- `experiments/auth1/results.txt`
- `experiments/auth1/run.sh`
- `experiments/auth1/sources.json`
