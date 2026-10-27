# Local-only імпорт Excel-v1

Команда `nutrition:import-xlsx` переносить довідники та одну **нову спільну
чернетку batch_v1** за запуск. Джерело — перевірений XLSX із 24 аркушами та
153 SQL-колонками; контракт заголовків —
[`resources/import/nutrition-v1.json`](../resources/import/nutrition-v1.json).
Поточні колонки PostgreSQL звіряються перед читанням.

Це окремий локальний шлях перевірки власних рецептів. Канонічний T1.5 seed,
production-імпорт, зовнішні клієнти, published/legacy merge, раціони та
призначення не входять до v1. Інші рецепти з того самого файлу можна переносити
наступними окремими запусками з іншим `--recipe`.

## Дозволене середовище

Лише [постійний local Compose](local-compose.md): `APP_ENV=local`, явний
`TELMANFIT_LOCAL_COMPOSE=true`, непривілейована роль `telmanfit_local_runner`,
БД `telmanfit_local_*`, PostgreSQL 18 та чинний RestoreFence. Тестовий виняток
обмежений наявною ізольованою Stage 1 identity з synthetic epoch.

У погодженому локальному Compose редактор увімкнено через
`TELMANFIT_LOCAL_RECIPE_EDITOR=true`. `config/recipes.php` одночасно перевіряє
local environment та Compose-профіль; production залишається OFF. MFA, CSRF,
RestoreFence і чинні admin-перевірки не змінюються.

## Перенесення одного рецепта

Після збірки актуального локального образу покладіть XLSX у приватний каталог
контейнера. Наприклад, із PowerShell у корені репозиторію:

```powershell
docker compose exec -u www-data app mkdir -p storage/app/private/import-source
docker compose cp C:/path/TelmanFit_7_days.xlsx app:/var/www/html/storage/app/private/import-source/source.xlsx
docker compose exec app chown www-data:www-data storage/app/private/import-source/source.xlsx
```

Спочатку **dry-run**, без записів у БД і без створення журналу:

```powershell
docker compose exec -u www-data app php docker/local/import-nutrition.php storage/app/private/import-source/source.xlsx --recipe=1
```

`--recipe` — локальний `recipe_versions.id` у файлі, навіть якщо він складається
тільки з цифр. Результат містить SHA256, обсяг і назву вибраного рецепта.
Для запису потрібні цей SHA256 та справжній ID дозволеного локального admin:

```powershell
docker compose exec -u www-data app php docker/local/import-nutrition.php storage/app/private/import-source/source.xlsx --recipe=1 --actor=1 --expect-sha256=SHA256_З_DRY_RUN --apply
```

ID адміністратора не береться з Excel і не вважається завжди рівним 1. Приклад
треба адаптувати до фактично перевіреного локального користувача.

Результат повертає реальний `recipe_version_id` та адресу
`/admin/recipe-batches?version=ID`. Після штатного входу з MFA перегляньте A/B/C,
кроки й обладнання. Практичні мінімальні об’єми A/C можна виправити в редакторі.
Потім окремо підтвердіть manual/equipment review та, за потреби, опублікуйте.
Імпортер **не підтверджує достатність, не публікує і не створює recipe food**.

## Правила даних

- `_`-аркуші не імпортуються. Непорожні `_external_refs` у v1 відхиляються.
- Читаються значення клітинок, а не cached формули. Формули у data-аркушах,
  Excel errors, VBA, зовнішні links/relationships, DTD та завеликі ZIP/XML
  відхиляються. Ліміти: 20 MB файл, 80 MB розпакований вміст, 500 записів на аркуш.
- Текстові локальні ID, включно з `001`, не перетворюються на SQL identity.
  CatalogWriter створює ID, food+ingredient та конверсії; імпортер зберігає crosswalk.
- BatchEditor формує actual A/B/C, snapshots, frozen grams, totals і hashes.
  Клієнтські `*_snapshot`, hashes, КБЖВ і grams-кеші Excel не копіюються.
- Повнота заголовків, PK/FK, composite version scopes, A у g, три заголовки,
  B/C, обладнання, категорії, конверсії й підтримані tokens перевіряються до запису.
- Manual quantity/UOM передаються без перерахунку з округленої A. Auto у v1
  використовує exact g / step 0.01. Auto C після non-g B без прийнятої policy
  відхиляється; фасування та округлення до 0.5 л не вигадуються.
- Нові рядки отримують тимчасові `pending:`/`new-` ID. Чинний RecipeTokens remap
  замінює посилання стабільними PK тієї самої версії. Порядок рядків зберігається.

## Атомарність і повторення

Перший запуск допускає лише порожні харчові каталоги/foods/recipes. Імпортер не
зіставляє чужі наявні записи за назвою. Усі каталоги та вибраний aggregate
записуються в одній транзакції з чинними deferred guards, без їх вимкнення.

Приватний журнал `storage/app/private/nutrition-import-v1/<SHA256>.json` містить
identity БД та локальні↔SQL maps. Схема БД не розширюється. Один writer захищено
file lock та PostgreSQL advisory lock. Повтор того самого файлу/версії повертає
наявний ID. Для іншої версії того самого файлу використовуються ті самі каталоги;
зміна каталогів після першого імпорту відхиляється за fingerprint.

Перед COMMIT журнал атомарно отримує `pending`, після успішного COMMIT —
`complete`. Якщо процес перервався в цьому проміжку, повтор **заборонено** до
ручної перевірки БД/журналу. Не видаляйте pending-файл і не повторюйте імпорт
наосліп: транзакція могла завершитися. Відновлення/зміна джерела потребує
окремого зіставлення. Переносьте приватний журнал разом із локальною БД.

## Перевірки

`NutritionXlsxReaderTest` перевіряє текстові ID/нуль, formula caches та external
relationships. `LocalRecipeEditorConfigTest` — production OFF і явний local opt-in.
`NutritionImportTest` у disposable PG18 перевіряє dry-run, справжні ID/token remap,
серверні snapshots, manual pairs без ack, повтор без дублікатів, пізній DB rollback,
зміну SHA256, pending journal, чужий component scope та actor/environment відмови.
Клас включений у recipe-групу `tests/pg/run-stage1.sh`; робоча БД не використовується.
