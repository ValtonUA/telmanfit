# T5.3 — read-only список покупок

## Зроблено і feasibility

База — актуальний `master` e69a95bdc260b69c6c5638f39e48244504fd592b.
**FEASIBILITY: PASS** до змін: прочитані AGENTS, roadmap/project-status, BR-S-01…03,
client-app, meal-plans, Stage 3 §11 і §17.3; перевірені чинні readers та виконувані
міграції. У новій disposable PostgreSQL18.6 фактично застосовано всі міграції,
перевірено `information_schema.columns` для meal items, recipe versions/ingredients
і batch quantities. Production/реальних даних не використовували.

Реалізовані [API та алгоритм](../../shopping-lists.md): exact активне призначення
через ClientSession, тижневий календар без циклів, ingredient/legacy/A/B/C,
точна агрегація, збереження вихідних UOM та відмова замість неповного списку.
Новий маршрут включений у чинну auth boundary та safe-read session save/GC exclusions;
права доступу, MFA і поведінка наявних маршрутів не змінені.

## Перевірки

Локальний Linux runtime — PHP8.4.27 із чинними extensions/lockfile.
Початковий локальний PHP8.4.26 оновлено лише у зовнішньому workspace runtime,
без змін `composer.json`, lockfile, production-конфігурації чи runtime policy.
Офіційний PostgreSQL18.6 Bookworm pin:
`sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc`.
Штатний runner створював новий кластер/непривілейовану роль,
`network=none`, без портів, приватний Unix socket і PGDATA tmpfs; cleanup PASS.

| Набір | Результат |
|---|---|
| `AUTH4_ONLY=1 bash tests/pg/run-stage1.sh` | 130 tests / 1189 assertions PASS; чинні AUTH4 concurrency scenarios PASS; cleanup exit0 |
| `php artisan test --display-all-issues` | 99 tests / 382 assertions PASS |
| `npm run build` | PASS; штатний Vite manifest для Feature tests |
| `php vendor/bin/pint --test` | PASS |
| `python3 scripts/docs_guard.py --pr-base origin/master` | PASS |
| `python3 -m unittest discover -s tests/docs -v` | 11 PASS |
| `git diff --check` | PASS |

Shopping tests перевіряють: сім B/7=одна B; три C/3+чотири C/4=дві C
(також на малих frozen grams, де проміжне округлення дало б неправильну суму);
дробові A/B/C з exact119/1200; legacy servings; mixed UOM; різні історичні
конверсії однієї одиниці; frozen банка420г без ceil кожної порції; зміна live
конверсії/label не змінює B/C history; два тижні та включний кінець assignment;
нові published/draft recipes не підміняють exact food; однакові назви різних ID
не об'єднуються. Foreign owner і видалена дозволена UOM дають409 без partial list.
Негативний PG selector write відхилений без вимкнення guards.

Успадковані T5.1 HTTP gates виконані для нового endpoint: відсутнє/чуже/майбутнє/
завершене призначення, revoked/expired/binding/auth_version sessions, MFA,
anonymous/admin, controlled OFF/fence, GET/HEAD/405. Для GET перевіряються hashes
**всіх public tables разом із xmin**, примусова session GC lottery100% та відсутність
будь-якого DML/DDL у query log; для HEAD — hashes/xmin.

Перший локальний Fast запуск не мав Vite manifest; після штатної збірки99/99 PASS.
Font build потребував дозволеного proxy поза filesystem/network sandbox;
TLS і мережеву політику не послаблювали. Ранній synthetic fixture без обов'язкового
title виправлено до фінального130/130 прогону.
Локальні результати не є GitHub required checks: їхні URLs, статуси та exact head
фіксуються окремо в описі єдиного Draft PR після завершення Fast/Docs/Targeted.

## Усі змінені файли

- `app/Services/MealPlans/ClientShoppingList.php`
- `app/Http/Controllers/Api/ClientShoppingListController.php`
- `app/Http/Middleware/ClientAuthBoundary.php`
- `app/Http/Middleware/SerializedAuthSession.php`
- `routes/api.php`
- `tests/Auth4/ClientShoppingListTest.php`
- `docs/shopping-lists.md`
- `docs/client-app.md`
- `docs/meal-plans.md`
- `docs/roadmap.md`
- `docs/project-status.md`
- `docs/evidence/shopping-lists/report.md`

## Рішення, ризики та свідомі межі

Окреме відкрите рішення DEC-5S зафіксоване в [roadmap](../../roadmap.md):
магазинні фасування/SKU, їстівна маса та purchase rounding ще не погоджені.
Decimal серіалізація до6 знаків не є округленням до покупки; exact fields зберігають
повну точність. Historical stored ratio не видається за підтверджену упаковку.

Немає shopping schema/migrations/storage, checkbox/history, UI, kitchen sessions,
мегарецептів, залишків, автоматичних циклів, змін T5.2 або production activation.
На реальних рецептах чи клієнтських даних перевірок не проводили; synthetic дані
доводять контракт сервісу, а не наповнення production каталогу. Full CI та merge
не виконуються. Docs-Impact: updated — новий API-контракт, roadmap і project-status.
