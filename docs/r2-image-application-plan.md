# План локального застосування зображень R2

**Статус: підготовлено до окремого дозволу; apply не виконано.**

Обсяг: лише наявна локальна PostgreSQL telmanfit-local. Production не використовується.
Канонічне обладнання — kitchen_equipment/; R2 не змінюється.

## Перевірений результат

85 точних відповідностей: 47 ingredients, 37 kitchen_equipment, 1 recipes.
Повний dry-run: 132 об’єкти, confident 85, ambiguous 0, unmatched 10,
ignored legacy equipment/ 37. Усі 108 поточних image_url = NULL;
до й після dry-run SHA256 каталогу однаковий. Режим PostgreSQL transaction_read_only=on.
Усі 85 URL https://assets.telmanfit.com.ua/... пройшли HEAD 200 з image/jpeg або
image/webp, додатною довжиною, перевіркою TLS і без redirects/credentials.
Нативний urllib-клієнт отримував 403; остаточні 85/85 HEAD виконано штатним curl.
Це перевірка доступності, не візуальна перевірка вмісту фото.

Погоджені додаткові aliases: ingredients#4 beef_top_round, #22 mushrooms,
#52 lavash, #40 yogurt_2_5_percent, #42 cheese, #28 rice;
kitchen_equipment#32 parchment. Попередні 78 відповідностей збережено.

## Передумови застосування

1. Окремий явний дозвіл користувача на локальний rollout конфігурації та apply.
   Draft PR не є дозволом на merge, rollout чи запис до БД.
2. Використати перевірений commit і aliases з цього PR. Звірити поточний master,
   три required CI checks і review перед будь-яким окремо дозволеним merge.
3. Передати PUBLIC_IMAGE_HOSTS=assets.telmanfit.com.ua до реальних app/worker/scheduler.
   На момент звіту існуючі сервіси ще мають порожній allowlist; він підтверджений
   тільки в одноразовому dry-run процесі. Не запускати штатний entrypoint,
   setup або bootstrap для rollout нижче.
4. Відтворити [read-only запуск](r2-image-matching.md) і порівняти ID, назви й
   image_url із manifest. Очікувати рівно 85/0/10/37. Будь-яка зміна цілей або
   інший наявний URL зупиняє застосування до нового рішення. Повторний apply
   приймає лише NULL або точний URL із manifest; pre-apply runner очікує NULL. Зберегти цей JSON
   як передопераційний експорт image_url. При зміні R2 повторити HEAD 85 URL.

## Rollout allowlist без bootstrap (план, не виконано)

Після окремого дозволу створити тимчасовий override `compose.r2-runtime.yaml`:

```yaml
x-runtime: &runtime
  entrypoint: [sh, -ec, 'set -a; . /tmp/telmanfit-auth4-compose/runtime.env; set +a; exec "$$@"', r2-runtime]
services:
  app:
    <<: *runtime
    command: [php-fpm]
  worker:
    <<: *runtime
    user: www-data
  scheduler:
    <<: *runtime
    user: www-data
```

Із checkout цього PR перевірити конфігурацію, після дозволу відтворити лише
наявні три сервіси (образи не перебудовувати, database/setup не запускати):

```powershell
$private = 'C:\Projects\private\telmanfit-r2-readonly.env'
docker compose --env-file $private -f compose.yaml -f compose.r2-runtime.yaml config --quiet
docker compose --env-file $private -f compose.yaml -f compose.r2-runtime.yaml up -d --no-deps --no-build --force-recreate app worker scheduler
```

Звірити, що app/database/web healthy. У кожному сервісі через штатний loader
та console kernel перевірити effective presentation.image_hosts і
PublicImageUrl::safe('https://assets.telmanfit.com.ua/ingredients/paprika.jpg').
Не друкувати інші config/environment/credentials. Приватний env-file до
Compose тут потрібен лише для interpolation PUBLIC_IMAGE_HOSTS; R2 credentials
не стають environment app/worker/scheduler. Override має лишатися явним для
цього погодженого rollout; звичайний bootstrap у цій операції заборонений.

## Запис після окремого дозволу

Команда запису має бути тільки такою (у одноразовому процесі з loader,
перевіреною локальною DB identity, трьома PHP bind mounts (matcher, manifest verifier, command), manifest та aliases):

```text
assets:match-r2-images --apply --exact-only --manifest-file=/audit/manifest.json --aliases-file=/audit/aliases.json --public-base-url=https://assets.telmanfit.com.ua
```

Для запису PostgreSQL READ ONLY не використовується; файлові mounts і root
filesystem залишаються read-only, entrypoint php, bootstrap не викликається.
Команда сама блокує три каталоги SHARE ROW EXCLUSIVE у PostgreSQL
READ COMMITTED, заново читає дані й звіряє весь набір table/ID/name/key/URL
проти manifest безпосередньо перед записом у тій самій транзакції.
Відсутній/зайвий збіг, дубль, змінена назва/ID/key/URL або інший наявний URL
блокують усю операцію. Уже встановлений точний URL зберігається (UNCHANGED).
Під час lock інші записи до трьох каталогів очікують завершення транзакції. Не запускати apply простим копіюванням dry-run runner-а: він навмисно
містить READ ONLY й ніколи не виконує apply.

Очікуваний результат: APPLIED 85 (47/37/1), CONFLICT 0; 37 legacy пропущено,
10 unmatched залишаються без призначення. WHERE image_url IS NULL захищає від
перезапису навіть при конкурентній зміні; якщо застосовано менше 85 або є
CONFLICT, припинити подальші дії і перевірити фактичний стан, не дописувати вручну.

## Перевірки після застосування

1. Read-only перевірити всі 85 (table,id,image_url) проти manifest, а не лише count.
   Підсумок: 85 непорожніх URL, 23 NULL; незадіяні 23 записи не змінилися.
   Додатково звірити ID/назви та всі поля, що були в передопераційному експорті.
2. Повторний штатний dry-run з exact-only: 85 confident, 0 ambiguous, 10 unmatched,
   37 ignored. Для цього використати універсальну команду, а не цей strict
   pre-apply runner, який очікує NULL і після apply навмисно завершується помилкою.
3. Повторне apply не потрібне на реальній БД: ідемпотентність доведена на synthetic
   PostgreSQL. Очікувана поведінка повторного apply — UNCHANGED 85, APPLIED 0.
4. Перевірити відображення representative зображень у локальній адмінці та
   клієнтському API/UI з effective allowlist; ненульові URL мають пройти safe().
5. При відхиленні зберегти фактичні значення. Rollback — лише за новим дозволом,
   по конкретних 85 ID і тільки якщо поточне значення ще дорівнює запропонованому
   URL. Не виконувати blanket NULL/UPDATE і не торкатися R2.

## Ізольовані тести

19 Unit/Feature — 119 assertions; 20 PostgreSQL18 — 254 assertions; Pint 6 файлів PASS;
CI selector — 5 тестів PASS; documentation guard і Compose config — PASS.
PostgreSQL-тести використовують власний новий cluster без мережі/наявних volumes
і synthetic таблиці, без міграцій/seeders; concurrency-тест використовує
власну тимчасову schema, видиму двом сесіям, та прибирає її після перевірки. Перевірено read-only dry-run,
відсутність fallback на equipment/, порядок ключів, duplicate canonical conflicts,
exact-only без приховування точних неоднозначностей, збереження наявних URL
у всіх трьох каталогах, повторне застосування та відмову для недозволеного host.
Повний migration-based PG набір локально не запускався через заборону міграцій.
Новий тест підключено до автоматичної recipe CI групи; CI — окремий статус від
цих локальних результатів.

## Точний manifest

[Машинозчитуваний план і HEAD результати](assets/r2-local-image-plan.json),
[aliases](assets/r2-local-image-aliases.json). Усі ID локальні, не production.

| Таблиця | ID | Назва БД | R2 key | Новий image_url |
|---|---:|---|---|---|
| ingredients | 47 | Мигдаль | ingredients/almonds.webp | https://assets.telmanfit.com.ua/ingredients/almonds.webp |
| ingredients | 4 | Яловичина пісна | ingredients/beef_top_round.webp | https://assets.telmanfit.com.ua/ingredients/beef_top_round.webp |
| ingredients | 21 | Перець болгарський | ingredients/bell_pepper.webp | https://assets.telmanfit.com.ua/ingredients/bell_pepper.webp |
| ingredients | 61 | Чорний перець | ingredients/black_pepper.webp | https://assets.telmanfit.com.ua/ingredients/black_pepper.webp |
| ingredients | 15 | Броколі | ingredients/broccoli.webp | https://assets.telmanfit.com.ua/ingredients/broccoli.webp |
| ingredients | 27 | Гречана крупа | ingredients/buckwheat.webp | https://assets.telmanfit.com.ua/ingredients/buckwheat.webp |
| ingredients | 56 | Масло 82% | ingredients/butter_82_percent.webp | https://assets.telmanfit.com.ua/ingredients/butter_82_percent.webp |
| ingredients | 17 | Морква | ingredients/carrot.webp | https://assets.telmanfit.com.ua/ingredients/carrot.webp |
| ingredients | 42 | Сир твердий 45% | ingredients/cheese.webp | https://assets.telmanfit.com.ua/ingredients/cheese.webp |
| ingredients | 44 | Яйце куряче | ingredients/chicken_egg.webp | https://assets.telmanfit.com.ua/ingredients/chicken_egg.webp |
| ingredients | 1 | Куряче філе | ingredients/chicken_fillet.webp | https://assets.telmanfit.com.ua/ingredients/chicken_fillet.webp |
| ingredients | 6 | Куряча печінка | ingredients/chicken_liver.webp | https://assets.telmanfit.com.ua/ingredients/chicken_liver.webp |
| ingredients | 2 | Куряче стегно, філе без шкірки | ingredients/chicken_thigh_skinless_fillet.webp | https://assets.telmanfit.com.ua/ingredients/chicken_thigh_skinless_fillet.webp |
| ingredients | 32 | Кукурудза консервована | ingredients/corn_canned.webp | https://assets.telmanfit.com.ua/ingredients/corn_canned.webp |
| ingredients | 38 | Кисломолочний сир 0% | ingredients/cottage_cheese_0_percent.webp | https://assets.telmanfit.com.ua/ingredients/cottage_cheese_0_percent.webp |
| ingredients | 39 | Кисломолочний сир 5% | ingredients/cottage_cheese_5_percent.webp | https://assets.telmanfit.com.ua/ingredients/cottage_cheese_5_percent.webp |
| ingredients | 14 | Огірок | ingredients/cucumber.webp | https://assets.telmanfit.com.ua/ingredients/cucumber.webp |
| ingredients | 59 | Діжонська гірчиця | ingredients/dijon_mustard.webp | https://assets.telmanfit.com.ua/ingredients/dijon_mustard.webp |
| ingredients | 31 | Макарони з тв. сортів | ingredients/durum_pasta.webp | https://assets.telmanfit.com.ua/ingredients/durum_pasta.webp |
| ingredients | 19 | Баклажан | ingredients/eggplant.webp | https://assets.telmanfit.com.ua/ingredients/eggplant.webp |
| ingredients | 66 | Часник | ingredients/garlic.webp | https://assets.telmanfit.com.ua/ingredients/garlic.webp |
| ingredients | 37 | Горошок консервований | ingredients/green_peas_canned.webp | https://assets.telmanfit.com.ua/ingredients/green_peas_canned.webp |
| ingredients | 52 | Лаваш тонкий | ingredients/lavash.webp | https://assets.telmanfit.com.ua/ingredients/lavash.webp |
| ingredients | 63 | Лимон | ingredients/lemon.webp | https://assets.telmanfit.com.ua/ingredients/lemon.webp |
| ingredients | 43 | Молоко 2,5% | ingredients/milk_2_5_percent.webp | https://assets.telmanfit.com.ua/ingredients/milk_2_5_percent.webp |
| ingredients | 22 | Гриби печериці | ingredients/mushrooms.webp | https://assets.telmanfit.com.ua/ingredients/mushrooms.webp |
| ingredients | 58 | Гірчиця | ingredients/mustard.webp | https://assets.telmanfit.com.ua/ingredients/mustard.webp |
| ingredients | 30 | Вівсяні пластівці | ingredients/oats.webp | https://assets.telmanfit.com.ua/ingredients/oats.webp |
| ingredients | 55 | Олія оливкова | ingredients/olive_oil.webp | https://assets.telmanfit.com.ua/ingredients/olive_oil.webp |
| ingredients | 20 | Цибуля ріпчаста | ingredients/onion.webp | https://assets.telmanfit.com.ua/ingredients/onion.webp |
| ingredients | 62 | Паприка | ingredients/paprika.jpg | https://assets.telmanfit.com.ua/ingredients/paprika.jpg |
| ingredients | 5 | Свиняча вирізка | ingredients/pork_tenderloin.webp | https://assets.telmanfit.com.ua/ingredients/pork_tenderloin.webp |
| ingredients | 33 | Сочевиця червона | ingredients/red_lentils.webp | https://assets.telmanfit.com.ua/ingredients/red_lentils.webp |
| ingredients | 28 | Рис білий | ingredients/rice.webp | https://assets.telmanfit.com.ua/ingredients/rice.webp |
| ingredients | 29 | Рис басматі | ingredients/rice_basmati.webp | https://assets.telmanfit.com.ua/ingredients/rice_basmati.webp |
| ingredients | 9 | Лосось | ingredients/salmon.webp | https://assets.telmanfit.com.ua/ingredients/salmon.webp |
| ingredients | 60 | Сіль | ingredients/salt.webp | https://assets.telmanfit.com.ua/ingredients/salt.webp |
| ingredients | 65 | Соєвий соус | ingredients/soy_sauce.webp | https://assets.telmanfit.com.ua/ingredients/soy_sauce.webp |
| ingredients | 54 | Олія соняшникова | ingredients/sunflower_oil.webp | https://assets.telmanfit.com.ua/ingredients/sunflower_oil.webp |
| ingredients | 7 | Тилапія | ingredients/tilapia_fillet.webp | https://assets.telmanfit.com.ua/ingredients/tilapia_fillet.webp |
| ingredients | 13 | Помідор | ingredients/tomato.webp | https://assets.telmanfit.com.ua/ingredients/tomato.webp |
| ingredients | 10 | Тунець у власному соку | ingredients/tuna_canned_water.webp | https://assets.telmanfit.com.ua/ingredients/tuna_canned_water.webp |
| ingredients | 3 | Філе індички | ingredients/turkey_fillet.webp | https://assets.telmanfit.com.ua/ingredients/turkey_fillet.webp |
| ingredients | 68 | Вода | ingredients/water.webp | https://assets.telmanfit.com.ua/ingredients/water.webp |
| ingredients | 67 | Протеїн сироватковий | ingredients/whey_protein.webp | https://assets.telmanfit.com.ua/ingredients/whey_protein.webp |
| ingredients | 40 | Білий йогурт 2,5% | ingredients/yogurt_2_5_percent.webp | https://assets.telmanfit.com.ua/ingredients/yogurt_2_5_percent.webp |
| ingredients | 18 | Кабачок | ingredients/zucchini.webp | https://assets.telmanfit.com.ua/ingredients/zucchini.webp |
| kitchen_equipment | 15 | Аерогриль | kitchen_equipment/air_fryer.webp | https://assets.telmanfit.com.ua/kitchen_equipment/air_fryer.webp |
| kitchen_equipment | 5 | Форма для запікання | kitchen_equipment/baking_dish.webp | https://assets.telmanfit.com.ua/kitchen_equipment/baking_dish.webp |
| kitchen_equipment | 4 | Деко | kitchen_equipment/baking_tray.webp | https://assets.telmanfit.com.ua/kitchen_equipment/baking_tray.webp |
| kitchen_equipment | 18 | Блендер стаціонарний | kitchen_equipment/blender.webp | https://assets.telmanfit.com.ua/kitchen_equipment/blender.webp |
| kitchen_equipment | 31 | Відкривачка | kitchen_equipment/can_opener.webp | https://assets.telmanfit.com.ua/kitchen_equipment/can_opener.webp |
| kitchen_equipment | 24 | Ніж | kitchen_equipment/chef_knife.webp | https://assets.telmanfit.com.ua/kitchen_equipment/chef_knife.webp |
| kitchen_equipment | 7 | Друшляк | kitchen_equipment/colander.webp | https://assets.telmanfit.com.ua/kitchen_equipment/colander.webp |
| kitchen_equipment | 20 | Гриль контактний | kitchen_equipment/contact_grill.webp | https://assets.telmanfit.com.ua/kitchen_equipment/contact_grill.webp |
| kitchen_equipment | 25 | Обробна дошка | kitchen_equipment/cutting_board.webp | https://assets.telmanfit.com.ua/kitchen_equipment/cutting_board.webp |
| kitchen_equipment | 33 | Фольга | kitchen_equipment/foil.webp | https://assets.telmanfit.com.ua/kitchen_equipment/foil.webp |
| kitchen_equipment | 11 | Контейнер | kitchen_equipment/food_container.webp | https://assets.telmanfit.com.ua/kitchen_equipment/food_container.webp |
| kitchen_equipment | 12 | Ємність | kitchen_equipment/food_vessel.webp | https://assets.telmanfit.com.ua/kitchen_equipment/food_vessel.webp |
| kitchen_equipment | 3 | Сковорідка | kitchen_equipment/frying_pan.webp | https://assets.telmanfit.com.ua/kitchen_equipment/frying_pan.webp |
| kitchen_equipment | 34 | Фольга потовщена | kitchen_equipment/heavy_duty_foil.webp | https://assets.telmanfit.com.ua/kitchen_equipment/heavy_duty_foil.webp |
| kitchen_equipment | 19 | Блендер занурювальний | kitchen_equipment/immersion_blender.webp | https://assets.telmanfit.com.ua/kitchen_equipment/immersion_blender.webp |
| kitchen_equipment | 23 | Ювелірні ваги | kitchen_equipment/jewelry_scales.webp | https://assets.telmanfit.com.ua/kitchen_equipment/jewelry_scales.webp |
| kitchen_equipment | 22 | Кухонні ваги | kitchen_equipment/kitchen_scale.webp | https://assets.telmanfit.com.ua/kitchen_equipment/kitchen_scale.webp |
| kitchen_equipment | 27 | Ножиці | kitchen_equipment/kitchen_scissors.webp | https://assets.telmanfit.com.ua/kitchen_equipment/kitchen_scissors.webp |
| kitchen_equipment | 17 | Мікрохвильовка | kitchen_equipment/microwave.webp | https://assets.telmanfit.com.ua/kitchen_equipment/microwave.webp |
| kitchen_equipment | 9 | Миска | kitchen_equipment/mixing_bowl.webp | https://assets.telmanfit.com.ua/kitchen_equipment/mixing_bowl.webp |
| kitchen_equipment | 16 | Мультиварка | kitchen_equipment/multicooker.webp | https://assets.telmanfit.com.ua/kitchen_equipment/multicooker.webp |
| kitchen_equipment | 14 | Духовка | kitchen_equipment/oven.webp | https://assets.telmanfit.com.ua/kitchen_equipment/oven.webp |
| kitchen_equipment | 6 | Решітка для духовки | kitchen_equipment/oven_rack.webp | https://assets.telmanfit.com.ua/kitchen_equipment/oven_rack.webp |
| kitchen_equipment | 36 | Паперові рушники | kitchen_equipment/paper_towels.webp | https://assets.telmanfit.com.ua/kitchen_equipment/paper_towels.webp |
| kitchen_equipment | 32 | Пергамент силіконізований | kitchen_equipment/parchment.webp | https://assets.telmanfit.com.ua/kitchen_equipment/parchment.webp |
| kitchen_equipment | 1 | Каструля | kitchen_equipment/pot.webp | https://assets.telmanfit.com.ua/kitchen_equipment/pot.webp |
| kitchen_equipment | 35 | Рукав для запікання | kitchen_equipment/roasting_sleeve.webp | https://assets.telmanfit.com.ua/kitchen_equipment/roasting_sleeve.webp |
| kitchen_equipment | 2 | Сотейник | kitchen_equipment/saucepan.webp | https://assets.telmanfit.com.ua/kitchen_equipment/saucepan.webp |
| kitchen_equipment | 10 | Піала | kitchen_equipment/small_bowl.webp | https://assets.telmanfit.com.ua/kitchen_equipment/small_bowl.webp |
| kitchen_equipment | 21 | Сувід циркулятор | kitchen_equipment/sous_vide_circulator.webp | https://assets.telmanfit.com.ua/kitchen_equipment/sous_vide_circulator.webp |
| kitchen_equipment | 29 | Лопатка | kitchen_equipment/spatula.webp | https://assets.telmanfit.com.ua/kitchen_equipment/spatula.webp |
| kitchen_equipment | 8 | Вставка-пароварка | kitchen_equipment/steamer_insert.webp | https://assets.telmanfit.com.ua/kitchen_equipment/steamer_insert.webp |
| kitchen_equipment | 13 | Плита | kitchen_equipment/stovetop.webp | https://assets.telmanfit.com.ua/kitchen_equipment/stovetop.webp |
| kitchen_equipment | 30 | Щипці | kitchen_equipment/tongs.webp | https://assets.telmanfit.com.ua/kitchen_equipment/tongs.webp |
| kitchen_equipment | 26 | Овочечистка | kitchen_equipment/vegetable_peeler.webp | https://assets.telmanfit.com.ua/kitchen_equipment/vegetable_peeler.webp |
| kitchen_equipment | 28 | Вінчик | kitchen_equipment/whisk.webp | https://assets.telmanfit.com.ua/kitchen_equipment/whisk.webp |
| kitchen_equipment | 38 | Зіп-пакет 15×30 см | kitchen_equipment/zip_bag_15x30.webp | https://assets.telmanfit.com.ua/kitchen_equipment/zip_bag_15x30.webp |
| recipes | 1 | Гречка з грибами | recipes/buckwheat_mushrooms.webp | https://assets.telmanfit.com.ua/recipes/buckwheat_mushrooms.webp |

## Файли без призначення

- `ingredients/honey.webp`
- `ingredients/lime_juice.webp`
- `ingredients/milk_1_percent.webp`
- `ingredients/veggie_mix_mexican.webp`
- `ingredients/veggie_mix_spring.webp`
- `recipes/chicken_fillet_lemon.webp`
- `recipes/pork_tenderloin_spices.webp`
- `recipes/rice_peas_corn.webp`
- `recipes/tilapia_soy_sauce.webp`
- `recipes/vegetable_stew.webp`
