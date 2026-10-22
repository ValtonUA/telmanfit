# AUTH-6B / B1 — дефекти авторизації та HTTP-сесії

Дата: 22.10.2026. База `6536611a2fc982f50d638891ebaa804d83168968` — master
після підтвердженого merge AUTH-6A PR #14. Один Draft PR до master, без merge.
P5/P6 та production rollout залишаються відкритими. Усі gates default OFF.
SQL/DBML, міграції, залежності й реальні БД/акаунти/секрети не змінено.

## D1 — login flow не розкриває придатність адреси через 202/422

`ClientAuth::request` перетворює лише domain-відмову 422 при перевірці login
resend flow на нейтральну відповідь. Infrastructure faults не маскуються як успіх.
Missing/foreign/other-user/expired/used/invalidated/version-stale/wrong-purpose/
wrong-audience UUID дають однакові 202, поля `challenge_id`, `expires_in=300`,
`resend_after=60` для known/unknown/admin/unverified email. Decoy UUID не має
рядка challenge, не дозволяє verify чи session issuance; enqueue шлях спільний.
Malformed UUID однаково дає validation 422. Suppression також повертає 202;
спільний IP budget дає 429 з Retry-After; queue/rate-store outage — sanitized 503.

Доказ: `tests/Auth6/DefectsTest.php`, 36 комбінацій email/flow, перевірка відсутності
challenge authority, headers, suppression, лімітів, queue та cache faults.
Чинні реєстрація/resend/verify залишаються в AUTH-4 regression. Це виправлення
статусу/контракту D1; statistical timing equivalence не доведено (R2 у AUTH-6A).

## D2 — спільний session GC враховує audience

Database driver використовує `ContextDatabaseSessionHandler`. GC незалежно від
request lifetime видаляє assured admin з idle ≥900 s і всі sessions з idle
≥max(43200 s, переданий lifetime). Guest/restricted/legacy без assurance мають
консервативний retention ≥12 год: без схеми для audience безпечніше утримати рядок.
Це retention, не дозвіл входу: читання payload та assurance зберігають admin idle
15 min / absolute 8 h і client idle 12 h / absolute 7 d. Lottery лишається чинною;
окремої scheduled retention/P6 не додано.

Доказ: forced GC з client/admin/guest/restricted/expired-client у PostgreSQL 18;
client idle 16 min лишається читабельним, admin видаляється, client idle >12 h
видаляється разом із FK assurance. Додатковий AUTH-5 тест використовує справжню
client issuance та перевіряє живий доступ після admin GC і відмову після client TTL.

## D3 — notice failure має outcome, subject і correlation

`client.email_changed` і `client.notice_failed` мають один UUID `request_id` та
subject користувача. `AuditOutcome` обмежує outcome; failure записується як `error`.
Encrypted notice job переносить correlation/subject у worker. Transport failure
записується перед sanitized `NoticeDeliveryFailed` без previous exception; вихідний
SMTP exception не потрапляє у цей failure exception/failed-job trace.

Збій enqueue також записує failure. Незалежний `Log::error` містить лише event,
request UUID, subject ID та стан audit; жодного email, OTP, exception чи payload.
Якщо audit недоступний, цей канал лишає спостереження `audit=failed`.
Повідомлення виконується після identity commit; email/version/revocations не
відкочуються. За доступного logger збій notice/audit не змінює успішну відповідь.

Доказ: AUTH-5 fault injection enqueue й audit; AUTH-6 encrypted queue → dequeue →
transport exception із canary. Перевірено `outcome=error`, однаковий request UUID,
subject, збережений email/revoke, redaction і відсутність previous exception.
Це не durable outbox: crash між commit та enqueue, decrypt/worker crash до handle,
реальний SMTP і одночасна недоступність audit/logger лишаються для B4/P6.

## R1 — підтверджена HTTP race та виправлення

До зміни session middleware два справжні HTTP servers з однією encrypted cookie
відтворили: GET зупинений після controller до session save → reauth verify обертає
session → пізня GET-відповідь встановлює стару cookie → наступний `/api/me` =401.
Bypass авторизації не встановлено. Доказ збережено в `evidence/auth6/baseline.json`.

`SerializedAuthSession` бере PostgreSQL session advisory lock до читання payload
і тримає до завершення domain operation/session save. Lock order: session →
наявні domain locks; очікування максимум 5 s, timeout → sanitized 503/Retry-After=1.
Release — у finally, без lease, яка могла б спливти посеред запиту. Middleware
підключено і до web group, і до явного списку Filament; business exceptions із
`$next` не підмінюються session catch. Знищений ID не відновлює session/assurance
і повертає client 401 / admin 403 без Set-Cookie. CSRF bootstrap та restricted admin login дозволяють
почати новий вхід після втрати/GC cookie.

Відповіді з незміненим session ID не встановлюють session cookie повторно. Тому
їх пізній прихід не замінює rotated cookie. Cookie envelope задається до absolute
expiry assurance (для guest — максимум audience); idle TTL перевіряється сервером,
не refresh cookie. `expire_on_close`, Secure, HttpOnly, Path, SameSite збережено.
Не-CSRF відповіді зі старим ID також не перевстановлюють XSRF cookie. Після rotation
SPA виконує чинний CSRF bootstrap; чутлива мутація після 419 автоматично не повторюється.
Admin session payload примусово encrypted; client encryption збережено.
Після rotation паралельна мутація зі знищеним ID відхиляється: це не успішний
logout нової сесії. Для неї потрібна явна нова дія з актуальною cookie та CSRF.

Доказ: `tests/http/auth6-in-context.sh`, два незалежні PHP HTTP servers/PG PIDs,
справжні cookies та CSRF. Barrier після controller/перед save; PG `wait_event_type=Lock`
до release; read-first/mutation-first та обидва порядки Set-Cookie. Перевірені
client reauth, rotation, email state, revoke, logout-all/logout, admin logout,
прямі гонки rotation/reauth/email/revoke проти logout в обох порядках,
parallel session save (обидва записи збережені), bounded timeout та release після
CSRF exception. Fixtures/barriers існують лише у test router, production routes
не додано. Структурований результат — `evidence/auth6/http.json`.

## Відтворення та результати

```bash
AUTH3_BROWSER=1 AUTH4_BROWSER=1 AUTH5_BROWSER=1 bash tests/pg/run-stage1.sh
AUTH6_ONLY=1 bash tests/pg/run-stage1.sh
bash tests/pg/test-runner.sh
bash tests/pg/run-isolated-pg.sh
bash experiments/auth1/run.sh
vendor/bin/pint --test
npm run lint --prefix client
npm run build --prefix client
NODE_USE_ENV_PROXY=1 npm run build
```

Runner guard допускає лише новий PG18 container з tmpfs, network=none, без ports,
приватним Unix socket та непривілейованим test runner. Ключі випадкові/synthetic.
Cleanup видаляє тільки ресурси цього прогону. Fault triggers — лише на тимчасовій БД.
Фінальні числа й browser-результати: [checks.json](evidence/auth6/checks.json).

| Набір | Результат |
|---|---|
| Stage 1/2 + базовий PHPUnit | 41/713 + 22/46 tests/assertions |
| AUTH-1 / AUTH-2 / AUTH-3 | 15/93 + 12/131 + 25/129 |
| AUTH-4 / AUTH-5 / AUTH-6B B1 | 24/152 + 19/132 + 5/359 |
| Усього PHPUnit | **163 tests / 1755 assertions, PASS** |
| SQL Laravel / еталонний PG18 | кожен 76 PASS / 0 FAIL / 0 GAP |
| Service concurrency AUTH-2–5 | 14 + 3 + 5 + 5 сценаріїв PASS |
| Повні паралельні HTTP | 25 сценаріїв PASS |
| Chromium / Livewire / React | PASS, Chromium 154.0.8037.57 |
| Pint / PHP syntax / ESLint / дві збірки | PASS |
| Fail-closed контракти SQL runner | 10 очікуваних exit codes PASS |
GitHub Actions перевіряється окремо для опублікованого head SHA.

## Межі та залишкові ризики

Session advisory locks потребують одного PostgreSQL connection протягом request;
transaction-pooling PgBouncer та persistent HTTP workers цим не сертифіковано.
Розрив DB connection закриває операцію; failover/load/два app hosts — B6.
Новий encryption format вимагає нового входу для старих plaintext admin sessions
у controlled стенді; live-конвертації чи production activation немає.
R2–R6, durable outbox, P5 recovery, P6 retention/keys/backup/provider acceptance
залишаються відкритими. Одночасний збій audit/logger не дає гарантії спостереження.
Статус green synthetic tests не означає production readiness.

## Змінені та створені файли

- `.github/workflows/stage1.yml`
- `app/Http/Middleware/AdminSessionContext.php`
- `app/Http/Middleware/SerializedAuthSession.php`
- `app/Jobs/DeliverSecurityNotice.php`
- `app/Providers/AppServiceProvider.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `app/Services/Auth/Client/ClientAuth.php`
- `app/Services/Auth/Client/ClientSecurity.php`
- `app/Services/Auth/Client/ControlledAuth.php`
- `app/Services/Auth/Client/NoticeDeliveryFailed.php`
- `app/Services/Auth/Client/NoticeFailure.php`
- `app/Support/Auth/AuditOutcome.php`
- `app/Support/Session/ContextDatabaseSessionHandler.php`
- `bootstrap/app.php`
- `docs/auth6-b1.md`
- `docs/evidence/auth6/baseline.json`
- `docs/evidence/auth6/browser3.json`
- `docs/evidence/auth6/browser4.json`
- `docs/evidence/auth6/browser5.json`
- `docs/evidence/auth6/checks.json`
- `docs/evidence/auth6/http.json`
- `docs/project-status.md`
- `docs/roadmap.md`
- `phpunit.auth6.xml`
- `tests/Auth5/ClientSecurityTest.php`
- `tests/Auth6/DefectsTest.php`
- `tests/browser/auth3.py`
- `tests/http/auth6-fixture.php`
- `tests/http/auth6-in-context.sh`
- `tests/http/auth6-router.php`
- `tests/http/auth6.py`
- `tests/http/barrier.php`
- `tests/pg/run-stage1.sh`
