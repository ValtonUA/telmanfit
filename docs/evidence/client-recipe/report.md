# T5.4 — перевірки перегляду рецепта

`FEASIBILITY: PASS`: актуальний master на початку роботи —
`698d4c30260a996366ef08c6b299d59e0143babb`. Підтверджено чинні Model I,
ClientSession AUTH-4/5, calendar T5.2, RecipeTokens, PublicImageUrl та SQL
recipe directions/equipment. Даних у чинних таблицях достатньо; міграцій немає.

## Відтворення

```sh
npm run lint --prefix client
npm run build --prefix client
NODE_USE_ENV_PROXY=1 npm run build
php artisan test
vendor/bin/pint --test
python3 scripts/docs_guard.py --pr-base origin/master
CLIENT_RECIPE_BROWSER=1 AUTH4_ONLY=1 bash tests/pg/run-stage1.sh
```

Runner створює новий disposable PostgreSQL 18.6 без мережі/зовнішніх портів,
зі synthetic records та непривілейованим runtime role; cleanup прибирає власні
ресурси. Browser runner використовує справжню React-збірку, CSP та email OTP /
ClientSession; synthetic queue доставляє тільки в encrypted disposable mailbox.
Успішні API-відповіді не підміняються. Зовнішні зображення підмінено локальною
синтетичним зображенням; error/retry використовує навмисний 503 fault injection.
За наявності системного Chromium допустимо вказати
`CLIENT_RECIPE_CHROMIUM=/usr/bin/chromium`; CI використовує встановлений Playwright
Chromium. Новий browser сценарій підключений до AUTH4 baseline Targeted.

PG-тести охоплюють legacy/A/B/C3/C4 і fractional assigned servings, full physical
composition, manual frozen can quantities, nullable/manual equipment, ingredient
та equipment tokens, allowlisted і невалідні фото, пошкоджений/відсутній склад,
невідомі tokens, owner/forged IDs, календарні межі/зміну призначення, revoked/idle/
absolute/MFA/restore gates, GET/HEAD без DML та без змін xmin усіх public tables,
точну версію після нової публікації та SQL immutability published history.

Локально: Unit/Feature — 99 тестів / 382 assertions; AUTH4 PostgreSQL —
148 тестів / 1527 assertions, п’ять AUTH4 concurrency сценаріїв — PASS.
Pint, lint, обидві frontend збірки й Docs guard — PASS.
CI runner unit regressions — 39 PASS; Docs regressions — 11 PASS.
Браузерні сценарії та версія записані в
[browser-checks.json](browser-checks.json). Результати GitHub Fast/Docs/Targeted
на фінальному head SHA наведено в описі Draft PR; локальні тести їх не замінюють.

## Скриншоти

| Ширина | Light | Dark |
|---|---|---|
| 1440 | [Рецепт](recipe-1440-light.png) | [Рецепт](recipe-1440-dark.png) |
| 390 | [Рецепт](recipe-390-light.png) | [Рецепт](recipe-390-dark.png) |
| 320 | [Рецепт](recipe-320-light.png) | [Рецепт](recipe-320-dark.png) |

## Обмеження

Реальні дані й production не використовувалися. AUTH controlled flags залишаються
OFF за замовчуванням. Історична схема не має immutable display labels для всіх
legacy/A UOM та equipment catalog names; їхні збережені параметри не перераховуються.
Поточний logical cover рецепта може змінитися незалежно від published directions.
Немає таймерів, кухонних сесій, закупівельного округлення чи КБЖВ на раціоні.

## Змінені файли

- `app/Http/Controllers/Api/ClientMealItemRecipeController.php`
- `app/Http/Middleware/ClientAuthBoundary.php`
- `app/Http/Middleware/SerializedAuthSession.php`
- `app/Services/MealPlans/ClientMealItemRecipe.php`
- `client/src/App.tsx`
- `client/src/api.ts`
- `client/src/components/MealItemRecipe.css`
- `client/src/components/MealItemRecipe.tsx`
- `client/src/components/MealPlan.tsx`
- `docs/client-app.md`
- `docs/client-recipes.md`
- `docs/evidence/client-recipe/browser-checks.json`
- `docs/evidence/client-recipe/recipe-1440-dark.png`
- `docs/evidence/client-recipe/recipe-1440-light.png`
- `docs/evidence/client-recipe/recipe-320-dark.png`
- `docs/evidence/client-recipe/recipe-320-light.png`
- `docs/evidence/client-recipe/recipe-390-dark.png`
- `docs/evidence/client-recipe/recipe-390-light.png`
- `docs/evidence/client-recipe/report.md`
- `docs/meal-plans.md`
- `docs/project-status.md`
- `docs/roadmap.md`
- `routes/api.php`
- `tests/Auth4/ClientMealItemRecipeTest.php`
- `tests/MealPlans/ClientMenuFixture.php`
- `tests/browser/client-meal-plan-fixture.php`
- `tests/browser/client_meal_plan.cjs`
- `tests/browser/recipe-client-in-context.sh`
- `tests/browser/recipe-client.py`
- `tests/pg/run-stage1.sh`
