# Зображення каталогу з Cloudflare R2

Команда `php artisan assets:match-r2-images --dry-run` читає об’єкти S3/R2 і назви, ID та `image_url` записів PostgreSQL. Вона використовує точну нормалізацію назви та необов’язкові alias-списки за типом і ID запису. Fuzzy-кандидати подаються лише як сумнівні пропозиції. `--apply` записує тільки унікальні точні збіги в порожнє `image_url`; неоднозначності, дублікати й наявні URL пропускаються. Повторний запуск не перезаписує URL.

R2 URL для БД має будуватися з переданого `--public-base-url`, який повинен проходити чинний `PUBLIC_IMAGE_HOSTS` allowlist. Це зберігає архітектуру публічних HTTPS URL в `image_url`; API endpoint R2 не вважається публічним URL зображення.

```sh
php artisan assets:match-r2-images --dry-run
php artisan assets:match-r2-images --apply --public-base-url=https://images.example.com --aliases-file=/path/to/r2-image-aliases.json
```

## Налаштування й контрольовані помилки

`AppServiceProvider` реєструє `R2ObjectSource → R2ObjectLister` без запиту до мережі під час boot.
Lister використовує підписані SigV4 GET `ListObjectsV2` (`auto/s3`), до 1000 ключів
на сторінку й opaque `NextContinuationToken`. Це наявний адаптер
[офіційного S3 API R2](https://developers.cloudflare.com/r2/api/s3/api/), без нових залежностей.
Повторений або відсутній token, некоректний XML, HTTP-помилка та connection timeout
дають контрольований exit code 1. HTTP redirects вимкнені; connect timeout 10 с,
request timeout 30 с. Transport exception, response body та credentials не друкуються.
Потрібні всі чотири значення: `R2_ENDPOINT`, `R2_BUCKET`, `R2_ACCESS_KEY_ID`,
`R2_SECRET_ACCESS_KEY`; без них команда повертає підказку до звернення до БД.

## Windows Docker Desktop: dry-run на наявній локальній БД

Відкрийте PowerShell у checkout з цим виправленням. Docker Desktop має працювати,
наявний проєкт `telmanfit-local` — бути healthy. Команди нижче не запускають
Compose setup/bootstrap, міграції чи перебудову робочих контейнерів.

Створіть приватний файл поза Git, наприклад `C:\private\telmanfit-r2-readonly.env`,
і заповніть його локально. Значення нижче — placeholders:

```dotenv
R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
R2_BUCKET=<BUCKET_NAME>
R2_ACCESS_KEY_ID=<READ_ONLY_ACCESS_KEY_ID>
R2_SECRET_ACCESS_KEY=<READ_ONLY_SECRET_ACCESS_KEY>
PUBLIC_IMAGE_HOSTS=<EXACT_PUBLIC_IMAGE_HOST>
```

Використайте R2 credentials лише для читання потрібного bucket; не передавайте їх
у чат або Git. `PUBLIC_IMAGE_HOSTS` — точний публічний DNS host без схеми/шляху;
S3 endpoint не є публічним image URL. Dry-run не вимагає цього allowlist,
але перед майбутнім apply його й публічний HTTPS URL треба перевірити окремо.

Наведений запуск використовує залежності наявного app image та два виправлені
PHP-файли з checkout, змонтовані read-only. Наявні volumes підключаються
read-only, конфігурація читається через штатний loader. Секрети діють лише
в одноразовому процесі. Стандартні імена контейнера/мережі перевірте:

```powershell
docker ps --filter label=com.docker.compose.project=telmanfit-local
docker network inspect telmanfit-local_local --format '{{.Name}}'
$repo = (Get-Location).Path
$dryRunPhp = @'
require 'docker/local/load-env.php';
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if (! $app->environment('local')
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== 'database'
    || ! str_starts_with((string) config('database.connections.pgsql.database'), 'telmanfit_local_')) {
    fwrite(STDERR, "Unexpected database/environment; stopped.\n");
    exit(2);
}
Illuminate\Support\Facades\DB::statement('SET default_transaction_read_only = on');
exit($kernel->handle(
    new Symfony\Component\Console\Input\ArrayInput([
        'command' => 'assets:match-r2-images',
        '--dry-run' => true,
    ]),
    new Symfony\Component\Console\Output\ConsoleOutput()
));
'@
docker run --rm --read-only --network telmanfit-local_local `
  --volumes-from telmanfit-local-app-1:ro `
  --mount "type=bind,source=$repo\app\Providers\AppServiceProvider.php,target=/var/www/html/app/Providers/AppServiceProvider.php,readonly" `
  --mount "type=bind,source=$repo\app\Services\Catalog\R2ObjectLister.php,target=/var/www/html/app/Services/Catalog/R2ObjectLister.php,readonly" `
  --env-file 'C:\private\telmanfit-r2-readonly.env' `
  -e 'PGOPTIONS=-c default_transaction_read_only=on' `
  --entrypoint php telmanfit-local-app -r $dryRunPhp
```

Цей варіант розрахований на app image, який уже містить `MatchR2Images`,
`R2ObjectSource` та `R2ObjectLister` з тією самою архітектурою.
Якщо перевірка без credentials потрібна першою, пропустіть `--env-file`:
очікується exit 1 з переліком потрібних R2 змінних, без BindingResolutionException.
Для alias-файлу потрібні реальні ID БД та окремий read-only bind mount;
історичні номери й синтетичні fixtures не є відповідностями робочого каталогу.

## Формат aliases

Формат alias-файлу: JSON object з ключами таблиць та числових ID, значенням є масив назв, синонімів або історичних кодів:

```json
{
  "ingredients": {"17": ["black pepper", "ING-OLD-017"]},
  "kitchen_equipment": {"4": ["kitchen scales", "EQ-OLD-04"]},
  "recipes": {"9": ["buckwheat with mushrooms", "REC-OLD-09"]}
}
```

## Перевірка поточного bucket

Read-only `ListObjectsV2` виконано 26.10.2026. Отримано **132 об’єкти**, що не збігається з попереднім підрахунком у 95:

| Префікс | Об’єктів |
|---|---:|
| `ingredients/` | 52 |
| `kitchen_equipment/` | 37 |
| `recipes/` | 6 |
| `equipment/` | 37 |

Розклад: 52 `ingredients/`, 37 `kitchen_equipment/`, 6 `recipes/` і 37 додаткових `equipment/`. Вміст не завантажувався, об’єкти не змінювалися.

**Зіставлення з PostgreSQL не виконано:** у середовищі немає конфігурації PostgreSQL (`DB_*` unset, `.env` відсутній), а Docker daemon недоступний для запуску disposable PostgreSQL. Тому ID/назви записів, упевнені збіги, неоднозначності та відсутні відповідності нижче позначені як «не перевірено», а не вигадані. Локальний каталог не використано як заміну робочим даним.

## Повний перелік R2-об’єктів

| R2 key | PostgreSQL ID / запис | Статус зіставлення |
|---|---|---|
| `equipment/air_fryer.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/baking_dish.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/baking_tray.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/blender.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/can_opener.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/chef_knife.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/colander.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/contact_grill.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/cutting_board.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/foil.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/food_container.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/food_vessel.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/frying_pan.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/heavy_duty_foil.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/immersion_blender.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/jewelry_scales.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/kitchen_scale.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/kitchen_scissors.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/microwave.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/mixing_bowl.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/multicooker.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/oven.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/oven_rack.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/paper_towels.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/parchment.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/pot.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/roasting_sleeve.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/saucepan.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/small_bowl.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/sous_vide_circulator.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/spatula.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/steamer_insert.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/stovetop.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/tongs.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/vegetable_peeler.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/whisk.webp` | Не перевірено | PostgreSQL недоступна |
| `equipment/zip_bag_15x30.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/almonds.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/beef_top_round.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/bell_pepper.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/black_pepper.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/broccoli.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/buckwheat.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/butter_82_percent.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/carrot.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/cheese.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/chicken_egg.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/chicken_fillet.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/chicken_liver.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/chicken_thigh_skinless_fillet.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/corn_canned.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/cottage_cheese_0_percent.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/cottage_cheese_5_percent.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/cucumber.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/dijon_mustard.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/durum_pasta.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/eggplant.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/garlic.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/green_peas_canned.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/honey.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/lavash.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/lemon.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/lime_juice.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/milk_1_percent.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/milk_2_5_percent.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/mushrooms.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/mustard.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/oats.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/olive_oil.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/onion.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/paprika.jpg` | Не перевірено | PostgreSQL недоступна |
| `ingredients/pork_tenderloin.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/red_lentils.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/rice.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/rice_basmati.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/salmon.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/salt.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/soy_sauce.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/sunflower_oil.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/tilapia_fillet.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/tomato.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/tuna_canned_water.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/turkey_fillet.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/veggie_mix_mexican.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/veggie_mix_spring.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/water.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/whey_protein.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/yogurt_2_5_percent.webp` | Не перевірено | PostgreSQL недоступна |
| `ingredients/zucchini.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/air_fryer.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/baking_dish.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/baking_tray.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/blender.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/can_opener.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/chef_knife.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/colander.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/contact_grill.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/cutting_board.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/foil.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/food_container.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/food_vessel.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/frying_pan.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/heavy_duty_foil.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/immersion_blender.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/jewelry_scales.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/kitchen_scale.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/kitchen_scissors.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/microwave.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/mixing_bowl.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/multicooker.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/oven.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/oven_rack.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/paper_towels.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/parchment.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/pot.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/roasting_sleeve.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/saucepan.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/small_bowl.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/sous_vide_circulator.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/spatula.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/steamer_insert.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/stovetop.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/tongs.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/vegetable_peeler.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/whisk.webp` | Не перевірено | PostgreSQL недоступна |
| `kitchen_equipment/zip_bag_15x30.webp` | Не перевірено | PostgreSQL недоступна |
| `recipes/buckwheat_mushrooms.webp` | Не перевірено | PostgreSQL недоступна |
| `recipes/chicken_fillet_lemon.webp` | Не перевірено | PostgreSQL недоступна |
| `recipes/pork_tenderloin_spices.webp` | Не перевірено | PostgreSQL недоступна |
| `recipes/rice_peas_corn.webp` | Не перевірено | PostgreSQL недоступна |
| `recipes/tilapia_soy_sauce.webp` | Не перевірено | PostgreSQL недоступна |
| `recipes/vegetable_stew.webp` | Не перевірено | PostgreSQL недоступна |

### Підсумок dry-run

- Упевнені збіги: не визначено.
- Сумнівні пропозиції: не визначено.
- Файли без відповідностей: не класифіковано.
- Причина: без читання актуальних каталогів PostgreSQL будь-який ID чи назва були б припущенням.
- Для ізольованої перевірки додано PostgreSQL-тест команди; його запуск очікує disposable PG18 runner/CI.
