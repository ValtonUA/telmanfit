# TelmanFit — технічний стан реалізації

**Цей документ містить лише підтверджені факти коду й тестування, не черговість робіт.** Поточний пріоритет, статус кожного T/G/AUTH і відкриті рішення ведемо **тільки у [roadmap.md](roadmap.md)**. Правила оновлення — [AGENTS.md §16](../AGENTS.md#16-політика-актуальності-документації-обовязково-для-кожного-pr).

**Як читати:** наявна SQL-схема ≠ готовий бізнес-модуль; наявний код MFA ≠ дозвіл production. Перед твердженням звіряй відповідні `app/`, `client/`, `database/`, `tests/` та актуальний PR/CI.

## Підтверджені факти й межі

| Підсистема | Реалізовано / перевірено | Не реалізовано / не підтверджено | Джерело |
|---|---|---|---|
| Backend і UI | Laravel 13, Filament 5, React 19/TypeScript/Vite; health API, SPA fallback і адмінка `/admin` | Каркас ще не означає готовий MVP | `composer.json`, `client/package.json`, `routes/`, [architecture.md](architecture.md) |
| Бізнес-схема | Laravel-міграції 37 таблиць `01–04`, збереження `users` за B+R1; ізольована PG 18.6 з `btree_gist`, SQL `PASS=76 FAIL=0 GAP=0`, PG PHPUnit/CI | Це не реалізація продуктового UI; реальний Hyperhost не перевірено | [stage-1.md](stage-1.md), [schema-audit.md](schema-audit.md), [tests/pg](../tests/pg/README.md) |
| Клієнти та перша анкета | `Client`, `ClientPolicy`, Filament `/admin/clients`; React `/app/onboarding` з першими 3 реальними запитаннями, GET/POST `/api/onboarding`, PostgreSQL `client_onboardings.answers` і перегляд у Filament | Це **частина** Google-форми, не завершений опитувальник; auth тільки controlled/local/testing. Жодного автоматичного `active` / `completed_at` | [stage-2.md](stage-2.md), [roadmap.md](roadmap.md), `app/Http/Controllers/Api/ClientOnboardingController.php`, `tests/Auth4/OnboardingSliceTest.php` |
| Авторизація | AUTH-3/4/5: Filament MFA, invitation, email OTP, client sessions, React auth/security; додаткові auth міграції та тести | **Controlled/default OFF**, не production; AUTH-6B B3–B6 лише guarded/synthetic, а не operational recovery | [auth3.md](auth3.md), [auth4.md](auth4.md), [auth5.md](auth5.md), [B3](auth6-b3.md)–[B6](auth6-b6.md) |
| Продукти/раціони | Бізнес-схема й обмеження PostgreSQL | Повноцінних моделей, редакторів і сервісів публікації/призначення немає | [roadmap.md](roadmap.md), [business-rules.md](business-rules.md) |
| Прогрес/комунікація | Відповідні таблиці запроєктовано та частково включено в бізнес-міграції | Немає реалізованих UI/API прогресу, бізнес-повідомлень і Telegram adapter | [roadmap.md](roadmap.md), [entity-catalog.md](entity-catalog.md) |
| Інфраструктура | Hyperhost VPS Старт, SendPulse і зовнішні копії **обрані**; документаційний D8.1 preflight виконано | VPS/SMTP/backup restore не налаштовано й не доведено. Погоджений RPO до 24 год — ціль, не виміряний результат | [D8](auth6-d8-infrastructure.md), [D8.1](auth6-d8-preflight.md) |

## Докази й перевірки

| Напрям | Де перевіряти | Межі |
|---|---|---|
| Бізнес-схема | `tests/pg/`, `phpunit.pgsql.xml`, [Stage 1](stage-1.md) | Підтвердження в ізольованій PostgreSQL не дорівнює production |
| Auth та browser | `phpunit.auth*.xml`, `tests/Auth*`, [AUTH-3](auth3.md), [AUTH-4](auth4.md), [AUTH-5](auth5.md), `docs/evidence/` | Тестові прапорці та синтетична доставка не означають activation |
| Вигляд Filament | [UX-1](ux-1.md), `docs/screenshots/ux-1/` | Скриншоти з тестового браузера, не клієнтські файли |
| Recovery/retention/budgets | [B3](auth6-b3.md), [B4](auth6-b4.md), [B5](auth6-b5.md), [B6](auth6-b6.md) | Підтверджені лише зазначені там synthetic/guarded операції |
| CI | `.github/workflows/stage1.yml`, `.github/workflows/php-runtime.yml` | Результати звіряти за **конкретним SHA**, не копіювати старий PASS |

## Правило підтримки

При зміні реалізації/тестів **у тому самому PR** змінюй конкретний факт або доказ у цьому файлі. Застаріле речення замінюй, **не додавай зверху ще один датований звіт**. Пріоритети та статуси тут не дублюються: [roadmap.md](roadmap.md) — єдина поточна шкала. Історія попередніх датованих звітів збережена в [Git history](https://github.com/ValtonUA/telmanfit/commits/master/docs/project-status.md) та модульних файлах `docs/auth*.md`, `docs/stage-*.md`.
