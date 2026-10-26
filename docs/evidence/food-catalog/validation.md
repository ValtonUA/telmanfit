# Локальна перевірка Food Catalog Management

Усі DB-прогони — нова disposable PostgreSQL 18.6, network none/tmpfs,
без зовнішніх портів, synthetic role/data та cleanup. Production і
Telmanfit_dev не використовувалися. Runtime PHP 8.4.27; Filament 5.10.2;
Chromium 154.0.8037.57. Feature flag у вихідній конфігурації не змінений.

| Перевірка | Результат |
|---|---|
| `bash tests/pg/run-stage1.sh` | SQL 76 PASS / 0 FAIL / 0 GAP; PG PHPUnit 137 PASS; SQLite 80 PASS; AUTH-2/3/4/5/6 PHPUnit 12/25/30/19/5 PASS |
| AUTH-2–5 concurrency; AUTH-6 parallel HTTP | PASS; AUTH-6: 25 сценаріїв, два HTTP servers/PG PIDs, справжнє lock wait, CSRF, обидва порядки відповідей |
| `RECIPE_BATCH_ONLY=1 RECIPE_BATCH_BROWSER=1 bash tests/pg/run-stage1.sh` | 85 PG PASS; усі recipe C1/C2/C3 та catalog concurrency PASS; Chromium 19 checks PASS |
| Catalog writer | Atomic food+ingredient, late rollback; decimal/NaN/Infinity/locale/overflow; g/serving/code, default/unique/FK/RESTRICT; CAS і свіжі admin/coach/client/flag перевірки |
| Збереження історії | Published manual snapshots, foods, плани й assignments однакові до/після catalog writes; stale Publish/Save відмовляють до explicit Refresh |
| Catalog Chromium | Create/edit трьох категорій, UOM, продукту/conversion/default, обладнання; validation/reload, empty/search states, CAS двох вкладок; 1440/390/320 light/dark без overflow |
| Реальний MFA/session/CSRF boundary | Missing CSRF → 419; revoked signed Livewire Save і всі catalog HTTP/create/edit → 403; catalog hash незмінний |
| AUTH-3/4/5 Chromium | PASS у повному auth/browser regression |
| `vendor/bin/pint --test`, `php artisan test` | PASS; Unit/Feature 80 PASS |
| Root/client build та client lint | PASS |
| Docs guard; CI/docs guard unit tests | PASS; 20 + 7 tests PASS |

[Сценарії Chromium](checks.json) та знімки в цьому каталозі — локальні synthetic
докази. Остаточні Fast/Docs/Full runs і результат `pre_merge.py` публікуються
в описі Draft PR на точному head SHA, щоб не створювати неперевірений commit
після CI. `eligible: true` не є дозволом на merge.

Каталог не додає схему/seed/фасування/SKU, не активує редактор рецептів і не
замінює Stage 3 Completion Audit. Чинна поведінка — у
[предметному контракті](../../food-catalog.md); статуси — у
[roadmap](../../roadmap.md).

## Повний перелік змінених файлів

- `.github/workflows/stage1.yml`
- `app/Filament/Resources/Catalog/CatalogResource.php`
- `app/Filament/Resources/Catalog/Concerns/MapsCatalogValidation.php`
- `app/Filament/Resources/Catalog/IngredientCategoryResource.php`
- `app/Filament/Resources/Catalog/IngredientResource.php`
- `app/Filament/Resources/Catalog/KitchenEquipmentCategoryResource.php`
- `app/Filament/Resources/Catalog/KitchenEquipmentResource.php`
- `app/Filament/Resources/Catalog/Pages/CreateCatalog.php`
- `app/Filament/Resources/Catalog/Pages/CreateIngredient.php`
- `app/Filament/Resources/Catalog/Pages/CreateIngredientCategory.php`
- `app/Filament/Resources/Catalog/Pages/CreateKitchenEquipment.php`
- `app/Filament/Resources/Catalog/Pages/CreateKitchenEquipmentCategory.php`
- `app/Filament/Resources/Catalog/Pages/CreateRecipeCategory.php`
- `app/Filament/Resources/Catalog/Pages/CreateUom.php`
- `app/Filament/Resources/Catalog/Pages/EditCatalog.php`
- `app/Filament/Resources/Catalog/Pages/EditIngredient.php`
- `app/Filament/Resources/Catalog/Pages/EditIngredientCategory.php`
- `app/Filament/Resources/Catalog/Pages/EditKitchenEquipment.php`
- `app/Filament/Resources/Catalog/Pages/EditKitchenEquipmentCategory.php`
- `app/Filament/Resources/Catalog/Pages/EditRecipeCategory.php`
- `app/Filament/Resources/Catalog/Pages/EditUom.php`
- `app/Filament/Resources/Catalog/Pages/ListCatalog.php`
- `app/Filament/Resources/Catalog/Pages/ListIngredient.php`
- `app/Filament/Resources/Catalog/Pages/ListIngredientCategory.php`
- `app/Filament/Resources/Catalog/Pages/ListKitchenEquipment.php`
- `app/Filament/Resources/Catalog/Pages/ListKitchenEquipmentCategory.php`
- `app/Filament/Resources/Catalog/Pages/ListRecipeCategory.php`
- `app/Filament/Resources/Catalog/Pages/ListUom.php`
- `app/Filament/Resources/Catalog/RecipeCategoryResource.php`
- `app/Filament/Resources/Catalog/UomResource.php`
- `app/Models/Catalog/Ingredient.php`
- `app/Models/Catalog/IngredientCategory.php`
- `app/Models/Catalog/KitchenEquipment.php`
- `app/Models/Catalog/KitchenEquipmentCategory.php`
- `app/Models/Catalog/RecipeCategory.php`
- `app/Models/Catalog/Uom.php`
- `app/Policies/CatalogPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Catalog/CatalogWriter.php`
- `docs/architecture.md`
- `docs/ci.md`
- `docs/evidence/food-catalog/checks.json`
- `docs/evidence/food-catalog/empty-1440-dark.png`
- `docs/evidence/food-catalog/empty-1440-light.png`
- `docs/evidence/food-catalog/empty-320-dark.png`
- `docs/evidence/food-catalog/empty-320-light.png`
- `docs/evidence/food-catalog/empty-390-dark.png`
- `docs/evidence/food-catalog/empty-390-light.png`
- `docs/evidence/food-catalog/equipment-1440-dark.png`
- `docs/evidence/food-catalog/equipment-1440-light.png`
- `docs/evidence/food-catalog/equipment-320-dark.png`
- `docs/evidence/food-catalog/equipment-320-light.png`
- `docs/evidence/food-catalog/equipment-390-dark.png`
- `docs/evidence/food-catalog/equipment-390-light.png`
- `docs/evidence/food-catalog/ingredient-1440-dark.png`
- `docs/evidence/food-catalog/ingredient-1440-light.png`
- `docs/evidence/food-catalog/ingredient-320-dark.png`
- `docs/evidence/food-catalog/ingredient-320-light.png`
- `docs/evidence/food-catalog/ingredient-390-dark.png`
- `docs/evidence/food-catalog/ingredient-390-light.png`
- `docs/evidence/food-catalog/list-1440-dark.png`
- `docs/evidence/food-catalog/list-1440-light.png`
- `docs/evidence/food-catalog/list-320-dark.png`
- `docs/evidence/food-catalog/list-320-light.png`
- `docs/evidence/food-catalog/list-390-dark.png`
- `docs/evidence/food-catalog/list-390-light.png`
- `docs/evidence/food-catalog/validation.md`
- `docs/food-catalog.md`
- `docs/project-status.md`
- `docs/recipe-batches.md`
- `docs/roadmap.md`
- `lang/uk/validation.php`
- `tests/Postgres/FoodCatalogTest.php`
- `tests/browser/food-catalog-fixture.php`
- `tests/browser/food_catalog.py`
- `tests/browser/recipe_batch.py`
- `tests/pg/food-catalog-concurrency.php`
- `tests/pg/run-stage1.sh`
