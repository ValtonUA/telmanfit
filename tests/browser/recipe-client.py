"""Chromium: справжня React/API/ClientSession на disposable synthetic PostgreSQL."""
import json
import os
from pathlib import Path
import subprocess
import time
from playwright.sync_api import sync_playwright, expect

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
        page.get_by_label('Електронна пошта', exact=True).fill('t52-browser@example.invalid')
        page.get_by_role('button', name='Отримати код', exact=True).click()
        expect(page.get_by_label('Код із листа', exact=True)).to_be_visible()
        php('artisan', 'queue:work', '--stop-when-empty', '--tries=1', '--sleep=0')
        code = php('tests/browser/auth5-fixture.php', 'mail', 't52-browser@example.invalid')
        page.get_by_label('Код із листа', exact=True).fill(code)
        page.get_by_role('button', name='Підтвердити', exact=True).click()
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
        expect(page.locator('.dish-link').first).to_be_visible()
        expect(page.locator('.dish').first.locator('a')).to_have_count(0)
        page.get_by_role('combobox', name='Тиждень раціону').select_option('2')
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
        fixture('revoke')
        page.evaluate("window.dispatchEvent(new Event('focus'))")
        expect(page).to_have_url(BASE + '/app/login')
        expect(page.get_by_role('status')).to_contain_text('Сесія завершилася')
        expect(page.locator('.recipe-ingredients')).to_have_count(0)
        assert not errors, errors
        checks.append('Revoked ClientSession / приватний вміст прибрано')
        result = {'result': 'PASS', 'browser': browser.version, 'checks': checks}
        (OUT / 'browser-checks.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
        print(json.dumps(result, ensure_ascii=False))
    finally:
        browser.close()
