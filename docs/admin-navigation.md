# SPA-навігація адміністративної панелі (UX-2)

Панель `/admin` використовує штатний декларативний режим Filament 5 `->spa()` на Livewire 4 `wire:navigate`. Серверний рендеринг Blade та чинні Policy/MFA/CSRF gates зберігаються; React залишається лише в `/app`. Hover-prefetch не ввімкнено. [Офіційний контракт Filament 5](https://filamentphp.com/docs/5.x/panel-configuration#spa-mode).

## Переходи та життєвий цикл

Штатні sidebar/table/action links обробляє Filament. Власні week/day, cooking links, selector версії рецепта та внутрішні redirects використовують ту саму Livewire navigation. Новий DOM ініціалізується Alpine після `livewire:navigated`; локальні `x-data`/drag/modal handlers живуть у відповідному DOM та очищуються при заміні сторінки. Глобальний history guard підключено один раз через `data-navigate-once`; hash URL і `data-navigate-track` узгоджують його оновлення з повним завантаженням нового asset. URL asset створюється у відкладеному render hook, після появи HTTP request context.

Перед діями браузерні тести очікують саме `livewire:navigated` й релевантний commit/render. Зміна history URL або видимість HTML до завершення Alpine init не вважається готовністю редактора.

## Точкові native exceptions

- `/admin/login`, `/admin/auth/*` — restricted MFA/OTP, logout та одноразові auth responses мають свій життєвий цикл сесії й не є Filament SPA DOM.
- `/app*` — окремий клієнтський React root; `/api/*` — JSON, а не адміністративний HTML.
- **Back/Forward із cached Livewire snapshot** та browser BFCache — native reload на поточному history pointer. У pinned Livewire cached history не виконує новий серверний запит; відновлювати старі приватні дані без MFA/ACL перевірки не можна. Reload не додає history entry та зберігає Forward. Звичайні переходи між ресурсами залишаються SPA. Admin responses мають `Cache-Control: no-store, private`; при cached restore поточний DOM приховується до серверної відповіді.

Жоден endpoint, Policy, assurance fence, cookie context чи строк сесії не послаблено. Відкликання та idle expiry мають давати чинну серверну відмову і під час SPA GET, і під час відновлення history.

## Перевірки

`tests/browser/filament_navigation.py` працює зі справжніми MFA та Filament/Livewire у новій disposable PostgreSQL 18 із синтетичними даними. Перевіряються 1440/390/320 px light/dark, sidebar resources, recipe selector/preview/save/create, exact week/day, modal cancel, drag lifecycle, Back/Forward/refresh, expiry та auth-version revocation. Основний recipe browser runner виконує цей сценарій перед рештою mutation/security regression; локальний `ADMIN_NAV_ONLY=1 bash tests/pg/run-stage1.sh` дає цільовий прохід із AUTH3 тестами.

[Факти реалізації й результати](project-status.md). Production activation, зміна схеми та бізнес-правил до цього scope не входять.
