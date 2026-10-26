# Roadmap TelmanFit — MVP

**Це канонічний поточний план.** Пріоритети й статуси нижче мають змінюватися разом із відповідним PR — без дублювання датованих SHA/статусів у README, AGENTS чи архітектурі.

Це **єдиний оперативний план**. Всі задачі мають однакові колонки й один статус із чотирьох: **Виконано** (код/документ підтверджено), **Частково** (зроблено не весь scope), **Очікує рішення** (без погодження відповідний крок не виконувати), **Заплановано** (ще не реалізовано). Статус реалізації **не дорівнює** дозволу production. Поточні факти реалізації та докази — у [project-status.md](project-status.md); старі датовані звіти зберігаються в історії Git і тематичних `docs/auth*.md`/`docs/stage-*.md`, а не дублюються тут.

## Поточний зріз

| Stage | Зміст | Статус | Наступна дія |
|---|---|---|---|
| 0 | Рішення та gates | Частково | Закривати лише питання, що блокують конкретний модуль |
| 1 | PostgreSQL: 37 бізнес-таблиць | Частково | Міграції/тести виконані; T1.5 seed-довідники ще не погоджені |
| 2 | Картки клієнтів і auth | Частково | Картки готові; auth controlled/local/testing, production OFF; перший робочий екран анкети, решта питань пізніше |
| **3** | **Каталог продуктів і рецептів** | **Частково** | **A/B1/B2: ядро та редактор; C1: нові версії A/B/C; C2: персональні CoW; C3 merged #51; D: merged #53, Completion Audit #54; T1.5 окремо** |
| 4 | Раціони і призначення | Частково | T4.1/T4.2 foundation і структура; G4-I selectors/readonly resolver і T4.3 writer; UX-4E week/day editor; T4.3R повторення тижнів; T4.4 публікація; DEC-4A перед T4.5 |
| 5 | Клієнтський кабінет | Частково | Auth/security shell є, харчового функціоналу немає |
| 6 | Метрики, звіти, фото | Заплановано | Після клієнтів; окремі контракти за потреби |
| 7 | Комунікація | Заплановано | Після базового прогресу |
| 8 | Telegram | Заплановано | Пізніший напрям чинного roadmap |
| 9 | Інфраструктура та запуск | Частково | D8.1 — документація; D8.2 — Draft PR #25 без VPS; реалізація перед production |

**Залежності:** Stage 3 → Stage 4 → Stage 5; Stage 6 може розроблятися після Stage 2 незалежно від Stage 4, Stage 7 — після Stage 6. Stage 8 — після Stage 7. Stage 9 — перед першим production. Тренувальний модуль **не входить у затверджений MVP**; зміна цього рішення потребує окремого погодження.

## Позначення

| Позначення | Призначення |
|---|---|
| `Stage 0–9` | Основні продуктові етапи |
| `T3.1` тощо | Конкретна задача етапу (тут у таблицях) |
| `G1–G5` | Передумови / gates, застосовні **до конкретної роботи**, а не обов'язково до всього проєкту |
| `AUTH-1…AUTH-8` | Окремий потік авторизації у Stage 2 |
| `B1–B7` | Підзадачі AUTH-6B; B7 — передрелізна acceptance |
| `D1–D8` | Архітектурні рішення AUTH-6B; **не Stage 1–8** |
| `D8.1`, `D8.2` | Документаційний preflight і планування/налаштування staging; `D8.3` — лише неофіційна робоча назва можливого last-admin recovery після restore |

## Gates — без глобального блокування виконаних робіт

| ID | Передумова | Статус | Пояснення |
|---|---|---|---|
| G1 | Погодження Open Questions | **Частково** | G3/роль coach/admin/клієнтський email OTP/архітектура auth погоджені; **залишаються лише предметні рішення**: T1.5 для Stage 3 (BR-F-11 визначено за §17), анкета для T2.4, заміни призначень для Stage 4. Не позначати всі етапи заблокованими. |
| G2 | Ізольований PostgreSQL із `btree_gist` | **Виконано** | PG18.6, інтеграційні тести [Stage 1](stage-1.md) |
| G3 | Стратегія міграцій `B+R1` | **Виконано** | Laravel-міграції, збереження `users.id` BIGINT |
| G4 | Тестовий PG runner/CI | **Виконано** | `phpunit.pgsql.xml`, `tests/pg/run-stage1.sh`, GitHub Actions |
| G5 | Перший бізнес-модуль | **Виконано** | Обрано і реалізовано картки клієнтів Filament у Stage 2 |

## Задачі за етапами

### Stage 0 — Підготовка та узгодження

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T0.1 | Частину рішень зафіксовано, але питання в [schema-audit.md](schema-audit.md) ще відкриті; дублікати не усунуті повністю. Це не глобальний стоп для Stage 3 — вирішувати за потреби. | Частково |
| T0.2 | G3=B+R1 підтверджено, міграції реалізовано. Не повторювати. | Виконано |
| T0.3 | Відповідність SQL і Laravel-міграцій перевірена [Stage 1](stage-1.md). | Виконано |
| T0.4 | Ізольовані PostgreSQL-тести інтегровано у CI; G4 не відкривати повторно. | Виконано |

**Примітка:** T0.1 — не завершене суцільне прибирання документації; уже погоджені рішення не відкриваються повторно. Реалізація бізнес-модулів залежить лише від відповідних відкритих правил.

### Stage 1 — Бізнес-схема PostgreSQL

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T1.1 | `btree_gist` перевірено в ізольованій PG18; перевірка VPS — у Stage 9. | Виконано |
| T1.2 | Модуль 01: 21 таблиця, функції, тригери, constraints та індекси. | Виконано |
| T1.3 | Модуль 02: 5 нових таблиць; `users` збережено (BIGINT); FK перевірено. | Виконано |
| T1.4 | Модулі 03–04 реалізовано (5+5 таблиць). | Виконано |
| T1.5 | Схема довідників існує, але погоджених початкових даних/seed немає. Для Stage 3 окремо визначити потрібні `uoms`, `ingredient_categories`, `recipe_categories`, `kitchen_equipment_categories`; решта — перед відповідним модулем. | Очікує рішення |
| T1.6 | SQL PASS=76 FAIL=0 GAP=0 та Laravel/PG regression у CI; [деталі](stage-1.md). | Виконано |

**Межа:** Stage 1 створює таблиці й інваріанти, але не сервіс/Filament для харчування; T1.5 — окрема робота із значеннями, а не провал міграцій. [Докази](stage-1.md).

### Stage 2 — Клієнти та авторизація

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T2.1 | `Client`, зв'язки `User::client/coachedClients` і `Client::user/coach` реалізовані. | Виконано |
| T2.2 | `ClientPolicy`: тільки admin list/view/edit, створення заборонено, coach explicit. | Виконано |
| T2.3 | Filament `/admin/clients`, пошук, статуси, редагування й захист create; [Stage 2](stage-2.md). | Виконано |
| T2.4 | **Перший робочий vertical slice:** 3 фактичні питання з анкети → React `/app/onboarding` → авторизований Laravel API → `client_onboardings.answers json` → повторне читання + відображення у Filament. Повну анкету ще не перенесено; схема відповідей розширюється з реалізацією UI. Не блокує рецепти. | Частково |
| T2.5 | AUTH-3/4/5: реалізований код invitation, email OTP, MFA та React auth/security у controlled local/testing, default OFF. Production запуск, P5/P6 ще відкриті. | Частково |
| T2.7 | Типізовані профілі, окремі first_name/last_name для нової реєстрації, archive guard та forward-upgrade реалізовані; legacy імена/JSON/інвентар збережено. NOT NULL старих імен і повне завершення анкети — окремі кроки; [контракт](client-profiles.md). | Частково |
| T2.6 | Підтверджено [Stage 2](stage-2.md) і схемою: перехідні картки з `user_id=NULL` зберігаються та редагуються; `NOT NULL` не додано. | Виконано |

**Межа:** AUTH-3/4/5 реалізовано у контрольованому режимі, а production не дозволено. [Stage 2](stage-2.md), [AUTH-4](auth4.md), [AUTH-5](auth5.md).

### Stage 3 — Продукти та рецепти

Архітектурний [контракт редактора трьох форматів](proposals/stage-3-recipe-batch-editor.md) — **продуктовий напрям погоджено 24.10.2026**, уточнення §17. Реалізація — окремі перевірені Draft PR. Фактичні значення T1.5, магазинні фасування та UX-пороги ще не погоджені; готовність T3.1–T3.5 наведено нижче, T1.5 лишається відкритим; DEC-3B формалізовано в [forward-контракті](recipe-batches.md).

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T3.1 | [PR A: forward-схема A/B/C, guards, decimal evaluator та synthetic PG/Unit тести](recipe-batches.md). PR B1 додає базовий Filament editor через query adapter; [PR D: моделі й ресурси каталогів, атомарний food+ingredient writer, decimal/CAS/ACL](food-catalog.md), без зміни схеми. | Виконано |
| T3.2 | [PR B1: атомарні Save/Refresh/Publish, locks/CAS, accepted diff і signed preview](recipe-batches.md). B2 додає stable equipment ID, manual/review, ceil count, nullable параметри та обов’язковий adequacy ack. Транзакційний сервіс публікації рецепта: створення `foods`, перевірка інгредієнтів, UOM і грамування, розрахунок КБЖВ на 1 порцію, `version_number`, `published_at`, блокування рядка версії (BR-F-09, BR-F-14). PR D перевіряє зміни каталогів між preview і Save/Publish без зміни frozen history. | Виконано |
| T3.3 | [PR B1: одна Filament сторінка A/B/C, pending/reload, manual/reset/review, ingredient tokens, компоненти та auto equipment](recipe-batches.md). Chromium 1440/390/320 light/dark, дві вкладки та stale source. B2 додає manual equipment, explicit presets B, equipment tokens, наслідки видалення й catalog empty states. C1 додає створення нової версії та редагування штатним A/B/C editor. C2 додає персональні копії з явним owner/confirm. C3 додає окремі legacy preview/confirm/cancel та redirect до штатного editor. PR D додає українські catalog forms, пошук/фільтри/пагінацію, empty states і ручні conversions/default. Timer token — DEC-3T. | Частково |
| T3.4 | [C1: новий draft із published A/B/C](recipe-batches.md#нова-версія-abc-pr-c1), frozen clone/ID remap, повторний review, одна чернетка/CAS та незмінність старих `food_id`/призначень (BR-F-16, BR-X-01). C3: явна атомарна legacy draft conversion та окрема нова версія для published legacy, forward SQL guard, snapshots/hashes/rollback/concurrency/browser докази; змерджено #51. | Виконано |
| T3.5 | [C2: персональна CoW-копія published A/B/C](recipe-batches.md#персональна-копія-abc-c2): новий recipe/owner/parent/draft, спільний C1 clone, lineage/ACL, Filament confirm, PG/concurrency/Chromium; feature flag OFF. | Виконано |

**Stage 3 audit:** [PR D #53 merged](https://github.com/ValtonUA/telmanfit/pull/53), ядро acceptance Issue #37 підтверджене [completion audit / Stage 4 gate #54](https://github.com/ValtonUA/telmanfit/issues/54). T3.3 лишається частково через DEC-3T; T1.5 та production activation не завершені. G4-I Model I реалізовано окремо; T4.3 writer використовує цей gate; UX-4E реалізовано поверх writer; T4.3R повторення тижнів реалізовано; T4.4 публікація реалізована.

### Stage 4 — Раціони та призначення

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T4.1 | [Сім Eloquent-моделей та Filament foundation](meal-plans.md): admin list/create/view, атомарний plan+draft, exact-version read-only hierarchy; без нижніх writers. | Виконано |
| T4.2 | [Генерація послідовних 7-денних тижнів, вибір чинних типів, редагування прийомів і видалення будь-якого повного тижня чернетки](meal-plans.md); exact draft, locks/retry/stale guards (BR-P-01, BR-P-03). | Виконано |
| T4.3 | [Командний writer позицій](meal-plans.md#writer-позицій-t43): ingredient/UOM/grams, legacy, explicit A/1 B/7 C/3 C/4, CAS/locks/ACL та мінімальні Filament actions. UX-4E — окремий day/week UI нижче; повторення тижнів — T4.3R нижче. | Виконано |
| UX-4E | [Єдиний week/day editor](meal-plans.md#редактор-тижня-й-дня-ux-4e): 7 колонок, mobile день, компактні photo cards/menus, create-only початок тижня (SQL15), каталог, явні порції, DnD/keyboard fallback, атомарне сортування через writer. | Виконано |
| T4.3R | [Повторення тижня у draft](meal-plans.md#повторення-тижнів-у-чернетці-t43r): незалежні deep copies 7 днів/прийомів/позицій, exact Model I, явне підтвердження всіх наявних цільових тижнів (навіть 0 позицій), snapshot/retry/locks/rollback; без Publish/assignment. | Виконано |
| T4.4 | [Публікація exact draft](meal-plans.md#публікація-точної-версії-t44): повнота/грамування, Model I, atomic locks, stale/duplicate/immutable history, Filament, PG/Livewire/concurrency/Chromium (BR-P-01…07). | Виконано |
| T4.5 | Призначення версії клієнту: атомарна заміна дат, `replaced_by_assignment_id`, подія в `client_events` (BR-P-08, BR-P-11). | Заплановано |
| T4.6 | Персональна копія плану (Copy-on-Write). | Заплановано |

### Stage 5 — Клієнтський кабінет

**Єдиний продуктовий/UX-контракт екранів:** [client-app.md](client-app.md). Тут фіксуємо **лише** задачі та статуси, без дублювання UI-специфікації. Моделі Stage 3 A/B/C не є ярликами унікальних днів меню; деталі та відкриті залежності — у предметному контракті.

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T5.1 | Клієнт API: автентифікація, профіль, читання активного призначення (лише опублікована версія). | Заплановано |
| T5.2 | Екран раціону/днів/прийомів/порцій з прив'язкою до чинного призначення та коректними переходами на рецепт (BR-P-14). | Заплановано |
| T5.3 | Генерація списку покупок у Laravel без нових shopping-таблиць (BR-S-01…BR-S-03). | Заплановано |
| T5.4 | Екран рецепта: склад, кроки, обладнання; необов'язковий показ КБЖВ на рівні рецепта. | Заплановано |
| T5.5 | Чотири вкладки й клієнтський API-шар SPA за [UX-контрактом](client-app.md); рішення про router/API-клієнт — окреме погодження. | Заплановано |
| T5.6 | «Корисне»: каталог публікацій у Filament та клієнтський перегляд; модель даних — окреме предметне рішення. | Заплановано |
| T5.7 | «Приготування та покупки»: вибір режиму, кухонні сесії, вручну створені ілюстровані мегарецепти і звірка порцій; data contract cooking groups/версій — **до** реалізації. | Заплановано |
| T5.8 | Компактна картка запланованого онлайн-тренування на «Сьогодні» (час, тривалість, URL, необхідне обладнання) без повного модуля виконання; джерело розкладу — окреме рішення. | Заплановано |

Кроки, FatSecret/вільні калорії й графіки прогресу — **відкладені залежності**, а не реалізовані функції Stage 5. Для тижневого звіту використовувати правила Stage 6; не додавати нову «щоденну» звітність.

### Stage 6 — Прогрес та звіти

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T6.1 | Моделі `Metric`, `ClientMetricAssignment`, `ClientMetric`, `ClientWeeklyReport`, `ClientProgressPhoto` + Filament-ресурси для довідника метрик і перегляду прогресу. | Заплановано |
| T6.2 | Внесення вимірів клієнтом через API (незалежно від звітів) і валідація значень щодо `metrics` (BR-T-02). | Заплановано |
| T6.3 | Тижневий звіт: межі понеділок–неділя в таймзоні клієнта, чернетка → `submitted`, перенесення числових відповідей у `client_metrics` (BR-T-05…BR-T-11, BR-T-15). | Заплановано |
| T6.4 | Завантаження фото прогресу в приватне сховище + контроль доступу (BR-T-13, BR-T-14). | Заплановано |
| T6.5 | Візуалізація динаміки метрик у SPA та перегляд історії у Filament. | Заплановано |

### Stage 7 — Комунікація та автоматизація

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T7.1 | Моделі `MessageTemplate`, `OutboundMessage`, `ClientEvent` + Filament-ресурси шаблонів і журналу. | Заплановано |
| T7.2 | Рендеринг шаблону з токенами й фіксація `rendered_text` / `rendered_markup` на момент постановки в чергу (BR-M-02, BR-M-04). | Заплановано |
| T7.3 | Планувальник нагадувань (щоденні/щотижневі) з урахуванням таймзони й активного супроводу (BR-M-08). | Заплановано |
| T7.4 | Життєвий цикл статусів `pending → sending → sent \\|failed \\|cancelled` як сервісний контракт (BR-M-03, BR-M-05). | Заплановано |
| T7.5 | Канал-заглушка («dry run»), який логує доставку замість зовнішнього API, щоб домен не залежав від Telegram. | Заплановано |

### Stage 8 — Telegram

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T8.1 | Telegram-автентифікація з перевіркою `initData`, підтверджена прив’язка `users.telegram_user_id` з PWA та зворотне підключення до того самого акаунта (BR-U-11, BR-U-17). Ще не реалізовано; частина доступу клієнта залежить від T2.5, не від готовності каналу відправки. | Заплановано |
| T8.2 | Реальний канал відправки поверх Stage 7 (`provider_message_id`, `last_error`, `attempt_count`). | Заплановано |
| T8.3 | Підтвердження дій клієнта кнопками з `rendered_markup` (ідемпотентна обробка). | Заплановано |
| T8.4 | Telegram Mini App і PWA максимально на одному React-застосунку; Mini App може мати обмежений набір можливостей. Дані спільні через Laravel API/PostgreSQL; підтримуються одна або обидві оболонки (затверджено 21.10.2026). | Заплановано |

### CI — витрати й merge gate

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| CI-COST-1 | [Два рівні CI](ci.md): Fast на кожному commit, явний Full на фінальному SHA, fail-closed pre-merge та вимірювання GitHub jobs; інтегровано через PR #42; активний strict ruleset `CI-COST-1` із трьома required checks підтверджено read-only preflight. | Виконано |
| CI-COST-2 | [Issue #68](https://github.com/ValtonUA/telmanfit/issues/68): вимірювання Stage1, по дві ізольовані PG/Chromium групи всередині одного job, bytecode reuse і navigation/render readiness без зміни сценаріїв/ізоляції; [before/after evidence](evidence/ci-cost-2/report.md). Фінальний Full і фактичний відсоток — в описі Draft PR, без merge. | Частково |

### Stage 9 — Підготовка до запуску

| ID | Завдання / підтвердження | Статус |
|---|---|---|
| T9.1 | Перевірка можливостей хостингу: `btree_gist`, cron, worker черг, приватне сховище (блокери [schema-audit.md](schema-audit.md), розділ 5.1). | Заплановано |
| T9.2 | Резервне копіювання БД і план відкату міграцій. | Заплановано |
| T9.3 | Перевірка обов’язкової admin MFA, recovery та session revocation перед запуском. Admin primary email+password — пропозиція P4, без парольної залежності PWA; саму MFA реалізувати раніше в AUTH-3. | Заплановано |
| T9.4 | Регресійний прогін усіх тестів (backend + схема на PostgreSQL + SPA) і перевірка збірки `public/app`. | Заплановано |
| T9.5 | Чек-ліст безпеки: приватність фото, маскування подій, обмеження доступу до API. | Заплановано |

**Під час Stage 9:** Hyperhost VPS Старт (2 CPU/3 ГБ/45 ГБ), SendPulse SMTP та зовнішні копії Hyperhost обрані, але **не налаштовані**. Допустимий простій і до **24 годин втрати останніх змін** — погоджена ціль RPO, не підтверджений фактичний результат. D8.1 preflight merged [PR #24](https://github.com/ValtonUA/telmanfit/pull/24); D8.2 тільки [Draft PR #25](https://github.com/ValtonUA/telmanfit/pull/25), без сервера. До production потрібні PG-aware encrypted external backup, restore drill, незалежне last-admin recovery після B5 restore та B7 go/no-go. D3 recovery через друзів відкладено (Draft [PR #22](https://github.com/ValtonUA/telmanfit/pull/22)).

## AUTH: реалізоване й незавершене

| ID | Зміст | Статус | Межа |
|---|---|---|---|
| AUTH-1 | Ізольована перевірка сумісності | Виконано | Spike, не production |
| AUTH-2 | Auth storage/schema | Виконано | PG18 tests, rollout окремо |
| AUTH-3 | Filament admin MFA, recovery codes | Частково | Код/тести є, controlled OFF |
| AUTH-4 | Invitation, registration, email OTP, client sessions | Частково | Код/тести є, controlled OFF |
| AUTH-5 | React auth/security, optional MFA, sessions | Частково | Код/тести є, controlled OFF |
| AUTH-6B B1–B6 | Technical recovery/outbox/retention/budgets kernels | Частково | Guarded/synthetic; реальні adapters/production OFF |
| AUTH-6B B7 | Release rehearsal, security/restore go-no-go | Заплановано | Перед production |
| AUTH-7 | Telegram login/linking | Заплановано | Пізніше |
| AUTH-8 | Passkeys | Заплановано | Окреме рішення |

**Важливо:** двоє друзів (D3) — не актуальний спосіб запуску. Після B5 disaster restore звичайний код із 1Password може бути відкликаний; до production необхідно окремо затвердити й протестувати відновлення останнього admin. Не створювати bypass MFA і не вимикати `4040404` без дозволу.

## Питання — тільки за потребою

| ID | Питання | Статус | Коли потрібно |
|---|---|---|---|
| DEC-3A | Значення довідників T1.5 (зокрема `uoms`, категорії) | Очікує рішення | Перед відповідною частиною Stage 3 |
| DEC-3T | Джерело duration, ідентичність timer та формат часу для timer token; у чинних таблицях є лише текст кроку | Очікує рішення | Перед timer tokens; решту B2 не блокує, час/температура не масштабуються |
| DEC-3B | Явний refresh із diff, GET read-only; accepted snapshot для Save/Publish, manual quantity/UOM зберігаються; §17 і Issue #37 | Виконано | Базові команди PR B1; решта editor slice за T3.2–T3.3 |
| DEC-2A | Для першого екрана затверджено гнучкий JSON без попереднього повного контракту; решта запитань переноситься поступово з Google-форми. | Частково | Розширювати разом із UI, без блокування інших модулів |
| UX-4D | [Погоджено #60](https://github.com/ValtonUA/telmanfit/issues/60): видалення будь-якого повного тижня draft; 0 items — відразу, ≥1 — confirmation; stable anchor та перенумерація без зміни ID/вмісту наступних тижнів. Окремі дні не переставляються/не видаляються. | Виконано | Доповнення T4.2 у PR #59; повторення тижнів (#61) реалізовано окремим T4.3R після T4.3, перед T4.4 |
| DEC-4A | Дострокове завершення/заміна призначення та `replaced_by_assignment_id` | Очікує рішення | Перед Stage 4 assignment workflow |
| DEC-9A | Безпечне повторне MFA enrollment останнього admin після B5 restore | Очікує рішення | Перед Stage 9 / production |

Інші питання з [schema-audit.md](schema-audit.md), §7, вирішувати **для відповідної функції**, не відкриваючи заново закриті G1/G3/G4/G5. Детальні бізнес-правила — [business-rules.md](business-rules.md), архітектура — [architecture.md](architecture.md).

## Джерела й історія

- [project-status.md](project-status.md) — поточний стан і датовані історичні звіти.
- [stage-1.md](stage-1.md), [stage-2.md](stage-2.md) — факти та тести.
- [auth3.md](auth3.md), [auth4.md](auth4.md), [auth5.md](auth5.md) — реалізація auth.
- [auth6-readiness.md](auth6-readiness.md), [B3](auth6-b3.md), [B5](auth6-b5.md), [B6](auth6-b6.md), [D8](auth6-d8-infrastructure.md), [D8.1](auth6-d8-preflight.md) — технічні специфікації та докази.
- [entity-catalog.md](entity-catalog.md), [database.dbml](database.dbml), `01–04_*.sql` — схема. Пріоритет джерел SQL → DBML → Entity Catalog.

