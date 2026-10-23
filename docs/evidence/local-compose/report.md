# Перевірка постійного Docker Compose

Реалізовано локальне середовище PHP 8.5.12 / Laravel 13, PostgreSQL 18.6,
React/Vite, nginx, queue worker та scheduler. Усі runtime/dependency tools містяться
в образах. Постійні named volumes зберігають БД, APP_KEY/OTP-ключі, незалежний epoch,
локальну encrypted mailbox і приватне storage. Production/default auth та Stage 3
не змінені; бізнес-схема й міграції не редагувалися.

Початкова основа — master `e7dac72` та PR #31 `bddd007`. PR #31 змерджено
як `dc240dc62c0f22e3491c81190caa84d860d63a33`; PR #32 перенаправлено на `master`.
Diff зберігає лише Compose-зміни; auth/session виправлення PR #31 не дублюються.
PR #32 залишається Draft до підтвердження перевірок.

## Виконані перевірки

- PHP 8.5 контейнер: `php artisan test --display-all-issues` — **47 passed / 111 assertions**.
- Окремий новий PostgreSQL 18 без мережі, штатні міграції та
  `php vendor/bin/phpunit -c phpunit.auth4.xml --display-all-issues` —
  **29 passed / 176 assertions**; власний тимчасовий контейнер прибрано.
- Chromium: `COMPOSE_PROJECT_NAME=telmanfit-compose-verified python3 tests/browser/local_compose.py`
  — **7 груп PASS**; [повний список](checks.json).
- Окремий bootstrap probe: втрата початкового marker на непорожній БД дає ненульовий
  exit code; міграції/очищення не запускаються. Відсутній epoch також дає ненульовий
  health exit code та HTTP 503.
- Реальні password/TOTP enrollment/login та invitation/email OTP, без session injection.
  POST анкети без CSRF — 419, анонімне читання — 401.
- React: три відповіді save/reload. Filament: картка → анкета → зміна → save/reload;
  React читає зміну з тієї самої БД.
- Справжні `docker compose down` та `docker compose up -d --wait` **без видалення volumes**:
  ті самі browser sessions, відповіді та новий password+TOTP вхід без enrollment.
- nginx: `/app` і `/admin/login` — HTTP 200. Database/app/web health checks пройдені;
  worker та scheduler запущені. PostgreSQL host-port відсутній; web лише loopback.
- Root Vite build та SPA `npm run lint` / `npm run build` пройдені в Node-контейнері.
- Pint, `docker compose config --quiet`, `git diff --check`, docs guard та його
  **5 тестів** пройдені (docs guard запускається після створення цього звіту).

Текстовий доказ: [checks.json](checks.json). Локальні screenshots Filament/React
не входять до Git: автоматична перевірка відхилила їхній експорт через відсутність
окремої згоди на завантаження зображень. Вони залишені лише у workspace.
Секрети/TOTP seed/recovery codes не записувалися у screenshots, trace або HAR.

## Межі перевірки

Наведені вище результати — попередній cloud-прогін. Фінальний аудит виконується
окремо: [Local Compose cold build](../../../.github/workflows/local-compose.yml)
бере точний head SHA, запускає `docker compose build --pull --no-cache` зі стандартним
Compose/Dockerfile без offline override, створює нові synthetic volumes і проходить
Chromium flow з down/up. Публікується лише текстовий `checks.json`, без секретів
і screenshots. Успіх слід звіряти з конкретним SHA в GitHub Actions.
На Windows-хості аудиту Docker відсутній, у WSL немає встановленого дистрибутива;
локальний cold build/browser тут не запускався.

Перевірено Linux Docker у керованому cloud-середовищі, не Windows 11 / Docker Desktop.
Перші стандартні container builds (Composer, root/SPA npm) пройшли. Під час фінальних
ітерацій cloud proxy втратив upstream tunnel: npm, Docker Hub та GitHub через shell
повертали 503. Фінальні runtime/browser перевірки виконано з тимчасовим **поза Git**
offline build override: наявні client node_modules звірено з manifest, lint/build
виконано всередині Node-контейнера; виправлені CLI helpers/entrypoint додано в образ
локальними COPY шарами. PHP-код, конфігурація nginx і Compose відповідають файлам PR.
Остаточний повний cold build стандартного Dockerfile потребує повтору при справній
мережі. Жодних TLS/proxy/auth послаблень або offline override у коді немає.

Власний перевірений stack використовує окреме ім’я `telmanfit-compose-verified`;
його постійні volumes залишені зі синтетичними даними. Наявні користувацькі/реальні
БД та секрети не використовувалися. Незалежний epoch-file — лише local adapter,
не production restore/control plane. Частковий перший bootstrap і нові міграції
після початкової інсталяції закривають запуск і потребують окремого розбору/upgrade;
автоматичних wipe, fresh або перевидачі MFA немає. Volumes не замінюють backups.
Блокуючих продуктових Open Questions для цього локального scope немає.

## Документаційний вплив

Оновлено README (навігація), architecture (runtime), project-status (факти),
додано українську інструкцію local-compose. Roadmap/Stage 3 не змінені: це
інфраструктура для вже реалізованого slice, без нового бізнес-рішення.

## Повний перелік змінених / нових файлів

- `.dockerignore`
- `.github/workflows/local-compose.yml`
- `.gitignore`
- `README.md`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Auth/Restore/LocalComposeEpoch.php`
- `compose.yaml`
- `config/local_compose.php`
- `docker/local/Dockerfile`
- `docker/local/access.php`
- `docker/local/bootstrap.php`
- `docker/local/database-init.sh`
- `docker/local/entrypoint.sh`
- `docker/local/health.php`
- `docker/local/load-env.php`
- `docker/local/mail.php`
- `docker/local/nginx.conf`
- `docker/local/setup.php`
- `docs/architecture.md`
- `docs/evidence/local-compose/checks.json`
- `docs/evidence/local-compose/report.md`
- `docs/local-compose.md`
- `docs/project-status.md`
- `package-lock.json`
- `tests/Feature/LocalComposeEpochTest.php`
- `tests/browser/local_compose.py`
- `tests/browser/local_demo.py`
