# T5.2 — перевірки клієнтського раціону

FEASIBILITY: PASS. База — актуальний `master` після merged [PR #83](https://github.com/ValtonUA/telmanfit/pull/83),
`f2a9146ff958e0f0f1a00a6a97c2a24bc142fa81`. До реалізації прочитано AGENTS §2.1,
roadmap/status, client-app, meal-plans, бізнес-правила та код T5.1/Model I.
Мінімальна перевірка: PHP 8.5.12, офіційний PostgreSQL 18.6, SQL/btree_gist
у новому network-none/tmpfs контейнері, production SPA build на чинному lock.

## Результат і межі

API → React → PostgreSQL/HTTP/Chromium реалізовано. Доменний контракт і JSON —
[meal-plans](../../meal-plans.md#клієнтський-календар-і-меню-t52), статус і майбутні
рішення — [roadmap](../../roadmap.md). Нових таблиць, міграцій, залежностей,
Composer/Infection/CI/Compose змін немає. Production/auth activation не виконано.
Повний [перелік змінених файлів](changed-files.txt) збережено поряд зі звітом.

Усі БД — власні нові disposable PG18 із синтетичними даними та штатними guards.
PGDATA й новий socket volume — tmpfs. PG та CLI PHP — `network=none`, без портів
і Docker socket. Окремий browser PHP server має лише loopback mapping
`127.0.0.1:18052`, PostgreSQL доступний йому через власний Unix socket volume.
Образ PHP взято з наявного локального image, але робочі контейнери/volumes не
використовувалися. Робочі `telmanfit-local`, його PG, MFA/паролі й конфігурація
не змінювалися. Тестова роль непривілейована, БД `telmanfit_stage1_test`.

## Локальні перевірки

| Перевірка | Результат |
|---|---|
| Повний `phpunit -c phpunit.auth4.xml` | 91 tests / 843 assertions PASS; 34 нові сценарії нового endpoint |
| `phpunit -c phpunit.auth5.xml` | 19 / 131 PASS |
| `php artisan test` | 87 / 364 PASS, SPA build наявний, без skips |
| `npm run lint --prefix client` | PASS |
| `npm run build --prefix client` | PASS, TypeScript + Vite |
| Pint для змінених PHP-файлів | PASS |
| Chromium/Edge 156 | PASS; [машинний звіт](browser-checks.json) |

HTTP тести використовують cookie після штатного OTP/ClientSession; перевіряють
7/14/21 днів, універсальні/персональні плани, порядок, exact версії, ingredient
g/count UOM, legacy і A/1 B/7 C/3 C/4 із дробовими порціями. Перевірені календар
Пн→Ср і Ср→Ср, day 8, end_date, exhaustion без нового циклу, майбутня заміна,
owner/draft/MFA/revocation/fence та підставні IDs. Успадковані gates T5.1 виконано
також для `/days`: GET/HEAD не змінюють усі public rows/xmin/TTL, GC lottery=100%,
SQL-log не містить DML/DDL. Рівно 7 domain SELECT для 5 і 7 днів, без N+1.
Логічні covers відокремлені від step images, небезпечні URL відсікаються;
окремий тест CSP виключає wildcard/localhost/injection.

Chromium проходить реальний OTP login → кабінет → `/app/meal-plan` → API.
Перевіряє weeks/days/keyboard, готові серверні кількості, exact recipe ID у
payload, відсутність несправних посилань, loading, успішне та пошкоджене фото,
error/retry, empty assignment, зміну assignment і revoked session. Помилка 503,
пошкоджене зображення та неможливий у валідній published-схемі порожній день
інжектуються лише в browser transport; нормальне меню, empty assignment,
replacement і session revocation працюють зі справжньою test PG/API.
Для всіх 6 viewport/theme поєднань перевірено відсутність горизонтального
overflow; контраст заголовків карток ≥4.5, решту кольорів перевірено візуально.
Фізичні мобільні пристрої та screen reader вручну не перевірялися.

## Скриншоти синтетичного тестового призначення

| Ширина | Light | Dark |
|---|---|---|
| 1440 | [Light 1440](meal-plan-light-1440.png) | [Dark 1440](meal-plan-dark-1440.png) |
| 390 | [Light 390](meal-plan-light-390.png) | [Dark 390](meal-plan-dark-390.png) |
| 320 | [Light 320](meal-plan-light-320.png) | [Dark 320](meal-plan-dark-320.png) |

Назви synthetic на знімках — лише test fixtures. Реальний UI не містить fallback
страв, вбудованих fixtures чи демонстраційного меню.

## Відтворення

Linux PG/API: `AUTH4_ONLY=1 bash tests/pg/run-stage1.sh`; AUTH-5 окремо:
`AUTH5_ONLY=1 bash tests/pg/run-stage1.sh`. Runner сам створює нову БД і прибирає
її; не підставляти робочий DB_HOST. Windows потребує еквівалентного orchestration
із новими контейнерами та socket volume; guards не змінюються.

Browser: на такому самому disposable testing сервері після повних міграцій і
`php tests/pg/auth6b5-bootstrap.php` встановити штатні controlled AUTH3/4/5 env,
database sessions/cache, synthetic mailbox `/tmp/telmanfit-auth4-<id>/mailbox`,
`MAIL_MAILER=auth4_synthetic`, sync queue, `PUBLIC_IMAGE_HOSTS=images.example.com`.
Запустити `php tests/browser/client-meal-plan-fixture.php`, зібрати SPA та сервер,
потім `node tests/browser/client_meal_plan.cjs` з `CLIENT_MENU_BASE` loopback URL.
Playwright використовується з наявного тестового runtime, без package.json змін.
Для Windows доступні `CLIENT_MENU_DOCKER=<власний disposable PHP container>` і
`CLIENT_MENU_CHANNEL=msedge`; це окремий тимчасовий browser profile.

GitHub Fast/Docs/Targeted оцінюються окремо на актуальному head PR; локальні PASS
не замінюють GitHub checks. Merge та Full CI не виконуються.
