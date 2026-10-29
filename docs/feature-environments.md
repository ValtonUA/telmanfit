# Feature → master: приймання в ізольованому середовищі

Канонічний цикл: `master → feature/<назва> → master`, без постійної `dev`.
Одна функція — одна гілка й один PR. Не включати інші неприйняті функції через
merge чужої feature. Передавати користувачу версію після технічних перевірок та
незалежного review. Власник приймає функцію, після чого агент повторює фінальний
технічний gate і зливає точний SHA без додаткового погодження операції merge.
Це правило не дозволяє змінювати робочу БД або production.

## Windows: запуск однією командою

Потрібні Git, Python 3.10+ (`py -3`), GitHub CLI (`gh`, автентифікований read-доступ
до PR/Actions) і Docker Desktop із Linux containers та Compose v2 із `up --wait`.
Команду запускати в **довіреному checkout інструментів** із цим launcher; не
перемикати робочу стабільну копію на feature. Launcher підтримує PowerShell 5.1/7.

```powershell
powershell -NoProfile -File .\scripts\feature.ps1 -Feature feature/my-function
```

У PowerShell 7 замість `powershell` можна використати `pwsh`. Дотримуйтеся
чинної [execution policy](local-compose.md).
Якщо policy блокує локальний файл, використайте затверджений host, що дозволяє
такі скрипти; launcher не змінює policy. Native Windows 5.1/7 CI виконує сам
launcher зі synthetic Python observer без Docker/БД та без policy override.

Перший запуск збирає образи та показує PR/head/base, project й адресу після
healthy. Відкрийте `http://telmanfit-feature-<id>.localhost:<порт>/admin/login`
у Chrome/Edge/Firefox. `.localhost` спрямовується браузером на loopback без
hosts-файлу. Окремий hostname ізолює cookies, CSRF та browser storage від
стабільного `127.0.0.1:8098` й інших ревізій. Реквізити — через `access`.

## Перший запуск bootstrap PR до merge

До merge launcher ще відсутній у `master`. В описі bootstrap PR агент надає **один
готовий PowerShell-блок із конкретним reviewed SHA**, сформований із
[`feature-bootstrap.ps1`](../scripts/feature-bootstrap.ps1). Його можна вставити
в PowerShell з будь-якого каталогу; stable checkout не використовується.
Це явний перегляд/запуск тексту команди, без download-and-execute та policy override.

Блок створює новий GUID-каталог у `%LOCALAPPDATA%\TelmanFit\bootstrap`, клонує
лише офіційний repository без checkout/hooks/templates, отримує точний SHA,
робить detached checkout і перевіряє HEAD. Потім викликає `feature.ps1` з
`-ExpectedHead <SHA>`: live admission має підтвердити той самий поточний PR head,
CI й незалежний review report. Немає bootstrap bypass, pinned CI-success fixture
або дозволу запустити довільну гілку. Старий блок після зміни head відмовляє до
створення feature ресурсів; агент надає новий блок після повторних CI/review.

Bootstrap виводить шлях окремих інструментів. Після запуску команди
`& '<цей шлях>\scripts\feature.ps1' -Feature feature/isolated-feature-environments -Action list`
і `access/stop/resume` працюють із тим самим registry. Після merge використовується
штатний trusted checkout launcher. Bootstrap-каталоги містять тільки інструменти;
їх видалення не видаляє feature volumes. Docker resources видаляє лише штатний
`remove -Project <id>`.

Windows CI виконує цей bootstrap через реальні Git clone/fetch/detached checkout
зі synthetic local repository (URL rewrite), у шляхах із пробілами. Перевіряє
передачу SHA, відмову unknown SHA, незмінність synthetic stable `.env` та policy.
Це доказ bootstrap/tooling, не запуск Docker Desktop користувача.

## Повторне тестування та оновлення даних

Повторний `start` або `update` **того самого підготовленого SHA** відновлює
останню успішну ревізію: її URL, БД, введені дані, storage, паролі та MFA/epoch
збережені. Немає нового build, нової БД чи повторного initial migrate.

```powershell
.\scripts\feature.ps1 -Feature feature/my-function -Action update
```

Якщо PR має **новий SHA**, `start/update` відмовляє до будь-яких Docker-змін і
пояснює вибір. Безпечне рішення для цього процесу — явний clean preview:

```powershell
.\scripts\feature.ps1 -Feature feature/my-function -Action fresh
```

`fresh` створює окрему синтетичну БД з initial migrations перевіреного SHA.
Старі введені дані не переносяться: міграція populated DB, включно SQL20, потребує
окремого перевіреного upgrade-контракту, якого цей launcher не вигадує.
Стара ревізія не видаляється й доступна через `list/resume -Project <id>`.
`fresh` на тому самому SHA також можливий лише як явне створення чистої БД.
Невдала/частково мігрована ревізія не підходить для resume; потрібен новий fresh.

**За замовчуванням одне активне preview в усьому registry користувача**, включно
іншими feature. До build/resume manager перевіряє ownership/config усіх peers,
зупиняє їх через власний Compose down без volumes/images і звільняє container
rootfs. Попередні БД/файли/образи залишаються. У разі невдалого build/start спочатку
зупиняє невдалий runtime, потім відновлює останнє попереднє активне preview.
Якщо безпеку cleanup підтвердити не вдалося, не запускає ще один stack у
невідомому стані. Стабільний `telmanfit-local` не належить цьому registry й
ніколи не стає peer. Images перевіряються за збереженими IDs перед resume.
Оновлення стабільного TelmanFit через `local-update.ps1` — окрема процедура.

## Керування ревізіями

```powershell
.\scripts\feature.ps1 -Feature feature/my-function -Action list
.\scripts\feature.ps1 -Feature feature/my-function -Action stop -Project telmanfit-feature-<id>
.\scripts\feature.ps1 -Feature feature/my-function -Action resume -Project telmanfit-feature-<id>
.\scripts\feature.ps1 -Feature feature/my-function -Action access -Project telmanfit-feature-<id>
.\scripts\feature.ps1 -Feature feature/my-function -Action mail -Project telmanfit-feature-<id>
.\scripts\feature.ps1 -Feature feature/my-function -Action logs -Project telmanfit-feature-<id>
.\scripts\feature.ps1 -Feature feature/my-function -Action remove -Project telmanfit-feature-<id>
```

`list` показує фактичний runtime стан і URL кожної ревізії. `stop` звільняє
контейнери/мережу без видалення даних, `resume` відновлює вибраний незмінний image
і зупиняє інші preview. Resume історичного SHA дає доступ до його старих даних;
це не приймання актуального PR head.

`access` показує пароль та invitation лише у вашій консолі; MFA enrollment і
клієнтський OTP — як у [локальному Compose](local-compose.md). `mail` читає лише
synthetic mailbox; `logs` показує останні 100 рядків кожного сервісу. Не додавайте ці реквізити в PR/логи.

Registry, export коду й Compose JSON лежать у `%LOCALAPPDATA%\TelmanFit\features`
(на Linux — `~/.local/share/TelmanFit/features`). Lock не дозволяє паралельні
операції manager. `remove` потребує точного project із list, перевіряє конфігурацію
та Compose ownership і видаляє лише його контейнери, volumes, мережу, image tags
та registry entry. Спільний BuildKit cache manager не очищує. Глобальні prune/down, видалення stable storage та `.env`
не використовуються. Failed stacks збережені для діагностики; після огляду
видаляйте їх тією самою командою. Не редагуйте registry/Compose вручну.

## Межа безпеки та міграції

Перед будь-яким build/up launcher читає GitHub: один відкритий PR цієї гілки до
master, поточний master є предком head, required checks успішні на точному SHA,
немає auto-merge, є незалежний review за протоколом нижче й немає чинних
`CHANGES_REQUESTED`. Після build повторюється та сама перевірка.
Draft PR можна запускати після review; merge Draft заборонений.

Виконується лише Git-tracked export exact SHA. Робочий checkout не перемикається,
незакомічені файли, `.env`, host storage й credentials не копіюються. Імена images,
контейнерів, volumes та мереж містять випадковий feature ID. БД без опублікованого
порту; web слухає лише loopback. Compose overrides/`.env` та remote Docker не
успадковуються. Build виконує код перевіреної гілки: CI/review і довірений launcher
є передумовою, це не sandbox проти навмисно шкідливого Dockerfile.

Новий PG18 cluster генерує власні ключі/паролі/epoch. Чинний `bootstrap.php`
перевіряє роль без elevated privileges, DB name/proof/PG18, порожнечу public schema
і лише тоді запускає forward migrations. Непорожня/частково мігрована БД та pending
міграції після initial bootstrap блокуються. Автоматичного retry app/setup немає.
Невдалий initial migrate не ремонтується через Artisan reset/wipe/restore чи
підроблення migration history: створіть окрему ревізію `-Action fresh` після виправлення й review.

**SQL20:** його відсутність у `scripts/local-migration-allowlist.json` продовжує
блокувати upgrade наявної стабільної БД. Allowlist і guard не послаблюються.
Початкові міграції перевіреної feature застосовуються тільки до її нового
синтетичного кластера; це не доказ безпечного upgrade робочих даних і не дозвіл
запускати SQL20 на них. Синтетичні дані створюються чинним bootstrap; реальні dumps,
R2/Telegram/SMTP credentials і production дані не використовуються.

## Продуктове приймання та merge

У результаті приймання зафіксуйте exact head і base, показані launcher. Власник
репозиторію залишає в PR **окремий коментар** точного формату (не цитату/checkbox):

```text
TelmanFit-Accept: <40-символьний head SHA> <40-символьний master SHA>
```

Для відкликання — `TelmanFit-Reject: <head SHA> <master SHA>`. Переважає останнє
створене/відредаговане рішення власника для цієї пари. Label, PR description,
коментар агента/бота або review автора не замінюють приймання. Агент не створює
приймання сам за результатами своїх тестів. Новий head чи master потребують
повторних CI/review/приймання; збережена стара ревізія не підтверджує нову.

Фінальний порядок із довіреного актуального checkout інструментів:

```sh
python3 scripts/ci/pre_merge.py <PR> --repo ValtonUA/telmanfit
# Лише eligible=true, після продуктового приймання; без нового питання про merge:
gh pr merge <PR> --repo ValtonUA/telmanfit --merge --match-head-commit <HEAD_SHA>
```

Gate нічого не зливає й читає live GitHub evidence, а не локальний JSON або
прапорець bypass. Повторити безпосередньо перед merge; pending/failed CI, stale
SHA/base, відкликане приймання/review блокують операцію. Якщо змінено master —
безпечний merge master у feature, повторні перевірки, review та приймання.
Немає дозволу на auto-merge, force-push чи перенесення неприйнятої feature в master.

CI `Documentation health` відхиляє джерело PR поза `feature/*`; required Windows
matrix перевіряє parser/BOM launcher і manager regressions. `Feature environment checks`
запускає cold Docker lifecycle для релевантних змін tooling/runtime/schema. Фінальний live gate
додає незалежний review та продуктове приймання до технічних статусів.

## Серверний захист і встановлення цього процесу

Чинний ruleset `CI-COST-1` уже захищає default/master від deletion/non-fast-forward,
вимагає PR і strict Fast/Docs/Targeted, без bypass actors. Classic protection API
повертає 403, але сучасний Rulesets API доступний для читання. Новий план
`feature_rules.py` зберігає ці правила й додає обидві Windows jobs, Feature environment checks, commit status
`Feature acceptance`, dismiss stale reviews, resolution threads і merge method
`merge`. Наявні review-count/last-push вимоги зберігаються без послаблення;
недоступний другий акаунт не додається як нова вимога. У поточному ruleset count=0;
новий status перевіряє session report і продуктове приймання.

`feature-acceptance.yml` запускає лише код **довіреного master**, не checkout PR,
не artifacts і не Docker. Він оновлює admission status на exact PR head після
CI completion, review і створення/редагування/видалення PR-коментаря. Спочатку
встановлюється pending; API error, неактуальні докази чи Reject дають failure.
Одна concurrency queue переоцінює всі відкриті feature PR, тому втрата зайвої
pending події не втрачає відкликання іншого PR. Статус не замінює фінальний live
gate безпосередньо перед SHA-locked merge. Окремий read-only approval job не
вимагається перед preview: продуктове приймання відбувається після preview.

Початковий PR із цими інструментами проходить незалежне review та приймання,
потім фінальний gate і merge за чинними required checks. **Не вимагати нового
серверного admission status до встановлення його workflow в master**: це
заблокувало б bootstrap. Після merge агент із попередньо наданим дозволом на
впровадження процесу виконує з актуального довіреного checkout:

```sh
python3 scripts/ci/feature_rules.py --review-pr 117          # read-only план + реальна reviewer capability
python3 scripts/ci/feature_rules.py --review-pr 117 --apply  # після proven review та merged bootstrap
# Перерахувати admission statuses на відкритих PR:
gh workflow run feature-acceptance.yml --repo ValtonUA/telmanfit
```

`--apply` відмовляє, доки реальний reviewer route не доведено, bootstrap PR не
merged або workflow/publisher/gate не встановлені в master; не
послаблює існуючих checks, не додає bypass і не пише при зміні ruleset між
читаннями. Права на Rulesets write перевіряються фактичним API записом після
bootstrap; read-доступ не доводить write. Якщо запис відхилений, серверний rollout
залишається **не активованим**; агентський live gate чинний, але не називати його
серверною гарантією. Прямий push/auto-merge й обхід правил заборонені.
Перелік required checks та деталі — [ci.md](ci.md).

## Незалежний review без другого GitHub акаунта

Незалежність забезпечується **окремою read-only review-сесією після freeze коду**.
Reviewer отримує exact head/base, критерії, весь diff і тести, але не авторський
діалог; сам формує findings, перевірки, обмеження й verdict. Автор виправляє
blockers, потім новий head перевіряється знову. Reviewer може бути окремим агентом
або людиною. CI не замінює змістовний review.

Інтегратор записує справжній звіт reviewer у GitHub Reviews API як `COMMENTED`
review (`event=COMMENT`, exact `commit_id`). Другий GitHub акаунт не потрібен,
продуктовий власник не створює credentials і не погоджує технічні операції.
Це **атестація процесу**, а не GitHub formal `APPROVED` від автора самому собі.
Опублікувати реальний JSON звіт можна з довіреного checkout:

```sh
python3 scripts/ci/record_review.py 117 --report /path/to/reviewer-report.json          # read-only payload
python3 scripts/ci/record_review.py 117 --report /path/to/reviewer-report.json --publish
python3 scripts/ci/feature_rules.py --review-pr 117                                   # live readback
```

Тіло review має бути рівно `TelmanFit-Review: ` + JSON такого контракту:

```json
{
  "version": 1,
  "head": "<40 hex exact head>",
  "base": "<40 hex exact master>",
  "implementation_session": "<справжній ID авторської сесії>",
  "review_session": "<справжній інший ID reviewer-сесії>",
  "read_only": true,
  "verdict": "APPROVED",
  "summary": "Предметний звіт reviewer про перевірений diff та результат, мінімум 40 символів.",
  "checks": ["Конкретні виконані перевірки або прочитані контракти."],
  "blocking_findings": [],
  "limitations": ["Конкретні межі перевірки; shared-account не дає криптографічної незалежності."]
}
```

Gate довіряє лише запису GitHub repository owner типу User, з exact head/base,
різними непорожніми session IDs, `read_only=true`, змістовними summary/checks/limitations
і порожніми blocking findings для APPROVED. Остання атестація на поточному head
замінює попередню: `CHANGES_REQUESTED`, `REVOKED`, malformed або dismissed record
блокує обидва gates. Оновлення base/head анулює proof. Формальне незалежне GitHub
APPROVED також підтримується; чинний formal CHANGES_REQUESTED блокує обидва шляхи.
`record_review.py` не генерує звіт: публікує переданий reviewer JSON після перевірки
live head/base, а `feature_rules.py` читає реальний запис через API.

**Межа гарантій:** GitHub автентифікує publisher і commit, але не походження
session ID або авторство тексту. Інтегратор зі спільним credential технічно може
підробити атестацію; GitHub admin може обійти процес. Тут немає окремого IAM
бар'єра чи криптографічно незалежного reviewer-сервісу. Це свідомо менш суворий
GitHub admission із перевірюваним звітом/журналом окремої сесії, а не рівноцінна
другому credential ізоляція. Owner acceptance exact head/base залишається окремим
обов'язковим рішенням; CI, SQL20 та migration guards не послаблюються. Наявний
master ruleset не змінюється до реального successful readback цього route,
продуктового приймання, merged bootstrap й перевірки trusted publisher у master.

[reviewer-capability.json](evidence/feature-workflow/reviewer-capability.json) —
**історичний snapshot старого `d2ddecd`**, не exact-head evidence нового PR і не
runtime allowlist. Поточний head, live review IDs, CI URLs та результат gate
фіксуються в описі PR після окремого review; код гілки для цього не змінюється.
