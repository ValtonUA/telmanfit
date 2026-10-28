# UX-AUTO: координатор та неактивні live-адаптери

[Issue #107](https://github.com/ValtonUA/telmanfit/issues/107) координує шість
послідовних PR із [Issue #106](https://github.com/ValtonUA/telmanfit/issues/106).
Bootstrap/mock і його команди збережено. Окремі [live-адаптери](ux-auto-live.md)
реалізують Codex/GitHub/browser/state/merge transport, але **не активовані**.
Bootstrap-команда `coordinator live` залишається заблокованою; реальний CLI —
`scripts.ux_auto.live`. Саме додавання secrets не замінює sandbox receipt та активацію.

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
не доказ реальної незалежності або достовірності evidence. Live backend отримує
evidence від власних ізольованих workers, а не з PR payload.

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
Workflow timeout охоплює запуск і тести. Ці CLI-команди не використовують live backend.

## Live-інтеграція

Наявний `tick()` використовується без переписування: додано `Waiting` без втрати
спроби для CI, live mode та окремий sandbox evidence contract. Реалізація адаптерів,
межі ізоляції, configuration/secrets, бюджет, одноразове налаштування та команди
preflight/sandbox/activate/run описані в [операційній інструкції](ux-auto-live.md).

Live feasibility залишається PARTIAL до реального isolated vertical slice.
[Локальні докази адаптерів](evidence/ux-auto-live/report.md) відокремлюють
synthetic transport tests від справжнього Chromium та Docker probe. Вони не є
доказом реального Codex API або bot-created PR CI. UX-1…UX-6 не запускалися.
