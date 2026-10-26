# T4.5 — локальний предметний review і перевірки

Перевірено 26.10.2026 на ізольованому PHP 8.4.27 / PostgreSQL 18.6.
Основа — актуальний master після merge PR #73. Канонічний контракт —
[DEC-4A](../../meal-plans.md#призначення-клієнтам-t45-dec-4a).

## Результат

- PostgreSQL/Livewire: **20 PASS / 264 assertions**.
- Concurrency: **9 PASS**, у кожному сценарії доведено реальний PG lock wait.
- Chromium: **8 груп перевірок PASS**: штатний login/MFA, шість комбінацій
  1440/390/320 × light/dark та stale/CSRF419/revoked403.
- Filament theme build, Pint, docs guard із PR base, shell syntax та diff check: PASS.
- Лише нова disposable PG18, network none, без зовнішніх портів; cleanup успішний.

Команда відтворення (за наявності залежностей, зібраної теми та сумісного PHP):

```bash
MEAL_PLAN_ASSIGNMENT_ONLY=1 MEAL_PLAN_ASSIGNMENT_BROWSER=1 bash tests/pg/run-stage1.sh
```

## Предметний review

Перевірено включні межі, сьогодні/майбутнє, одноденне попереднє призначення,
непересічний період без replacement link, конфлікти наступних призначень,
exact version/персонального власника, fresh admin/recovery fence, спільний
lock order із publisher/restore, підпис input/effect/snapshot, replay/stale/ABA,
незмінність published та атомарність дати/link/new/event. Виправлено нормалізацію
порожньої дати, identity no-op, Filament injection і прив’язку повідомлень до action.
Невиправлених предметних findings у власному review немає.

## Межі та ризики

Full CI, повний Stage1 і повна Chromium regression **не запускалися**;
Full відкладається до незалежного review власника. Fast PR і Documentation health
перевіряються окремо на head PR; локальні PASS їх не замінюють. Фізичний mobile
пристрій вручну не перевірено; responsive перевірено Chromium. «Сьогодні» — чинний
серверний Laravel календар. Нових відкритих предметних рішень для T4.5 немає.
Production, робочі БД, flags, залежності, SPA і Telegram не змінено; merge/auto-merge
не виконувалися. Схема та глобальна таксономія подій не додавалися.

## Усі змінені файли

- `app/Filament/Resources/Clients/ClientResource.php`
- `app/Filament/Resources/Clients/Pages/ManageMealPlanAssignments.php`
- `app/Filament/Resources/Clients/Pages/ViewClient.php`
- `app/Services/MealPlans/MealPlanAssigner.php`
- `docs/business-rules.md`
- `docs/ci.md`
- `docs/entity-catalog.md`
- `docs/evidence/meal-plan-assignments/report.md`
- `docs/meal-plans.md`
- `docs/project-status.md`
- `docs/roadmap.md`
- `docs/schema-audit.md`
- `tests/MealPlans/AssignmentFixture.php`
- `tests/Postgres/MealPlanAssignmentTest.php`
- `tests/browser/meal-plan-assignment-fixture.php`
- `tests/browser/meal-plan-assignment-in-context.sh`
- `tests/browser/meal_plan_assignments.py`
- `tests/pg/meal-plan-assignment-concurrency.php`
- `tests/pg/run-stage1.sh`
