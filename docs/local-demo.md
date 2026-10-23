# Безпечна локальна демонстрація

Це одноразовий стенд із синтетичними акаунтами, не production-конфігурація і не процедура disaster restore. Наявна `.env`, бази та їхні дані не змінюються. Потрібен окремий checkout без активного `public/hot` від іншого Vite-сервера.

## Причина відмови звичайного запуску

`AdminMfaBoundary` перед входом у Filament для PostgreSQL викликає `RestoreFence`. Fence вимагає одночасно незалежний `ExternalEpoch` і рядок `auth_security_runtime(id=1,state=ready)` із відповідним epoch. Порожній runtime після міграцій або відсутній adapter означають 503 `security_runtime_unavailable`. На вихідному master adapter реєструвався тільки при `APP_ENV=testing` + `TELMANFIT_PG_TEST=1`; звичайний `local` не мав binding. Саме вимкнення MFA це не усуває.

Клієнтський auth default OFF. Звичайний запуск без controlled-конфігурації повертає 404 для `/api/auth/*` («Вхід зараз недоступний»). Просте ввімкнення AUTH4 недостатнє: потрібні MFA policy, незалежний epoch, database session/cache, ключі OTP та контрольована доставка.

Браузер також виявив пропущений `/api/onboarding` у client boundary/session serialization: без правильного session context POST отримував 419, а GET не міг використати клієнтську сесію. Маршрут включено до цих меж **лише в local/testing**. Його помилки проходять через наявний захищений JSON handler; вимкнений controlled auth закриває маршрут. У production matchers та default OFF залишаються попередніми.

## Windows: запуск через WSL2

Потрібні Docker Desktop із Linux containers та WSL integration для вибраного дистрибутива, Git, Composer, Node.js 22+ та PHP CLI **8.4.27+ або 8.5.12+** з `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `intl`, `mbstring`, `xml`, `curl`, `zip`. Версію перевіряє скрипт до створення БД. У PowerShell відкрийте WSL:

```powershell
wsl
```

Далі у WSL, у каталозі **окремого checkout** гілки цього PR:

```bash
php scripts/check-php-runtime.php
composer install --no-interaction --no-scripts
npm install --ignore-scripts
npm ci --prefix client --ignore-scripts
bash scripts/local-demo/run.sh
```

Не запускайте `composer setup`: він має міграції для поточного `.env`. Docker context повинен бути локальним через Unix socket. Порт 8098 має бути вільний. Скрипт сам збирає Filament theme/assets та React, використовує зафіксований офіційний PostgreSQL 18.6 image, новий cluster на tmpfs і випадковий PG-порт **тільки на 127.0.0.1**. App працює також тільки на 127.0.0.1:8098; host БД, ім'я, роль, пароль та app/auth-ключі генеруються незалежно від `.env`.

Після `Demo bootstrap ready` дочекайтеся повідомлення «Локальний стенд». Виведений шлях `/tmp/telmanfit-auth4-XXXXXXXX/access.txt` містить випадковий admin-пароль та одноразове запрошення. Перегляньте **саме цей файл** у другому WSL-терміналі:

```bash
cat /tmp/telmanfit-auth4-XXXXXXXX/access.txt
```

Не комітьте й не публікуйте цей файл або `env.sh`. Усі локальні секрети зберігаються в приватному каталозі з правами 0700, файли — 0600.

1. У Windows-браузері відкрийте `http://127.0.0.1:8098/admin/login`, увійдіть як `owner@example.invalid` із паролем із файла. Натисніть «Створити ключ Authenticator», додайте показаний ключ у Authenticator/1Password, підтвердьте TOTP та збережіть резервні коди. Після enrollment потрібен **новий вхід із паролем + новим TOTP**. Без MFA картки закриті.
2. Відкрийте invitation URL із файла в іншому профілі/приватному вікні браузера. Реєструйте `client@example.invalid`. Після «Отримати код» прочитайте його в другому WSL-терміналі (замініть XXXXXXXX точним шляхом із виводу):

```bash
source /tmp/telmanfit-auth4-XXXXXXXX/env.sh
php scripts/local-demo/mail.php
```

3. Введіть OTP, завершіть реєстрацію з демонстраційним ім'ям. Картку створює штатний invitation flow; bootstrap не створює клієнтів вручну. Пошта synthetic: зашифрований локальний mailbox, лише `@example.invalid`, жодних SMTP/справжніх листів. Черга `sync`, окремий worker не потрібний.
4. У `/app` → «Заповнити анкету» введіть причини, формат роботи та кількість прийомів їжі → «Зберегти відповіді» → перезавантажте сторінку.
5. У Filament `/admin/clients` відкрийте створену картку → «Анкета — 3 запитання». Перевірте ті самі відповіді, змініть, підтвердьте, перезавантажте та відкрийте повторно. React після reload має бачити зміни. Статус супроводу залишається pending.

`Ctrl+C` у першому терміналі зупиняє PHP і видаляє **тільки контейнер цього запуску** та його тимчасові дані/секрети. Наступний запуск — новий порожній стенд, новий MFA enrollment. При аварійному завершенні хоста tmpfs не зберігає дані; залишки власного контейнера/каталогу перевіряйте адресно, без `docker system prune` чи масового очищення. Не використовуйте цей стенд для зберігання відповідей реальних клієнтів.

## Як зберігається безпека

`LocalDemoEpoch` підключається тільки за явним `TELMANFIT_LOCAL_DEMO=true` в `local`. Читання дозволене лише для loopback PostgreSQL, demo-імені БД та demo-ролі без DB URL override; незалежний файл читається заново при кожному assert, не з БД або `.env`. Немає fallback, кешу epoch чи автоматичного переведення відновленої БД у ready. Bootstrap відмовляється працювати за наявності users/runtime, перевіряє PG18 та непривілейовану роль; він призначений тільки для щойно створеного скриптом cluster. Втрата/підміна файла або розбіжність із runtime дає 503. Наявні RestoreFence, advisory locks, MFA/CSRF/session gates не вимикаються.

Не переносити цей adapter або epoch-файл у production: він демонструє незалежність від DB snapshot, але не реалізує production control plane, B5 restore drill або last-admin recovery. Production go/no-go не надано.

## Автоматичні перевірки

На машині з установленими Python Playwright та Chromium:

```bash
TELMANFIT_DEMO_BROWSER=1 bash scripts/local-demo/run.sh
php artisan test
AUTH4_ONLY=1 bash tests/pg/run-stage1.sh
```

Browser flag створює новий стенд, проходить справжній enrollment/login/OTP, перевіряє missing/mismatched epoch, заборону анонімного доступу, три відповіді через React та редагування/повторне відкриття у Filament і прибирає стенд. Не виконує session injection, не вимикає gate, не зберігає секрети в trace/HAR. Докази: [checks.json](evidence/local-demo/checks.json), [Filament](evidence/local-demo/filament-answers.png), [React](evidence/local-demo/client-answers.png). Unit/HTTP негативні перевірки — `LocalDemoEpochTest`, `OnboardingBoundaryTest`; повні PG onboarding-перевірки — `OnboardingSliceTest`.

Windows/WSL2 інструкція потребує ручного підтвердження на Windows; автоматизований прогін виконано в Linux на PHP 8.4.27/PostgreSQL 18.6/Chromium. Stage 3 та схема БД не змінюються.
