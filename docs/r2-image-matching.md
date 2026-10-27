# Зображення каталогів із Cloudflare R2

`assets:match-r2-images` читає R2 через підписаний GET ListObjectsV2 і записи
`ingredients`, `kitchen_equipment`, `recipes`. Credentials: R2_ENDPOINT,
R2_BUCKET, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY; лише приватний env-file поза Git.
Lister перевіряє HTTPS endpoint, пагінацію/XML, не переходить за redirects і не
виводить transport exception, response body або credentials.

## Канонічні ключі та зіставлення

Підтримані папки: `ingredients/`, `kitchen_equipment/`, `recipes/`.
`equipment/` завжди має статус `ignored` із поясненням: канонічний префікс —
`kitchen_equipment/`. Legacy-об’єкти залишаються у R2, не застосовуються навіть
за відсутності канонічного файлу й не створюють конфлікту з ним.
Два різні канонічні об’єкти для одного запису залишаються `ambiguous`.

Без `--exact-only` fuzzy-кандидати залишаються сумнівними пропозиціями.
`--exact-only` використовує лише точну нормалізовану назву або aliases:
fuzzy-only файли мають `unmatched`, а кілька точних кандидатів — `ambiguous`.
Цей режим потрібен для вручну погодженого списку; переклад не виконується автоматично.

```sh
php artisan assets:match-r2-images --dry-run --exact-only --aliases-file=/audit/aliases.json
```

Формат aliases — object `table → real ID → list of names/basenames`:

```json
{"ingredients":{"17":["black pepper"]},"kitchen_equipment":{"4":["baking tray"]}}
```

[Погоджений локальний список](assets/r2-local-image-aliases.json) містить
85 реальних ID конкретної локальної БД. Не переносити ці ID до іншої БД без
повторної перевірки назв та image_url. [План](r2-image-application-plan.md)
містить усі ID, назви, canonical keys, очікувані URL і HEAD-перевірки.

## Запис і публічний URL

Потрібен рівно один режим: `--dry-run` або `--apply`. Застосування потребує
окремого дозволу й `--public-base-url` з HTTPS host у PUBLIC_IMAGE_HOSTS.
Endpoint R2 не є публічним image host. PublicImageUrl::safe перевіряє кожен
згенерований URL до транзакції запису. Значення image_url не перезаписуються:
запис відбувається лише через `WHERE image_url IS NULL`; збіг із наявним URL
має UNCHANGED, інший URL — CONFLICT. Повторне застосування не створює нових змін.

## Read-only перевірка Windows / Docker Desktop

Наявні telmanfit-local app/database мають бути healthy. Не запускати setup,
bootstrap, міграції або `compose up --build` для цієї перевірки.

```powershell
$repo = (Get-Location).Path
$private = 'C:\Projects\private\telmanfit-r2-readonly.env'
Get-Content -Raw -Encoding UTF8 scripts/assets/r2-local-dry-run.php | docker run --rm -i --read-only --network telmanfit-local_local --volumes-from telmanfit-local-app-1:ro --mount "type=bind,source=$repo\app\Services\Catalog\R2ImageMatcher.php,target=/var/www/html/app/Services/Catalog/R2ImageMatcher.php,readonly" --mount "type=bind,source=$repo\app\Console\Commands\MatchR2Images.php,target=/var/www/html/app/Console/Commands/MatchR2Images.php,readonly" --mount "type=bind,source=$repo\docs\assets\r2-local-image-aliases.json,target=/audit/aliases.json,readonly" --env-file $private -e 'PGOPTIONS=-c default_transaction_read_only=on' --entrypoint php telmanfit-local-app > r2-local-dry-run.json
if ($LASTEXITCODE -ne 0) { throw 'Dry-run failed' }
```

Runner перевіряє local/pgsql identity, host database, префікс імені локальної БД,
allowlist, 85 порожніх цілей, відсутність ambiguous та незмінність каталогу.
PostgreSQL REPEATABLE READ READ ONLY завершується ROLLBACK. Код команди/matcher-а
та aliases монтуються read-only; робочі контейнери не змінюються.

Приватний файл має PUBLIC_IMAGE_HOSTS=assets.telmanfit.com.ua.
Compose передає тільки PUBLIC_IMAGE_HOSTS до app/worker/scheduler через
environment. Зміна файлу не змінює вже запущені процеси. R2 credentials потрібні
лише одноразовій команді; runtime API віддає публічні URL без R2 credentials.
