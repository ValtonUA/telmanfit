# Постійний локальний TelmanFit у Docker Desktop

Потрібні Windows 11, Docker Desktop з Linux containers та копія репозиторію. PHP,
Composer, Node.js і PostgreSQL у Windows/WSL встановлювати не потрібно.
У PowerShell відкрийте каталог репозиторію. Docker Desktop має працювати.

## Перший запуск

```powershell
docker compose up -d
```

Перший запуск завантажує образи й збирає Laravel/Filament та React/Vite; це може
тривати кілька хвилин. Інтернет потрібен для залежностей і шрифтів під час збірки.
Готовність видно в Docker Desktop або через `docker compose ps`: app, database,
web мають статус healthy. База створюється та мігрується автоматично.

- Адмінка: <http://127.0.0.1:8098/admin/login>.
- React: <http://127.0.0.1:8098/app>.

Один раз отримайте приватні реквізити **у своїй консолі**:

```powershell
docker compose exec app php docker/local/access.php
```

Увійдіть з показаними email і випадковим паролем. Натисніть «Створити ключ
Authenticator», додайте ключ у застосунок Authenticator та підтвердьте поточним
TOTP. Збережіть резервні коди у приватному менеджері паролів. Після enrollment
повторіть вхід із паролем і новим TOTP. Наступні входи не потребують enrollment.
Це локальний обліковий запис, не production адміністратор.

Для першого клієнта відкрийте показане персональне запрошення в окремому профілі
браузера. Використайте `client@example.invalid`, запросіть код і прочитайте його:

```powershell
docker compose exec app php docker/local/mail.php
```

Підтвердьте код, введіть ім’я та завершіть реєстрацію. Після цього в React доступна
анкета, а в адмінці — картка клієнта та кнопка «Анкета — 3 запитання».
Звичайний повторний вхід клієнта: email → новий OTP тією самою командою.
Зашифрована локальна поштова скринька підтримує тільки `@example.invalid`;
черга доставляє повідомлення протягом кількох секунд, а команда показує останній код для `client@example.invalid`. Реальна пошта не надсилається.
Для іншої локальної адреси передайте її останнім аргументом команді `mail.php`.
Нові запрошення створюються штатно в адмінці з password/TOTP step-up.

## Щоденна робота

Публічний host зображень передається через `PUBLIC_IMAGE_HOSTS` у Compose
environment app/worker/scheduler. Приватний R2 env-file сам по собі не змінює
вже запущені процеси; R2 credentials до цих сервісів не передаються.
Застосування URL і rollout конфігурації виконуються лише за окремо погодженим
[планом зображень](r2-image-application-plan.md), без bootstrap/міграцій.

Редактор A/B/C у цьому локальному профілі має явний
`TELMANFIT_LOCAL_RECIPE_EDITOR=true`; production/default профілі залишаються OFF.
Для перенесення власних каталогів і першої чернетки з Excel див.
[local-only імпорт Excel-v1](excel-nutrition-import.md). Він має dry-run,
SHA256-підтвердження та захист від повторів і не публікує рецепти автоматично.

```powershell
docker compose up -d
```

Відкрийте ті самі адреси. Облікові записи, MFA, відповіді, налаштування, сесії та
приватні файли збережені. Після завершення строку сесії потрібен звичайний вхід,
але не повторна реєстрація. Сервіси мають `restart: unless-stopped` і відновлюються
після перезапуску Docker Desktop, якщо не були явно зупинені.

Зупинка зі збереженням даних:

```powershell
docker compose down
```

Помилки та стан:

```powershell
docker compose ps -a
docker compose logs --tail=100
```

## Оновлення після merge — Windows PowerShell

Для звичайного оновлення `telmanfit-local` відкрийте **Windows PowerShell 5.1** у
Windows-копії репозиторію, з якої створено відповідний Docker Compose-проєкт.
Не запускайте coordinator з checkout у WSL: збій виклику `powershell.exe` із WSL
не означає несправність Windows PowerShell або Docker Desktop і не вимагає
налагодження WSL interop перед звичайним оновленням.

Перейдіть у фактичний Windows-каталог репозиторію й виконайте:

```powershell
.\scripts\local-update.ps1
```

Coordinator сам перевіряє Git/Compose identity, робоче дерево, необхідні backup
та health checks. Окремий Codex/WSL preflight не потрібен. Файл
`compose.r2-runtime.yaml` у статусі untracked уже дозволено штатною перевіркою —
не видаляйте й не комітьте його для оновлення. Якщо coordinator завершується
помилкою, не повторюйте запуск навмання: спочатку прочитайте фазу та журнали
в `%LOCALAPPDATA%\TelmanFit\updates` без розголошення секретів.

`scripts/local-update.ps1` зберігається як UTF-8 із BOM, щоб Windows PowerShell
5.1 правильно читав українські повідомлення. Не видаляйте BOM і не додавайте перед
`[CmdletBinding()]` вивід термінала. Сумісність можна перевірити без запуску
coordinator, Docker чи доступу до БД:

```powershell
powershell.exe -NoProfile -File tests/local-update-powershell-compat.ps1
pwsh.exe -NoProfile -File tests/local-update-powershell-compat.ps1
```

Перевірка лише контролює BOM і синтаксичний розбір coordinator у вибраній версії
PowerShell. Якщо керований PowerShell host відхиляє навіть локальний `.ps1`, хоча
`Get-ExecutionPolicy -List` показує дозвільну policy і файл не має
`Zone.Identifier`, це обмеження host/runtime. Не змінюйте policy і не використовуйте
`Bypass`; скористайтеся затвердженим host, який дозволяє локальні скрипти.

Coordinator перевіряє Git origin/гілку, чистоту дерева та саме цей локальний Compose
проєкт; оновлює `master` лише fast-forward і збирає app/web образи до зупинки
сервісів. `compose.r2-runtime.yaml` не включається й не перезаписується. Якщо pending
міграцій немає, резервна копія не створюється: оновлені сервіси запускаються, після
чого coordinator чекає healthy для app/web та running для worker/scheduler.

Для зовнішніх Git/Docker команд coordinator зберігає stdout і stderr окремо та
визначає успіх за exit code: діагностичний stderr із кодом `0` не зупиняє preflight,
а ненульовий код зупиняє його, крім явно дозволених перевірок. У журнал помилки
записуються назва команди й exit code; сирий вивід CLI не копіюється, бо може
містити секрети.

Для міграцій coordinator вимагає явний allowlist із SHA256 міграції та її SQL
залежностей. У cooking-послідовність включено SQL17/18 та additive equipment
міграцію SQL19 з окремими SHA/передумовами/postconditions. Невідома, змінена
або непередбачена міграція блокує процес. Перед
дозволеним upgrade він зупиняє app/worker/scheduler/web, перевіряє відсутність
application DB sessions, створює custom-format PostgreSQL dump і архіви всіх п’яти
пов’язаних volumes, перевіряє SHA256/структуру та виконує ізольовану репетицію
відновлення. Лише після успішної перевірки запускаються allowlisted міграції й
postconditions; RestoreFence та fingerprint наявних даних перевіряються до/після.

Backup та журнали зберігаються поза Docker volumes у
`%LOCALAPPDATA%\TelmanFit\backups` і `%LOCALAPPDATA%\TelmanFit\updates`. Backup
містить приватні БД-дані, локальні ключі та файли; не копіюйте його у спільні чи
публічні каталоги. Каталог обмежений ACL поточного користувача й SYSTEM. Не
перезаписуйте попередні набори.

Якщо coordinator блокується або завершується помилкою, відкрийте виведений журнал і
JSON-журнал фази в `updates`. Coordinator не робить автоматичний rollback/restore та
не видаляє volumes. Після помилки міграції сервіси можуть лишитися зупиненими;
збережіть backup і журнали та визначте стан БД до будь-якого повторного запуску.
Якщо операція завершилась помилкою або процес аварійно перервався до запису `FAILED`,
наступний запуск зупиниться на незавершеному журналі. Після огляду фази й ручного
визначення стану дозволено явно підтвердити конкретний run командою
`.\scripts\local-update.ps1 -AcknowledgeJournal <run_id>`. Підтвердження записується
окремо й не виконує відновлення чи повтор міграцій.
Невідома/змінена міграція потребує окремого перегляду й явного оновлення allowlist.
Не запускайте `db:wipe`, seeders, bootstrap для обходу блокування або `docker compose
down -v`.

Ручне відновлення виконуйте лише після зупинки всіх локальних writer-сервісів і
перевірки manifest та SHA256. У чистому ізольованому середовищі спершу відновіть
volume-архіви з одного набору (`database`, `database-secrets`, `local-state`, `epoch`,
`storage`) і перевірте PostgreSQL identity/RestoreFence; custom dump використовуйте
для логічного відновлення в окрему порожню БД, а не поверх наявних даних. Узгодженість
ключів і epoch є обов’язковою. Якщо потрібно відновити робоче середовище, спочатку
перевірте весь набір у disposable Compose проєкті та погодьте окрему процедуру.

## Зберігання й межі

Compose має фіксоване ім’я `telmanfit-local`: та сама копія/каталог на цьому Docker
Desktop використовує ті самі volumes. Не запускайте другу копію одночасно.
`database` містить PostgreSQL 18 (volume монтується на `/var/lib/postgresql`),
`local-state` — APP_KEY, OTP-ключі, приватні реквізити й mailbox,
`epoch` — незалежний від БД RestoreFence epoch, `storage` — приватні файли,
`database-secrets` — credentials нового cluster. Секрети генеруються локально,
не потрапляють до Git, образів або звичайних logs. Не публікуйте вивід access/mail.

**Не виконуйте `docker compose down -v` і не видаляйте ці volumes у Docker Desktop:**
це видаляє дані та ключі. Volumes переживають down/up і перезапуск Docker Desktop,
але не є резервними копіями; для перенесення потрібен узгоджений комплект усіх
volumes при зупинених сервісах. Втрата/невідповідність epoch закриває доступ;
автоматичної підміни epoch або скидання MFA немає.

Для розробки coordinator-а є окремий E2E runner. Read-only preflight ефективної
конфігурації та наявних Docker ресурсів:

```powershell
pwsh -NoProfile -File tests/local-update-e2e.ps1
```

Повний disposable E2E після read-only preflight:

```powershell
pwsh -NoProfile -File tests/local-update-e2e.ps1 -Run
```

`-Run` створює disposable Compose project із випадковим
суфіксом, окремими volumes і image tags, loopback портом та синтетичними даними.
Coordinator у тесті запускається лише з `-IsolatedTestMode`, `-NoGitSync`, точним
`-ExpectedHead` та Compose override у `%TEMP%\TelmanFit-E2E`. Звичайний виклик
`scripts/local-update.ps1` лишається зафіксованим на `telmanfit-local`. Runner до
створення перевіряє проєкт, п’ять робочих volumes, image tags, контейнери, мережу
й порт; будь-який збіг завершує тест до Docker-запису. Cleanup перевіряє owner
labels і volume attachments кожного ресурсу та видаляє їх по одному;
`docker compose down -v` не використовується.

PostgreSQL не має host-порту. Вебпорт доступний лише на loopback; використовуйте
саме `127.0.0.1`, щоб cookie-контексти лишалися узгодженими. Наявні `.env`, БД і
production секрети не використовуються. Роль застосунку не є superuser і не має
CREATEDB/CREATEROLE/BYPASSRLS. Перша міграція перевіряє identity, незалежний cluster
маркер та порожню schema. Повторний запуск лише перевіряє fence і стан міграцій.
Якщо початковий запуск перервався посеред міграцій, середовище зупиняє bootstrap
та показує причину; автоматичного очищення або повторної міграції непорожньої БД немає.

Профіль увімкнений тільки всередині локальних контейнерів (`APP_ENV=local`).
Використано auth/session виправлення [одноразового local profile](local-demo.md).
RestoreFence, MFA, CSRF та ізоляція клієнтів працюють штатно. Production adapters,
Stage 3 і бізнес-схема не змінюються. Анкета містить чинні три запитання.

Автоматична Chromium перевірка для **нових** volumes: `python3 tests/browser/local_compose.py`
після запуску stack, до ручного enrollment. Це інструмент розробника, не крок
щоденного запуску; потрібні Playwright/Chromium. Тест використовує реальні MFA/OTP,
зберігає/читає анкету й виконує `down/up` без видалення volumes.

Перевірка штатного оформлення Filament у цьому самому disposable сценарії:
`TELMANFIT_FILAMENT_THEME_BROWSER=1 python3 tests/browser/local_compose.py`.
Вона охоплює запрошення, клієнтів, анкету, dropdown і сповіщення у двох темах на
1440/390/320 px; [причина виправлення та докази](filament-theme.md).

