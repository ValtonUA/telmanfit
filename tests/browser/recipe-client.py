"""Chromium: справжня React/API/ClientSession на disposable synthetic PostgreSQL."""
import json
import os
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
        page.get_by_role('link', name='Відкрити профіль').click()
        expect(page.get_by_role('heading', name='Профіль', exact=True)).to_be_visible()
        page.get_by_role('link', name='Моя анкета').click()
        expect(page.get_by_role('heading', name='Початкова анкета', exact=True)).to_be_visible()
        page.go_back()
        expect(page.get_by_role('heading', name='Профіль', exact=True)).to_be_visible()
        page.go_forward()
        expect(page.get_by_role('heading', name='Початкова анкета', exact=True)).to_be_visible()
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
        expect(page.get_by_role('combobox', name='Тиждень раціону')).to_have_value('2')
        page.get_by_role('combobox', name='Тиждень раціону').select_option('1')
        checks.append('T5.7a: PG exact grams, week selector, deep URL/reload, 409 clears UI, retry, responsive light/dark')
        expect(page.locator('.dish-link').first).to_be_visible()
        expect(page.locator('.dish').first.locator('a')).to_have_count(0)
        page.get_by_role('combobox', name='Тиждень раціону').select_option('2')
        page.locator('.plan-days button').nth(1).click()
        expect(page.locator('.day-heading .eyebrow')).to_have_text('День 9')
        page.go_back()
        expect(page.locator('.day-heading .eyebrow')).to_have_text('День 8')
        page.go_forward()
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
        page.wait_for_function('window.__wakeProbe.calls === 1')
        page.evaluate('window.__wakeProbe.resolve()')
        page.wait_for_function('window.__wakeProbe.active === 1')
        page.get_by_role('navigation', name='Основна навігація').get_by_role('link', name='Прогрес').click()
        expect(page.get_by_role('heading', name='Прогрес', exact=True)).to_be_visible()
        page.wait_for_function('window.__wakeProbe.active === 0')
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
