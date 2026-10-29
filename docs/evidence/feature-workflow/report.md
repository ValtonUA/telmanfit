# Докази впровадження feature → master

Реалізовано ізольовані ревізії feature, Windows launcher, admission/pre-merge gates,
CI та read-only план/server activation tool. Продуктове приймання, незалежний review,
merge й активація ruleset — наступні контрольовані стани, не факти цієї реалізації.
Канонічна процедура — [feature-environments.md](../../feature-environments.md).

## Доопрацювання за review PR #117

Зауваження взято з [коментаря власника](https://github.com/ValtonUA/telmanfit/pull/117#issuecomment-6505598383).

| Зауваження | Результат реалізації | Доказ / межа |
|---|---|---|
| Реальний незалежний reviewer | Додано live capability preflight до ruleset activation; без окремої write-ідентичності та реального exact-head APPROVED запис неможливий | API показує лише автора `ValtonUA`; [snapshot](reviewer-capability.json). Route **BLOCKED**, не підмінений synthetic PASS. Конкретна machine-user/service пропозиція — у runtime-контракті; інтеграція не активована |
| Накопичення активних previews | Одне активне preview в registry; штатні stop/resume, перевірка ownership усіх peers, rollback після невдалої збірки/запуску | Container rootfs зупинених ревізій прибираються; БД/storage/images залишаються. Непідтверджений cleanup не запускає ще один runtime |
| Ітерації з даними | Повторний update того самого SHA відновлює ту саму ревізію; новий SHA вимагає явного fresh, старі дані доступні через resume | Немає populated DB upgrade або копіювання data/history між версіями. Digest images перевіряється перед resume |
| Windows policy | Основна команда без override execution policy; matrix виконує launcher з observer Python і перевіряє native argv у шляху з пробілами | Жодного Docker/DB у Windows stub-тесті; policy до/після не змінюється. Повний Docker Desktop runtime на Windows залишається продуктовим прийманням |

## Перевірки

- Локально `python3 -m unittest discover -s tests/ci -v`: 76 PASS, включно
  same-SHA reuse, explicit fresh, cross-feature parking, stop/resume, immutable
  images, failed build/resume recovery, unknown ownership, reviewer capability
  і відсутність зовнішнього PUT за недоведеної ідентичності.
- Docs tests/guard, Python/PHP syntax, Pint для PHP та diff-check — у перевірках PR.
  Фінальний exact-head SHA/run URLs і результати в описі PR, а не підмінені
  зеленим CI попереднього head.
- Docker E2E використовує публічні методи manager для lifecycle, без ручного
  `down/up` старих ревізій у harness. Перевіряє синтетично введені дані users і
  storage marker, повторний update, явний fresh, stop/resume, one-active стан,
  failed build recovery, точні pending/nonempty guards, fingerprints усіх
  таблиць/схеми/history, cleanup власних ресурсів і незмінний stable inventory.
- У Docker harness замінено лише зовнішній admission синтетичним fixture;
  це не доказ доступної reviewer-ідентичності. Source snapshot фіксується один
  раз, runtime SQL працює лише після PG18 identity/proof preflight disposable БД.
- На попередньому head PG18 bootstrap, HTTP, Chromium hostname/cookie canary та
  всі п’ять CI workflows пройшли. Ці факти не видаються за PASS доопрацьованого head.
- Повний local vfs/32GB прогін раніше вичерпав диск. Непідтверджений cache cleanup
  відхилив automatic security review, і він не виконувався; безпечна альтернатива —
  required disposable GitHub Docker runner. Спільний build-cache не очищується.

## Межі та наступний контрольований стан

Звичайні виправлення коду/тестів/документації й оновлення PR виконуються в чинному
scope. Робочі БД, production, стандартний Compose, migration allowlist і SQL20
upgrade guard не змінені. Синтетичні DB/storage marker writes виконуються лише
в disposable тесті; немає schema/data змін робочого застосунку.

Reviewer precondition — **PARTIAL/BLOCKED**: доступний лише акаунт автора.
Підготовлено конкретний еквівалентний шлях через credential ізольованої
machine-user/service і реальний Reviews API, але такого credential/актора зараз
немає. Немає запрошень іншим людям, створення акаунтів, самосхвалення чи ослаблення
formal approval. Активація ruleset/merge заборонені до доведеного route та нового
незалежного review/продуктового приймання. Продуктовий власник не погоджує технічні
операції кожної функції. Відкрита зовнішня передумова — саме окрема reviewer
ідентичність; нових бізнесових рішень немає.

## Усі змінені файли

- `.github/workflows/docs-health.yml`
- `.github/workflows/feature-acceptance.yml`
- `.github/workflows/feature-environments.yml`
- `.github/workflows/local-update-powershell-compat.yml`
- `AGENTS.md`
- `README.md`
- `docker/local/access.php`
- `docker/local/setup.php`
- `docs/agent-workflow.md`
- `docs/architecture.md`
- `docs/ci.md`
- `docs/evidence/feature-workflow/report.md`
- `docs/evidence/feature-workflow/reviewer-capability.json`
- `docs/feature-environments.md`
- `docs/local-compose.md`
- `docs/project-status.md`
- `scripts/ci/feature_rules.py`
- `scripts/ci/pre_merge.py`
- `scripts/ci/publish_feature_gate.py`
- `scripts/feature.ps1`
- `scripts/feature_env.py`
- `tests/ci/test_feature_env.py`
- `tests/ci/test_feature_rules.py`
- `tests/ci/test_gate.py`
- `tests/feature-env-e2e.py`
- `tests/feature-powershell-launch.ps1`
- `tests/local-update-powershell-compat.ps1`
