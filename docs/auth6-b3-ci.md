# AUTH-6B/B3 — інтеграція master та обов'язковий CI

22.10.2026. Продовження існуючого Draft PR #18; новий PR не створювався.
GitHub master перевірено на `97d34a5e34c11327523df33c7434dff73dc16cb3`
(PR #17 merged), вихідний B3 HEAD — `e3045544af8e926cf97c53f998b193c15bd98e53`.
Merge-коміт `3d26b5b80340e023bb3519a63688999ef3d86377` має обидва commits
батьками; rebase/reset/force-push не використовувалися. Єдиний конфлікт у
`project-status.md` вирішено збереженням обох повних записів AUTH-6B/B3 та PHP security.
Recovery-код, тести, SQL/DBML і міграції B3 збережено; усі файли PHP security
із master збережено без змін, зокрема Composer floor/lock і matrix 8.4.27/8.5.12.

## Обов'язковий CI

Чинний Stage 1 workflow збережено; додано безумовний крок
`AUTH-6B B3 migration, privileges, security and concurrency`, без continue-on-error:
`AUTH6B_ONLY=1 tests/pg/run-stage1.sh | tee tests/pg/out/auth6b.out`.
Explicit Bash забезпечує pipefail у GitHub Actions; B3 failure не маскується tee.
Лог потрапляє до наявного `stage1-pg-logs` artifact (`if: always()`).

Крок створює власний новий PostgreSQL 18.6 за чинним pinned digest,
network=none, без published ports, приватний Unix socket, непривілейований runner,
окрему synthetic NOLOGIN runtime role з негативними ACL перевірками та cleanup trap.
Виконує Node canonical/Ed25519 vector, усі Laravel migrations, AUTH-2,
`phpunit.auth6b.xml` (21 tests / 203 assertions, включно MigrationTest,
PrivilegesTest, SignaturesTest і негативними RecoveryTest), AUTH-3–6,
`tests/pg/auth6b-concurrency.php` і HTTP B1 harness. Швидкий локальний
`AUTH6B_CASES_ONLY` у workflow не встановлено. До створення контейнера runner
перевіряє runtime через `scripts/check-php-runtime.php`; CI pinned на PHP 8.4.27.

## Середовище повтору

Новий runtime PHP **8.4.27 CLI**, Debian Security `8.4.27-1~deb13u1`,
розпакований в окремий `/workspace/auth6b-runtime`, SHA-256 пакетів звірено з
security Packages index; усі PHP-набори запускаються з цим PATH. Wrapper Composer
у базовому середовищі жорстко посилається на старий PHP 8.4.26: початкова
`composer check-platform-reqs` закономірно відхилила його. Остаточні Composer
перевірки виконано явно через виправлений `php .../composer.phar` — PASS.
Реальні web/queue/production workers не оновлювалися й не перевірялися.

Playwright 1.62.0, системний Chromium **154.0.8037.57**. Завантаження bundled
Chromium із cdn.playwright.dev отримало policy 403; наявні browser harness
підтримують системний Chromium, на ньому виконано реальні перевірки.
У GitHub workflow збережено штатне встановлення Playwright Chromium.
Frontend: Node 24.19.0 локально; GitHub використовує чинний Node 22.
Root build має наявне попередження про optional fontaine; build успішний.

## Повторні локальні результати

| Перевірка | Результат на інтегрованому дереві |
|---|---|
| Stage 1 + AUTH-2–6 + browser/HTTP | exit 0; SQL 76 PASS / 0 FAIL / 0 GAP |
| PG PHPUnit / основний PHPUnit | 41 tests / 713 assertions; 38 / 62 |
| AUTH-2 / AUTH-3 / AUTH-4 / AUTH-5 / AUTH-6 | 12/131; 25/129; 24/152; 19/132; 5/359 |
| AUTH-2–5 concurrency | PASS, незалежні blocked PG PIDs і перевірені результати |
| AUTH-3/4/5 browser | PASS, реальні Chromium contexts; 320/390/1440 для AUTH-5 |
| HTTP AUTH-6/B1 | 22 впорядковані сценарії + timeout/CSRF unlock/parallel save — PASS |
| B3 PHPUnit | 21 tests / 203 assertions, без skipped; migration/privileges/negative cases PASS |
| AUTH-1 | 15 tests / 93 assertions — PASS |
| Інше | Pint, client lint/build, root build, Composer validate/platform, Node vector — PASS |
| SQL/PDO ізольований runner | 76 PASS / 0 FAIL / 0 GAP, cleanup exit 0 |
| Fail-closed shell runner | усі 10 positive/negative контрактів — PASS |

Online Composer audit локально заблоковано policy 403 для packagist.org; результат
не позначається PASS. Чинний PHP runtime security workflow зберігає online audit
на GitHub. Історичні результати [B3](auth6-b3.md) та checks.json не переписано.

## Межі та незалежне review

Інтеграція не змінює поведінку MFA/recovery: gate-aware code і production denial
порівняно з вихідним B3 незмінні. Перевірки охоплюють quarantine при MFA flag OFF,
відмову за missing boundary/production, notice/audit rollback, cancel/expiry без
release, старі sessions/codes/flows, shared TOTP watermark і новий повний login.
Статичні перевірки: ancestry обох гілок, відсутність unmerged paths/markers,
збереження Stage 1 кроків, SQL byte equality і git diff --check — PASS.

D3 trustees/keys/verifier/custody, B4 durable transactional notices,
D4–D8/B4–B6, restore/load/real provider drills та P5/P6 залишаються відкритими.
Synthetic fixtures не доводять реальну human identity verification або доставку.
Operational recovery і production activation закриті. Merge/auto-merge,
production deployment, зміни реальних БД або секретів не виконувалися.
Після CI — зупинка до незалежної перевірки; merge потребує окремого дозволу.

Зміни цього продовження: `.github/workflows/stage1.yml`, `tests/pg/run-stage1.sh`,
`docs/auth6-b3-ci.md`, `docs/project-status.md`; PHP security файли інтегровано
merge-комітом, їхній перелік — у [PHP security](php-security-2026-10-22.md).
Фінальні GitHub run URLs, HEAD SHA та висновки — у результатному коментарі
[PR #18](https://github.com/ValtonUA/telmanfit/pull/18), прив'язаному до нового HEAD.
