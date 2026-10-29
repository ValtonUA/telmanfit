"""Chromium: справжня React/API/ClientSession на disposable synthetic PostgreSQL."""
import json
import os
import re
from pathlib import Path
import subprocess
import sys
import traceback
import time
from playwright.sync_api import sync_playwright, expect
from wake_lock import WAKE_LOCK_PROBE

def report_failure(kind, error, trace):
    traceback.print_exception(kind, error, trace)
    frames = [frame for frame in traceback.extract_tb(trace) if Path(frame.filename).resolve() == Path(__file__).resolve()]
    if frames:
        frame = frames[-1]
        # Короткий підсумок після Aria snapshot лишається в CI tail; без runtime secrets.
        print(f'Client browser failure: {kind.__name__} at recipe-client.py:{frame.lineno}: {frame.line}', flush=True)


sys.excepthook = report_failure

BASE = 'http://127.0.0.1:18054'
OUT = Path('docs/evidence/client-recipe')
OUT.mkdir(parents=True, exist_ok=True)


def php(*args):
    return subprocess.check_output(['php', *args], text=True).strip()


def fixture(mode):
    result = php('tests/browser/client-meal-plan-fixture.php', mode)
    assert result == 'T5.2 fixture action complete', result


checks = []


def wait_for_held_route(page, routes, message):
    deadline = time.monotonic() + 5
    while not routes and time.monotonic() < deadline:
        page.wait_for_timeout(50)
    assert routes, message


def release_held_routes(page, routes, pattern):
    while True:
        if not routes:
            page.wait_for_timeout(100)
            if not routes:
                return
        with page.expect_response(pattern):
            routes.pop(0).continue_()


def expect_wake_probe(page, key, expected):
    # waitForFunction виконує eval у сторінці й залежить від Chromium/CSP.
    # Пряме protocol evaluate не потребує послаблення script-src застосунку.
    deadline = time.monotonic() + 15
    while True:
        actual = page.evaluate('(key) => window.__wakeProbe[key]', key)
        if actual == expected:
            return
        assert time.monotonic() < deadline, (key, actual, expected)
        time.sleep(.05)


with sync_playwright() as p:
    options = {'headless': True}
    if os.environ.get('CLIENT_RECIPE_CHROMIUM'):
        options['executable_path'] = os.environ['CLIENT_RECIPE_CHROMIUM']
    browser = p.chromium.launch(**options)
    try:
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, color_scheme='light')
        page = context.new_page()
        page.set_default_timeout(15000)
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        for _ in range(60):
            try:
                if context.request.get(BASE + '/api/health').ok:
                    break
            except Exception:
                time.sleep(.1)
        fixture('reset')
        php('tests/browser/auth5-fixture.php', 'window', 't52-browser@example.invalid')
        page.route('https://images.example.com/**', lambda route: route.fulfill(content_type='image/png', body=Path('client/src/assets/hero.png').read_bytes()))
        page.goto(BASE + '/app/login')
        # Ізольована UI-регресія same-URL restart, перед справжнім OTP login нижче.
        page.route('**/api/auth/email-otp/request', lambda route: route.fulfill(
            status=202, content_type='application/json',
            body='{"challenge_id":"00000000-0000-4000-8000-000000000001","resend_after":60,"expires_in":300}'), times=1)
        page.get_by_label('Електронна пошта', exact=True).fill('restart@example.invalid')
        page.get_by_role('button', name='Отримати код', exact=True).click()
        expect(page.get_by_label('Код із листа', exact=True)).to_be_visible()
        page.get_by_role('link', name='Почати знову').click()
        expect(page.get_by_label('Електронна пошта', exact=True)).to_have_value('')
        expect(page.get_by_label('Код із листа', exact=True)).to_have_count(0)
        page.get_by_label('Електронна пошта', exact=True).fill('t52-browser@example.invalid')
        page.get_by_role('button', name='Отримати код', exact=True).click()
        expect(page.get_by_label('Код із листа', exact=True)).to_be_visible()
        php('artisan', 'queue:work', '--stop-when-empty', '--tries=1', '--sleep=0')
        code = php('tests/browser/auth5-fixture.php', 'mail', 't52-browser@example.invalid')
        page.get_by_label('Код із листа', exact=True).fill(code)
        page.get_by_role('button', name='Підтвердити', exact=True).click()
        # UX-1: одна HTML document та ті самі DOM-вузли оболонки у всіх переходах.
        expect(page.locator('.client-header')).to_be_visible()
        page.evaluate("window.uxShell = [document, document.querySelector('.client-header'), document.querySelector('.client-navigation')]")
        document_requests = []
        csrf_requests = []
        page.on('request', lambda r: document_requests.append(r.url) if r.is_navigation_request() and r.frame == page.main_frame else None)
        page.on('request', lambda r: csrf_requests.append(r.url) if r.url.endswith('/api/auth/csrf') else None)
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                page.set_viewport_size({'width': width, 'height': 1000})
                page.emulate_media(color_scheme=theme)
                for name, heading in [('Раціон', 'Раціон'), ('Корисне', 'Корисне'), ('Прогрес', 'Прогрес'), ('Сьогодні', 'Сьогодні')]:
                    page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name=name).click()
                    expect(page.get_by_role('heading', name=heading, exact=True).first).to_be_visible()
                    assert page.evaluate("uxShell[0] === document && uxShell[1] === document.querySelector('.client-header') && uxShell[2] === document.querySelector('.client-navigation')")
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                if theme == 'dark':
                    assert page.evaluate('getComputedStyle(document.documentElement).backgroundColor') == 'rgb(21, 37, 30)'
        # Перехід на вже відвідане «Сьогодні» має показувати перевірене меню,
        # навіть коли фоновий no-store запит ще не повернувся.
        expect(page.locator('.today-menu')).to_be_visible()
        held_days = []
        page.route('**/api/meal-plan/days*', lambda route: held_days.append(route))
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Корисне').click()
        expect(page.get_by_role('heading', name='Корисне', exact=True)).to_be_visible()
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Сьогодні').click()
        expect(page.locator('.today-menu')).to_be_visible()
        assert page.locator('.today-menu').is_visible(), 'відвідане меню не повинно зникати на час SPA revalidation'
        assert page.get_by_role('heading', name='Завантажуємо меню…').count() == 0
        assert page.get_by_role('heading', name='Поки немає призначеного раціону').count() == 0
        wait_for_held_route(page, held_days, 'перехід має виконати фонову перевірку API')
        release_held_routes(page, held_days, '**/api/meal-plan/days*')
        page.unroute('**/api/meal-plan/days*')
        checks.append('SPA «Сьогодні»: cached content remains visible during delayed route revalidation')
        # Явно обраний інший тиждень не може замінити кеш сьогоднішнього меню.
        today_menu = page.locator('.today-menu')
        expect(today_menu.locator('.dish').first).to_be_visible()
        today_text = today_menu.inner_text()
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Раціон').click()
        expect(page.get_by_role('combobox', name='Тиждень раціону')).to_be_visible()
        with page.expect_response(lambda response: '/api/meal-plan/days?week=2' in response.url and response.status == 200):
            page.get_by_role('combobox', name='Тиждень раціону').select_option('2')
        expect(page.locator('.plan-days button').first).to_contain_text('День 8')
        held_days = []
        page.route('**/api/meal-plan/days*', lambda route: held_days.append(route))
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Сьогодні').click()
        wait_for_held_route(page, held_days, 'повернення на сьогодні має перевіряти API')
        expect(today_menu).to_be_visible()
        assert today_menu.inner_text() == today_text, 'сьогоднішні страви мають залишатися незмінними'
        for heading in ('Завантажуємо меню…', 'Поки немає призначеного раціону', 'На сьогодні меню не заплановано'):
            assert page.get_by_role('heading', name=heading).count() == 0, heading
        release_held_routes(page, held_days, '**/api/meal-plan/days*')
        page.unroute('**/api/meal-plan/days*')
        checks.append('UX-1: week 2 cannot replace the current-day cache during delayed revalidation')
        page.get_by_role('link', name='Відкрити профіль').click()
        expect(page.get_by_role('heading', name='Профіль', exact=True)).to_be_visible()
        page.get_by_role('link', name='Моя анкета').click()
        expect(page.get_by_role('heading', name='Початкова анкета', exact=True)).to_be_visible()
        expect(page.locator('#onboarding-reasons')).to_be_visible()
        page.go_back()
        expect(page.get_by_role('heading', name='Профіль', exact=True)).to_be_visible()
        held_onboarding = []
        page.route('**/api/onboarding', lambda route: held_onboarding.append(route))
        page.go_forward()
        expect(page.get_by_role('heading', name='Початкова анкета', exact=True)).to_be_visible()
        expect(page.locator('#onboarding-reasons')).to_be_visible()
        wait_for_held_route(page, held_onboarding, 'анкета має повторно перевірятися без зникнення відомих відповідей')
        release_held_routes(page, held_onboarding, '**/api/onboarding')
        page.unroute('**/api/onboarding')
        assert not document_requests, document_requests
        assert not csrf_requests, csrf_requests
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Сьогодні').click()
        page.set_viewport_size({'width': 1440, 'height': 1000})
        page.emulate_media(color_scheme='light')
        checks.append('UX-1: 4 вкладки, профіль/анкета, Back/Forward, незмінна оболонка, 0 document/CSRF повторів, 1440/390/320 light/dark')
        # T5.6: справжня сесія, опублікована бібліотека та порожня бібліотека.
        nav = page.get_by_role('navigation', name='Основна навігація')
        nav.get_by_role('link', name='Корисне').click()
        expect(page.get_by_role('heading', name='Матеріали поки недоступні')).to_be_visible()
        fixture('library')
        page.reload()
        article = page.locator('.library-tile').filter(has_text='Бібліотека: корисна стаття')
        expect(article).to_be_visible()
        expect(page.locator('.library-tile')).to_have_count(2)
        assert 'Прихована чернетка' not in page.locator('body').inner_text()
        article.click()
        expect(page.get_by_role('heading', name='Безпечний заголовок')).to_be_visible()
        page.reload()
        expect(page.get_by_role('heading', name='Безпечний заголовок')).to_be_visible()
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                page.set_viewport_size({'width': width, 'height': 1000})
                page.emulate_media(color_scheme=theme)
                expect(page.get_by_role('heading', name='Безпечний заголовок')).to_be_visible()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        page.set_viewport_size({'width': 1440, 'height': 1000})
        page.emulate_media(color_scheme='light')
        page.get_by_role('link', name='До бібліотеки').click()
        expect(page.locator('.library-tile')).to_have_count(2)
        page.locator('.library-tile').filter(has_text='Бібліотека: корисна стаття').click()
        expect(page.get_by_role('heading', name='Безпечний заголовок')).to_be_visible()
        held_library = []
        page.route('**/api/library', lambda route: held_library.append(route))
        page.get_by_role('link', name='До бібліотеки').click()
        expect(page.locator('.library-tile').first).to_be_visible()
        assert page.locator('.library-tile').count() == 2, 'кешована бібліотека має залишатися під час refetch'
        wait_for_held_route(page, held_library, 'повернення до бібліотеки має виконати фонову перевірку')
        release_held_routes(page, held_library, '**/api/library')
        page.unroute('**/api/library')
        page.locator('.library-tile').filter(has_text='Бібліотека: відео').click()
        expect(page.get_by_role('link', name='Відкрити матеріал')).to_have_attribute('href', 'https://www.youtube.com/watch?v=test')
        page.get_by_role('link', name='До бібліотеки').click()
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Сьогодні').click()
        checks.append('Бібліотека T5.6: auth, published-only, Markdown, HTTPS, reload, 1440/390/320 light/dark')
        page.get_by_role('link', name='Переглянути раціон').click()
        # T5.7a: реальна read-only shopping агрегація для обраного тижня.
        expect(page.get_by_role('link', name='Список покупок на тиждень')).to_be_visible()
        page.get_by_role('link', name='Список покупок на тиждень').click()
        expect(page.get_by_role('heading', name='Список покупок')).to_be_visible()
        expect(page.locator('.shopping-product').first).to_be_visible()
        shopping_response = context.request.get(BASE + '/api/meal-plan/shopping-list?week=1')
        assert shopping_response.status == 200, shopping_response.status
        shopping_data = shopping_response.json()
        assert shopping_data['state'] == 'ready', shopping_data['state']
        assert shopping_response.headers.get('cache-control') == 'no-store, private'
        assert len(shopping_data['items']) == page.locator('.shopping-product').count()
        for actual, expected_item in zip(page.locator('.shopping-product').all(), shopping_data['items']):
            assert expected_item['name'] in actual.inner_text()
            decimal = expected_item['quantity_grams'].rstrip('0').rstrip('.')
            assert decimal + ' г' in actual.inner_text()
        expect(page.get_by_text('Кількості розраховані за призначеним раціоном', exact=False)).to_be_visible()
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                page.set_viewport_size({'width': width, 'height': 1000})
                page.emulate_media(color_scheme=theme)
                expect(page.locator('.shopping-product').first).to_be_visible()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        page.set_viewport_size({'width': 1440, 'height': 1000})
        page.emulate_media(color_scheme='light')
        page.get_by_role('link', name='← Повернутися до раціону').click()
        expect(page.get_by_role('heading', name='Раціон', exact=True)).to_be_visible()
        held_shopping = []
        page.route('**/api/meal-plan/shopping-list*', lambda route: held_shopping.append(route))
        page.get_by_role('link', name='Список покупок на тиждень').click()
        expect(page.locator('.shopping-product').first).to_be_visible()
        wait_for_held_route(page, held_shopping, 'повернення до списку має перевірити актуальні права та дані')
        release_held_routes(page, held_shopping, '**/api/meal-plan/shopping-list*')
        page.unroute('**/api/meal-plan/shopping-list*')
        page.get_by_role('combobox', name='Тиждень раціону').select_option('2')
        expect(page).to_have_url(BASE + '/app/meal-plan/shopping-list?week=2')
        expect(page.locator('.shopping-product').first).to_be_visible()
        page.reload()
        expect(page.locator('.shopping-product').first).to_be_visible()
        page.route('**/api/meal-plan/shopping-list*', lambda route: route.fulfill(
            status=409, content_type='application/json',
            body='{"code":"shopping_list_unavailable"}'))
        page.get_by_role('button', name='Оновити', exact=True).click()
        expect(page.get_by_role('heading', name='Повний список покупок недоступний')).to_be_visible()
        expect(page.locator('.shopping-product')).to_have_count(0)
        page.unroute('**/api/meal-plan/shopping-list*')
        page.get_by_role('button', name='Спробувати ще раз', exact=True).click()
        expect(page.locator('.shopping-product').first).to_be_visible()
        page.get_by_role('link', name='← Повернутися до раціону').click()
        expect(page).to_have_url(BASE + '/app/meal-plan?week=2')
        expect(page.locator('.plan-heading h1')).to_have_text('Раціон')
        expect(page.get_by_role('combobox', name='Тиждень раціону')).to_have_value('2')
        page.get_by_role('combobox', name='Тиждень раціону').select_option('1')
        expect(page).to_have_url(BASE + '/app/meal-plan?week=1')
        expect(page.locator('.plan-days button').first).not_to_contain_text('День 8')
        week_one_first_day = ' '.join(page.locator('.plan-days button').first.inner_text().split())
        checks.append('T5.7a: PG exact grams, week selector, deep URL/reload, 409 clears UI, retry, responsive light/dark')
        expect(page.locator('.dish-link').first).to_be_visible()
        expect(page.locator('.dish').first.locator('a')).to_have_count(0)
        page.get_by_role('combobox', name='Тиждень раціону').select_option('2')
        expect(page.locator('.plan-days button').first).to_contain_text('День 8')
        page.locator('.plan-days button').nth(1).click()
        expect(page.locator('.day-heading .eyebrow')).to_have_text('День 9')
        page.go_back()
        expect(page.locator('.day-heading .eyebrow')).to_have_text('День 8')
        page.go_forward()
        expect(page.locator('.day-heading .eyebrow')).to_have_text('День 9')
        # Повернення на раніше перевірений тиждень має показати його кеш,
        # а не лишати попередній тиждень під URL нового запиту.
        page.get_by_role('combobox', name='Тиждень раціону').select_option('1')
        expect(page.locator('.plan-days button').first).not_to_contain_text('День 8')
        assert ' '.join(page.locator('.plan-days button').first.inner_text().split()) == week_one_first_day
        expect(page.locator('.day-heading .eyebrow')).to_have_text(re.search(r'День \d+', week_one_first_day).group())
        page.get_by_role('combobox', name='Тиждень раціону').select_option('2')
        expect(page.locator('.plan-days button').first).to_contain_text('День 8')
        expect(page.locator('.day-heading .eyebrow')).to_have_text('День 8')
        page.locator('.plan-days button').nth(1).click()
        expect(page.locator('.day-heading .eyebrow')).to_have_text('День 9')
        target = page.locator('.dish-link').nth(2)
        url = BASE + target.get_attribute('href')
        target.click()
        expect(page.locator('.recipe-page h1')).to_contain_text('Рис із овочами')
        expect(page.locator('.recipe-assigned')).to_have_text('Призначено: 1,25 порції')
        expect(page.locator('.recipe-intro')).to_contain_text('B/7')
        expect(page.locator('.recipe-ingredients')).to_contain_text('1050')
        expect(page.locator('.recipe-directions')).to_contain_text('1050.00 gram')
        expect(page.locator('.recipe-directions')).not_to_contain_text('{{')
        expect(page.locator('.recipe-equipment')).to_contain_text('5 л')
        expect(page.locator('.recipe-step-photo img')).to_be_visible()
        expect(page.locator('.recipe-cover img')).to_be_visible()
        page.get_by_role('link', name='← Повернутися до раціону').click()
        expect(page.get_by_role('combobox', name='Тиждень раціону')).to_have_value('2')
        expect(page.locator('.day-heading .eyebrow')).to_have_text('День 9')
        checks.append('Картка / ingredient без посилання / B physical quantities / tokens / фото / повернення до week2 day9')
        held_cached_recipe = []
        page.route('**/api/meal-plan/items/*/recipe', lambda route: held_cached_recipe.append(route))
        page.locator('.dish-link').nth(2).click()
        expect(page.locator('.recipe-ingredients')).to_be_visible()
        wait_for_held_route(page, held_cached_recipe, 'повторне відкриття рецепта має перевірити його актуальність без blank state')
        release_held_routes(page, held_cached_recipe, '**/api/meal-plan/items/*/recipe')
        page.unroute('**/api/meal-plan/items/*/recipe')
        page.get_by_role('link', name='← Повернутися до раціону').click()
        expect(page.get_by_role('heading', name='Раціон', exact=True)).to_be_visible()
        held = []
        page.route('**/api/meal-plan/items/*/recipe', lambda route: held.append(route))
        page.goto(url)
        expect(page.get_by_role('heading', name='Завантажуємо рецепт…')).to_be_visible()
        assert held
        held[0].continue_()
        page.unroute('**/api/meal-plan/items/*/recipe')
        expect(page.locator('.recipe-ingredients')).to_be_visible()
        page.reload()
        expect(page.locator('.recipe-page h1')).to_contain_text('Рис із овочами')
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                page.set_viewport_size({'width': width, 'height': 1000})
                page.emulate_media(color_scheme=theme)
                expect(page.locator('.recipe-page h1')).to_be_visible()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                page.screenshot(path=str(OUT / f'recipe-{width}-{theme}.png'), full_page=True)
        checks.append('Прямий URL / reload / 1440,390,320 light,dark без overflow')
        page.unroute('https://images.example.com/**')
        page.route('https://images.example.com/**', lambda route: route.abort())
        page.reload()
        expect(page.locator('.recipe-cover .photo-placeholder')).to_be_visible()
        expect(page.locator('.recipe-step-photo .photo-placeholder')).to_be_visible()
        page.route('**/api/meal-plan/items/*/recipe', lambda route: route.fulfill(status=503, content_type='application/json', body='{"code":"auth_unavailable"}'))
        page.reload()
        expect(page.get_by_role('alert')).to_be_visible()
        expect(page.locator('.recipe-ingredients')).to_have_count(0)
        page.unroute('**/api/meal-plan/items/*/recipe')
        page.get_by_role('button', name='Спробувати ще раз').click()
        expect(page.locator('.recipe-ingredients')).to_be_visible()
        fixture('replace')
        page.evaluate("window.dispatchEvent(new Event('focus'))")
        expect(page.get_by_role('heading', name='Рецепт недоступний', exact=True)).to_be_visible()
        expect(page.locator('.recipe-ingredients')).to_have_count(0)
        page.goto(BASE + '/app/meal-plan/items/2147483647/recipe')
        expect(page.get_by_role('heading', name='Рецепт недоступний', exact=True)).to_be_visible()
        checks.append('Фото fallback / error clears content / retry / заміна призначення / forged ID')
        page.goto(BASE + '/app/unknown-ux1-route')
        expect(page.get_by_role('heading', name='Сторінку не знайдено')).to_be_visible()
        page.get_by_role('link', name='Повернутися на головну').click()
        expect(page.get_by_role('heading', name='Сьогодні', exact=True)).to_be_visible()
        fixture('cooking')
        page.add_init_script("Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: undefined })")
        page.goto(BASE + '/app/meal-plan')
        expect(page.get_by_role('link', name='Помічник приготування', exact=True)).to_be_visible()
        page.get_by_role('link', name='Помічник приготування', exact=True).click()
        expect(page.get_by_role('heading', name='Помічник приготування', exact=True)).to_be_visible()
        expect(page.get_by_role('heading', name='1 день приготування')).to_be_visible()
        page.locator('.cook-session-choice').first.click()
        expect(page.get_by_role('heading', name='Що приготуємо')).to_be_visible()
        expect(page.get_by_text('Це синтетична демонстрація авторського вступу.')).to_be_visible()
        expect(page.get_by_text('Синтетична каструля для демо')).to_be_visible()
        expect(page.locator('.cook-equipment-grid img')).to_be_visible()
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                page.set_viewport_size({'width': width, 'height': 900})
                page.emulate_media(color_scheme=theme)
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                page.screenshot(path=str(OUT / f'cooking-{width}-{theme}.png'), full_page=True)
        page.get_by_role('button', name='Почати', exact=True).click()
        expect(page.locator('.cook-token')).to_contain_text('г')
        expect(page.locator('.cook-image-button img')).to_be_visible()
        wake = page.get_by_role('checkbox', name='Не вимикати екран')
        wake.check()
        expect(page.get_by_text('Цей браузер не підтримує режим')).to_be_visible()
        wake.uncheck()
        page.get_by_role('button', name='Почати', exact=True).click()
        expect(page.get_by_role('region', name='Активні таймери')).to_be_visible()
        page.get_by_role('button', name='Пауза', exact=True).click()
        expect(page.get_by_role('region', name='Активні таймери')).to_have_count(0)
        page.get_by_role('button', name='Почати', exact=True).click()
        page.get_by_role('button', name='Скинути', exact=True).click()
        expect(page.get_by_role('region', name='Активні таймери')).to_have_count(0)
        page.get_by_role('button', name='Почати', exact=True).click()
        page.get_by_role('button', name='Далі', exact=True).click()
        expect(page.get_by_role('heading', name='Завершіть приготування')).to_be_visible()
        assert page.evaluate("Object.keys(localStorage).filter(k => k.startsWith('tf:cooking:v1:')).length === 1")
        page.reload()
        expect(page.get_by_role('heading', name='Завершіть приготування')).to_be_visible()
        expect(page.get_by_role('region', name='Активні таймери')).to_be_visible()
        # UX-1: SPA unmount звільняє Wake Lock; history повертає persisted таймер.
        page.evaluate(WAKE_LOCK_PROBE)
        page.get_by_role('checkbox', name='Не вимикати екран').check()
        expect_wake_probe(page, 'calls', 1)
        page.evaluate('window.__wakeProbe.resolve()')
        expect_wake_probe(page, 'active', 1)
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Прогрес').click()
        expect(page.get_by_role('heading', name='Прогрес', exact=True)).to_be_visible()
        expect_wake_probe(page, 'active', 0)
        page.go_back()
        expect(page.get_by_role('heading', name='Завершіть приготування')).to_be_visible()
        expect(page.get_by_role('region', name='Активні таймери')).to_be_visible()
        assert page.evaluate('window.__wakeProbe.active === 0')
        checks.append('UX-1: SPA navigation releases Wake Lock, Back restores cooking stage/timer without document reload')
        page.get_by_role('button', name='← До вибору сесій').click()
        expect(page.get_by_role('heading', name='Помічник приготування', exact=True)).to_be_visible()
        expect(page.locator('.cook-template')).to_have_count(1)
        page.locator('.cook-session-choice').first.click()
        expect(page.get_by_role('heading', name='Завершіть приготування')).to_be_visible()
        expect(page.get_by_role('region', name='Активні таймери')).to_be_visible()
        page.get_by_role('button', name='Видалити локальний прогрес').click()
        assert page.evaluate("Object.keys(localStorage).filter(k => k.startsWith('tf:cooking:v1:')).length === 0")
        checks.append('T5.7b synthetic Filament-compatible published selection → ClientSession API → React; exact token/equipment; 1440/390/320 light/dark; local stage/timer reload+resume/discard; wake-lock unsupported')
        # Зміна user identity мусить прибрати попередній кеш до нового першого запиту.
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Раціон').click()
        expect(page.locator('.plan-calendar')).to_be_visible()
        held_days = []
        page.route('**/api/meal-plan/days*', lambda route: held_days.append(route))
        def switched_identity(route):
            route.fulfill(status=200, content_type='application/json', body=json.dumps({
                'user': {'id': '999999999', 'first_name': 'Інший', 'last_name': 'Клієнт', 'full_name': 'Інший Клієнт'},
                'client': {'id': '999999999', 'status': 'active'},
                'security': {'mfa_enabled': False, 'methods': [], 'recovery_codes_remaining': 0, 'recovery_used': False},
            }, ensure_ascii=False))
        page.route('**/api/me', switched_identity)
        page.wait_for_timeout(250)
        page.evaluate("window.dispatchEvent(new Event('focus'))")
        page.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
        expect(page.get_by_role('link', name='Відкрити профіль')).to_have_text('І')
        wait_for_held_route(page, held_days, 'зміна user ID має перемонтувати клієнтський route')
        expect(page.get_by_role('heading', name='Завантажуємо раціон…')).to_be_visible()
        expect(page.locator('.plan-calendar')).to_have_count(0)
        release_held_routes(page, held_days, '**/api/meal-plan/days*')
        page.unroute('**/api/me')
        page.unroute('**/api/meal-plan/days*')
        expect(page.locator('.plan-calendar')).to_be_visible()
        checks.append('Зміна user identity очищує in-memory cache до відповіді нового користувача')
        # Успішний logout очищує приватний UI синхронно з відповіддю endpoint.
        logout_calls = []
        page.route('**/api/auth/logout', lambda route: (logout_calls.append(route), route.fulfill(status=204)))
        page.get_by_role('button', name='Вийти', exact=True).click()
        expect(page).to_have_url(BASE + '/app/login')
        expect(page.get_by_role('status')).to_contain_text('Ви вийшли з акаунта.')
        expect(page.locator('.today-menu')).to_have_count(0)
        assert logout_calls, 'logout endpoint має повернути успішну відповідь до очищення UI'
        page.unroute('**/api/auth/logout')
        # Перезавантажуємо чинну синтетичну сесію, щоб окремо перевірити backend revoke/401.
        page.goto(BASE + '/app')
        expect(page.locator('.client-header')).to_be_visible()
        fixture('revoke')
        page.evaluate("window.dispatchEvent(new Event('focus'))")
        expect(page).to_have_url(BASE + '/app/login')
        expect(page.get_by_role('status')).to_contain_text('Сесія завершилася')
        expect(page.locator('.recipe-ingredients')).to_have_count(0)
        for width in (1440, 390, 320):
            page.set_viewport_size({'width': width, 'height': 900})
            page.emulate_media(color_scheme='dark')
            expect(page.get_by_role('heading', name='Увійдіть у свій кабінет')).to_be_visible()
            assert page.locator('.auth-card').evaluate('(e) => getComputedStyle(e).backgroundColor') == 'rgb(32, 52, 42)'
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        assert not errors, errors
        checks.append('Revoked ClientSession / приватний вміст прибрано')
        result = {'result': 'PASS', 'browser': browser.version, 'checks': checks}
        (OUT / 'browser-checks.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
        print(json.dumps(result, ensure_ascii=False))
    finally:
        browser.close()
