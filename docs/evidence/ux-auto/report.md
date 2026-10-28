# UX-AUTO bootstrap: локальна перевірка

Окремий погоджений scope Issue #107: **неактивний mock**. Bootstrap feasibility
PASS: доступні вихідний код, Python та локальна симуляція. Початкова база звірена
через GitHub connector і `git ls-remote`: `d0a3a6d4278feff360b6f11b2aff9ccd2c19a5f7`.
Live activation gate PARTIAL; реальні UX-етапи не запускалися.

## Результати

- `python3 -m unittest discover -s tests/ci -v`: **60 PASS**, включно з 20 тестами
  координатора. Негативні варіанти в subtests перевіряють усі три required workflows.
- `python3 -m unittest discover -s tests/docs -v`: **11 PASS**.
- Шість CLI-сценаріїв і повторний запуск кожного: [машинний результат](scenarios.json).
  `success` і `retry` завершили всі шість stages (exit 0). `failure` та `approval`
  вичерпали три спроби першого stage; `race` і `unconfirmed` заблокували перший stage;
  решта залишились pending (exit 2). Усі повторні запуски ідентичні.
- Restart після кожного переходу; crash після publish/merge до checkpoint; незалежний
  durable журнал; SHA/base race; resume і невичерпний бюджет заборонено; pause/lock;
  atomic write failure зберігає старий checkpoint; недовірений trigger відхиляється.
- CI missing/failed/stale/skipped/wrong event/attempt, wrong base/fork/spec/conflicts,
  review PASS без структури/незалежності, browser без matrix/assertions/screenshots
  або stale head не дозволяють merge. Реальна `pre_merge.evaluate` не змінена.

Перший загальний локальний прогін виявив жорсткий список workflows у regression
тесті; список доповнено новим mock workflow зі збереженням перевірок permissions/pins.
Socket test спочатку був заблокований sandbox, після дозволеного мережевого доступу
повний suite пройшов. Тести та guards не вимикалися.

## Межі та активація

Це не реальний Codex run, незалежний AI review, browser/PG acceptance або GitHub CI.
Mock screenshots/CI/PR/merge receipts — синтетичні дані. Не зроблено висновку про
готовність реальних UX PR. API spend mock — 0, production/реальні БД не змінено.

GitHub читання й push permissions підтверджено; читання
`repos/ValtonUA/telmanfit/actions/permissions` повернуло **403 Resource not accessible
by integration**. Отже, репозиторні push permissions не доводять workflow permissions.
Не підтверджено Codex API credential/billing, installation token для bot PR і
автоматичні PR-event required checks без approval. Не реалізовані live transport,
isolated coder/reviewer/publisher, durable CAS state і writeback #106; вони описані
в [контракті активації](../../ux-auto.md). API budget/model потребують числової
конфігурації перед live. Нових продуктових рішень для mock немає.

## Змінені файли

- `.gitignore`, `AGENTS.md`, `README.md`.
- `.github/workflows/ux-auto.yml`.
- `scripts/ux_auto/coordinator.py`, `scripts/ux_auto/mock.py`,
  `scripts/ux_auto/spec.json`, `scripts/ux_auto/issue-106.md`.
- `tests/ci/test_ux_auto.py`, `tests/ci/test_gate.py`.
- `docs/ci.md`, `docs/roadmap.md`, `docs/project-status.md`, `docs/ux-auto.md`.
- Цей звіт і `scenarios.json`.

Docs-Impact: оновлено правила вузького merge-винятку, CI-контракт, canonical
roadmap/status і операційну інструкцію; snapshot Issue #106 не є новим бізнес-контрактом.
Фінальний head SHA та GitHub CI стан належать опису Draft PR, не локальному PASS.
