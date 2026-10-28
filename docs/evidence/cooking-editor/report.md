# T5.7b — адміністративний конструктор

Перевірки виконано лише з синтетичними даними в disposable PostgreSQL 18,
network=none, без DB ports, із PHP 8.5.12 із штатного `docker/local/Dockerfile`.
Робочі БД, production, feature flags та R2 дані не змінювалися.

- `CookingTemplatesTest`: 16 тестів, 294 assertions; SQL17/18, legacy/A/B/C,
  режими 1/2/7, outputs/allocations, frozen token resolution, stage IDs,
  lifecycle, stale, cross-version, ACL, unavailable week, overallocated rollback.
- `cooking-concurrency.php`: 37 сценаріїв незалежних PG18 процесів, observed locks,
  edit/review/publish, double publish, CAS/ABA, snapshots, deadlock rollback.
- Backend suite на PHP 8.5.12: 125 тестів, 511 assertions.
- Chromium: responsive 1440/390/320 light/dark; repeaters, стара вкладка,
  review/publish/окрема activation; штатні password + MFA, без auth bypass.
  Машинний результат — [checks.json](checks.json).
- Pint, Vite admin build, docs guard та `git diff --check`.

[Desktop light](1440-light.png) · [Mobile dark](320-dark.png).
Відтворення browser сценарію включене в штатний recipe Stage1 runner через
`recipe_batch.py`; `cooking-fixture.php` використовує обов'язковий auth2 PG guard.
Фактичні GitHub Fast/Docs/Targeted results слід перевіряти на head SHA Draft PR.

Межі: немає перевірки на реальних раціонах, фізичному телефоні або production.
Автор відповідає за безпеку технологічних параметрів; таймер не доводить готовності.
API, ClientSession reader і React-помічник залишені наступному етапу.
