# AUTH-1: ізольований compatibility spike

Це тестовий стенд, **не auth-модуль для застосунку**. Маршрути існують тільки в
тимчасовому Application всередині PHPUnit; HTTP-сервер не запускається.
Фіксований email OTP/HMAC key, SQLite та спрощений flow призначені тільки для
доказів сумісності. Не копіювати їх у production.

Потрібні PHP 8.4 з PDO SQLite, Python 3, Composer і штатний `vendor` із
`composer.lock` цього репозиторію. Runner нічого не встановлює в цей `vendor`.

```bash
bash experiments/auth1/run.sh
```

Runner завантажує pinned Git blobs Sanctum 4.3.3 і вибраних класів Fortify 1.41.0
через GitHub API, перевіряє кожний SHA й додає PSR-4 **тільки до процесу тестів**.
`bootstrap.php` повторно перевіряє SHA навіть для готового каталогу.
Повний Fortify service provider/pipeline не реєструється. Далі виконується
Composer SAT-resolution та PHPUnit з `--fail-on-warning --fail-on-risky`.

Якщо джерела вже отримано через GitHub connector (так виконано в цьому Cloud,
де shell-проксі недоступний), можна задати їхній перевірюваний каталог:

```bash
AUTH1_VENDOR=/workspace/telmanfit/vendor \
AUTH1_UPSTREAM=/workspace/scratch/auth1-upstream \
bash experiments/auth1/run.sh
```

Ці абсолютні шляхи описують поточний Cloud; для іншого checkout передайте власні.
Кожний тест створює приватний випадковий каталог, окремий Application, випадковий
APP_KEY, дві таблиці `auth1_*` у SQLite `:memory:` і файловий session store.
Основні bootstrap, `.env`, міграції та DB connection не використовуються.
У teardown закривається SQLite і видаляється лише власний каталог без обходу
symlink `vendor`. Runner очищає власні завантаження через `trap`; зовнішній
`AUTH1_UPSTREAM` залишається для повторення.

## Composer

```bash
python3 experiments/auth1/resolve.py
python3 experiments/auth1/resolve.py --online
```

Перша команда — справжній Composer SAT на скінченному snapshot: версії з основного
lock + upstream runtime `require/conflict/replace/provide/suggest/license` у
`manifests.json` (repository/tag в кожному записі). Взято всі root `require` і
`require-dev`, чотири досліджувані пакети pinned. Packagist вимкнено тільки для
цього тимчасового проєкту. Це перевіряє існування сумісного набору; не стверджує,
що latest Packagist вибере ті самі transitive versions, або що весь Fortify
встановлено/запущено. Власні `composer.json`/lock/cache створюються під `/tmp`
і прибираються. Plugins/scripts/install не виконуються, platform requirements
не ігноруються; security advisory audit не виконується.

`--online` перевіряє звичайний Packagist resolution тих самих root constraints.
У цьому Cloud він завершився `curl error 7` (proxy:8080), exit 100. Це мережевий
блокер, а не доказ несумісності. [Звіт і межі](../../docs/auth-compatibility-spike.md),
[зафіксовані результати](results.txt).

## Межі стенда

- Реальні Laravel kernel/cookies/CSRF/session guard, Sanctum middleware/guard,
  Filament AppAuthentication/Login і Livewire update handler.
- Серверне assurance/version, HTTP 401 до MFA, одноразове споживання кодів,
  CSRF/Origin, session rotation/revocation та persistent Livewire gate перевірено.
- Два guards використовують один тестовий cookie transport: production-перемикання
  двох cookies/stores, browser Path/Domain/CORS/PWA E2E тут не доведено.
- Livewire login і HTTP update перевіряються окремо. Для update тест створює
  синтетичну вже завершену admin-сесію; це не реалізація запису assurance у Filament.
- PostgreSQL, справжня concurrency, mail, rate limits, invitation registration,
  enrollment/replacement та адміністративний recovery не тестуються цим стендом.
