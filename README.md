# TelmanFit

Вебзастосунок для персонального онлайн-супроводу до схуднення: тренер веде клієнтів у Laravel/Filament, клієнт використовує React-застосунок. Проєкт на стадії розробки, **не розгорнутий у production**.

**Стек:** Laravel 13, PHP 8.5 (цільовий runtime; Composer також дозволяє визначені patch-версії 8.3/8.4), PostgreSQL 18, Filament 5, React 19, TypeScript, Vite.

## Де що шукати

| Потрібно | Документ |
|---|---|
| **Що робимо зараз і наступним** | [Roadmap](docs/roadmap.md) — Stage 3: каталог продуктів і рецепти |
| **Що вже реалізовано, а що лише спроєктовано** | [Project Status](docs/project-status.md) |
| **Чому дев'ять SQL, одинадцять PHPUnit XML та папки evidence/screenshots** | [Архітектура, карта технічних файлів](docs/architecture.md#31-карта-технічних-файлів) |
| Як працює архітектура | [Architecture](docs/architecture.md) |
| Бізнес-правила | [Business Rules](docs/business-rules.md) |
| Таблиці й зв'язки | [Entity Catalog](docs/entity-catalog.md), [DBML](docs/database.dbml) |
| **Правила для Codex/AI-агентів** | [AGENTS.md](AGENTS.md) |

## Реальний стан

- **Stage 1:** бізнес-схема 37 таблиць і міграції перевірені на ізольованій PostgreSQL 18.
- **Stage 2:** Filament-картки клієнтів та controlled local/testing auth (запрошення, email OTP, MFA, React security); production activation **OFF**.
- **Наступний Stage 3:** продукти, інгредієнти, рецепти та публікація — ще без реалізованих бізнес-форм і сервісів.
- Раціони, прогрес, тренування й Telegram-потоки не є готовими клієнтськими функціями.
- Hyperhost VPS Старт, SendPulse і зовнішні резервні копії — **обрані, але не налаштовані**; тестування VPS та B7 — перед production.

## Каталоги

| Каталог/файли | Призначення |
|---|---|
| `app/` | Laravel API, Filament, моделі та сервіси |
| `client/` | React/TypeScript SPA |
| `database/migrations/`, `database/sql/` | Виконувана інтеграція PostgreSQL |
| `docs/` | Roadmap, бізнес-правила, схеми та історичні технічні звіти |
| `tests/`, `phpunit*.xml`, `.github/workflows/` | Автоматизовані перевірки |
| `docs/evidence/`, `docs/screenshots/` | Докази тестів (не runtime-файли застосунку) |

**Безпека:** не запускати SQL/міграції на існуючій або production БД та не змінювати auth gates без окремого дозволу. Тести PostgreSQL виконуються в ізольованому середовищі за [tests/pg/README.md](tests/pg/README.md). Перед будь-якою задачею Codex читає [AGENTS.md](AGENTS.md).

**Поточний пріоритет:** реалізація продукту, не підготовка VPS.
