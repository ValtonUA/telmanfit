# D8.1 — попередня перевірка Hyperhost VPS Старт

**Дата перевірки:** 22.10.2026. **Висновок:** VPS Старт виглядає придатним кандидатом для 5–10 клієнтів за умови перевірки сумісності ОС/системних служб і окремого PostgreSQL-aware backup. Публічні матеріали не доводять повної сумісності тарифу з конкретними версіями PHP/PostgreSQL та узгодженості образу БД. Production не розгортався.

Звірено чинний [D8 Draft PR #23](https://github.com/ValtonUA/telmanfit/pull/23): цей звіт доповнює його конкретними результатами D8.1; PR #22 і #23 не змінювалися. Рішення щодо масштабу, одного VPS, SendPulse, простою та відкладеного D3 залишаються чинними.

## Статус перевірки

| Статус | Перевірка | Висновок |
|---|---|---|
| PASS | Ресурси тарифу | Hyperhost публікує VPS Старт: 2 CPU, 3 ГБ RAM, 45 ГБ NVMe. Це відповідає затвердженому вибору; доступність і точну комплектацію замовлення перевірити в панелі перед оплатою. |
| PASS | ОС, root, віртуалізація | Hyperhost описує VPS як OpenVZ/VZ та вказує повний root і вибір Linux-дистрибутивів. Тариф VPS Старт належить до VPS, а не KVM VDS. Точний образ ОС у формі замовлення не зафіксований. |
| NEEDS CONFIRMATION | PHP 8.5 + розширення | Публічні матеріали не підтверджують підтримуваний образ ОС і репозиторій PHP 8.5 для цього VPS. PHP 8.5 існує; потрібні щонайменше CLI/FPM, `pgsql`/`pdo_pgsql`, `mbstring`, `xml`, `curl`, `intl`, `zip`, `bcmath`, `openssl`, `fileinfo`, `tokenizer`, `ctype`, `json`, `opcache`. Підтвердити на тестовій ОС; Node потрібен лише для build і його краще не тримати на production VPS. |
| NEEDS CONFIRMATION | PostgreSQL 18, `btree_gist`, localhost | PostgreSQL 18 офіційно містить `btree_gist`; документація не гарантує встановлення extension-пакета/привілею `CREATE EXTENSION` у VPS Старт. Потрібні локальний PostgreSQL 18, локальні socket/loopback-з'єднання, розширення та тест міграцій під окремою least-privilege роллю. |
| NEEDS CONFIRMATION | PHP-FPM, cron/systemd, queue workers | VPS root має дозволяти FPM і тривалі systemd-сервіси або еквівалент, autorestart worker та cron щохвилинний `schedule:run`. Для OpenVZ доступність systemd як init і контроль cgroups не гарантовані сторінкою тарифу. |
| PASS | Docker | Docker не потрібен. Hyperhost описує OpenVZ Docker як обмежений базовим набором контейнерів зі старішими вимогами до ядра; не будувати конфігурацію TelmanFit на Docker. Якщо пізніше стане потрібний Docker, це вимагатиме повторної перевірки. |
| NEEDS CONFIRMATION | Навантаження / ліміти | 2 CPU / 3 ГБ / 45 ГБ — верхній ресурсний бюджет, а не гарантія тривалого CPU, IOPS або доступності. Публічних конкретних квот CPU steal, дискових IOPS, swap/OOM чи bandwidth для цього VPS не знайдено. |
| PASS | Hyperhost backup — частота/кількість | Для VPS є безкоштовний базовий backup раз на тиждень та платні повний щотижневий або повний щоденний, кожен із 5 копіями. Для production кандидат — платний повний щоденний. |
| NEEDS CONFIRMATION | PostgreSQL consistency / інший сервер | Hyperhost називає це архівом диска VPS і не описує quiesce, snapshot API для PostgreSQL, WAL/PITR чи перевірку транзакційної узгодженості. Архів можна видати файлом для стороннього користування, але процедура імпорту на новий VPS, сумісність/мережа зберігання та строки підтримки не визначені. Не вважати образ PostgreSQL-aware. |
| NEEDS CONFIRMATION | Поведінка при втраті VPS/доступу | Копії зберігаються на окремому backup-сервері; після зупинки/видалення сервісу останній архів може зберігатися до 60 днів. Відновлення архіву — через відділ продажу; повний backup включає безкоштовне розгортання, іншим планам може знадобитися платна технічна робота. Потрібно підтвердити доступ власника до аккаунта та варіант відновлення за повної втрати VPS. |
| PASS | SendPulse SMTP / Laravel | SendPulse надає SMTP із TLS: порт 587; SSL — 465 або 2525. Laravel Mail підтримує SMTP, а database queue — доставку листів через штатну чергу. Сумісність технічно очікувана, але живий тест виконати лише у staging після активації. |
| PASS | SendPulse безкоштовні ліміти | 12 000 листів/місяць, максимум 50/год і 400/день, 2 домени автентифікації та 3 адреси відправника. До 1 МБ — один лист; більші листи безкоштовний тариф не надсилає. Для 5–10 клієнтів обсяг виглядає достатнім за низького звичайного трафіку, але bursts понад ліміт затримають OTP/повідомлення. |
| NEEDS CONFIRMATION | SendPulse активація/домен | До production потрібні реєстрація, модерація анкети SMTP, додавання/підтвердження адреси відправника і DNS-перевірка домену. SendPulse генерує SPF/DKIM DNS записи; налаштувати один узгоджений SPF (не створювати кілька SPF TXT), DKIM і DMARC для власного домену та перевірити status `Підключено`. Обліковий запис і домен не перевірялися. |

## Висновки за постачальниками

### Hyperhost

- Опубліковані параметри VPS Старт: **2 vCPU, 3 ГБ RAM, 45 ГБ NVMe**. Hyperhost описує звичайні Linux VPS на OpenVZ/VZ, повний root і вибір ОС (зокрема Ubuntu/Debian). Конкретний доступний актуальний реліз ОС і точний backend для замовлення слід підтвердити; деякі сторінки Hyperhost розрізняють OpenVZ VPS та KVM VDS.
- Не знайдено публічної гарантії PHP 8.5, PostgreSQL 18 packages, `btree_gist`, systemd, cron або cgroup/process limits саме на Старті. Це не технічна заборона, а невідоме до тесту на конкретному OpenVZ образі. Ставити PostgreSQL із системного/офіційного PGDG репозиторію сумісної ОС лише після перевірки підтримки ОС і доступу до extension package.
- Docker не є вимогою. Публічна Wiki каже, що на OpenVZ Docker можливий для обмеженого набору контейнерів; власний kernel/cgroup стек не гарантований. Рекомендація — нативні пакети ОС без Docker.
- Backup копіює диск VPS на окремий backup-сервер. Доступні: 1 базова щотижнева копія безкоштовно; 5 повних щотижневих або 5 повних щоденних копій платно. Повний щоденний дає орієнтовно п'ять останніх щоденних точок (не 30 днів). При видаленні/зупинці за несплату остання копія може архівуватися до 60 днів; це не означає 60-денну ротацію активних щоденних копій. Відновлення/видача архіву через відділ продажу; безкоштовне розгортання для повних backup-планів. Під час відновлення IP може бути іншим.
- Утворення аварійно-узгодженої копії PostgreSQL, синхронізованої з WAL, не задокументоване. VPS image може бути crash-consistent; для recovery із гарантованою консистентністю потрібен окремий PostgreSQL-aware backup, доки Hyperhost письмово не підтвердить протилежне.

**Питання підтримці Hyperhost (перед D8.2):**

1. Для конкретного замовлення «VPS Старт» підтвердити тип OpenVZ (версія), перелік актуальних Debian/Ubuntu образів і root access; чи доступні PHP 8.5 FPM/extensions та офіційні PostgreSQL 18 server/contrib (`btree_gist`) packages? Чи дозволено встановлювати локальний PostgreSQL та створювати extension під адмін-роллю?
2. Чи доступні на цьому VPS `systemd` як PID 1, cron, довготривалі autorestart служби, loopback/Unix socket і вихідні TCP 587 до SendPulse? Які фактичні memory/swap, CPU quota/steal, disk IOPS і чи є обмеження процесів/cgroups?
3. Що саме означає повний щоденний backup: вікно, скільки точок зберігається та як заміщується найстаріша; чи є snapshot атомарним для PostgreSQL, чи робиться freeze/quiesce або PG hook і чи входить WAL? Чи відомий RPO?
4. Чи може власник самостійно отримати/download архів повного backup і відновити його на іншому VPS/локації/акаунті, якщо вихідний сервер або доступ до нього втрачено? Який строк, ціна/доступність експорту, зміна IP та чи гарантується доступ до backup без доступу до вихідного VPS?
5. Чи дозволяє послуга окреме сховище Hyperhost поза диском VPS для щоденного зашифрованого PostgreSQL `pg_dump` (і бажано перевірки контрольної суми); його ціна, retention та відновлення при втраті VPS/акаунта?

### Рекомендація щодо backup і тестового restore

1. Для майбутньої production послуги обрати **повний щоденний Hyperhost backup** після підтвердження ціни/умов. Планувати лише 5 ротаційних копій, поки провайдер не підтвердить інше.
2. До production додати PostgreSQL-aware нічний logical backup (`pg_dump -Fc`) або еквівалентну провайдерську підтриману процедуру, зашифрувати і винести з VPS у окреме зовнішнє сховище. Спершу підтвердити, чи надає Hyperhost сховище поза диском VPS з потрібними правами/retention; якщо ні — узгодити сумісний зовнішній destination. Секрет розшифрування зберігати незалежно від VPS. Не зберігати єдину копію на тому самому диску/у тому самому образі. PITR/WAL не потрібні автоматично при дозволеному простої; RPO і потрібну глибину відновлення власник має явно затвердити.
3. Автоматично перевіряти успішність/вік/розмір і checksum копії, сповіщати власника за пропуск. Обмежити доступ до копій, ключів і журналів, що можуть містити персональні дані.
4. На окремому чистому тестовому VPS/ізольованій VM відновити останній PostgreSQL dump у нову тестову БД; звірити `pg_restore --list`, migrations/schema, `btree_gist`, контрольні кількості synthetic записів, запуск Laravel health check і цілісність завантажених файлів. Вимкнути зовнішню SMTP-доставку, scheduler та production worker side effects під час drill. Зафіксувати вік останньої копії, фактичну втрату змін (RPO) та час до працездатного тесту (RTO).
5. Окремо попросити Hyperhost відновити/видати VPS image як файл або розгорнути його на новому тестовому VPS; перевірити, чи відкривається кластер PostgreSQL і чи немає recovery/WAL помилок. Не підміняти цим логічний dump тест.
6. Повторювати drill перед production і після суттєвої зміни схеми/backup. Це тестовий сценарій; у цій D8.1 роботі жодного backup чи сервера не створено.

## Мінімальна конфігурація для 5–10 клієнтів

| Компонент | Початкова настройка тестового VPS |
|---|---|
| ОС і веб | Мінімальна підтримувана Debian/Ubuntu без панелі керування та без Docker; nginx → PHP-FPM; React зібрати у CI і роздавати як static assets. HTTPS Let's Encrypt із автоматичним renew. PostgreSQL слухає тільки Unix socket/loopback; web root лише Laravel `public/`. |
| Пам'ять (3 ГБ загалом) | Залишати близько 500–700 МБ ОС/page cache; PostgreSQL `shared_buffers` 384 МБ, `work_mem` 4 МБ, `maintenance_work_mem` 128 МБ, `max_connections` 20–30 (валідувати з фактичним app pool); FPM `pm=dynamic`, `pm.max_children=4`, `pm.max_requests=500`; один database queue worker, concurrency=1. Не задавати swap як заміну RAM; спершу виміряти OOM/RSS на staging. |
| PostgreSQL | Один локальний кластер PG18; `btree_gist`; окремі runtime/migration/backup ролі з least privilege; регулярний vacuum/autovacuum; логування помилок, повільних запитів із помірним порогом, connections і disk. Не відкривати 5432 назовні. |
| Queue і scheduler | Database queue, без Redis. Один systemd worker із `--sleep=3 --tries=1 --timeout=60`, autorestart із контрольованою паузою; перевірити таймаути проєктних job-ів. `cron` щохвилини викликає Laravel `schedule:run`. Не запускати кілька копій scheduler/довгі job-и без потреби. |
| Диск 45 ГБ | Окремо відстежувати root, PostgreSQL data/WAL, `storage`, журнали й тимчасові файли; початкові попередження на 70% і критичні на 85%. Не тримати backup dump на VPS як єдину копію. Ротація логів і тимчасових файлів з обмеженим розміром/строком. |
| Секрети | `.env` поза webroot/Git і доступний лише сервісному користувачу (0600); не включати у build artifact. `APP_KEY`, ключі шифрування/MFA, DB і SMTP credentials у контрольованому secrets vault власника; зашифрована аварійно доступна копія окремо від VPS. `APP_DEBUG=false`, не логувати OTP, паролі, recovery codes, SMTP credential чи SQL bindings. |
| TLS/SSH | Лише HTTPS, HSTS після перевірки, secure/HttpOnly/SameSite cookies. SSH за ключем, вимкнути парольний/root login лише після перевірки альтернативного доступу, firewall — SSH з дозволених адрес, HTTP/HTTPS публічно, PostgreSQL приватний. |
| Оновлення | Щомісячно перевіряти security updates ОС/PHP/PostgreSQL; застосовувати minor security patches спершу на staging, потім у погоджене вікно з backup і перевіркою login/queue/DB. Не переходити між major-версіями PHP/PostgreSQL автоматично. |
| Моніторинг | Зовнішня перевірка `/api/health`, PostgreSQL/FPM/worker status, queue age, диску (>70/85%), OOM/restarts, останнього backup/dump, TLS expiry і SendPulse reject/failure. Сповіщення на незалежну пошту власника; без платного APM/NOC. Локальні логи з rotation та без auth secrets. |

Ці числа — **стартові межі для тесту**, а не production sizing claim. 3 ГБ досить вірогідно для невеликого інстансу з малою одночасністю, але OpenVZ quotas і пікова витрата Laravel/Auth треба заміряти; якщо 4 FPM children + PG + worker спричиняють OOM, зменшити `max_children`/worker concurrency або переглянути тариф до запуску.

## B5: відновлення останнього admin після disaster restore

За B5 D6-A відсутність або застарілість зовнішнього epoch означає fail-closed. Synthetic restore відкликає невикористані recovery codes, інвалідовує сесії, вимикає підтверджені TOTP-фактори та встановлює `recovery_required` і щонайменше 24-годинний issuance fence. Production adapter/reconciliation/reopening механізму немає. Отже старий код із 1Password не можна вважати чинним після restore, а одиночний admin може не мати дозволеного шляху знову увімкнути MFA. Це **блокує production disaster readiness**; простій не розв'язує відсутність admin access.

Кандидати для окремого рішення власника (жоден тут не реалізовано):

1. **Найменша зміна:** D8/B7 runbook та окрема вузька операція повторного enrollment після restore: тільки maintenance mode; незалежна перевірка особи власника через попередньо визначений канал поза відновленою поштою/БД; подвійний контроль другою довіреною технічною особою; чинний external epoch; invalidate всіх відновлених сесій/codes/factors; незмінений ≥24h fence; новий TOTP + новий recovery batch; audit і повний звичайний login. Старий пароль+recovery code не є достатнім доказом, bypass/password-only не допускаються. Потребує explicit design/approval, оскільки зараз такого механізму немає.
2. **Відкласти production:** залишити D3 відкладеним і не активувати production, доки власник не обере погоджений спосіб незалежного повторного enrollment. Це не змінює MFA policy, але означає, що infrastructure restore сам по собі не повертає last-admin доступ.
3. **Додатковий admin з власною MFA** може знизити ризик звичайного блокування, але не розв'язує restore fence для всіх факторів і не замінює варіант 1; не вважати достатнім планом.

## Блокуючі питання до власника

1. Який прийнятний **RPO/вік останньої придатної копії** і чи погоджуєте платний щоденний backup з лише п'ятьма VPS-точками плюс окремий зашифрований PostgreSQL dump? Без явної межі допустимої втрати даних production backup acceptance лишається відкритим.
2. Який варіант обрати для **останнього admin після B5 restore**: дозволити окремий design/approval вузького dual-control повторного enrollment із незмінним MFA/24h fence (описаний вище), чи залишити production вимкненим до іншого рішення? Поточний B5 не має safe reopening path; D3 не слід вважати доступним.

## D8.2 — покроковий план тестового VPS

1. Отримати письмові відповіді Hyperhost на питання вище; зафіксувати ОС, OpenVZ version, root/systemd, локальні PG/PHP пакети, outbound SMTP TLS і реальні backup умови. Підтвердити відповідь щодо Docker як непотрібної для цього плану.
2. Після окремого рішення/бюджету створити **тестовий** VPS Старт без production домену/клієнтських даних; вибрати підтримувану мінімальну Debian/Ubuntu. Перевірити доступ SSH/console до hardening змін.
3. Поставити security updates, firewall, часову синхронізацію, обмежений SSH, nginx, TLS test domain, PHP 8.5/FPM та потрібні extensions. Перевірити php-fpm health і Laravel CLI/FPM однакової версії/ini.
4. Встановити PostgreSQL 18 + `btree_gist`; створити окрему порожню synthetic DB/roles і перевірити extension/migrations та `localhost` only. Не підключати наявні/реальні БД.
5. Завантажити контрольований код із Draft/merge-approved гілки без секретів у Git; React assets збирати CI. Встановити database queue, один systemd worker, cron `schedule:run`, Nginx HTTPS і `APP_DEBUG=false`.
6. Увімкнути тільки тестові synthetic SMTP credentials/адреси після підтвердження SendPulse та домену; перевірити TLS certificate, Laravel queued mail, retry/failed job і ліміти. Забезпечити worker не дублює/не втрачає job при restart.
7. Налаштувати external synthetic VPS backup та окремий encrypted PG dump; створити тестовий backup, перевірити checksum/alert. Виконати описаний чистий restore drill і записати фактичні RPO/RTO, RAM/CPU/disk/queue metrics на workload 5–10 синтетичних клієнтів.
8. Зафіксувати звіт ресурсів, відкриті проблеми OpenVZ, остаточні значення конфігурації та rollback/reboot процедуру. Зупинити staging перед будь-яким production cutover; production activation, реальні секрети/дані й зміна MFA/recovery policy потребують окремого дозволу та B7/P6 go/no-go.

## Офіційні джерела

- Hyperhost: [VPS/VDS і VPS Старт](https://hyperhost.ua/uk/vps-vds), [Linux/Unix VPS і root/ОС](https://hyperhost.ua/uk/linux-unix-vps), [VPS/VDS Wiki](https://hyperhost.ua/uk/wiki), [backup: графік, кількість, архів та відновлення](https://hyperhost.ua/uk/wiki/vy-vypolnyaete-rezervnoe-kopirovanie-na-khosting-na-vps-kakie-sroki), [Docker на VPS/VDS](https://hyperhost.ua/en/wiki/mozhno-li-razvernut-docker-na-vps-vds), [різниця VPS/VDS, OpenVZ/KVM](https://vz.hyperhost.ua/uk/wiki/v-chem-raznitsa-mezhdu-vps-i-vds-i-mezhdu-kvm-i-vz).
- PHP: [актуальні PHP downloads](https://www.php.net/downloads.php), [PDO PostgreSQL driver](https://www.php.net/manual/en/ref.pdo-pgsql.php), [mbstring](https://www.php.net/manual/en/mbstring.installation.php), [Laravel 13 server requirements](https://laravel.com/docs/13.x/deployment).
- PostgreSQL: [PostgreSQL 18 `btree_gist`](https://www.postgresql.org/docs/18/btree-gist.html), [extensions і привілеї](https://www.postgresql.org/docs/18/contrib.html), [backup/restore](https://www.postgresql.org/docs/18/backup.html).
- Laravel: [Mail](https://laravel.com/docs/13.x/mail), [Queues](https://laravel.com/docs/13.x/queues), [Task Scheduling](https://laravel.com/docs/13.x/scheduling).
- SendPulse: [SMTP тарифи/ліміти](https://sendpulse.ua/knowledge-base/smtp/limits), [початок SMTP та TLS порти](https://sendpulse.ua/knowledge-base/smtp/setup-smtp-server), [SPF](https://sendpulse.ua/knowledge-base/smtp/how-create-spf-record), [DKIM](https://sendpulse.ua/knowledge-base/smtp/how-can-you-configure-dkim-sendpulse-smtp), [DMARC](https://sendpulse.ua/knowledge-base/email-service/general/dmarc-policy).
