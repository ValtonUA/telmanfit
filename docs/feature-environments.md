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
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\feature.ps1 -Feature feature/my-function
```

Це локальна execution policy лише цього процесу. Скрипт не завантажується з
інтернету для негайного виконання. На першому запуску збірка може тривати кілька
хвилин; launcher показує точні PR/head/base, project і адресу після healthy.
Відкрийте показаний `http://telmanfit-feature-<id>.localhost:<порт>/admin/login`
у Chrome/Edge/Firefox. `.localhost` браузер спрямовує на loopback без hosts-файлу;
окремий hostname ізолює також cookies, CSRF та browser storage від стабільного
`127.0.0.1:8098` і сусідніх feature. Приватні реквізити — через `access` нижче.

Після виправлень і повторного CI/review та сама команда створює нову ревізію.
Явний синонім:

```powershell
.\scripts\feature.ps1 -Feature feature/my-function -Action update
```

Кожен запуск створює новий синтетичний кластер; дані попереднього приймання не
переносяться. Старі stacks залишаються доступними, у тому числі після невдалої
нової збірки. Повторний запуск того самого SHA теж створює чисту ревізію.
Оновлення стабільного TelmanFit через `local-update.ps1` — окрема процедура.

## Керування ревізіями

```powershell
.\scripts\feature.ps1 -Feature feature/my-function -Action list
.\scripts\feature.ps1 -Feature feature/my-function -Action access -Project telmanfit-feature-<id>
.\scripts\feature.ps1 -Feature feature/my-function -Action mail -Project telmanfit-feature-<id>
.\scripts\feature.ps1 -Feature feature/my-function -Action logs -Project telmanfit-feature-<id>
.\scripts\feature.ps1 -Feature feature/my-function -Action remove -Project telmanfit-feature-<id>
```

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
немає auto-merge, незалежний reviewer (людина, не автор PR) має `APPROVED` на цьому
SHA й немає чинних `CHANGES_REQUESTED`. Після build повторюється та сама перевірка.
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
Невдалий initial migrate не відновлюється через `fresh`, `wipe`, restore чи
підроблення migration history: створіть нову ревізію після виправлення й review.

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
`Feature acceptance`, один незалежний approval, dismiss stale reviews, last-push
approval, resolution threads і merge method `merge`.

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
python3 scripts/ci/feature_rules.py                 # read-only точний план
python3 scripts/ci/feature_rules.py --apply         # запис і обов’язковий read-back
# Перерахувати admission statuses на відкритих PR:
gh workflow run feature-acceptance.yml --repo ValtonUA/telmanfit
```

`--apply` відмовляє, доки workflow/publisher/gate не встановлені в master; не
послаблює існуючих checks, не додає bypass і не пише при зміні ruleset між
читаннями. Права на Rulesets write перевіряються фактичним API записом після
bootstrap; read-доступ не доводить write. Якщо запис відхилений, серверний rollout
залишається **не активованим**; агентський live gate чинний, але не називати його
серверною гарантією. Прямий push/auto-merge й обхід правил заборонені.
Перелік required checks та деталі — [ci.md](ci.md).
