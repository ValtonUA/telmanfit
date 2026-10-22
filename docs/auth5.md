# AUTH-5 — React PWA та безпека клієнтського акаунта

Дата: 22.10.2026. База — GitHub master `57b257f0d9ab293bb5bcb4b601762ba65cc99a64`
після merge PR #12. Початковий checkout був чистим, але застарілим (`5506358`).
Master перевірено через GitHub API та Git. Перед змінами відновлено точні blobs,
trees і підписаний commit через GitHub connector, звірено хеші; після надання
мережевого доступу завантажено повну історію `git fetch --refetch`. Окрема гілка
`codex/auth5-client-security`, один Draft PR до master, без merge.

P1/P2/P3/P4 та A1–A8 збережено. P5/P6 відкриті. Реалізація призначена виключно для
контрольованого local/testing. Успіх synthetic acceptance не є дозволом rollout.

## Погоджена additive-зміна схеми

Під час реалізації виявлено блокер: FK recovery → factor `ON DELETE RESTRICT`,
CHECK на confirmed/ciphertext та durable watermark не дозволяли вимкнути фактор
зі збереженням історії. Користувач **окремо погодив** nullable
`user_totp_factors.disabled_at`, нову additive-міграцію й узгодження SQL/DBML/EC.
Жодної іншої структурної зміни не додано. Історичні міграції, AUTH-2 SQL-частини
та бізнес-модулі 01–04 незмінні.

Нова міграція `2026_10_22_001000_add_disabled_at_to_user_totp_factors.php` застосовує
`database/sql/auth5/00_disabled_factor.sql`. `docs/05_auth_schema.sql` зберігає
початковий AUTH-2 DDL і додає той самий ALTER-блок. Активність визначається тільки
`confirmed_at IS NOT NULL AND disabled_at IS NULL`, включно з admin assurance,
client login/session, restricted MFA, recovery, enrollment та API-профілем.

Вимкнення після нового OTP + чинного TOTP/recovery: під user/factor locks
відкликаються невикористані коди, очищаються ciphertext/key та pending material,
обнуляється активний recovery batch, записується disabled_at; confirmed_at,
last_used_step, used/revoked codes та append-only audit залишаються. Атомарно
зростає auth_version, відкликаються sessions/flows/challenges, пишеться аудит.
Admin disable заборонений. Старий секрет не відновлюється.

Повторне підключення використовує той самий рядок історії, новий random pending
secret, прив'язаний до нового enrollment flow, та код із `step > last_used_step`.
Лише підтвердження нового секрету очищає disabled_at та встановлює новий batch.
Нові CHECK/trigger не дозволяють повернути ciphertext у disabled стан, обнулити
watermark або активувати disabled рядок без нового pending матеріалу й нового step.
Empty down відновлює попередні CHECK; down при будь-якій factor history відмовляє.
Потрібен forward fix або окремо погоджений план, без видалення auth-історії.

## Реалізований React

- `/app/register#invite=…`: fragment зчитується один раз до React mount і відразу
  прибирається через replaceState, також коли нове посилання відкрито у вже
  завантаженій SPA через hash navigation. Token обмінюється в POST body, надалі є лише
  browser-bound flow. Email → OTP → ім'я → pending-кабінет. Missing/revoked/consumed/
  expired invitation мають зрозумілий стан. Парольних полів немає.
- `/app/login`: email OTP, expiry/error, resend з cooldown, restricted TOTP або
  recovery після OTP, повна session лише після потрібних факторів.
- `/app`: справжнє users.name, поточний clients.status, безпека та вихід. Pending
  явно означає, що платний супровід не активовано. Раціони/метрики не вигадуються.
- `/app/security`: нове підтвердження дії, QR/manual enrollment, одноразовий показ
  recovery batch, regeneration, replacement, disable/re-enrollment, opaque own
  session handles/revoke/logout-all, підтвердження нової адреси.

React 19/TypeScript/Vite без нових залежностей, router/UI framework. Українські
тексти, labels, keyboard focus, live errors/status, mobile 320/390 та desktop.
Manifest та локальна SVG-іконка дають standalone PWA shell. **Offline авторизації
немає**: service worker не встановлюється, API/QR/codes не потрапляють у CacheStorage.
Немає localStorage/sessionStorage, аналітики або сторонніх ресурсів у клієнтській
SPA. У спільному браузері Filament може зберігати несекретні UI preferences;
E2E перевіряє відсутність секретів, а не помилково вимагає порожній storage admin UI.

Форми очищають OTP після кожного submit; enrollment/recovery material видаляється
після підтвердження/acknowledgment/cancel, при pagehide та приховуванні вкладки.
Секрети показуються тільки на явному одноразовому POST-кроці. Немає повторного
GET секрету чи старих кодів. Резервні коди треба зберегти поза застосунком.

## API та межі повноважень

Усі нові маршрути — `/api/auth/security/…`, штатні Laravel web/session/CSRF та
ClientAuthBoundary. Чинні AUTH-4 контракти реєстрації/входу збережено.

| Метод | Суфікс | Контракт |
|---|---|---|
| POST | `reauth/request` | action із whitelist, новий OTP тільки на поточний verified email; 202 |
| POST | `reauth/verify` | challenge_id + email code; restricted MFA або authorized action handle |
| POST | `reauth/mfa` | flow_id, method totp/recovery_code, code; чинний фактор через MfaService |
| POST | `mfa/enroll` | proof_id + replace boolean; одноразові flow_id, manual secret та локальний QR |
| POST | `mfa/confirm` | enrollment flow_id + новий TOTP; одноразовий recovery batch, усі сесії відкликано |
| POST | `mfa/recovery` | fresh proof_id для rotate_recovery; новий batch, старі коди відкликано |
| POST | `mfa/disable` | fresh proof_id для disable_factor; 204, history retained, усі сесії відкликано |
| GET | `sessions` | власні current/time/opaque handles; без session IDs, IP або UA |
| POST | `sessions/revoke` | fresh proof_id + власний handle; чужий/старий handle — 403 |
| POST | `logout-all` | fresh proof_id; version bump та атомарне відкликання, 204 |
| POST | `email/request` | fresh proof_id + новий email; для resend flow_id + та сама адреса |
| POST | `email/confirm` | challenge_id + OTP нової адреси; UNIQUE/canonical checks, revoke-all, 204 |

`ClientAuth` повторно використовує одну OTP/HMAC/generation/queue/budget реалізацію
для reauth та email_change. З HTTP неможливо передати user ID, role, session hash
або server context. Загальний AUTH-4 request endpoint приймає лише register/login.
Невдалі OTP/MFA спроби комітять лічильники, а помилка відповіді не відновлює код.
Немає другого MFA-сховища чи другої TOTP-криптографії.

Action proof прив'язаний до user/version/browser/session/action і діє не довше
первісного 5-хвилинного flow. Випадковий proof material залишається **тільки у
серверній encrypted session**, у PG — capability hash; React отримує public UUID,
який сам по собі не авторизує дію. Proof перевіряється і витрачається під user/flow
locks в одній транзакції з мутацією. Старий login MFA timestamp не замінює fresh
OTP та поточний MFA. Recovery у reauth також збільшує version і відкликає інші сесії в одній
транзакції з витрачанням коду. Argon2id regeneration готується поза user/global
locks; перед install proof повторно перевіряється.

Step-up обертає session ID/CSRF зі збереженням початкового absolute expiry.
Client idle 12 h/absolute 7 d та admin idle 15 min/absolute 8 h не подовжуються.
Після rotation React виконує CSRF bootstrap. При 419 оновлюється лише bootstrap;
чутлива мутація автоматично не повторюється — потрібна явна дія користувача.

Власні session handles — HMAC(user/version/session ID) зі стабільним server rate
key. Чужі sessions не перелічуються і не відкликаються. Final MFA mutation повторно
перевіряє живу session assurance під user lock: revoke між HTTP precheck та hash/
TOTP verification не дає завершити enrollment. Операції стенда зберігають bounded
AUTH-4 PG advisory lock; production throughput/lock sharding не заявляється.

Email change перевіряє первісну session/version, OTP нової адреси, UNIQUE canonical
key та історичні email без canonical key. Жодного merge, role change чи переприв'язки
clients. Після commit на стару адресу ставиться encrypted synthetic security notice
без секретів. Збій повідомлення не відкочує захисну зміну; повідомлення про збій
пишеться без payload. Production retry/observability — AUTH-6/P6.

## Секрети, помилки та default OFF

`AUTH5_CONTROLLED=false` за замовчуванням. Нові security endpoints повертають 404,
доки прапорець явно не увімкнений. Навіть увімкнений AUTH-5 потребує AUTH-4 + AUTH-3,
local/testing, PostgreSQL/database session/cache, дозволеного synthetic transport,
чинних ключів/параметрів. Некоректні прапорці не вмикають auth. Production відмовляє,
немає fallback до email-only для активного MFA або password-only admin.

Client cookies збережено: scoped HttpOnly session `/api`, readable encrypted XSRF
cookie `/`; admin cookie `/admin` незалежна. Client session payload тепер encrypted
at rest. Невідомі/прострочені/використані flows не видають сесію. Недоступність БД,
codec, MFA чи central rate store закриває запити. 401/403/419/422/429 зберігають
звичайний зміст; внутрішні збої дають sanitized 503 без SQL bindings або stack trace.

`/api/me`, session list та звичайний auth JSON не містять OTP/TOTP secret/recovery.
Виняток — **навмисна одноразова** POST-видача enrollment/recovery для поточного
користувача після fresh proof, з no-store/no-referrer. QR генерується локально
Google2FAQRCode/Chillerlan із чинних Composer-залежностей; немає запиту до зовнішнього QR service. Профіль віддає тільки число залишків
recovery codes та безсекретну ознаку використання recovery; кодів або selectors
у ньому немає. Після recovery-входу React показує попередження без вимкнення MFA.
Secret/QR не передаються в навігаційний URL, downloads, analytics, логи чи storage.
SPA entry роздається з CSP/no-store/no-referrer; PHP dev router та Apache rewrite
проводять також `/app`, `/app/`, `/app/index.html` через Laravel.

## Відтворення та докази

```bash
npm run lint --prefix client
npm run build --prefix client
NODE_USE_ENV_PROXY=1 npm run build
AUTH3_BROWSER=1 AUTH4_BROWSER=1 AUTH5_BROWSER=1 bash tests/pg/run-stage1.sh
bash tests/pg/test-runner.sh
bash tests/pg/run-isolated-pg.sh
bash experiments/auth1/run.sh
# Цільовий прогін на іншій новій ізольованій PG18:
AUTH5_ONLY=1 AUTH5_BROWSER=1 bash tests/pg/run-stage1.sh
```

Runner створює тільки новий pinned PostgreSQL 18.6 з network=none, tmpfs, приватним
Unix socket і непривілейованим test runner. Не читає telmanfit_dev/production.
Нову міграцію застосовано виключно до тимчасових БД. Synthetic delivery — той самий
AUTH-4 encrypted database queue/mailbox @example.invalid; реального SMTP немає.

Chromium працює зі **справжньою React-збіркою та CSP**, без injected HTML чи API
підмін для успішних сценаріїв. Перевірка UI 419 навмисно ін'єктує одну відмову;
окрема справжня POST-відмова без CSRF теж перевірена. Registration resend очікує
реальні 60 секунд. Для численних незалежних security-сценаріїв fixture моделює
плин delivery rate windows між діями, очищаючи тільки відомі synthetic account/IP
ключі. Самі TTL/ліміти/expiry/attempts окремо перевіряються PHPUnit та PG races;
така прискорена fixture не є production clock або bypass endpoint.

Legacy AUTH-4 inline HTTP harness ізольований від React bundle й запускається з
`bypass_csp=True`; це збережена HTTP-регресія, **не** перевірка CSP. CSP/реальний
React acceptance належать AUTH-5, де bypass вимкнений. AUTH-3 використовує справжній
Filament. Обидва browser contexts/cookies перевіряються також у новому E2E.

Конкурентні AUTH-5 сценарії запускають два PHP processes/PG PID та доводять обидва
Lock waits перед зняттям barrier: action proof/enrollment, logout-all, new email OTP,
disable та re-enrollment confirmation. Рівно один успіх/version bump/activation.
Є upgrade/down та catalog snapshot, негативні SQL CHECK/trigger, watermark,
recovery, email conflict/rollback, session ownership/version/expiry та fail-closed.

Фактичні результати: [checks.json](evidence/auth5/checks.json),
[Chromium results](evidence/auth5/results.json). Масковані скриншоти —
`docs/evidence/auth5/*-{320,390,1440}.png`; поля/QR/manual key/recovery маскуються
під час capture. Немає HAR, trace або DOM dumps. CI результат завжди належить
конкретному head SHA; локальний PASS не замінює GitHub Actions.

## AUTH-6 перед безпечним production rollout

1. Погодити P5: незалежна identity verification, чужий/останній admin recovery,
   уповноважені особи, dual control та recovery drill. Цих процедур тут немає.
2. Погодити P6: production SMTP, однакова fail-closed поведінка реальної queue/
   provider, security notices/retry/monitoring, статистичний timing та load аудит.
3. Production keyring/version rotation, backup/restore drill ключів і encrypted
   DB/queue/session, NTP, least-privilege grants/append-only audit, узгоджена retention
   та cleanup expired challenges/flows/pending material. Жодної автоматичної retention
   або доступу до production secrets цим PR не додано.
4. Перевірити цільовий hosting, PostgreSQL extensions, cron/workers, HTTPS/Secure/
   HSTS/CSP/no-store/reverse proxy, session durability і race/load профіль. Окремо
   перевірити роздачу SPA entry на конкретному web server (Apache rule є; nginx тут
   не розгортався). Long-lived HTTP workers та масштабування global auth lock не
   заявляються готовими до production.
5. Read-only live email preflight, окремо погоджений collision/unverified review та
   backfill, plan міграції й forward fix, bootstrap усіх admin MFA без блокування
   останнього, review-controlled rollout/rollback та фінальний security acceptance.

Telegram, Passkeys, анкета, харчування/прогрес/тренування, admin recovery, SMTP,
production keys/backup/retention/activation та merge свідомо не реалізовані.

## Фактичні локальні результати

| Набір | Результат |
|---|---|
| SQL на Laravel PG18 / окрема еталонна PG18 | кожен 76 PASS / 0 FAIL / 0 GAP |
| Stage 1/2 PHPUnit | 41 tests / 713 assertions |
| Основний PHPUnit | 22 tests / 46 assertions |
| AUTH-2 schema/catalog/upgrade/down | 12 tests / 131 assertions; upgrade/down та 14 races PASS |
| AUTH-3 | 25 tests / 129 assertions; service races та Chromium PASS |
| AUTH-4 | 24 tests / 152 assertions; 5 service races та Chromium PASS |
| AUTH-5 | 16 tests / 116 assertions; 5 service race scenarios PASS |
| AUTH-1 | 15 tests / 93 assertions |
| SQL runner contracts | 10 очікуваних exit codes PASS |
| Pint / frontend ESLint / React build / Laravel assets | PASS |

Chromium AUTH-5 результат та точний список сценаріїв — у `evidence/auth5/results.json`;
GitHub Actions перевіряється для опублікованого PR head окремо. Усі локальні команди
завершилися на нових ізольованих тестових ресурсах, які очищаються runner trap.

## Змінені та створені файли

- `.github/workflows/stage1.yml`
- `AGENTS.md`
- `app/Http/Controllers/Api/ClientAuthController.php`
- `app/Http/Controllers/Api/ClientSecurityController.php`
- `app/Http/Controllers/Auth/AdminMfaController.php`
- `app/Http/Middleware/ClientAuthBoundary.php`
- `app/Jobs/DeliverSecurityNotice.php`
- `app/Mail/SecurityNotice.php`
- `app/Services/Auth/AdminAssurance.php`
- `app/Services/Auth/Client/ClientAuth.php`
- `app/Services/Auth/Client/ClientSecurity.php`
- `app/Services/Auth/Client/ClientSession.php`
- `app/Services/Auth/Client/ControlledAuth.php`
- `app/Services/Auth/MfaPolicy.php`
- `app/Services/Auth/MfaService.php`
- `client/index.html`
- `client/public/icon.svg`
- `client/public/manifest.webmanifest`
- `client/src/App.css`
- `client/src/App.tsx`
- `client/src/api.ts`
- `client/src/components/AuthForm.tsx`
- `client/src/components/SecurityPanel.tsx`
- `client/src/index.css`
- `client/src/useCooldown.ts`
- `config/auth5.php`
- `database/migrations/2026_10_22_001000_add_disabled_at_to_user_totp_factors.php`
- `database/sql/auth5/00_disabled_factor.sql`
- `docs/05_auth_schema.sql`
- `docs/architecture.md`
- `docs/auth-architecture.md`
- `docs/auth-schema.md`
- `docs/auth5.md`
- `docs/database.dbml`
- `docs/entity-catalog.md`
- `docs/evidence/auth5/01-register-1440.png`
- `docs/evidence/auth5/01-register-320.png`
- `docs/evidence/auth5/01-register-390.png`
- `docs/evidence/auth5/02-email-otp-1440.png`
- `docs/evidence/auth5/02-email-otp-320.png`
- `docs/evidence/auth5/02-email-otp-390.png`
- `docs/evidence/auth5/03-dashboard-pending-1440.png`
- `docs/evidence/auth5/03-dashboard-pending-320.png`
- `docs/evidence/auth5/03-dashboard-pending-390.png`
- `docs/evidence/auth5/04-security-1440.png`
- `docs/evidence/auth5/04-security-320.png`
- `docs/evidence/auth5/04-security-390.png`
- `docs/evidence/auth5/05-enrollment-masked-1440.png`
- `docs/evidence/auth5/05-enrollment-masked-320.png`
- `docs/evidence/auth5/05-enrollment-masked-390.png`
- `docs/evidence/auth5/06-recovery-masked-1440.png`
- `docs/evidence/auth5/06-recovery-masked-320.png`
- `docs/evidence/auth5/06-recovery-masked-390.png`
- `docs/evidence/auth5/07-email-change-1440.png`
- `docs/evidence/auth5/07-email-change-320.png`
- `docs/evidence/auth5/07-email-change-390.png`
- `docs/evidence/auth5/08-unavailable-invitation-1440.png`
- `docs/evidence/auth5/08-unavailable-invitation-320.png`
- `docs/evidence/auth5/08-unavailable-invitation-390.png`
- `docs/evidence/auth5/checks.json`
- `docs/evidence/auth5/results.json`
- `docs/project-status.md`
- `docs/roadmap.md`
- `phpunit.auth5.xml`
- `public/.htaccess`
- `routes/api.php`
- `routes/web.php`
- `server.php`
- `tests/Auth5/ClientSecurityTest.php`
- `tests/Auth5/FactorMigrationTest.php`
- `tests/AuthPostgres/AuthSchemaTest.php`
- `tests/browser/auth4.py`
- `tests/browser/auth5-fixture.php`
- `tests/browser/auth5-in-context.sh`
- `tests/browser/auth5.py`
- `tests/pg/auth2-upgrade.php`
- `tests/pg/auth5-concurrency.php`
- `tests/pg/run-stage1.sh`
