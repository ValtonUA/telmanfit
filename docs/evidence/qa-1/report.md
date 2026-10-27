# QA-1 — стабільність T4.5 і cold infrastructure

База: актуальний master `15a4525028c21dfc8bd35d4e57dc3622752d4d3c` (27.10.2026).
Перевірений test-code commit: `5e64719f26cbb6af821f03c1e60f387e93b3e2c1`.
Наступний commit додає лише цей звіт і посилання у project-status; hashes тестових
файлів зафіксовані в [машинному результаті](results.json).

**QA-1A: PASS. QA-1B: BLOCKED. QA-1 загалом не завершено:**
критерій cold infrastructure acceptance ще не виконаний.

## Feasibility окремих частин

QA-1A PASS: Linux, локальний Docker28.4.0/Unix socket без endpoint overrides,
PHP8.4.27 та8.5.12 зі штатним runtime gate/extensions/Composer platform requirements,
новий PostgreSQL18.6 з приватним bind-mounted Unix socket, непривілейована роль,
network=none, PGDATA tmpfs та успішний cleanup. Chromium154.0.8037.57,
Python Playwright1.62.0. Системний Chromium використовується за штатним
executable_path у T4.5; bundled browser parity не заявляється.

Для QA-1B зараз лише приблизно3.4GiB вільного диска перед прогоном (32GB root),
/tmp має4.7GiB. Доступних інструментів створення/збільшення іншого executor немає.
Прямий дозвіл користувача на окремі частини дозволяє завершити QA-1A із BLOCKED
QA-1B; це не глобальний PASS інфраструктури.

## Причина T4.5 timeout

Попереднє падіння: перше `form('Призначити раціон', today)` у
[meal_plan_assignments.py](../../../tests/browser/meal_plan_assignments.py),
очікування button «Опублікована версія», Locator.click timeout15000ms.
Старий click() приймав **будь-який POST /livewire/update** як відповідь дії.

Read-only діагностика на новій synthetic PG показала дві справжні відповіді200:
`pullNotificationsFromSession`, потім `mountAction(assignMealPlan)`.
Старий helper фактично обрав `pullNotificationsFromSession`
(`ORIGINAL_MATCHED_METHODS [['pullNotificationsFromSession']]`). Між завершенням
фонового commit та стартом action commit глобальний pending може бути0;
livewire_ready() у цей момент не доводить завершення саме натиснутої дії.
Це помилка кореляції тестового сценарію, залежна від порядку HTTP/commit events.
Відсутність form у старому failure не є доказом завислої PostgreSQL-транзакції.
Історичні logs не дозволяють виключити всі додаткові чинники навантаження,
але конкретний дефект очікування доведений без припущення про швидкість сервера.

Мінімальне виправлення: новий livewire_click у
[readiness.py](../../../tests/browser/readiness.py) спочатку чекає tracker,
потім відповіді з потрібним request call method і HTTP200, далі commit succeed/onRender.
T4.5 явно розрізняє mountAction, callMountedAction, unmountAction.
Не додано timeout, sleep, polling delay, пропусків або слабших assertions.
Select/cancel/confirm/replace/history/stale/CSRF419/revoked403 та DB-invariance
assertions залишилися. Бізнес-логіка, SQL, T5.2, runtime policy та Compose незмінні.

Нові негативні HTTP/Chromium регресії гарантують порядок background-response
перед дією, відмову для failed action та іншого method. Збережені existing
missing/failed tracker, pending render і реальний cancellation cleanup tests.
Зайвий Response.finished у попередній локальній реалізації прибирався після
Playwright async Target-closed warning; остаточний gate — сильніший succeed/onRender.

## Фінальні5 послідовних ізольованих запусків

Кожен запуск: новий PG/container/socket, повний чинний T4.5 browser scenario,
1440/390/320 light/dark, 8 check groups, shutdown HTTP/browser та container cleanup.
PG/schema setup і cleanup включені в секунди. Жодних diagnostics паралельно
остаточній серії. Це не5 повторів full regression.

| Запуск | Статус | Секунди |
|---|---|---:|
| 1 | PASS | 47.673 |
| 2 | PASS | 47.429 |
| 3 | PASS | 47.782 |
| 4 | PASS | 47.998 |
| 5 | PASS | 47.813 |

Exact commands, UTC start/end, exit codes та SHA кожного запуску — results.json.
Для економії не дублювали PostgreSQL PHPUnit/concurrency: зовнішній audit harness
використовує незмінений setup/guards/cleanup зі штатного run-stage1.sh, migrate і
bootstrap, далі чинний meal-plan-assignment-in-context.sh. Його synthetic MFA,
Laravel/Livewire/CSRF і PostgreSQL залишаються справжніми. Звичайне штатне
відтворення разом із пов'язаним PG набором:

```bash
MEAL_PLAN_ASSIGNMENT_ONLY=1 MEAL_PLAN_ASSIGNMENT_BROWSER=1 bash tests/pg/run-stage1.sh
python3 -m unittest discover -s tests/browser -p 'test_readiness.py' -v
```

Readiness/cancellation7 tests PASS3.789s, CI helper39 PASS0.523s, Docs11 PASS0.008s.
Перший CI helper запуск без network permission отримав PermissionError на створенні
loopback socket; контрольний запуск поза sandbox із підтримуваним PHP пройшов.
Рання серія5/5 на проміжному helper збережена в зовнішніх logs, але не є фінальним
доказом. Додаткова diagnostic спроба зі спільним8108 отримала Address already in use;
її відкинуто як harness-помилку, повторна read-only діагностика на8118 — PASS.
Остаточні5 runs виконані вже після неї та без Response.finished warning.

## QA-1B — причина й вимоги до ресурсів

**BLOCKED, cold acceptance не запускався повторно.** Daemon використовує vfs:
новий image/container layer є повною копією directory, а не overlay delta.
Залишилися90 cache records попередніх власних PHP/cold builds, створені
01:47:10–02:01:03 UTC. BuildKit du повідомляє0B для цих записів: це не доказ
нульового фактичного місця. Docker daemon directories недоступні UID1000 для
прямого du; чужі файли/ресурси не монтували й не досліджували.

У попередньому cold build вільне місце впало приблизно з9.6GiB до1.7GiB,
збірка зупинена exit130; app/web build не завершився. Подальші vfs-копії PG
containers короткочасно вичерпали root. Це storage amplification/capacity
середовища, не падіння cold acceptance assertion застосунку. Зараз3.4GiB менше
вже фактично спожитих приблизно8GiB попередньої незавершеної спроби.

**Точний мінімум завершеного cold build невідомий:** незавершений build не дає
права вигадати виміряний peak. Мінімальна операційна вимога — більше диска за
попередній доступний budget, повна місткість для завершення build та stack/volumes,
резерв щонайменше2GiB і відсутність паралельних PG suites. Практичний запит для
наступного ізольованого executor:64GiB filesystem Docker і щонайменше32GiB free
до запуску; це рекомендація із запасом, а не доведений точний мінімум.

Після надання ресурсу: стандартний Dockerfile з --pull --no-cache, private
project/image names, synthetic credential/SQLite exclusion probes, compose up,
local_compose.py з TELMANFIT_FILAMENT_THEME_BROWSER=1, persistence down/up і
cleanup лише власних volumes. Не використовувати cached image замість cold build.
Production Compose/app-image/telmanfit-local не змінювали. Global prune,
видалення чужих volumes чи зміна Docker storage driver не виконувались.

## Composer audit та GitHub CI

[Fast run40430634221](https://github.com/ValtonUA/telmanfit/actions/runs/40430634221):
head_sha15a4525028c21dfc8bd35d4e57dc3622752d4d3c, run success, job121947139970,
step10 `composer audit --locked --no-interaction` completed/success.
Перевірено GitHub API run metadata і job steps, не лише повідомлення користувача.
Composer constraints/lock у QA-1 не змінено; локальний advisory повтор не запускався.
Fast/Docs/Targeted на фінальному PR head — у check results Draft PR; локальний
PASS не видається за GitHub CI. Full workflow не запускається.

## Cleanup і залишкові ризики

Тестові дані лише synthetic, нові PG без зовнішніх портів, без робочих credentials
або MFA secrets. Existing BrowserGroupCleanupTests перевіряє cancellation реальних
Chromium descendants. Фінальний inventory і source scope — cleanup.json:
containers/volumes до і після мають збігатися; source app/Compose/lockfiles без змін.
Zombie Chromium PIDs після завершення відокремлюються від живих процесів: zombie
не тримає browser/DB resources і не може бути повторно завершений kill.

5/5 є обмеженим доказом стабільності, не гарантією від усіх майбутніх flakes.
Залишається QA-1B BLOCKED; загальний QA-1 не може отримати PASS до справжнього
cold infrastructure acceptance на достатньому диску. Один Draft PR містить тільки
test synchronization та докази; merge не виконується.
