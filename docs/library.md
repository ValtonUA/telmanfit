# T5.6 — бібліотека «Корисне» (реалізовано в `master`)

## Мета та статус

Єдина бібліотека для 5–10 клієнтів. Реалізовано в [PR #92](https://github.com/ValtonUA/telmanfit/pull/92), squash-commit `e207d88e7bde8c7b9616fc2535a5d6f15c6e41be`. Чинні Filament 5, AUTH-4/5 ClientSession, SPA та PostgreSQL 18 використано без нових зовнішніх API чи залежностей. **Код змерджено, але production-активацію та застосування міграції до робочої БД не виконано.**

## Модель і права

Одна нова таблиця `library_items`, forward-only міграція `2026_10_27_000200_create_library_items.php`; authoritative DDL `database/sql/library/01_library_items.sql`, DBML і Entity Catalog синхронізовані. Усі поля: id BIGINT PK identity, type (`article`, `video`, `playlist`, `instruction`), title, nullable summary, cover_image_url, content_markdown, external_url, status (`draft`, `published`), nullable published_at, created_at, updated_at (timestamptz). Не додаємо окремих таблиць типів, категорій, грантів, медіафайлів чи історії.

Лише admin через Filament Resource `/admin` може створювати, редагувати, публікувати, повертати у draft. `coach` та `client` не входять у панель. Видалення через UI вимкнене. Опублікований матеріал можна редагувати: на відміну від рецептів, це **не immutable published version**.

Валідатор моделі + SQL CHECK: title непорожній; `published_at` лише у published; відео/плейліст вимагають `external_url`; стаття/інструкція вимагає Markdown або external_url. Draft може бути неповним. Зняття з публікації очищує published_at. Публікація не призначає матеріал клієнту індивідуально. Всі активні сесії клієнтів читають один список.

## Безпека та рендеринг

Обкладинка та inline-зображення Markdown проходять `PublicImageUrl::safe`, тільки exact HTTPS hosts із `PUBLIC_IMAGE_HOSTS`, і CSP. Зовнішнє посилання валідовується `ExternalContentUrl`: HTTPS до публічного DNS-хоста; без userinfo/IP/локального DNS/порту чи fragment. Сервер **не завантажує URL**.

Markdown конвертується через уже встановлений league/commonmark з `html_input=strip`, `allow_unsafe_links=false`; DOM-постобробка видаляє недозволені img/src та a/href, зовнішні посилання отримують noreferrer/noopener. React показує лише HTML, згенерований цим серверним санітайзером. Відсутні/broken images мають placeholder. Немає довільного HTML із форми.

## API і екрани

- `GET /api/library` — JSON `items: [{id,type,title,summary,cover_image_url,published_at}]`, сортування latest first; лише status=published і published_at <= PostgreSQL clock_timestamp() (фактичний час, а не час початку транзакції).
- `GET /api/library/{id}` — `item` з card-даними, `content_html` (для article/instruction), `external_url` (перевірений). 404 для неопублікованих/некоректних/відсутніх. Ніяких SQL/stacktrace у відповідях.
- `ClientAuthBoundary` і `SerializedAuthSession` розширені на обидва шляхи. Тільки чинний ClientSession, JSON, `no-store, private`, GET/HEAD без DML/session save/GC. Auth revoked/expired повертає 401 і прибирає попередній вміст на React.
- React `/app/resources` — реальні published картки, loading/empty/error/retry; `/app/resources/{id}` — Markdown або кнопка зовнішнього посилання, прямий URL/reload, назад до списку. Focus/visibility/60s refresh прибирає вміст після unpublish або втрати доступу.
- «Сьогодні», раціон/рецепти, AUTH, Telegram, профілі, production flags не змінюються.

## Приймання й докази

Ізольовані PostgreSQL 18 та Auth/Unit тести охоплюють SQL CHECK, приховування чернеток, відкликання сесій, GET/HEAD без DML, санітизацію Markdown і захист URL, Filament ACL і цикл публікації. Браузерний набір перевіряє клієнтські сторінки й прямі URL, responsive 1440/390/320 light/dark. GitHub **Fast PR / Documentation health / Targeted PR regressions — PASS** на фінальному SHA `adb9c9430f5fe665ddf066702b99ecfed598064d` перед злиттям. Реальні середовища не тестувалися і не мігрувалися; цей документ не є дозволом застосовувати міграцію до робочої БД.
