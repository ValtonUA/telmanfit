# T5.7b — адміністративний конструктор і клієнтський помічник

Перевірки виконано лише з синтетичними даними в disposable PostgreSQL 18,
network=none, без DB ports, із PHP 8.5.12 із штатного `docker/local/Dockerfile`.
Робочі БД, production, feature flags та R2 дані не змінювалися.

- `CookingTemplatesTest`, `RecipeBatchesTest`, `BatchEditorTest`: 80 тестів,
  1295 assertions; SQL17/18/19, legacy/A/B/C, snapshots обладнання,
  режими 1/2/7, outputs/allocations, frozen token resolution, stage IDs,
  lifecycle, stale, cross-version, ACL, unavailable week, overallocated rollback.
- `cooking-concurrency.php`: 37 сценаріїв незалежних PG18 процесів, observed locks,
  edit/review/publish, double publish, CAS/ABA, snapshots, deadlock rollback.
- Backend suite на PHP 8.5.12: 125 тестів, 511 assertions.
- AUTH-4: 156 тестів, 1593 assertions; зокрема `ClientCookingTest`:
  3 тести, 22 assertions, авторизований HEAD=200, GET/HEAD без cookie=401,
  `no-store, private`, незмінність усіх таблиць і `xmin`. Видалення cookie
  використовує наявний Laravel test cookie bag зі скиданням auth guards.
- AUTH-4 concurrency: 5 сценаріїв; AUTH-2 SQL snapshot/BIGINT FK:
  1 тест, 30 assertions. CI regression: 40 тестів; docs regression: 11 тестів.
- Chromium: responsive 1440/390/320 light/dark; repeaters, стара вкладка,
  review/publish/окрема activation; штатні password + MFA, без auth bypass.
  Далі — справжній ClientSession OTP/API/React player, frozen інгредієнти,
  обладнання, timer/image. Перевірено відсутність перекриття двох навігацій,
  контраст другорядної кнопки ≥4.5:1 і галерею поверх основної навігації.
  Fixture stdout відокремлено від stderr, перехід до раціону чекає завершення OTP.
  Машинний результат — [checks.json](checks.json).
- Повний `recipe-client.py`: 7 груп перевірок, включно з токенами, обладнанням,
  pause/reset таймерів, reload/resume/discard progress, Wake Lock unsupported
  і відкликанням ClientSession. Виправлено відновлення player до збереження того
  самого ключа та revalidation списку сесій; чинні assertions не послаблено.
  Selector synthetic output береться з exact pattern, heading locator є точним.
  Машинний результат — [client-checks.json](client-checks.json).
- React lint/build, Vite admin build, Pint (424 файли), docs guard та `git diff --check`.
  Окремого frontend unit-test script у `client/package.json` немає.

[Desktop light](1440-light.png) · [Mobile dark](320-dark.png).
[Клієнт desktop light](client-1440-light.png) · [Клієнт mobile dark](client-320-dark.png).
Відтворення browser сценарію включене в штатний recipe Stage1 runner через
`recipe_batch.py`; `cooking-fixture.php` використовує обов'язковий auth2 PG guard.
Фактичні GitHub Fast/Docs/Targeted results слід перевіряти на head SHA Draft PR.

Межі: немає перевірки на реальних раціонах, фізичному телефоні або production.
Автор відповідає за безпеку технологічних параметрів; таймер не доводить готовності.
Це локальні докази у WSL2 Ubuntu з Chromium 151 / Playwright 1.62.0;
вони не підтверджують GitHub CI на фінальному head. PHP запускався у новому
контейнері з перевіреним Composer lock; browser HTTP — лише loopback через
Docker stdio proxy. Тимчасова копія не використовувала host `.env`.
