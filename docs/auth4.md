# AUTH-4 — запрошення, реєстрація та passwordless email OTP

Дата: 22.10.2026. База: GitHub master `33494b1ef033fb28e4cd27a34b362f8d73a8191e`
після merge PR #11. Початковий checkout був чистий; remote SHA перевірено через
Git та GitHub API. Окрема гілка; один Draft PR до master, без merge.

P3 затверджено прямою задачею: ASCII lowercase + trim + IDNA, invitation 256 біт
без expiry, OTP 8 цифр / 300 s / 5 failures, cooldown 60 s, одночасні email/purpose,
aggregate email та IP limits; client idle 12 h / absolute 7 d, admin idle 15 min /
absolute 8 h; registration 15 min та максимум 5 min після OTP. P5/P6 відкриті.
Нижчі історичні згадки про непогоджений P3 у документах AUTH-0/2/3 не скасовують
цього рішення. SQL, DBML, історичні міграції та бізнес-схему не змінено.

## Фактична реалізація

| Метод | Маршрут | Поведінка |
|---|---|---|
| GET | `/api/auth/csrf` | 204, bootstrap session та readable `TELMANFIT-CLIENT-XSRF` |
| POST | `/api/auth/invitations/exchange` | Token у body; restricted browser-bound flow; не consume |
| POST | `/api/auth/email-otp/request` | purpose register/login, email, optional flow; 202 із opaque challenge UUID |
| POST | `/api/auth/email-otp/verify` | Одноразовий OTP; next registration_complete / mfa_required / authenticated |
| POST | `/api/auth/registration/complete` | Flow і name; 201 та client session після commit, conflict 409 без identity |
| POST | `/api/auth/mfa/verify` | Той самий AUTH-3 MfaService, totp або recovery_code; без дублювання secret |
| GET | `/api/me` | Лише повна client assurance; BIGINT IDs як рядки, name/status/security без email/секретів |
| POST | `/api/auth/logout` | Restricted/full client logout; invalidation flows, session/CSRF rotation |

Health залишається stateless/public. Нові API мають явну Laravel web session/CSRF
підгрупу, без Sanctum/Fortify. Додатковий `ClientCsrf` вимагає token навіть коли
Laravel 13 дозволив би same-origin origin shortcut. Header: `X-XSRF-TOKEN` із
URL-decoded encrypted `TELMANFIT-CLIENT-XSRF`; bootstrap і після rotation оновлюють
cookie. Це readable host-only cookie з Path=/, щоб SPA /app могла її читати;
повноваження в ній відсутні. Session cookie — `telmanfit_client_session`, HttpOnly,
host-only, SameSite=Lax, Path=/api. Admin має окремий cookie/guard Path=/admin.
Secure визначається session config контрольованого стенда; HTTPS rollout — P6.

Global boundary задає контекст до StartSession, повертає no-store/no-referrer,
не допускає SQL exceptions/bindings у JSON/logger. Form errors — 422; невалідний,
чужий, прострочений або повторний OTP — invalid_challenge; pending MFA/guest — 401.
Після кінцевого primary/MFA commit видається одноразовий внутрішній proof із
WeakMap provenance, потім повторна user/version/flow/factor перевірка та атомарні
Laravel database session + assurance. Старий ID знищується, CSRF/binding змінюються.
Збій issuance після registration commit потребує звичайного email OTP login;
запрошення не повертається в active.

### Filament

Сторінка `/admin/invitations`: перегляд останніх 100 записів, coach, date та
active/consumed/revoked status; явний вибір coach/admin без default. Створення та
відкликання — native POST форми в Filament із CSRF та чинною admin assurance,
новим password primary + TOTP для конкретної операції. Password hashing виконується
перед бізнес-gate lock; opaque request-memory proof походить лише від MfaService,
а під user lock повторно перевіряється незмінний password hash. Step-up має
додаткові shared limits 5/15 min на user і 20/15 min на IP. Внутрішній proof не
видається браузеру, використовується один раз і перевіряє version/freshness ≤5 min.
Жодної привілейованої дії лише на основі старого session MFA timestamp.

`POST /admin/auth/invitations/create` показує URL **лише в одноразовій HTML POST
відповіді**, не в Livewire state/download JSON, session/flash чи notification.
JSON Accept відхиляється 406. URL `/app/register#invite=<64 hex символи>` містить
256 CSPRNG біт; у client_invitations зберігається лише SHA-256. Reload/resubmit
не відновлює попередній URL; повтор TOTP відхиляється. Передавання — вручну admin.
`POST /admin/auth/invitations/revoke` має той самий fresh step-up і row lock.
Термінальні стани незворотні. Немає target NULL-card issuance/relink чи reactivation.
Звичайний manual client create лишається заборонений Policy/HTTP/Livewire.
У controlled AUTH-4 звичайне редагування user_id заблоковане також у server update
handler; перехідні NULL-картки зберігають редагування coach/status без прив'язування.

### OTP, пошта та concurrency

HMAC-SHA-256 має versioned external key та однозначний JSON domain context:
version, flow/challenge UUID, generation, purpose/audience, binding та normalized
email. Невідома key version відхиляється. CSPRNG через random_int, рівно 8 цифр.
Старий generation invalidated під flow lock; conditional consume перевіряє TTL,
attempts та одноразовість. Failure increments комітяться до повернення відмови.
5 failures на flow не скидаються resend; shared email budget переживає новий flow.
П'ятий failure запускає повну 15-хвилинну паузу.

Database cache під PG advisory transaction lock — спільний rate store. Keys —
HMAC зі стабільним окремим rate key, тому заміна OTP key version не скидає budgets.
Ковзні вікна: 1/60 s, 3/900 s, 10/86400 s email+purpose; 15/86400 s email загалом;
IP 20/900 s і 100/86400 s; verify IP 10/min, invitation exchange/complete IP 10/min,
exchange token fingerprint 5/15 min, complete flow 5/15 min. Account suppression
зберігає 202. Account-only verify pause повертає ту саму 422 invalid_challenge, що
і unknown/decoy: 429 тільки для загального IP budget. Це явне звуження кандидатного
429 із §11: різниця 429 real / 422 unknown після п’яти помилок давала enumeration.
Пауза 15 хвилин та всі ліміти залишаються чинними. Додано §10 global delivery circuit budget 1000/h. Cache TTL має
консервативну додаткову секунду; фактичні timestamps із PG з мікросекундами, щоб
округлення секунд не дозволяло resend раніше 60 s.

ControlledAuth серіалізує нові auth операції одним PG advisory lock з timeout 5 s;
усередині зберігається users → invitation → flow → challenge порядок. Це свідомо
обмежує пропускну здатність стенда. Sharding/навантаження/операційне керування
rate store перед production — P6; не заявляється необмежене масштабування.
Існуючий shared MFA лишається під user/factor locks; client failures мають 5/5 min
на flow і 10/15 min на user, pause 15 min, HTTP IP 20/15 min. Durable TOTP watermark
та recovery version/revocation лишаються AUTH-3.

`DeliverEmailOtp` — Laravel ShouldQueue + ShouldBeEncrypted. Код у jobs/failed_jobs
знаходиться всередині encrypted serialized command; DB challenge має лише HMAC.
Job перед delivery перевіряє current generation, pending flow та обидва expiry.
Dispatch після domain transaction, без mail/network під її locks. На dispatch
failure real challenge invalidated, відповідь 503. Невідомі/непридатні/suppressed
email також отримують encrypted decoy job тієї самої форми: queue outage не
розкриває існування акаунта через різницю 202/503. Decoy нічого не надсилає.
Це функціональний захист від enumeration; статистичний timing/load аудит провайдера
потрібний перед P6 rollout, бо production provider тут відсутній.

Пошта дозволена тільки `array` або `auth4_synthetic`; log/SMTP/failover заборонені
gate. PHPUnit використовує Mail::fake (тільки пам'ять). Chromium використовує
реальну database queue/worker і Laravel Mail synthetic transport: лише адреси
@example.invalid; тіло шифрується Laravel Crypt у приватний mode 0600 файл під
власним випадковим /tmp каталогом. Код читає лише локальна fixture subprocess,
не API; скриньки й HTTP server log видаляються trap. Скриншоти маскують поля,
HAR/trace/HTML dumps не створюються. Production SMTP/ключі не підключені.

### Email та historical transition

Єдиний EmailNormalizer: trim; ASCII lowercase повної адреси після UTS46
nontransitional IDNA (STD3/BIDI/CONTEXTJ), ext-intl/idn_to_ascii обов'язковий;
Unicode local-part відхиляється, максимум 254 bytes. Крапки та +tag зберігаються.
Новий user зберігає trim початкової delivery адреси та окремий canonical key.
Delivery email для завершення береться тільки зі restricted server session;
challenge/generation повинні підтверджувати той самий normalized key.

`auth:email-preflight` має власну REPEATABLE READ READ ONLY PG транзакцію і показує
лише агрегати: invalid, collisions, mismatches, unverified, NULL та candidates.
Колізії/invalid/mismatch → exit 1; чистий structural snapshot → exit 0, що **не є
дозволом production rollout**. Unverified не підтверджуються. `EmailInventory`
містить процедуру **лише synthetic backfill**: fail-closed runner identity guard,
testing, новий PG18, ACCESS EXCLUSIVE users lock, повторний preflight, canonical
keys у одній транзакції. Email delivery, verification, roles і business data не
змінюються. Окремого production backfill command/автоматичної міграції немає.

Кожен registration complete повторно резервує також canonical адреси історичних
users із email_normalized=NULL; invalid/колізія/mismatch зупиняють реєстрацію.
Пошук login дозволяє лише verified client із consistent normalized key та clients;
історична canonical колізія також дає нейтральний decoy, без вибору переможця.
Admin/coach/unverified/акаунт без профілю отримує нейтральний decoy. Немає merge,
автоматичного verify, attach NULL-card або профілю до історичного user.

Registration commit створює users(role client, password NULL, verified email),
clients(pending, coaching_started_at NULL, coach з invitation), consume invitation,
complete flow та append-only audit. Fault у будь-якому з users/clients/audit
відкочує всю операцію. Реєстрація не активує платний супровід і не створює анкету.

## Відтворення та межі

```bash
NODE_USE_ENV_PROXY=1 npm run build
npm run lint --prefix client
npm run build --prefix client
AUTH3_BROWSER=1 AUTH4_BROWSER=1 bash tests/pg/run-stage1.sh
bash tests/pg/test-runner.sh
bash tests/pg/run-isolated-pg.sh
bash experiments/auth1/run.sh
# Цільовий повтор на іншій новій PG18, коли базові регресії вже пройдені:
AUTH4_ONLY=1 AUTH4_BROWSER=1 bash tests/pg/run-stage1.sh
```

Runner використовує новий pinned postgres:18.6, --network none, tmpfs, приватний
Unix socket, non-superuser test runner, випадковий app key і cleanup власного
контейнера. Не читає telmanfit_dev/production. Фактичний PG каталог перевіряється
існуючими AUTH-2 тестами проти docs/05; нових DDL/міграцій немає. Harness HTML
інжектується Playwright на реальному origin /app; окремого відкритого test route
або production auth screen не додається.

Конкурентні перевірки викликають **реальні сервіси** з двох PHP processes/PG PID;
barrier доводить обидва Lock waits до commit: OTP consume, same invitation/flow,
same invitation/different flows, two invitations/same canonical email, simultaneous
issuance з різних flows/IP. Рівно один consume/registration/delivery; neutral
issuance responses однакові. AUTH-2 додатково перевіряє revoke vs consume,
expiry/rollback/generation і AUTH-3 — TOTP/recovery/logout-all gate races.

Фактичні результати поточного прогону: [evidence/auth4/results.json](evidence/auth4/results.json)
та [evidence/auth4/checks.json](evidence/auth4/checks.json). GitHub Actions статус
належить head SHA PR; локальний PASS не видається за CI PASS.

**Default OFF:** AUTH4_CONTROLLED=false; при вимкненні auth4 API — 404, invitation
routes/actions недоступні. Навіть із прапорцем true production або неправильні
PG/session/cache/mail/MFA/keys settings дають відмову. Потрібний окремий
AUTH3_CONTROLLED synthetic gate. `.env`, production configuration і реальні
користувачі не змінювалися; автоматичного live backfill немає.

AUTH-5: повноцінні React registration/login/MFA/security screens; optional client
TOTP enrollment/confirm/disable та recovery management з fresh action proof;
client reauth/session management/logout-all HTTP і email-change UX/контракти.
Наявний MfaService.logoutAll перевірено також для client, HTTP endpoint не додано.
Немає анкети JSON, Telegram, Passkeys чи нових frontend-залежностей. Захищене
приєднання NULL-карток — окрема процедура. P5 admin recovery та P6 keys/SMTP/
backup/retention/NTP/rollout лишаються явними блокерами production; вони не
реалізовані цим PR. Production навантаження, статистичний timing-аудит та реальний
провайдер не перевірялися.

## Результати локального фінального прогону

- SQL на Laravel PG18 та окремій еталонній PG18: кожен 76 PASS / 0 FAIL / 0 GAP.
- Stage 1/2: 41 tests / 713 assertions; основний PHPUnit: 22 / 46.
- AUTH-2: 12 / 130, upgrade/down PASS, 14 SQL races PASS.
- AUTH-3: 25 / 129; service/middleware races та Chromium regression PASS.
- AUTH-4 фінальний targeted: 23 / 145; 5 service concurrency сценаріїв PASS.
- Chromium 154.0.8037.57: invitation create/revoke, OTP registration, MFA login,
  cookies/CSRF/rotation/logout/version та відсутність секретів у JSON/logs PASS.
- AUTH-1: 15 / 93; runner fail-closed contracts 10 PASS.
- Pint, frontend ESLint/build, Laravel assets build, diff whitespace: PASS.
- Усі тимчасові БД/контейнери/скриньки фінальних локальних прогонів очищені.

## Змінені та створені файли

- `.github/workflows/stage1.yml`
- `AGENTS.md`
- `app/Console/Commands/AuthEmailPreflight.php`
- `app/Filament/Pages/Invitations.php`
- `app/Filament/Resources/Clients/ClientResource.php`
- `app/Filament/Resources/Clients/Pages/EditClient.php`
- `app/Http/Controllers/Api/ClientAuthController.php`
- `app/Http/Controllers/Auth/AdminInvitationController.php`
- `app/Http/Middleware/AdminMfaBoundary.php`
- `app/Http/Middleware/AdminSessionContext.php`
- `app/Http/Middleware/ClientAuthBoundary.php`
- `app/Http/Middleware/ClientCsrf.php`
- `app/Jobs/DeliverEmailOtp.php`
- `app/Mail/EmailOtp.php`
- `app/Mail/SyntheticTransport.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Auth/AdminAssurance.php`
- `app/Services/Auth/Client/AuthBudget.php`
- `app/Services/Auth/Client/AuthDenied.php`
- `app/Services/Auth/Client/ClientAuth.php`
- `app/Services/Auth/Client/ClientSession.php`
- `app/Services/Auth/Client/ControlledAuth.php`
- `app/Services/Auth/Client/EmailInventory.php`
- `app/Services/Auth/Client/EmailNormalizer.php`
- `app/Services/Auth/Client/InvitationService.php`
- `app/Services/Auth/MfaService.php`
- `bootstrap/app.php`
- `config/auth4.php`
- `config/mail.php`
- `docs/architecture.md`
- `docs/auth-architecture.md`
- `docs/auth-schema.md`
- `docs/auth3.md`
- `docs/auth4.md`
- `docs/business-rules.md`
- `docs/evidence/auth4/01-invitations.png`
- `docs/evidence/auth4/02-client-session.png`
- `docs/evidence/auth4/03-mfa-restricted.png`
- `docs/evidence/auth4/checks.json`
- `docs/evidence/auth4/results.json`
- `docs/project-status.md`
- `docs/roadmap.md`
- `phpunit.auth4.xml`
- `resources/views/auth/invitation-result.blade.php`
- `resources/views/filament/pages/invitations.blade.php`
- `routes/api.php`
- `routes/web.php`
- `tests/Auth4/Auth4Test.php`
- `tests/browser/auth4-fixture.php`
- `tests/browser/auth4-harness.html`
- `tests/browser/auth4-in-context.sh`
- `tests/browser/auth4.py`
- `tests/pg/auth4-concurrency.php`
- `experiments/auth1/fetch-upstream.py`
- `tests/pg/run-stage1.sh`

### Фінальне уточнення security/CI

Регресія enumeration порівнює всі шість невдалих verify для real/unknown login:
ідентичні 422 invalid_challenge, із чинною 15-хвилинною account pause. Загальний
IP throttle і далі повертає 429. Наявність email не розкривається статусом на
шостій спробі. AUTH-4 фінально: 23 tests / 145 assertions.

Перший GitHub Actions run на 9bb0920 пройшов усі PG/SQL/AUTH-2/3/4/Chromium
перевірки; завершився помилкою тільки на AUTH-1 regression. Локально AUTH-1
повторно PASS 15/93. CI fetch pinned public upstream blobs тепер використовує
штатний ephemeral GitHub token замість shared anonymous IP; у разі failure CI
публікує bounded error annotation, щоб причина була доступна без raw log service.
Це не змінює production auth, залежності або pinned upstream revisions. Остаточний
CI результат перевіряється на новому head SHA PR.
