# D8 — мінімальна інфраструктура TelmanFit (5–10 клієнтів)

**Статус:** рішення власника щодо постачальників та масштабу **затверджені**; операційна перевірка й запуск — **не виконані**. Це документаційний план, без provisioning, доступу до сервера, секретів, deployment, міграцій, зміни auth/gates чи реальних даних.

## 1. Рішення власника (22.10.2026)

- **Масштаб: 5–10 власних клієнтів.** Жодного планування під тисячі одночасних відвідувачів.
- **VPS:** Hyperhost, **«VPS Старт»** (не VPS іншого постачальника). За публічною сторінкою: **2 CPU, 3 ГБ RAM, 45 ГБ NVMe**, VPS (VZ); характеристика тарифу потребує фінального підтвердження під час замовлення. Джерело: https://hyperhost.ua/uk/vps-vds.
- **Один VPS** для Laravel 13 / PHP 8.5 / React static assets / PostgreSQL 18 / локальних database queues та workers. **Без Redis, Kubernetes, другого app/DB VPS чи платного HA**.
- **SMTP:** **SendPulse**. Публічний базовий план: до 12 000 листів/міс, обмеження 50/год, SPF/DKIM/DMARC; можливість реєстрації/активації акаунта та фактична доставка — перевірити перед релізом. https://sendpulse.ua/pricing/smtp і https://sendpulse.ua/knowledge-base/smtp/limits.
- **Зовнішні резервні копії:** послуга **Hyperhost**. Базова щотижнева безоплатна, платні — щотижневі/щоденні по 5 копій згідно з описом провайдера. Обрано самого провайдера; **конкретний тип/розклад/утримання ще треба перевірити**. https://hyperhost.ua/uk/wiki/vy-vypolnyaete-rezervnoe-kopirovanie-na-khosting-na-vps-kakie-sroki.
- **Простій під час аварії допустимий.** Не вимагати active-active, automatic failover чи zero downtime. Проте **припустима втрата даних не погоджена**: доступність ≠ цілісність даних.
- **Звичайне повернення admin MFA:** пароль + наявний одноразовий recovery code; власник планує зберігати його у 1Password. Потрібен незалежний спосіб доступу до самого 1Password та бажано відокремлення запасної копії від пароля TelmanFit.
- **D3 assisted/last-admin recovery через друзів:** **відкладено без строку**. Майбутнє завдання roadmap: можливо, 1–2 друзі, новий дизайн після окремого рішення. **Не вмикати** B3 assisted recovery і не вважати його готовим. Draft PR #22 — історичний дизайн-кандидат, **не поточний план впровадження**.

## 2. Мінімальна топологія

Інтернет → HTTPS (nginx/Caddy, конкретний сервер не обрано) → PHP-FPM/Laravel (Filament admin + API + React static) → локальний PostgreSQL 18 на loopback/Unix socket.

На тому самому VPS: scheduler (cron/systemd), два логічні worker queues (OTP і security notices, фізично можуть працювати у 1–2 невеликих процесах), локальний файл/лог із обмеженням розміру та базовий моніторинг. SMTP вихідний через SendPulse TLS; **порт 25 не потрібен**, налаштувати лише фактично підтримуваний порт relay. Зовнішня послуга Hyperhost зберігає VPS backup **поза робочим томом**.

Ніяких secrets, backups, PG портів або admin utility endpoints назовні. SSH доступ лише за ключем і обмеженим правилом доступу. Наявні B1–B6 security gates, `4040404` і PostgreSQL-only бюджети не спрощувати без окремого reviewed cutover.

## 3. Питання, які реально впливають на придатність VPS «Старт»

| Що перевірити | Прийнятний мінімум |
|---|---|
| VZ контейнерні обмеження | Установка/стабільна робота потрібних PHP 8.5.12+, extensions, PostgreSQL 18, `btree_gist`, Node лише для build (бажано збирати на CI), cron/systemd/supervisor та TLS, підтримувана ОС |
| БД/привілеї | PostgreSQL на localhost, окремі least-privilege runtime/owner/backup roles, grants/DDL/restricted SECURITY DEFINER; root/DB owner недоступні вебдодатку |
| RAM/CPU | Розподіл для PHP-FPM, PostgreSQL 18, черг, systemd; уникати OOM, swap thrash, надлишкових процесів, Argon2 overload; **2 CPU/3 ГБ — гіпотеза, не доказ** |
| Диск | 45 ГБ NVMe включно з OS, DB, WAL, журналами, файлами, тимчасовими backups і зростанням; alerts за порогом і cleanup без ризику втрати auth/audit |
| SMTP SendPulse | Перевірка акаунта/домену, SPF/DKIM/DMARC, 50/год, реальні тестові OTP/notice на контрольні скриньки, retries/timeout; тестувати без даних клієнтів |
| Вихідний інтернет/оновлення | HTTPS, DNS/NTP, контроль patch PHP/PostgreSQL/OpenSSL та рестарт workers |
| Фактичні ресурси | Synthetic smoke і невелике навантаження, максимум 5–10 тестових користувачів плюс burst, замір HTTP latency/CPU/RSS/PG pool/lock waits/queue age; без безпідставного «1000 RPS» |

## 4. Hyperhost backup ≠ автоматично PITR

Постачальник описує **образ VPS**. Така копія може бути crash-consistent, але **не доводить** transaction-consistent PostgreSQL snapshot, безпечну прив'язку до WAL чи PITR. Перевірити у підтримки **що саме резервується**, як часто, скільки утримують, в який датацентр, чи копія доступна за відмови основного VPS та чи можна незалежно завантажити/перевірити її.

**Мінімальний варіант для 5–10 клієнтів:**
1. Обрати в Hyperhost **платне щоденне резервування**, перевірити ліміт 5 збережених копій і фактичний графік. Безоплатний **раз на тиждень — недостатній кандидат** для робочої бази клієнтів. Не заявляти 30 днів retention, якщо сервіс тримає лише п'ять щоденних копій.
2. Окремо проєктувати коректну **PostgreSQL-aware backup/restore** процедуру (або узгодити із Hyperhost її наявність), із узгодженим RPO/retention. Не вважати «повний образ VPS» автоматично аналогом verified base backup + WAL архівування.
3. Зашифрувати резервні копії; **ключ до backup та критичні auth secrets не повинні зникнути разом із VPS**. 1Password може слугувати приватним custody власника для відновлення секретів, але не замінює незалежний external epoch/anti-replay ledger.
4. Перед запуском — **ізольовано відновити** тестову backup-копію з перевіркою схеми, даних, TLS/файлів, заборони зовнішніх mail/worker side effects, цілісності auth/session/recovery fences. Задокументувати actual data loss interval і recovery time.
5. Після реального PostgreSQL PITR **не вмикати одразу login**: B5 D6-A вимагає external epoch, консервативного revoke, re-enrollment та ≥24h issuance fence за відсутності current external rate checkpoint. **Звичайний старий MFA recovery code із 1Password після консервативного B5 restore може бути відкликаний.** Без D3 або іншої окремо затвердженої процедури **останній admin може залишитися заблокованим**. Це **відкритий blocker для production disaster recovery**, а не привід тихо bypass MFA. Потрібен окремий вузький review/рішення перед production.
6. Початкові RPO≤15 хв / RTO≤4 год та 30d backup retention із `docs/auth6-readiness.md` **не оголошуються виконаними**. Простій користувачем дозволений, але нові втрати даних/числові RPO/RTO не встановлено. Переглянути після цін і реального restore drill.

## 5. Мінімум безпеки, секретів і спостереження

- HTTPS/cookies/CSRF/CSP, `APP_DEBUG=false`, лише `public/` webroot; закрити `.env`, `storage`, `vendor`, файли backup і PostgreSQL з інтернету.
- `APP_KEY`, TOTP encryption/key versions, OTP HMAC, стабільний rate HMAC, DB/SMTP credentials — **поза Git/CI artifact/DB backup**; з контрольованою відновлюваною encrypted копією, без секретів у PR/логах. Ротація APP_KEY не є кнопкою «скинути фактор»; окрема D8/B7 перевірка.
- Без Redis: використати існуючі Laravel database queue та PostgreSQL budgets. Малі обмежені workers із autorestart, cron/scheduler, спостереження за непрацюючим worker і затримкою листів.
- Мінімальні оповіщення власнику: недоступний HTTP, переповнення диска, падіння PostgreSQL/queue, backup прострочився/restore test failed, збої OTP SendPulse. Без платного APM/24×7 NOC.
- Audit/notice redaction і retention не змінювати. Відсутність email delivery не створює password-only fallback.
- Перші синтетичні тести робити **не** на реальній клієнтській БД. Жодного production запуску/відкриття auth з цим design PR.

## 6. План реалізації після документаційного review

**D8.1 (тільки перевірки, без реального серверного доступу):** зібрати точні характеристики Hyperhost VPS Старт, тариф щоденних зовнішніх копій, з'ясувати підтримку PostgreSQL18/roles, PHP8.5 patch, scheduler/workers і actual backup consistency, SendPulse quota/terms.

**D8.2 (окрема узгоджена конфігурація staging):** описати і перевірити least privilege, TLS, секрети, deploy/upgrade, database queue, mail sandbox, базові оповіщення; без live клієнтів і production keys.

**D8.3 (окремі restore/load drills):** виміряти tiny workload на 2 CPU/3 GB, full restore із backup, досягнуті RPO/RTO, та **last-admin access після консервативного B5 restore**. Якщо останній admin не може безпечно повернути доступ без D3, **не оголошувати production disaster-ready**.

**B7/P6 go/no-go:** реальний server readiness + independent security review, узгодження ризику втрати даних і disaster admin recovery; тільки потім явно дозволити production activation. Неможливість гарантувати HA для 5–10 клієнтів — **не blocker**, але неможливість відновити дані або безпечно авторизувати останнього admin — blocker.

## 7. Що не робимо

- Не купуємо другий VPS, HA PostgreSQL, Redis, Kubernetes, дорогий моніторинг чи managed database лише заради невеликого масштабу.
- Не впроваджуємо зараз recovery через друзів, не використовуємо Draft PR #22 як затверджений D3, не створюємо один «суперключ», що обходить MFA.
- Не змінюємо існуючі SQL/DBML, міграції, `4040404`, B5 external epoch fencing, D7 cutover, retention чи production settings.

**Джерела:** [auth6-readiness.md](auth6-readiness.md), [auth6-b2-design.md](auth6-b2-design.md), [auth6-b5.md](auth6-b5.md), [auth6-b6.md](auth6-b6.md), [project-status.md](project-status.md).
