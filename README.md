# TelmanFit

Вебзастосунок для персонального онлайн-супроводу до схуднення: тренер веде клієнтів у Laravel/Filament, клієнт використовує React-застосунок. Проєкт на стадії розробки, **не розгорнутий у production**.

**Стек:** Laravel 13, PHP 8.5 (цільовий runtime; Composer також дозволяє визначені patch-версії 8.3/8.4), PostgreSQL 18, Filament 5, React 19, TypeScript, Vite.

## Де що шукати

| Потрібно | Документ |
|---|---|
| **Що робимо зараз і наступним** | [Roadmap](docs/roadmap.md) — єдиний актуальний план і статуси |
| **Що вже реалізовано, а що лише спроєктовано** | [Project Status](docs/project-status.md) |
| **Чому дев'ять SQL, одинадцять PHPUnit XML та папки evidence/screenshots** | [Архітектура, карта технічних файлів](docs/architecture.md#31-карта-технічних-файлів) |
| **Локальна демонстрація у Windows/WSL2** | [Безпечний запуск на окремій PostgreSQL 18](docs/local-demo.md) |
| **Як має працювати клієнтський вебзастосунок** | [Канонічний продуктовий і UX-контракт](docs/client-app.md) |
| Як працює архітектура | [Architecture](docs/architecture.md) |
| Бізнес-правила | [Business Rules](docs/business-rules.md) |
| Таблиці й зв'язки | [Entity Catalog](docs/entity-catalog.md), [DBML](docs/database.dbml) |
| **Правила для Codex/AI-агентів** | [AGENTS.md](AGENTS.md) |
| Координатор UX та безпечна симуляція | [Операційна інструкція UX-AUTO](docs/ux-auto.md) |
| Зіставлення медіа каталогу з R2 | [Інструкція та інвентаризація](docs/r2-image-matching.md) |

## Стан реалізації

**Не фіксуємо у README поточні етапи, SHA та кількість готових функцій.** За ними звертайтеся до [roadmap.md](docs/roadmap.md). Фактичний стан коду, середовищ та результати перевірок — у [project-status.md](docs/project-status.md). Архітектура і межі між компонентами — [architecture.md](docs/architecture.md).

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

**Правило актуальності:** у тому самому PR, що змінює реалізацію або архітектуру, переглядати відповідні джерела; [правила для Codex і розробників](AGENTS.md#16-політика-актуальності-документації-обовязково-для-кожного-pr).

Локальна розробка на Windows 11 без PHP/Node на хості: [Docker Compose — одна команда запуску](docs/local-compose.md).
