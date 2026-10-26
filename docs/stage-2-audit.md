# Stage 2 — фінальний аудит карток, 23.10.2026

Вихідний аудит виконано на `bb0816d` (merge PR #24). Для збереження тестів
перевірено GitHub `master` на `2734ea7` (merge PR #27) і створено від нього
гілку `test/stage2-client-audit`. Це базові ревізії перевірки, а не позначення
поточного стану проєкту. Підтверджених функціональних дефектів у перевіреному
модулі не виявлено; бізнес-код не змінювався.

Документаційний вплив звірено з [AGENTS.md §16](../AGENTS.md#16-політика-актуальності-документації-обовязково-для-кожного-pr):
докази тестування наведено в цьому звіті та пов'язано з
[project-status.md](project-status.md) і [Stage 2](stage-2.md).
Статуси задач не змінюються; канонічний план і відкриті рішення залишаються
тільки у [roadmap.md](roadmap.md). Архітектура та контракти незмінні.

Прочитано інструкції репозиторію, roadmap, project status, звіт Stage 2,
відповідні правила BR-U-01…BR-U-22, Entity Catalog/DBML/SQL 02 і код AUTH-4.
Історичні формулювання про відсутній AUTH-4 зіставлено з реалізацією та
[auth4.md](auth4.md).

## Покриття до змін і результат аудиту

| Сценарій | Уже наявні перевірки | Підсумок |
|---|---|---|
| Список, пошук ID/імені/email, статуси, сортування, перегляд NULL/linked | `ClientResourceTest`: list/search/view, statuses, semantic colors | Підтверджено; додано окремий пошук за тренером і порожній результат |
| Редагування, явний coach/admin, недозволений client/відсутній coach/підроблений ID | `ClientResourceTest`: invalid edits, ineligible coach, model validation | Підтверджено, типового тренера немає |
| Перша активація, пауза, завершення, повторна активація | `ClientResourceTest`: lifecycle та status data provider | Дата виникає при першому `active`, потім зберігається; акаунт сам не активує супровід |
| `admin`/`coach`/`client`, гість | HTTP/Policy/Livewire role matrix; `UserPanelAccessTest` | Список/перегляд/редагування лише admin; coach може бути відповідальним, але не входити у Filament |
| Ручне створення | HTTP, Policy, Livewire mount/create, відсутня/підроблена CreateAction для трьох ролей | Заблоковано; запрошення не відкривають ручне створення |
| Видалення | Policy `delete` у role matrix; відсутні delete actions у ресурсі | Заблоковано; додано перевірки `deleteAny`, row/bulk actions і підроблених викликів |
| NULL-картки | Редагування в усіх чотирьох статусах; кілька NULL при UNIQUE | Збережено; нова реєстрація не переприв'язує перехідний запис |
| Один профіль на акаунт, самосупровід | Livewire/Eloquent validation; PostgreSQL UNIQUE/FK/CHECK | Підтверджено; роль тренера й самосупровід перевіряє Laravel, а не SQL CHECK |
| Запрошення → акаунт + картка | `Auth4Test`: atomic registration, rollback на помилці запису, revoked invitation, historical email conflicts; `auth4-concurrency.php` | Одна успішна реєстрація, `pending`, дата початку NULL, роль client, тренер із запрошення; запрошення витрачається атомарно |
| Унікальність реєстрації при конкуренції | Одне запрошення/різні flows; різні запрошення/одна normalized email | Один акаунт; запрошення невдалої спроби не витрачається |
| Переприв'язування при AUTH-4 | Disabled/dehydrated поле та server update guard у `EditClient` | Доданий тест підтвердив захист від підробленого Livewire state для linked і NULL-картки |
| Тренер утратив дозволену роль перед завершенням реєстрації | Повторна перевірка ролі в `ClientAuth::complete`, окремого тесту не було | Доданий тест: немає нового user/client, invitation не витрачено, NULL-картка незмінна |

За `auth4.enabled=false` явне редагування прив'язки наявного акаунта залишається
історично погодженою поведінкою. За `auth4.enabled=true` звичайне редагування
`user_id` заборонено; тренер і статус залишаються доступними для редагування.
Це поточне уточнення до історичного [stage-2.md](stage-2.md).

Технічна унікальність акаунта/профілю та normalized email не доводить, що різні
адреси належать різним фізичним людям. Автоматичного ототожнення за ім'ям чи email
не додано; підтверджене Telegram linking залишається майбутнім етапом.

## Додані тести

- `tests/Postgres/ClientResourceTest.php`:
  `test_search_by_coach_and_empty_results`,
  `test_invitation_mode_preserves_account_when_edit_state_is_forged`,
  `test_delete_actions_are_unavailable_and_forged_calls_preserve_card`.
- `tests/Auth4/Auth4Test.php`:
  `test_registration_rechecks_coach_role_before_creating_account_and_card`.

Наявні сценарії не дублювалися. Прямі вставки в тестах — підготовка синтетичних
наявних записів, не дозвіл ручного створення через застосунок.

## Перевірки вихідного аудиту на bb0816d

Встановлений у середовищі PHP 8.4.26 не проходить чинну runtime policy.
Для прогону використано окремий CLI PHP **8.4.27** з офіційного образу
`php:8.4.27-cli-bookworm`, digest
`sha256:496b5e02fe9fe44fcfb715023b931048111dc4e0bc719789d83c906be9f04b05`.
Тестовий wrapper у `/workspace/stage2-audit-runtime/wrappers/php` налаштовує
наявні сумісні extensions; репозиторій, runtime policy й production-конфігурацію
не змінено. Перевірка версії та Composer platform requirements на цьому CLI — PASS.

`PATH=/workspace/stage2-audit-runtime/wrappers:$PATH PAO_DISABLE=1 tests/pg/run-stage1.sh`
завершився кодом **0**:

| Перевірка | Результат |
|---|---|
| Незмінені SQL-регресії Stage 1 | PASS=76, FAIL=0, GAP=0 |
| PostgreSQL PHPUnit, включно з трьома новими тестами карток | 44 tests / 771 assertions |
| Основний PHPUnit (`php artisan test --display-all-issues`) | 38 passed / 62 assertions |
| Pint | PASS |
| AUTH-2 PHPUnit | 12 tests / 131 assertions; fresh/populated upgrade/down PASS |
| AUTH-3 PHPUnit | 25 tests / 129 assertions |
| AUTH-4 PHPUnit до четвертого доданого тесту | 24 tests / 152 assertions |
| AUTH-5 PHPUnit | 19 tests / 131 assertions |
| AUTH-6 PHPUnit | 5 tests / 359 assertions |
| AUTH-2/3/4/5 concurrency та AUTH-6 parallel HTTP | PASS |

Після додавання перевірки зміни ролі тренера виконано цільовий повтор на ще одному
новому контейнері:
`PATH=/workspace/stage2-audit-runtime/wrappers:$PATH PAO_DISABLE=1 AUTH4_ONLY=1 tests/pg/run-stage1.sh`
→ код **0**, **25 tests / 159 assertions**, усі п'ять AUTH-4 concurrency сценаріїв PASS.
Фінальний Pint та `git diff --check` — PASS. PHPUnit warnings/skipped немає.

Root build, client build і client lint — PASS. Root build потребує
`NODE_USE_ENV_PROXY=1` для шрифтів через налаштований proxy; початковий запуск
без цього параметра завершився помилкою DNS `fonts.bunny.net`.
Новий Chromium-прогін не виконувався: UI не змінювався, поточний аудит перевіряв
HTTP/Livewire і доменні інваріанти. Попередні візуальні докази — [ux-1.md](ux-1.md).

БД — щоразу новий офіційний **PostgreSQL 18.6**, pin із штатного раннера,
`network=none`, без published ports, tmpfs data, приватний Unix socket і
непривілейована роль `telmanfit_test_runner`. Міграції виконано лише всередині
цих нових тестових кластерів. Власні контейнери й socket-каталоги прибрано,
що перевірено після завершення. Реальна БД та реальні секрети не використовувалися.
Локальні журнали: `/tmp/telmanfit-stage2-audit.log` і
`/tmp/telmanfit-stage2-auth4.log`.

## Повторна перевірка для Draft PR на базі 2734ea7

Чотири тести збережено з попереднього аудиту. Під час першого повного повтору
виявлено залежність нового AUTH-4 тесту від порядку запуску: абсолютне очікування
одного user/client некоректне після синтетичних concurrency-сценаріїв AUTH-2/3.
Тест виправлено на порівняння кількості записів до й після відхиленої реєстрації;
перевірки збереження NULL-картки та невитраченого invitation залишено.
Бізнес-код не потребував змін. Початковий повтор завершився кодом 1, тому його
не використано як доказ успішного фінального прогону.

Фінальний повтор `PAO_DISABLE=1 tests/pg/run-stage1.sh` на PHP 8.4.27
завершився кодом **0** на новій PostgreSQL 18.6: SQL **76 PASS**,
PostgreSQL PHPUnit **44 / 771**, основний PHPUnit **38 / 62**,
AUTH-2 **12 / 131**, AUTH-3 **25 / 129**, AUTH-4 **25 / 159**,
AUTH-5 **19 / 131**, AUTH-6 **5 / 359** (tests / assertions).
Concurrency AUTH-2/3/4/5 та всі 25 parallel HTTP перевірок AUTH-6 — PASS.
Warnings/skipped немає. Журнал: `/tmp/telmanfit-stage2-pr-validation-final.log`.

Composer platform requirements, фінальний Pint, `git diff --check`,
`python3 -B scripts/docs_guard.py` та 5 тестів
`python3 -B -m unittest discover -s tests/docs -p 'test_*.py' -v` — PASS.
Нові frontend/browser-прогони не виконувалися: UI/SPA не змінювалися;
результати попередніх lint/build вище належать вихідному аудиту.

## Межі та подальші рішення

Аудит не закриває весь Stage 2 і не змінює пріоритети інших модулів.
Статуси T2.1–T2.6 та AUTH, контракт анкети DEC-2A, майбутнє Telegram linking
і передрелізні рішення наведено лише в [roadmap.md](roadmap.md).
Відсутність контракту анкети не є глобальним блокером інших етапів.

Змінені файли: `tests/Postgres/ClientResourceTest.php`,
`tests/Auth4/Auth4Test.php`, `docs/stage-2-audit.md`, `docs/stage-2.md`,
`docs/project-status.md`. Збереження роботи у Draft PR погоджено окремою
задачею користувача; merge не входить у scope.

Схема PostgreSQL, SQL/DBML, міграції, MFA/recovery, глобальні gates, поведінка
входу, конфігурація, Stage 3, рецепти, раціони та довідники T1.5 незмінні.
