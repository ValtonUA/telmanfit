# D8.2 — staging kit для Hyperhost VPS Старт

**Статус:** підготовлені інструкції та read-only preflight; **реальний VPS не замовляли і не налаштовували**. Запуск **лише** на окремому staging без даних/секретів клієнтів. Це не B7/production go-no-go.

## Погоджені умови

- 5–10 клієнтів; один Hyperhost VPS Старт (2 CPU/3 ГБ RAM/45 ГБ NVMe), OpenVZ/VZ; 24 години максимального віку резервної копії після аварії прийнятні. Простій допустимий.
- Laravel 13 + PHP **8.5.12 або новіший виправлений patch**, Filament 5, PostgreSQL 18 + `btree_gist`, React 19 (CI build), nginx/PHP-FPM, database queue. **Без Redis, Docker, HA і другого production VPS**.
- SendPulse SMTP TLS — провайдер обраний; облікові дані й домен ще не підключені.
- Вибрані **зовнішні backup Hyperhost**, для PostgreSQL потрібна окрема коректна зашифрована копія (`pg_dump -Fc` або інша узгоджена PG-aware процедура).
- D3 через друзів відкладено. **D8.3 last-admin after B5 restore обов'язковий перед production**. Ніякого bypass MFA, `4040404` чи `recovery_required`.

Джерела: [D8](auth6-d8-infrastructure.md), [D8.1](auth6-d8-preflight.md), [B5](auth6-b5.md), [B6](auth6-b6.md), [AGENTS](../AGENTS.md).

## 1. Передумови — що ще не доведено

| Gate | Перевірка | Стан |
|---|---|---|
| H1 | VPS Старт із підтримуваною Debian/Ubuntu, root+console, systemd і cron | **НЕ ПЕРЕВІРЕНО НА VPS** |
| H2 | Репозиторій встановлення PHP 8.5.12+ та потрібні extensions | **НЕ ПЕРЕВІРЕНО НА VPS** |
| H3 | PostgreSQL 18, package `postgresql-contrib` / `btree_gist`, локальний socket та окремі roles | **НЕ ПЕРЕВІРЕНО НА VPS** |
| H4 | HTTPS/піддомен staging, dns та вихідний SMTP 587 SendPulse | **НЕ НАЛАШТОВАНО** |
| H5 | Умови зовнішніх копій Hyperhost + окремого PG-aware export/retention | **ПОТРЕБУЄ ВІДПОВІДІ ПІДТРИМКИ** |
| H6 | Provisioned staging без production даних, затверджених ключів чи доступів | **НЕ ІСНУЄ ПІДТВЕРДЖЕННЯ** |

**Не починати автоматичне встановлення**, доки не перевірені H1–H3 безпосередньо на конкретному VPS. Відповіді підтримки Hyperhost сформульовані в [D8.1](auth6-d8-preflight.md), § «Питання підтримці Hyperhost».

## 2. Перший запуск: лише інвентаризація, нічого не змінювати

Після створення **окремого тестового VPS** і доступу через уповноважений інструмент запустити:

```bash
bash scripts/staging/d8-2-preflight.sh
```

Скрипт **не встановлює, не змінює й не перезапускає** служби, не читає `.env` і не виводить секрети. Збирає: ОС, версію ядра, тип init, CPU/RAM/диск, наявність `php`, `psql`, `pg_config`, `systemctl`, `cron`, `nginx` та `btree_gist.control`; не перевіряє привілеї або реальну працездатність PostgreSQL чи SendPulse. Вивід містить технічні метадані вузла — не публікувати їх у PR без перевірки.

## 3. Послідовність ручних контрольних gates для staging (майбутня D8.2 execution)

1. **VPS/OS.** Отримати письмові відповіді Hyperhost або підтвердити на тестовому VPS доступність системного init/служб і сумісної ОС. Перевірити root/console до будь-яких SSH/firewall змін; не вимикати єдиний канал доступу.
2. **Runtime.** Затвердити джерело підписаних пакетів PHP 8.5 із security patch policy, встановити CLI/FPM + PDO_PGSQL/pgsql/mbstring/xml/curl/intl/zip/bcmath/opcache; звірити PHP CLI та FPM. Не запускати `composer setup`: він генерує ключі, запускає migrations і виконує build без staging-review.
3. **PostgreSQL.** На **окремій порожній synthetic DB** PG18 з `btree_gist`, а не `telmanfit_dev`/production, довести grants, міграції, negative tests, loopback-only `5432`. Мінімум окремі власник-мігратор, runtime з найменшими правами й backup role; runtime не superuser/owner. Для auth B3–B6 не надавати production GRANT без B7 acceptance.
4. **HTTP.** HTTPS test hostname → nginx → FPM/Laravel, public webroot лише `public/`. React збирати у CI, не витрачати RAM VPS на Node build. Перевірити `/api/health`, SPA fallback, Filament, cookies/CSP/CSRF, закриття `/.env`/`storage`/`vendor` і PG-портів.
5. **Config/secrets.** `APP_ENV` тільки staging, `APP_DEBUG=false`, `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `MAIL_MAILER=log` до sandbox. Private `.env`/ключі поза Git, permission 0600, окрема encrypted recovery копія. `APP_KEY` не генерувати заново при deploy/restore. Значення AUTH/recovery feature flags зберігають **production OFF**.
6. **Queue/scheduler.** Один контрольований Laravel database worker та `schedule:run` раз на хвилину із process supervision, `restart`/heartbeats. **ДО створення unit** перевірити timeout/job-specific retry-after і кількість auth queues у коді, щоб не втратити OTP/notice.
7. **SendPulse.** SMTP TLS 587 після окремої активації власного test domain і SPF/DKIM/DMARC. Лише контрольні адреси; не відправляти реальні OTP клієнтам, не публікувати credentials, перевірити bounce/rate limit/expiry.
8. **Backup.** Тестовий щоденний `pg_dump -Fc` → зашифроване відокремлене сховище; checksum, календар з 24h age alert. Щоденні VPS images Hyperhost — додатково, **не** замість PG logical backup. Зробити ізольований `pg_restore` із вимкненими SMTP/workers; зафіксувати вік копії, час відновлення та список файлів/секретів, відсутніх у dump.
9. **Acceptance.** Synthetic 5–10 users, короткі burst auth tests, RAM/OOM, lock waits, черги, листи, uptime, диск. Якщо не проходить — зупинити без production cutover. `4040404` та B5 fence не вимикати заради test pass.

## 4. Мінімальні приклади конфігурації, не deploy-ready

```ini
# systemd TEMPLATE ONLY; перевірити шлях, права, назви queues/timeout/job contracts.
[Unit]
Description=TelmanFit staging queue worker
After=network.target postgresql.service
[Service]
Type=simple
User=telmanfit-staging
WorkingDirectory=/srv/telmanfit-staging
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --max-time=1800 --max-jobs=100
Restart=on-failure
RestartSec=5
[Install]
WantedBy=multi-user.target
```

```text
# Cron TEMPLATE ONLY after scheduler verification:
* * * * * cd /srv/telmanfit-staging && /usr/bin/php artisan schedule:run >/dev/null 2>&1
```

Не копіювати шаблони у `/etc` без перевірки `User`, `ExecStart`, `PHP` FPM service, queue names, permissions, logfile/health й аварійного rollback. Програмних міграцій для staging/production у цьому PR немає.

## 5. Зафіксована межа майбутніх рішень

- **RPO до 24 годин** погоджений як максимально прийнятний вік останньої придатної копії. Це не гарантує конкретного RPO до виміру після backup+restore; nightly графік має запас на failures.
- **D8.3 до production:** після B5 restore старі recovery codes/TOTP можуть не працювати. Потрібен окремо затверджений захищений owner identity proof і спосіб повторного MFA enrollment, без MFA bypass та без повернення D3 trustees у scope D8.2.
- **B7** перевіряє D8.2 + D8.3 + backup/restore/end-to-end + secure cutover; лише після цього production.
- **Не змінювати** PR #22, історичні D1–D7, SQL/DBML, код auth, секрети чи реальні дані.

## 6. Definition of Done поточної документаційної підготовки

- Read-only інвентаризаційний скрипт у `scripts/staging/`; чіткі H1–H6; послідовність майбутніх installation/PG/HTTP/queue/mail/backup drills.
- Не видавати заплановане за виконане. Документ і скрипт **не підтверджують придатність купленого VPS**; повноцінна D8.2 execution потребує тестового VPS, дозволеного доступу та письмової відповіді Hyperhost щодо PG-aware backup.
