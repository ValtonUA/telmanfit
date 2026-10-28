# UX-AUTO live: адаптери та одноразове налаштування

Адаптери **реалізовано, але не активовано**. Вони використовують `tick()` із
[bootstrap](ux-auto.md); поточний Draft PR залежить від ще відкритого PR #108.
Розробка/контрактні тести без секретів дозволені окремо від реального запуску.
API billing не входить до ChatGPT Plus. Значення в `live.example.json` — **шаблон,
а не дозвіл витрат**, не перевірений image або робочий config.

## Що виконує код

| Адаптер | Поведінка |
|---|---|
| `github.py`, `app_token.py` | REST через gh; read pagination bounded, мутації не повторюються наосліп; installation token поновлюється через App JWT і scope одного repository |
| `store.py` | `coordinator.json` у `ux-auto-state`, Contents SHA CAS, lease, pause, immutable objects із digest; конфлікт зупиняє side effects |
| `worker.py`, `api_proxy.py` | окремі Docker coder/reviewer, workspace-write/read-only, ніяких host home/socket/write-token; OpenAI key лише в relay; request/input/output/model/time/spend bounds |
| `backend.py` | власний stage branch, Draft PR, structural patch allowlist, exact SHA CI, independent review, browser evidence, trusted pre_merge.py, explicit expected-head merge, GET confirmation/reconciliation |
| `live.py` | preflight → harmless sandbox → окрема activation → run на існуючому state machine; pause/resume/status та blocked/completion reports |

Publisher читає patch як UTF-8 дані й використовує Git blobs/tree/commit API;
не виконує candidate Git config/clean filters/hooks, build scripts або shell із PR.
Після моделі файли порівнюються з trusted Git tree без `git add` у candidate checkout. Зміни обмежено stage
paths; scripts/workflows/gates/schema/secret paths, symlink, rename/delete, binary,
порожні/завеликі patches блокуються. Повторна спроба має той самий PR і новий head;
commit має попередній head і актуальний master серед parents, без force push.
Cтатичний snapshot Issue #106 та AGENTS беруться з pinned controller checkout.

CI evidence збирається з GitHub API, а не з artifacts автора. `Waiting` не запускає
кодера повторно. Missing/approval CI після timeout — blocker. Failed CI/review
повертають конкретну причину bounded fix loop. Reviewer — інший invocation у
read-only container зі structured schema; plain PASS не приймається. Перед merge
ще раз збираються CI, head/base, виконується **незмінений** `scripts/ci/pre_merge.py`
із trusted ref і перевіряється master. REST merge має `sha=<tested-head>`, auto-merge
не використовується. Після timeout читається фактичний PR receipt; наступний stage
доступний лише після підтвердження merge SHA у master ancestry.

## Безпечний browser/PG контракт

Candidate додає `tests/browser/ux_auto_acceptance.py` у межах відповідного UX PR.
Цей код виконується **лише** в окремому worker без токенів, зовнішньої мережі чи
Docker socket. Launcher створює internal network і новий disposable PG18 на tmpfs,
без зовнішніх портів; перевіряє server_version_num, прибирає тільки власні UUID
контейнери/мережу. Немає підключення production/Telmanfit_dev.

Harness отримує `--stage`, `--head`, `--output /out`, testing env і синтетичний PG
`pg:5432`, database/user `postgres`. Audited worker image має залежності/fixtures
для Laravel/Filament/React та Chromium (`/opt/ux-auto/deps/vendor`, `node_modules`,
`client-node_modules`; trusted launcher додає лише runtime symlinks, які не публікуються); harness сам готує тільки цю disposable БД,
піднімає loopback PHP server й запускає реальні assertions. Немає мережі для npm/
Composer install під час candidate test. Відсутній harness або залежності — blocker.

`browser.json` містить exact head, status success, усі stage checks із
`coordinator.acceptance(stage)`, `assertions: {check: "passed"}`, matrix
1440/light,1440/dark,390/light,390/dark,320/light,320/dark та шість різних PNG filename.
PNG мусять мати відповідні width, коректну сигнатуру й bounded size; hashes і actual
images зберігаються у state branch. Independent reviewer перевіряє якість assertions
і те, що harness не підміняє перевірки self-attested статусом. JSON/PNG самі по собі
не доводять правильність UI; required Targeted також мусить пройти на тому самому SHA.
UX-1/2: no-document-navigation/auth/theme/Back/Forward/direct link;
UX-3…6: synthetic plan/drag/keyboard/touch/stale/ACL/rollback;
UX-6 додатково seven-independent-days і publish.

## Мінімальне одноразове налаштування власника

1. Предметно перевірити й змерджити bootstrap та adapter PR звичайним порядком
   (вони **не** підпадають під дозвіл автоматичного UX merge). Pin повний controller
   commit SHA в `UX_AUTO_TRUSTED_REF`. Після dependency merge retarget stacked PR до
   master і дочекатися Fast/Docs/Targeted на новому head/base.
2. Підготувати **окремий приватний sandbox repository** в тому самому owner із
   master і чинними Fast/Docs/Targeted workflows. Це може бути ізольована копія
   synthetic checkout без реальних даних/secrets. App встановлюється лише на main
   і sandbox. Записати repository IDs та installation ID; жодного org-wide token.
3. GitHub App permissions: Metadata/Actions read, Contents/Pull requests/Issues
   write. App ID/private key — secrets `UX_AUTO_APP_ID`, `UX_AUTO_APP_PRIVATE_KEY`;
   окремий project-scoped API key — `UX_AUTO_OPENAI_API_KEY`. Тільки controller step
   бачить App key; private key не монтується в workers. Default GITHUB_TOKEN не
   використовується для bot PR publishing. Локальний CLI використовує ті самі назви
   зі змінних процесу; `UX_AUTO_GH_TOKEN`/`UX_AUTO_SANDBOX_GH_TOKEN` допускаються тільки
   для read-only preflight/seed/status/pause, без fallback для live invocation.
4. Надати audited **digest-pinned** OCI worker image із Git/Python/PHP/Composer,
   зібраними synthetic dependencies, Chromium/Playwright та перевіреним Codex CLI,
   який підтримує flags із `worker.py`. Образ не має credentials, VOLUME/entrypoint
   hooks або host mounts. Окремий pinned Python proxy image і PG18 pin із шаблону.
   Кодер не має Docker socket/host network; середовище controller — окремий ephemeral
   runner без сторонніх сервісів. Image/CLI compatibility перевіряє sandbox probe.
5. Скопіювати `scripts/ux_auto/live.example.json` у зовнішній config; замінити всі
   placeholders: model, image digests, trusted_ref, spec_sha, IDs, sandbox repository.
   Spec digest: `python3 -c 'from scripts.ux_auto.coordinator import trusted_spec_sha; print(trusted_spec_sha())'`.
   Підтвердити актуальні **максимальні** ціни вибраного model у micro-USD/мільйон tokens
   й явно встановити числові total/day caps. Приклад $40 **не є погодженим бюджетом**.
6. Зберегти public config у `UX_AUTO_CONFIG`, коротке ім'я sandbox repository у
   `UX_AUTO_SANDBOX_REPOSITORY`, залишити `enabled:false`. Variable
   `UX_AUTO_LIVE_ENABLED=true` відкриває лише owner dispatch; без sandbox receipt і
   окремого `activate` команда run залишається заблокованою. Не встановлювати цю
   variable до перевірки конфігурації й зовнішнього go/no-go.

## Команди та реальний ізольований vertical slice

З чистого pinned checkout (config лежить поза ним):

```sh
python3 -m scripts.ux_auto.live preflight --config /tmp/ux-auto-config.json
python3 -m scripts.ux_auto.live seed --config /tmp/ux-auto-config.json
python3 -m scripts.ux_auto.live sandbox --config /tmp/ux-auto-config.json
```

Preflight перевіряє repository/workflows, ancestry controller→master і доступність
credential bindings; **не називає наявність змінної робочим API credential**.
`READY_FOR_SANDBOX` ще не означає live PASS. Seed створює лише state branch,
paused checkpoint без activation. Не запускати seed повторно для скидання budget.

`sandbox` використовує окремий scoped token і repository, тільки branch
`ux-auto/sandbox` та файл `ux-auto-probe.txt`. Реальний Codex пише harmless текст,
інший invocation перевіряє його diff, trusted Chromium probe тестує synthetic
navigation/theme на 6 viewport/theme, потрібні справжні PR-event Fast/Docs/Targeted,
далі trusted pre_merge та SHA-locked merge **лише sandbox PR**. Це не UX-1.
Pending CI: повторити ту саму sandbox-команду; опублікований PR/API invocation не
дублюється. Успішний receipt прив'язаний до config digest, exact SHA і фактичного
merge; screenshots/CI/reviewer evidence зберігаються. Перед activation receipt
перевіряється через API та не може бути старшим семи діб.

Лише після успішного зовнішнього sandbox та погодженого go/no-go змінити
`enabled:true` (інші поля не змінювати: вони змінюють identity), потім:

```sh
python3 -m scripts.ux_auto.live activate --config /tmp/ux-auto-config.json
python3 -m scripts.ux_auto.live run --config /tmp/ux-auto-config.json
```

Activate сам не запускає UX stage. Run самостійно проходить шість stages, доки
всі gates успішні. Для штатного PR merge ручний approval не потрібен за винятком
AGENTS; permissions/недоступний browser/невичерпні помилки — bounded blocker.
При timeout controller повторний run продовжує durable checkpoint. Повторна API
генерація за ключем із ambiguous invocation заборонена: нова спроба після розбору
причини витрачає нову reservation. Самостійного reset бюджету немає.

## Budget, pause та діагностика

Reservation ДО invocation = requests × (input_bytes × max input price +
output_tokens × max output price), округлено вгору. Byte вважається token як
консервативна межа; cached discount не віднімається. Relay обмежує model, body,
кількість requests (включно з retry), max_output_tokens; server-side previous context,
remote tools і service tier із додатковою ціною заборонені. Ledger рахує резерв,
**не фактично виставлений рахунок**; автоматичних refunds немає. Total/day ledger
зберігається в CAS state, окремий wall-clock/timeout/attempt budget не скидається
resume. App installation tokens поновлюються до expiry для тривалого run.

```sh
python3 -m scripts.ux_auto.live pause --config /tmp/ux-auto-config.json
python3 -m scripts.ux_auto.live status --config /tmp/ux-auto-config.json
python3 -m scripts.ux_auto.live resume --config /tmp/ux-auto-config.json
```

Pause робить CAS без захоплення активного lease; controller перевіряє його до кожної
мутації. У GitHub workflow concurrency серіалізує dispatch, тому для термінового
stop спершу cancel active run, потім pause; SIGTERM/timeout прибирає власні worker
resources. SIGKILL може лишити UUID resources до runner teardown; не прибирати чужі.
Expired lease може перехопити новий controller; старий owner більше не має права
side effect. `resume` знімає pause/відновлює bounded stage, сам не запускає run.

Статуси/blocked reason/PR URL/tested SHA/merge SHA/CI-review-browser evidence й
completion summary пишуться ідемпотентними comments до #106. Body/checklist issue
не переписується: Issues API не надає перевіреного Contents-подібного CAS для body,
тому збережено сторонні редагування. Поточні stage reports зберігаються також у
state branch. Це свідома межа writeback, не твердження про автоматичний checkbox edit.

## Поточні блокери активації

Немає підтверджених реальних Codex API credentials/billing, налаштованого model/budget,
audited повного worker image, App installation permission/PR-trigger evidence і
успішного зовнішнього sandbox receipt. Цей PR не активує workflows або UX stages.
Локальні Git/Chromium/Docker тести — [окремі докази](evidence/ux-auto-live/report.md),
а не заміна live gate. Не послаблювати `pre_merge.py` для workflow_dispatch, skipped,
старих SHA або відсутнього browser acceptance.
