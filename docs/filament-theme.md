# Відновлення штатного оформлення Filament 5

## Причина та межі

Перевірено `master` `51cd8f6` у новому Linux Docker Compose stack, окремому від
Windows Docker Desktop користувача. На сторінці `/admin/invitations` були звичайні
`input`, `select`, `label` і `table` без обгорток/класів компонентів Filament.
Tailwind Preflight скидає браузерне оформлення; штатні стилі Filament застосовуються
до його компонентів, а не до довільних HTML-полів. Тому поля втрачали фон, рамки,
внутрішні відступи й належне оформлення dark mode. Це відтворено в Chromium:
[запрошення до виправлення](evidence/filament-theme/before-invitations-1440-light.png).

Загальний збій завантаження теми не відтворено. `AdminPanelProvider::viteTheme()`
і Vite input використовують однаковий `resources/css/filament/admin/theme.css`.
Його import штатного `vendor/filament/filament/resources/css/theme.css` правильний;
Tailwind 4 компілює `@apply`, dark та стилі forms/schemas/tables/actions.
Manifest посилається на хешований CSS, nginx віддає його з HTTP 200 та
`Content-Type: text/css` (724864 bytes); `nginx -t` успішний. JS Filament і
Livewire завантажуються. Штатна форма клієнта вже до виправлення мала оформлення:
[контрольний скриншот](evidence/filament-theme/before-edit-1440-light.png).
Наявний власний CSS переважно обмежений `.clients-table`; глобальні mobile-правила
перенесення тексту не пояснюють відсутність рамок у HTML-полів запрошень.

## Виправлення

Сторінка запрошень використовує штатні `x-filament-forms::field-wrapper`,
`x-filament::input.wrapper`, `input`, `input.select`, `section` і `button`.
Поля мають окремі `id` та пов'язані labels. Статична таблиця використовує штатні
класи Filament 5 з mobile stacking, заголовками колонок і переносом тексту.
Додаткового CSS, оновлень залежностей або заміни теми немає.

POST actions, імена полів, CSRF, пароль/TOTP step-up, одноразовий показ URL,
авторизація та схема БД не змінюються. Окремі захищені HTML-сторінки MFA та
результату запрошення з їхньою CSP залишаються чинними; результат створення й
відкликання перевіряється реальним HTTP flow.

## Відтворення перевірки

Тільки для нового disposable stack, до ручного enrollment:

```bash
COMPOSE_PROJECT_NAME=telmanfit-filament-check docker compose up -d --build --wait
COMPOSE_PROJECT_NAME=telmanfit-filament-check TELMANFIT_FILAMENT_THEME_BROWSER=1 python3 tests/browser/local_compose.py
COMPOSE_PROJECT_NAME=telmanfit-filament-check docker compose down
```

Не використовуйте тест проти повсякденного `telmanfit-local`: він проходить
первинний enrollment і реєстрацію синтетичного клієнта. Порт 8098 має бути вільним.
Volumes не видаляються. Потрібні Python Playwright і Chromium.
У cloud використано тимчасовий build override із secret `proxy_ca` для довіри
session proxy; перевірка TLS не вимикалася, CA не додавався до репозиторію.
Звичайна збірка та browser-перевірка включені в `local-compose.yml` CI.

`filament_theme.py` запускається всередині сценарію `local_demo.py`, після
справжніх MFA та email OTP. Перевіряються головна сторінка, запрошення, список,
картка, редагування клієнта, dropdown тренера й анкета; світла/темна теми, ширини
1440, 390 і 320 px. Перевіряються computed styles, відсутність горизонтального
переповнення документа, завантаження CSS/JS/fonts, JS exceptions, збереження форм
та створення/відкликання запрошення з новими TOTP. Trace, HAR, сесії й секретні URL
не записуються в докази.

## Результати

Chromium 154.0.8037.57: **48 візуальних станів пройдено**, помилок assets/JS — **0**;
створення запрошення — HTTP **201**, відкликання — HTTP **200**, зміна стану видима
в таблиці. Підсумок і computed styles: [checks.json](evidence/filament-theme/checks.json).

Скриншоти після виправлення: [desktop light](evidence/filament-theme/after-invitations-1440-light.png),
[desktop dark](evidence/filament-theme/after-invitations-1440-dark.png),
[mobile dark](evidence/filament-theme/after-invitations-390-dark.png),
[анкета mobile dark](evidence/filament-theme/after-questionnaire-390-dark.png).
Збережено вибрані скриншоти; метрики охоплюють повну матрицю.
Повторний запуск stack підтвердив збереження MFA, сесій і відповідей без видалення
volumes. Базовий acceptance також пройшов усі 7 перевірок fence/MFA/OTP/CSRF та
збереження даних: [Compose checks](evidence/local-compose/checks.json).

| Перевірка | Результат |
|---|---|
| Dockerfile: app/web/database | Збірка успішна; тимчасовий cloud CA override |
| React lint і production build | Успішно всередині Docker-збірки |
| SQL Stage 1 | PASS=76, FAIL=0, GAP=0 |
| PostgreSQL PHPUnit Stage 1/2 | 45 tests, 796 assertions |
| Основний PHPUnit | 47 tests, 111 assertions |
| AUTH-4 PHPUnit | 29 tests, 176 assertions |
| Pint | PASS, 223 файли |

Backend-регресії виконано на PHP 8.5.12 у контейнері, PostgreSQL 18.6 — в окремому
одноразовому cluster без мережі на tmpfs. Для Stage 1/2 використано незмінний
фрагмент `tests/pg/run-stage1.sh` до AUTH-2; AUTH-4 виконано з `AUTH4_ONLY=1`.
Host PHP 8.4.26 не відповідає runtime policy, тому він не є підставою цього результату.
Windows Desktop вручну не перевірено; Dockerfile/Compose/nginx перевірені в Linux.
