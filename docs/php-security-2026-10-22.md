# PHP security — 22.10.2026

## Перевірені офіційні джерела

PHP опублікував обидва advisory 21.10.2026, Critical, CVSS v4 9.2:

| Advisory | Причина та застосовність |
|---|---|
| [CVE-2026-109618 / GHSA-w8hw-w9fx-j79w](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) | RCE через некоректне завершення після OOM, коли внутрішня пам’ять частково ініціалізована. Для mod_php підтверджено виконання до коду застосунку; FPM не слід вважати невразливим. |
| [CVE-2026-109619 / GHSA-6wmf-4rv5-p3rp](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp) | Request-owned global state залишається у worker після звільнення пам’яті; наступний запит може використати stale state. Точна досяжність залежить від SAPI, конфігурації та застосунку. |

[Офіційне повідомлення PHP від 21.10.2026](https://www.php.net/archive/2026.php)
вимагає оновити сам PHP. Перші виправлені релізи: **8.2.35, 8.3.36,
8.4.27, 8.5.12**. Гілка 8.2 не відповідає вимогам TelmanFit.
Composer update пакетів або application middleware не виправляє ці помилки engine.

## Факти TelmanFit

База огляду: `master` commit `d9ff62f8fddae20ecbe666d600c132496d10f75b`
(merge PR #16). Окрема копія та гілка
`security/php-rce-2026-109618-109619`; AUTH-6B/B3 працює в окремому Cloud chat.
Його checkout, branch та незавершений код не змінювалися.

| Компонент | Фактичний стан |
|---|---|
| Root PHP до зміни | `^8.3`: дозволяє вразливі patch-релізи |
| Laravel | `v13.38.0` |
| Filament | `v5.10.2` |
| Livewire | `v4.4.7` |
| Symfony HTTP Foundation / Kernel | `v8.1.9`, потребують PHP `>=8.4.1` |
| Google2FA | `v9.1.0` |
| PHPUnit | `12.5.40` |
| Windows Herd CLI | PHP `8.5.12`, фактично виконаний `php -v` |
| Встановлений Herd CGI | `php85/php-cgi.exe` ProductVersion `8.5.12`; Herd metadata також `8.5.12` |
| Активний вебпроцес | Версію запущеного процесу/відповідь SAPI не підтверджено; версія файлу CGI не доводить перезапуск worker |
| Cloud | Старі звіти фіксують `8.4.26` — вразлива версія; поточний runtime B3 не перевірений цим PR |
| Production | Немає підтвердженої версії чи deployment-конфігурації; доступ не здійснювався |

У tracked-коді є Laravel front controller, Filament/Livewire та auth/session
операції. Немає production Dockerfile чи повного FPM/Apache deployment manifest.
Відсутність конкретного upload endpoint не доводить відсутність ризику: частина
обробки виконується до Laravel. Експлойти, OOM та шкідливі HTTP-запити не запускалися.

Зафіксований dependency graph вже не встановлюється на PHP 8.3 через Symfony 8.
Тому **поточний lock перевіряється на 8.4.27 та 8.5.12**. Root зберігає сумісну
із затвердженою архітектурою гілку 8.3, але лише від 8.3.36; це не обіцянка
сумісності поточного lock з 8.3. Downgrade Symfony не входить у security patch.

## Реалізовано

- `composer.json`: `~8.3.36 || ~8.4.27 || ~8.5.12`; незнайомі minor/major
  потребують нової перевірки. `composer.lock` оновлено через Composer, лише
  platform requirement і content hash. Версії та references усіх 145 пакетів незмінні.
- `app/Support/PhpRuntimePolicy.php`, `scripts/check-php-runtime.php`: самостійна
  перевірка стабільної PHP-версії до Composer/Laravel, без `.env`, мережі чи БД.
  Скрипт перевіряє лише інтерпретатор, який його виконав; не оновлює PHP, не
  захищає вразливий parser та не підтверджує інший SAPI. Vendor-суфікси fail closed;
  backport потребує окремих доказів постачальника.
- `tests/Unit/PhpRuntimePolicyTest.php`: 16 позитивних/негативних меж, включно
  з 8.4.26, 8.5.11, RC/dev та незнайомими гілками. Це тести policy, не exploit regression.
- `.github/workflows/stage1.yml`: точна PHP `8.4.27`, перевірки runtime і platform.
  Наявний ізольований PostgreSQL-конвеєр збережено.
- `.github/workflows/php-runtime.yml`: PHP `8.4.27`/`8.5.12`, audit locked-пакетів,
  platform, збірки ресурсів, основний PHPUnit/Pint. Тільки synthetic testing env,
  SQLite `:memory:`, без міграцій і реальних секретів. Root frontend поки не має
  tracked lock-файлу, тому install повторює чинний Stage 1; client використовує `npm ci`.
- `docs/project-status.md`: посилання на цей звіт.

## Перевірки та межі

`composer validate --strict` і `composer check-platform-reqs` на PHP 8.5.12 — PASS.
Online `composer audit --locked --format=json --no-interaction`: `advisories: []`,
`abandoned: []`, exit 0 (22.10.2026). Це аудит пакетів, **не аудит PHP engine**.
`composer update --lock --no-install --no-scripts --no-plugins` не змінив пакетів.

Остаточний локальний `php artisan test --display-all-issues` на PHP 8.5.12:
**38 passed, 62 assertions**, без skipped. `php vendor/bin/pint --test` — PASS.
`npm run build` (root) та `npm run build --prefix client` — PASS; root Vite
повідомив лише про відсутню optional `fontaine` для font fallbacks.
`git diff --check` — PASS. Нова matrix 8.4.27/8.5.12 і Stage 1 очікують CI;
локальний PASS на 8.5.12 не підтверджує 8.4.27 чи PostgreSQL-регресії.
Локальний Windows sandbox повертав хибні `is_readable`/`is_writable`; запуск
ізольованих тестів поза sandbox усунув цю проблему. Без зібраної Vite-теми
HTTP-тести закономірно падали; перед остаточним прогоном потрібні обидві збірки.
PostgreSQL/Docker відсутній у локальному PATH: PG/auth concurrency та browser
регресії локально не виконані; наявний Stage 1 workflow має виконати їх у CI.

## Наступний безпечний крок та B3

1. Дочекатися CI цього Draft PR; merge — лише за окремим дозволом користувача.
2. Після погодження окремо оновити вразливі runtime середовищ (Cloud/dev/staging),
   зберігаючи minor-гілку: 8.4.26 → 8.4.27+. Звірити реально запущені CLI,
   CGI/FPM/Apache та queue/scheduler workers; для кожного зафіксувати версію/SAPI.
   Перезапуск і runtime update цим PR не виконуються.
3. B3 перед фінальними перевірками має врахувати PHP floor. Ця гілка не змінює
   recovery-сервіси, схему, SQL, конфіг auth або міграції; конфлікт можливий, якщо
   B3 окремо редагує Composer чи workflow. Після дозволеного об’єднання повторити
   B3-регресії на виправленому PHP. Цей PR не підтверджує їх наперед.
4. Production лишається окремим погодженням: інвентаризація SAPI/образу,
   backport-доказів за потреби, оновлення і перезапуск усіх workers, staging smoke
   та окремий rollout. P5/P6 цим PR не закриваються.

Не змінювати `memory_limit` на `-1` як універсальний fix: advisory не вважають
конфігураційні обхідні заходи повним виправленням. Тут не застосовано жодних таких
заходів. Не виконувалися merge, deployment, міграції, сіди, SQL чи зміни
`telmanfit_dev`/production. Відкриті бізнес-питання `schema-audit.md` не блокують
цей runtime patch; фактичні runtime і CI залишаються умовами подальшого rollout.
