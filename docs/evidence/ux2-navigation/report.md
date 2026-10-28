# UX-2 — перевірки та предметний review

[Контракт](../../admin-navigation.md). Реалізація використовує штатний Filament SPA; guards і схему не змінено. Цей локальний звіт не підмінює required GitHub CI на фінальному SHA та безпосередній SHA-locked merge gate.

## Результати

- Unit/Feature: **126 / 522 assertions**, PASS; AUTH3 на disposable PG18: **25 / 129 assertions**, PASS.
- Recipe/catalog/meal-plan/cooking PG/Livewire: **231 / 3821 assertions**, PASS; відповідні concurrency runners, зокрема 37 cooking scenarios, PASS.
- `python3 -m unittest discover -s tests/ci -p 'test_*.py'`: 40 тестів, PASS; Pint, docs guard, Python/JS syntax і diff checks — PASS.
- `ADMIN_NAV_ONLY=1 bash tests/pg/run-stage1.sh`: нова ізольована PostgreSQL 18, без мережі/портів, synthetic data, cleanup; реальні password/TOTP/recovery, CSRF, Filament і Livewire — PASS. [Browser checks](checks.json), скриншоти `day-1440/390/320-light/dark.png` у цьому каталозі.
- Звичайні sidebar/catalog/client/invitation/recipe/week/day переходи зберігають документ; theme, modal cancel і drag handles працюють після кількох переходів. Recipe save перевірений фактичною БД, create — рівно однією новою версією та SPA redirect. Back/Forward/refresh і cached Back після idle expiry перевірені серверними відповідями; revoked auth_version блокує SPA GET. Native logout та recovery login виконані після SPA.

## Незалежний review-прохід

Перевірені всі власні anchors, selector версії, redirects, Alpine lifetime та MFA/CSRF middleware. Усунені проблеми:

1. Native href/`window.location`/explicit `navigate:false` залишали повні завантаження — переведено на штатну Livewire navigation для сумісних panel URL.
2. Cached Livewire history та BFCache не є новою серверною перевіркою — одноразовий guard виконує native reload, зберігаючи history pointer/Forward, і приховує DOM до відповіді; admin responses no-store/private. Auth/React/JSON мають точкові exceptions.
3. Asset URL не можна створювати під час ранньої panel registration до request binding — використано lazy render hook та hash asset URL з data-navigate-once/track.
4. Test actions до Alpine initialization та offscreen mobile sidebar — очікування реального `livewire:navigated` і відкриття sidebar через видиму кнопку; deprecated button selector logout замінено на semantic menuitem.
5. Новий локальний test selector очищується на вході чинного CI worker, щоб inherited environment не звузило required coverage. Чинний workflow доповнено лише шляхами browser evidence, без нових jobs/координаторів.
6. Required Targeted виявив застаріле очікування повного navigation у тесті публікації з редактора дня: URL ще не встигав перейти до огляду. Тест очікує кінцеву адресу та статус «Опублікована» після Livewire response, а потім перевіряє reload/readonly. Перевірки публікації не послаблено.

## Межі

Cached history навмисно виконує нове native завантаження: це точкова security exception, тоді як звичайні переходи між ресурсами — SPA. Physical mobile і production не перевірялись; flags та production data не використовувались. Схема, nutrition/publication rules і middleware authorization незмінні.
