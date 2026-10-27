# QA-1 — стабільність T4.5 і cold infrastructure

База: актуальний master `15a4525028c21dfc8bd35d4e57dc3622752d4d3c` (27.10.2026).
Перевірений test-code commit: `5e64719f26cbb6af821f03c1e60f387e93b3e2c1`.
Наступні commits додають лише звіт/докази і посилання у project-status; hashes тестових
файлів зафіксовані в [машинному результаті](results.json).

**QA-1A: PASS. QA-1B: PASS. Критерії QA-1 виконані.**
Fast/Docs/Targeted на точному фінальному PR head перевіряються окремо; merge не виконується.

## Feasibility окремих частин

QA-1A PASS: Linux, локальний Docker28.4.0/Unix socket без endpoint overrides,
PHP8.4.27 та8.5.12 зі штатним runtime gate/extensions/Composer platform requirements,
новий PostgreSQL18.6 з приватним bind-mounted Unix socket, непривілейована роль,
network=none, PGDATA tmpfs та успішний cleanup. Chromium154.0.8037.57,
Python Playwright1.62.0. Системний Chromium використовується за штатним
executable_path у T4.5; bundled browser parity не заявляється.

Локальний Cloud QA-1B preflight був BLOCKED:3.4GiB free на32GB root і4.7GiB у /tmp.
Після перевірки додаткових можливостей використано окремий GitHub-hosted executor:
Docker29.4.2/overlayfs,145G filesystem,89G free до build. Приватний Unix endpoint
без overrides і порожні Docker inventories перевірено перед checkout/build.
Це нове ізольоване середовище; локальний Docker driver або production не змінювали.

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

## QA-1B — причина, ресурси та успішний cold acceptance

**PASS**: [cold run40440751275](https://github.com/ValtonUA/telmanfit/actions/runs/40440751275),
job121980257345, source aa691ec607d7137c5ec1e57b7a7a339707d6a3d5.
Виконано штатний local-compose.yml на GitHub-hosted runner. Тимчасова власна
гілка69374cdbd999d2fb7d2f07f6e309643f6b258044 додала тільки workflow_dispatch
і read-only Docker/storage probe. Checkout/build отримали окремий exact source SHA,
тому dispatcher не змінював перевірений Dockerfile/Compose або app source.
Гілка й worktree видалені після успіху; у PR немає постійних workflow змін.
Full workflow не запускався.

Причина Cloud ENOSPC — Docker vfs із повними directory copies для кожного
image/container layer та збереженим cache попередніх власних builds.90 cache
records створені01:47:10–02:01:03 UTC; BuildKit du віддавав0B, що не дорівнює
фактичному використанню файлової системи. Попередня незавершена спроба вже
спожила приблизно8GiB додатково, а нинішніх3.4GiB було недостатньо.
Прямий du daemon directories недоступний UID1000; чужі файли не монтували.

На альтернативному runner використано overlayfs,145G root із89G free.
Це фактично успішна конфігурація, не твердження, що145G — мінімум.
Точний peak не інструментувався, тому математичний мінімум не вигадували.
Практичний запит для окремого повтору: native overlay/snapshotter, щонайменше32GiB
вільного Docker storage з резервом2GiB, без паралельних PG suites; для vfs потрібен
більший budget через full-copy amplification. Попередній запит64GiB filesystem/32GiB
free був рекомендацією із запасом, а не виміряним мінімальним порогом.

| Частина cold acceptance | Статус | Секунди GitHub step |
|---|---|---:|
| Стандартний build --pull --no-cache, без override | PASS | 118 |
| Synthetic credential/SQLite exclusion у готовому образі | PASS | 1 |
| Fresh isolated volumes, compose up/health | PASS | 23 |
| MFA/OTP/CSRF/fence, onboarding, Filament, persistence down/up | PASS | 133 |
| Власний compose down --volumes --remove-orphans | PASS | 2 |

Увесь job308s. Успішні7 acceptance groups і48 theme/responsive views
(1440/390/320 light/dark), asset errors0, invitation create/revoke.
Перевірено marker refusal на непорожній synthetic DB, epoch fail-closed503,
password-only denial, TOTP/enrollment, invitation/OTP, anonymous denial,
CSRF419, збереження й повторне читання даних у React/Filament, persistence
users/sessions/answers/MFA після down/up без повторного enrollment.

Логи підтверджують видалення6 контейнерів,5 volumes і1 мережі тільки власного
project telmanfit-ci-40440751275-1. Job cleanup completed/success;
production/telmanfit-local на робочому host не використовувалися.
[Машинні steps/timings/cleanup](cold-result.json),
[GitHub artifact](https://github.com/ValtonUA/telmanfit/actions/runs/40440751275/artifacts/12706188655).
Cloud gh download artifact blob отримав403; plugin download успішний, artifact
доступний у GitHub, повний decoded job log збережено в локальному audit bundle.
Це обмеження локального завантаження, не FAIL виконаного acceptance.

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
QA-1B закрито справжнім cold acceptance на окремому runner. QA-1A та QA-1B — PASS,
cleanup локальних і remote disposable resources підтверджений. Один Draft PR
містить лише test synchronization та докази; merge і production activation не виконуються.
