# CLAUDE.md — вказівник для Claude Code

**Канонічне джерело правил — [AGENTS.md](AGENTS.md).** Цей файл не містить власних правил, не доповнює й не замінює `AGENTS.md`: у разі будь-якої розбіжності діє `AGENTS.md`.

Прочитай повністю перед роботою (у цьому порядку):

1. [AGENTS.md](AGENTS.md) — правила, заборони, Definition of Done, формат звіту.
2. [docs/architecture.md](docs/architecture.md) — стек, шари, стан реалізації (каркас, без бізнес-модулів).
3. [docs/business-rules.md](docs/business-rules.md) — підтверджені бізнес-правила та їхні джерела.
4. [docs/roadmap.md](docs/roadmap.md) — етапи MVP.
5. [docs/entity-catalog.md](docs/entity-catalog.md), [docs/database.dbml](docs/database.dbml), `docs/01…04_*.sql`, [docs/schema-audit.md](docs/schema-audit.md) — джерела істини щодо сутностей, структури та PostgreSQL-обмежень.

Найважливіші заборони (деталі — в `AGENTS.md`, розділ 4):

- не вигадувати нові таблиці, поля та бізнес-правила;
- не змінювати погоджену архітектуру, код, міграції, `docs/*.sql`, `docs/database.dbml`, залежності, `config/*`, `.env`;
- не запускати міграції та руйнівні команди, не використовувати production-БД і секрети;
- не робити `git commit` без дозволу;
- не встановлювати Laravel Boost;
- не ухвалювати самостійно рішення з Open Questions ([docs/schema-audit.md](docs/schema-audit.md));
- після кожної задачі — звіт українською: файли, тести, ризики (формат у `AGENTS.md`, розділ 13).

Мова документації та комунікації — українська; технічні ідентифікатори — англійською.

