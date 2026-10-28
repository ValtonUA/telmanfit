# T5.7b — адміністративний конструктор і клієнтський помічник

Перевірки виконано лише з синтетичними даними в disposable PostgreSQL 18,
network=none, без DB ports, із PHP 8.5.12 із штатного `docker/local/Dockerfile`.
Робочі БД, production, feature flags та R2 дані не змінювалися.

- `CookingTemplatesTest`, `RecipeBatchesTest`, `BatchEditorTest`: 80 тестів,
  1295 assertions; SQL17/18/19, legacy/A/B/C, snapshots обладнання,
  режими 1/2/7, outputs/allocations, frozen token resolution, stage IDs,
  lifecycle, stale, cross-version, ACL, unavailable week, overallocated rollback.
- `cooking-concurrency.php`: 37 сценаріїв незалежних PG18 процесів, observed locks,
  edit/review/publish, double publish, CAS/ABA, snapshots, deadlock rollback.
- Backend suite на PHP 8.5.12: 125 тестів, 511 assertions.
- AUTH-4: 167 тестів, 1678 assertions; зокрема `ClientCookingTest`:
  14 тестів, 107 assertions, авторизований HEAD=200, GET/HEAD без cookie=401,
  `no-store, private`, незмінність усіх таблиць і `xmin`. Видалення cookie
  використовує наявний Laravel test cookie bag зі скиданням auth guards.
  Авторизований JSON перевіряє A=100, B=700, C/3=C/4=350 г; B+C=1050,
  C3+C4=700, два B+C=1750 г. Сім allocations на output не множать фізичну
  партію. Зміна live catalog не змінює frozen назву/кількості; чужа сесія та
  missing/duplicate/conflicting frozen проєкції недоступні, без часткової відповіді.
- Recipe batch concurrency: 5 сценаріїв; AUTH-4 concurrency: 5 сценаріїв; AUTH-2 SQL snapshot/BIGINT FK:
  1 тест, 30 assertions. CI regression: 40 тестів; docs regression: 11 тестів.
- Chromium: responsive 1440/390/320 light/dark; repeaters, стара вкладка,
  review/publish/окрема activation; штатні password + MFA, без auth bypass.
  Далі — справжній ClientSession OTP/API/React player, frozen інгредієнти,
  обладнання, timer/image. Перевірено відсутність перекриття двох навігацій,
  контраст другорядної кнопки ≥4.5:1 і галерею поверх основної навігації.
  Fixture stdout відокремлено від stderr, перехід до раціону чекає завершення OTP.
  Deferred browser API probe доводить один pending/active Wake Lock, очікування
  release, hide/show, пізній promise після вимкнення/виходу та правильне відновлення.
  Реальний апаратний Wake Lock на фізичному телефоні не перевірявся.
  Машинний результат — [checks.json](checks.json).
- Повний `recipe-client.py`: 7 груп перевірок, включно з токенами, обладнанням,
  pause/reset таймерів, reload/resume/discard progress, Wake Lock unsupported
  і відкликанням ClientSession. Виправлено відновлення player до збереження того
  самого ключа та revalidation списку сесій; чинні assertions не послаблено.
  Selector synthetic output береться з exact pattern, heading locator є точним.
  Машинний результат — [client-checks.json](client-checks.json).
- React lint/build, Vite admin build, Pint (425 файлів), docs guard та `git diff --check`.
  Окремого frontend unit-test script у `client/package.json` немає.

[Desktop light](1440-light.png) · [Mobile dark](320-dark.png).
[Клієнт desktop light](client-1440-light.png) · [Клієнт mobile dark](client-320-dark.png).
Відтворення browser сценарію включене в штатний recipe Stage1 runner через
`recipe_batch.py`; `cooking-fixture.php` використовує обов'язковий auth2 PG guard.
Фактичні GitHub Fast/Docs/Targeted results слід перевіряти на head SHA Draft PR.

Межі: немає перевірки на реальних раціонах, фізичному телефоні або production.
Автор відповідає за безпеку технологічних параметрів; таймер не доводить готовності.
Це локальні докази у WSL2 Ubuntu з Chromium 151 / Playwright 1.62.0;
вони не підтверджують GitHub CI на фінальному head. PHP запускався у новому
контейнері з перевіреним Composer lock; browser HTTP — лише loopback через
Docker stdio proxy. Тимчасова копія не використовувала host `.env`.

Синхронізація з master `7f1c62d314369aadfd26ab1aa73b54e23c6b8d13` — звичайний
merge зі збереженням історії. Coordinator/CI зміни master збережені; його allowlist
доповнено тільки наявною SQL19 equipment міграцією з точними SHA, порядком і
pre/postconditions. Windows PowerShell 5.1 parser і coordinator helper suite:
68 assertions PASS на SHA-перевіреній копії в окремому Windows Temp під чинною
RemoteSigned, із cleanup; coordinator upgrade не виконувався. Full coordinator
E2E/PowerShell 7 локально не запускався: Windows PowerShell 7 відсутній.
PG18 forward path і helper перевірки виконані окремо; це не дозвіл реального upgrade.

Recipe concurrency runner тепер повертає exit 1 при bootstrap/exception або
відсутньому ext-pgsql до DDL; контрольований failure-probe повторюється штатним
recipe runner. Старий false-success після exception відтворено локально.
ext-pgsql для цього тесту зібрано лише в disposable PHP-контейнері.

Власні файли фінального виправлення:

- `app/Services/Cooking/ClientCooking.php`
- `client/src/components/CookingAssistant.tsx`
- `tests/Auth4/ClientCookingTest.php`
- `tests/browser/cooking.py`, `tests/browser/wake_lock.py`
- `tests/pg/recipe-batch-concurrency.php`, `tests/pg/run-stage1.sh`
- `scripts/local-migration-allowlist.json`
- `tests/local-update-coordinator.ps1`, `tests/local-update-e2e.ps1`
- `docs/cooking-templates.md`, `docs/local-compose.md`, `docs/project-status.md`
- `docs/evidence/cooking-editor/report.md`, `checks.json`, `320-dark.png`, `client-320-dark.png`

Merge з master також зберіг без власного переписування:
`.github/workflows/local-update-powershell-compat.yml`, `compose.yaml`,
`docker/local/upgrade-check.php`, `docs/architecture.md`, `docs/ci.md`,
`scripts/local-update.ps1`, `tests/ci/test_gate.py`,
`tests/local-update-powershell-compat.ps1`, `tests/pg/local-upgrade-backup.ps1`.
Інтеграційні зміни п'яти інших master-файлів входять до власного переліку вище.
