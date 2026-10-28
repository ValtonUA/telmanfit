# UX-AUTO: неактивний bootstrap/mock

[Issue #107](https://github.com/ValtonUA/telmanfit/issues/107) координує шість
послідовних PR із [Issue #106](https://github.com/ValtonUA/telmanfit/issues/106).
Ця реалізація покриває окремо погоджений **bootstrap/mock scope**. Вона не виконує
Codex API, GitHub writes, browser або справжній merge. Команда `live` завжди
завершується `LIVE_DISABLED`; додавання secrets чи repository variables не знімає блокування.

## Запуск і докази

З кореня довіреного checkout, Python 3.10+ на Linux, без сторонніх залежностей:

```sh
python3 -m unittest discover -s tests/ci -p 'test_ux_auto.py' -v
python3 -m scripts.ux_auto.coordinator simulate --directory /tmp/ux-auto-success
python3 -m scripts.ux_auto.coordinator simulate --scenario retry --directory /tmp/ux-auto-retry
python3 -m scripts.ux_auto.coordinator status --directory /tmp/ux-auto-success
```

Окремі каталоги для кожного сценарію. `success` проходить шість етапів;
`retry` відхиляє перший review кожного етапу й передає конкретну причину наступній
спробі; `failure` вичерпує три спроби; `approval` моделює waiting CI;
`race` змінює base безпосередньо перед merge; `unconfirmed` не підтверджує receipt.
Для невдалих симуляцій exit code 2 є очікуваним, а не успіхом конвеєра.

Ручний GitHub workflow **UX-AUTO bootstrap simulation** приймає тільки `simulate`.
Запускати з перевіреного ref; checkout без збереження credentials, read-only token,
один concurrent run, timeout 5 хвилин, actions pinned SHA. Він не запускається
через PR/comment/push/schedule і не активує UX-1. Після merge bootstrap workflow
з'явиться в default branch; сам bootstrap PR не підпадає під дозвіл автоматичного merge.

`state.json` — checkpoint координатора; `remote.json` — окремий синтетичний журнал
зовнішніх операцій; `report.json` — зведення. Усі `mock://` URL, SHA та screenshots
фіктивні. Файли пишуться через atomic replace/fsync під exclusive lock. Втрата
`remote.json` означає втрату mock-журналу: не видаляти його для обходу блокера.
Артефакти workflow зберігаються 7 днів. Реальні GitHub CI/evidence ними не доведені.

## Стани, повтори й відновлення

`pending → running → pr_open → verifying → merged`; відмова перевірки веде
до `fixing → pr_open`, після третьої спроби — `blocked`. Наступний stage починається
лише після підтвердженого merge receipt. Перед merge завжди повторно беруться
поточний PR та evidence. Ключ операції включає digest spec, stage, attempt або head;
падіння після publish/merge до checkpoint повторює ту саму операцію без дублювання.
Кодер і reviewer мають різні invocation IDs; review перевіряє конкретні технічні
категорії, SHA/base і evidence, а не слово PASS. Це валідація mock-контракту,
не доказ реальної незалежності або достовірності evidence.

```sh
python3 -m scripts.ux_auto.coordinator pause --directory /tmp/ux-auto-success
python3 -m scripts.ux_auto.coordinator resume --directory /tmp/ux-auto-success
python3 -m scripts.ux_auto.coordinator simulate --directory /tmp/ux-auto-success
```

`resume` сам не запускає симуляцію. Після звичайного блокера він використовує
наступну спробу; після невизначеного merge — перевіряє ту саму merge-операцію,
без нової публікації. Ліміти не обнуляються. Після вичерпання бюджету потрібен
розбір причини, а не нескінченні retries. Для навмисної fault injection у Python
тестах backend можна виправити перед resume; CLI прив'язує scenario до каталогу.
Повторний `simulate` завершеного каталогу ідемпотентний. Інший spec digest,
пошкоджений стан, невідомий trigger/stage й кілька активних етапів відхиляються.
CLI pause отримує той самий lock: для поточного короткого процесу зупинка —
перервати процес і відновити checkpoint. Це не асинхронний live kill switch.

## Довірена специфікація та межі

`scripts/ux_auto/issue-106.md` — snapshot source of truth; `spec.json` фіксує його
SHA-256, stage scope й ліміти. Prompt будується з цих файлів і `AGENTS.md` довіреного
checkout. Зміна spec вимагає review і нового запуску, старий state не приймається.
Не приймати prompt, shell command або рішення про stage із PR body/comments/artifacts.
`pre_merge.py` не змінено: mock CI проходить ту саму функцію `evaluate`, що production
gate, включно з exact head, PR event, latest attempt, ancestry і required jobs/steps.
Дозволений чинним gate skipped нецільовий regression job не дорівнює дозволу
пропустити browser acceptance координатора.

Ліміти mock: три спроби на stage, 200 ticks, 60 секунд на процес, API-витрати 0.
Workflow timeout охоплює запуск і тести. Відсутні мережеві клієнти та live write adapter;
модель не отримує секретів чи доступу до робочих БД.

## Контракт live-адаптерів перед активацією

Це наступна інтеграція, **ще не реалізована**. Не достатньо ввімкнути variable:

1. У довіреному controller job отримати стан із durable storage з compare-and-swap
   та lease, звірити свіжий `master`, Issue #106/spec, лише один активний stage.
   Ідентичність запуску: owner-controlled dispatch із pinned trusted ref; довільний
   issue/comment event не є авторизацією. Пауза перевіряється до кожного side effect.
2. Окремий coder runner: pinned `openai/codex-action` або перевірений CLI,
   workspace sandbox, read-only checkout token, без App write key, production/R2/DB
   secrets. API credential передається тільки action/CLI, не всьому untrusted job.
   Лише synthetic PG18, окремі storage/network межі. Artifact — patch і логи,
   не виконуваний сценарій для privileged publisher.
3. Publisher на окремому runner без виконання PR code перевіряє шляхи/розмір patch,
   забороняє scope escape, workflows/gates/secrets зміни, перевіряє expected base/head,
   застосовує diff як дані й пише лише stage branch; той самий PR при retry.
   App installation token: Contents/Pull requests/Issues write, Actions read,
   Metadata read, лише цей repository. App private key доступний лише publisher/controller.
   Не використовувати default GITHUB_TOKEN для створення PR з припущенням запуску CI.
4. Реальний малий sandbox PR мусить довести Fast/Docs/Targeted `pull_request` runs
   на exact head без ручного approval. Чинний gate не приймає `workflow_dispatch`
   замість PR event. Немає PR-event runs — блокування, без зміни gate.
5. Інший read-only Codex invocation виконує предметний review diff; controller
   отримує report через trusted transport, перевіряє schema/provenance/head/base.
   Browser runner без write-token виконує stage-specific assertions, шість viewport/theme
   комбінацій, зберігає реальні screenshots та stdout із SHA. Для UX-6 — незалежні
   сім днів і publish; для UX-1/2 — document navigation/auth/Back/Forward.
   Mock evidence не приймається live-адаптером.
6. Controller запускає `python3 scripts/ci/pre_merge.py <PR> --repo ValtonUA/telmanfit`
   із довіреного checkout, перевіряє свіжі review/browser і поточний base; виконує
   SHA-locked merge (`gh pr merge --match-head-commit <SHA>` або REST expected sha).
   При base race — новий ancestry/gate/review цикл; після API timeout reconcile PR
   merged/head/merge SHA, не повторювати blind publish/merge. Лише підтверджений receipt
   відкриває наступний stage. Bootstrap PR та сторонні PR заборонені для цього adapter.
7. Оновлення checklist #106 — bounded structured writeback із PR URL, tested SHA,
   CI/review/browser URL, merge SHA/blocked reason; не перезаписувати сторонній body
   без перевірки версії. При завершенні UX-6 — підсумковий report, при блокері —
   конкретний log/action. Ці writes у mock замінено локальними JSON reports.

## Одноразове налаштування та go/no-go

Потрібні `OPENAI_API_KEY` як GitHub Secret (API billing окремо від ChatGPT Plus),
GitHub App installation/private key з наведеними правами, погоджений model і
числовий API budget. Перед додаванням live adapter встановити максимальні output
 tokens/turns, timeout invocation/job/run, три retries, щоденний/загальний spend
cap і облік фактичного usage між перезапусками. Без числових лімітів live fail closed.
Не виносити ключі у загальний job env, artifact, prompt чи репозиторій.

Live activation gate поки **PARTIAL**: не підтверджені API credential/неінтерактивний
Codex, App permissions, автоматичні PR-event checks для bot PR, реальний незалежний
review і browser/PG acceptance в ізольованих runners. Також треба реалізувати й
перевірити live transport, durable state/CAS, publisher і status writeback за контрактом.
Локальна наявність Codex/Chromium/Docker не доводить їхню працездатність у Actions.

Після реалізації адаптерів і налаштування secrets: read-only preflight → реальний
малий sandbox vertical slice → перевірка SHA/CI/review/browser/receipt і cost →
окремий owner activation dispatch з pinned trusted ref. До PASS усіх пунктів
UX-1…UX-6 залишаються заблокованими. Поточний workflow не має live dispatch.
