# UX-AUTO live adapters: виконана перевірка та межі

**Розробка адаптерів без секретів — feasibility PASS. Live activation — PARTIAL.**
База — bootstrap `cf5e52eb8630cb91c326a48e0c1a5a3b54b15842` з відкритого PR #108;
master read-only звірено: `d0a3a6d4278feff360b6f11b2aff9ccd2c19a5f7`. Окремий Draft
PR є stacked над #108, без merge bootstrap і без активації реальних UX-етапів.

## Реалізація

Наявний state machine збережено; додано лише live mode, `Waiting` та окремий
sandbox evidence contract. Реалізовано REST publishing і expected-head merge,
App JWT/token refresh, GitHub Contents CAS/lease/artifact storage, conservative
budget ledger, Docker Codex coder/reviewer, bounded Responses relay, isolated
PG18/browser launcher, owner CLI/workflow та status writeback comments до #106.
`pre_merge.py` не змінено. Workflow default-disabled, pinned controller ref.

## Локальні тести

- `python3 -m unittest discover -s tests/ci -v`: **90 PASS**; 20 попередніх coordinator
  тестів і 30 нових live adapter тестів, включно з negative subtests.
- `python3 -m unittest discover -s tests/docs -v`: **11 PASS**.
- Шість transport-сценаріїв: [contracts.json](contracts.json). GitHub та Codex там
  **фіктивні**, API-поверхні/state/branch/PR/merge/CAS мають production-shaped payloads.
  Success: 6 PR, 12 різних coder/reviewer invocations, 6 expected-head merge;
  rejected review/browser, disabled, budget, waiting approval не дозволяють merge.
- Перевірено: restart, branch/PR timeout reconciliation, timeout після merge до
  checkpoint без другого merge; pending CI без витрати спроби; stale SHA/base,
  fresh gate/base race; exclusive lease, CAS conflict, expiry, owner pause;
  persisted daily/total reservations та заборона blind API retry після ambiguous crash.
- Scope/path/hash/size guards відхиляють workflows/gates/secrets/schema, traversal,
  symlink, rename/delete, binary. Реальний локальний Git clone перевіряє нові файли;
  malicious candidate Git clean filter **не виконується publisher**.
- Reviewer schema/file:line/technical reason, browser matrix/assertions/PNG width,
  proxy model/request/input/output/remote context caps, App scope/refresh без ключа
  в argv, Docker role argv/timeout cleanup/default-disabled workflow перевірено.

## Справжній Chromium, окремо від UX acceptance

`probe_browser.py` виконано через installed Playwright + `/usr/bin/chromium`
на loopback synthetic HTML. Усі 1440/390/320 light/dark: history push/back без
зміни document identity, computed theme, no-overflow і реальні screenshots PASS.
[Machine report із hashes](browser.json); PNG:
[1440 light](1440-light.png), [1440 dark](1440-dark.png),
[390 light](390-light.png), [390 dark](390-dark.png),
[320 light](320-light.png), [320 dark](320-dark.png).

Перший запуск виявив відсутність bundled Playwright browser; використано встановлений
Chromium. `file://` відхилено browser policy; probe переведено на штатний loopback HTTP.
Це synthetic probe, **не** перевірка UX-1…UX-6 або реального Laravel/Filament UI.
Позначений head у browser report — базовий checkout probe, не tested SHA UX PR.

## Справжній Docker/relay/PG18, без API-ключів

[Docker machine report](docker.json): реальний Docker 28.4, pinned Python image
та погоджений PG18 image. Перевірено internal worker→relay health, відсутність
GitHub/OpenAI real credentials у worker, worker `network none`, disposable PG18
version/readiness/connectivity на internal network без published ports. **0 залишених
власних контейнерів/мереж**, cleanup підтверджено. OpenAI API calls — 0.

Реальна перевірка знайшла помилку прав bind mounts: root без DAC capability не
читав 0600 файли власника checkout. Додано explicit UID/GID worker/relay, збережено
cap-drop/read-only/no-new-privileges; після виправлення всі три Docker probe PASS.
Full Codex worker image не будувався; тут використовувався мінімальний pinned Python
image для перевірки transport/isolation, а не для імітації реального Codex.

GitHub adapter CI першого head виявив SIGXFSZ при clone packed repository:
загальний 8 MB file limit зачіпав не лише stdout, а й Git packfile. Довірені
clone/fetch отримали окрему bounded 512 MB межу, ліміт worker output збережено.
Додано regression test з реальним synthetic 9 MB packfile; тести/guards не вимикалися.

## Неперевірене та блокери активації

Немає реальних Codex API credentials/billing, підтвердженого model/числового budget,
audited повного worker image з preinstalled app dependencies, GitHub App installation
secrets/доступів для main+sandbox та реального bot-created PR-event CI vertical slice.
Ці передумови налаштовуються один раз за [runbook](../../ux-auto-live.md).
Потрібні merge dependency/adapters звичайним порядком і pinned trusted master ref.

Справжні Codex coder/reviewer, sandbox PR/merge, Laravel browser/PG acceptance для
майбутніх UX-етапів і live workflow **не запускалися**. Самі adapter tests та локальні
Chromium/Docker probe не замінюють зовнішній feasibility gate. Workflow/CLI не допускають
реальних stages без config enabled + перевіреного sandbox receipt + окремої activation.

Status writeback — idempotent issue comments і durable stage reports. Body/checklist
#106 свідомо не переписується без перевіреного CAS, щоб не втратити сторонні правки.
Немає production/реальних БД/feature flag змін; API spend перевірок — 0.
Нових продуктових/архітектурних рішень для цієї реалізації не потрібно; реальні ціни,
model/image approvals і API budget власник мусить конкретно налаштувати перед live.

## Змінені файли

- `.github/workflows/ux-auto-live.yml`, `.github/workflows/ux-auto-adapters-tests.yml`.
- `scripts/ux_auto/coordinator.py`, `api_proxy.py`, `app_token.py`, `backend.py`,
  `github.py`, `live.py`, `live_config.py`, `live.example.json`, `probe_browser.py`,
  `review-schema.json`, `store.py`, `worker.py`.
- `tests/ci/test_ux_auto_live.py`, `tests/ci/test_gate.py`.
- `docs/ux-auto.md`, `docs/ux-auto-live.md`, `docs/ci.md`, `docs/project-status.md`,
  `docs/roadmap.md`.
- Цей report, `contracts.json`, `browser.json`, `docker.json` та шість PNG.

Docs-Impact: canonical facts/status/CI оновлено разом з реалізацією; одноразове
налаштування, команди, safety/budget та межі proof наведено в профільному runbook.
Фінальні head SHA та GitHub checks — в описі Draft PR. Локальний PASS не є GitHub CI.
