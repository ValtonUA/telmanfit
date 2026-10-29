# UX-1 — локальна перевірка та предметний review

Реалізовано декларативну React Router навігацію; серверні auth, CSP, схема й flags збережені. [PR #110](https://github.com/ValtonUA/telmanfit/pull/110). CI не підмінюється цим локальним звітом: фінальний head перевіряється Fast/Docs/Targeted та довіреним `scripts/ci/pre_merge.py` безпосередньо перед SHA-locked merge.

## Перевірки

- `npm run lint --prefix client`, `npm run build --prefix client`, root `npm run build`, `python3 scripts/docs_guard.py`, `git diff --check` — PASS.
- `php artisan test` — 125 тестів, 511 assertions, PASS.
- `AUTH4_ONLY=1 CLIENT_RECIPE_BROWSER=1 CLIENT_RECIPE_CHROMIUM=/usr/bin/chromium bash tests/pg/run-stage1.sh` — нова disposable PG18 без мережі/портів; 167 тестів, 1678 assertions, auth concurrency та реальний React/ClientSession browser — PASS.
- `AUTH5_ONLY=1 AUTH5_BROWSER=1 bash tests/pg/run-stage1.sh` — нова disposable PG18; 19 тестів, 131 assertions, auth concurrency, OTP/MFA/recovery/two-browser revoke/logout-all/session expiry — PASS.
- Chromium 154, 1440/390/320 light/dark: [browser-checks.json](browser-checks.json), [auth5-results.json](auth5-results.json). Чотири вкладки не створюють document navigation або повторної CSRF-ініціалізації; DOM-вузли оболонки збережені. Перевірені query/history, прямий рецепт/reload, unknown route, library, shopping, cooking, revoked assignment/session. SPA-вихід звільняє Wake Lock; Back відновлює cooking stage/timer. Темний login має контрастну картку, overflow відсутній.

## Незалежний предметний review-прохід

Перевірено router/auth boundary, bootstrap promise, React effect dependencies, асинхронні відповіді після unmount, query/history та CSP. Виявлені проблеми виправлено й повторно перевірено:

1. Змінний identity `navigate` міг повторно запускати initialization/fetch effects — використано стабільний callback із ref.
2. Автоматична нормалізація query могла переграти наступний перехід — URL змінюється явним вибором користувача.
3. Inline рання тема конфліктувала з CSP — зовнішній blocking CSS до React; Vite коректно додає production base.
4. Відповідь відкриття cooking session після unmount/discard — generation guard; lifecycle Wake Lock і таймерів підтверджений браузером.
5. Same-URL «Почати знову» не демонтував auth-форму — key за router location; завершення auth після unmount ігнорується. Error recovery очищує boundary без document reload.
6. SPA browser після повернення з покупок міг зачепити старий однойменний selector — очікування exact URL та plan heading перед наступною дією. Під час зміни тижня старі дні не інтерактивні, доки не прийде актуальна відповідь.
7. Темний колір тексту на світлій auth-картці — узгоджені dark токени; browser regression.

Початкові помилки локальної підготовки (PHP 8.4.26 замість 8.4.27, відсутній bcmath і root Vite manifest) усунено без зміни вимог репозиторію. Повторний повний AUTH4 browser harness (зокрема admin invitations) після підготовки root assets — PASS; цільовий React acceptance і повний AUTH5 browser також PASS.

Фізичні mobile-пристрої та production не перевірялися; production flags не змінювалися. Окремого SSR, Framework Mode чи нових API немає.

## Security patch required CI

Під час виконання з’явилася CVE-2026-45070 для чинного `symfony/mime`. Мінімальний patch lockfile: v8.1.7 → v8.1.10, без нових пакетів чи зміни constraints. Окремий локальний `composer audit` недоступний через мережеву політику `packagist.org`; обов’язковий Fast CI виконує audit на фінальному lockfile. Повний локальний baseline зі справжніми auth3/auth4/auth5/auth6 browser/HTTP та SQL завершився PASS (554 s) до цього patch; після patch повторюються Unit/Feature й цільовий client/auth acceptance, CI — усі вибрані групи.
