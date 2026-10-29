# Докази впровадження feature → master

Реалізовано ізольовані ревізії feature, Windows launcher, admission/pre-merge gates,
CI та read-only план/server activation tool. Продуктове приймання, незалежний review,
merge й активація ruleset — наступні контрольовані стани, не факти цієї реалізації.
Канонічна процедура — [feature-environments.md](../../feature-environments.md).

## Перевірки

- `python3 -m unittest discover -s tests/ci -v`: 71 PASS локально, включно
  позитивними/негативними admission, ownership, lifecycle, ruleset і publisher.
- `python3 -m unittest discover -s tests/docs -v`: 11 PASS.
- `python3 scripts/docs_guard.py --pr-base origin/master`: PASS; PHP syntax,
  Pint для змінених PHP і `git diff --check`: PASS.
- Локальний Docker: новий PG18, initial migrations, healthy app/web, HTTP login,
  invitation hostname, Chromium реальний `*.localhost` та cookie canary PASS.
  Повний локальний двоверсійний прогін не пройшов через `No space left on device`
  у cloud Docker vfs/32GB; це не local PASS. Власні failed stacks очищено.
- Повний двоверсійний lifecycle, збереження старої БД/секретів, pending/nonempty
  migration negatives і cleanup виконуються required `Feature environment checks`.
  Посилання й результат exact-head GitHub run — в описі PR; цей файл не підмінює CI.
- Native Windows 5.1/7 parser/BOM та manager regressions — required matrix у PR;
  повний Docker Desktop/Windows runtime на машині користувача локально не запускався.
- Read-only Rulesets API: активний CI-COST-1, PR + strict Fast/Docs/Targeted, без
  bypass. `feature_rules.py` успішно сформував розширений план. Зовнішній PUT
  не виконувався: bootstrap workflow спершу має потрапити у прийнятий master.

Docker harness замінює лише зовнішнє admission синтетичним fixture; production
launcher не має bypass прапорців. Через обсяг vfs harness послідовно зупиняє
контейнери старої ревізії без видалення її volumes/images; production manager
попередню ревізію не зупиняє. Тестовий export фіксується один раз на весь прогін.
GitHub CI не використовує секрети або реальні БД; browser опційно вмикається
`TELMANFIT_FEATURE_BROWSER=1` за наявності чинного Playwright/Chromium.

Автоматичний security review відхилив cache cleanup із недостатньо доведеним
походженням частини записів. Заблоковане очищення не виконано; як незалежну
безпечну альтернативу додано цільовий disposable GitHub acceptance runner.

## Межі

Робоча локальна БД, production, migration allowlist та SQL20 upgrade guard не
змінені. Немає перенесення реальних dumps/credentials, бізнес-схеми або даних.
Повторний запуск створює чисту синтетичну ревізію; тестові дані не переносяться.
BuildKit cache manager не очищує. Серверний rollout підготовлений, але не
активований; write permission ще не доведено фактичним записом після bootstrap.
Незалежний reviewer і приймання exact head/base потрібні до merge; технічна
операція після цього не потребує додаткового погодження. Відкритих продуктових
рішень у scope немає; питання реального SQL20 upgrade належить окремій процедурі.

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
- `tests/local-update-powershell-compat.ps1`
