"""UX-2: реальні Filament SPA/MFA/Livewire, лише disposable PostgreSQL fixtures."""
import base64
import hashlib
import hmac
import json
import re
from pathlib import Path
import shutil
import struct
import subprocess
import time
from playwright.sync_api import sync_playwright, expect
from readiness import TRACKER, livewire_ready

NAV_TRACKER = "window.__ux2Navigations = 0; document.addEventListener('livewire:navigated', () => window.__ux2Navigations++);"


def fixture(mode, *args):
    return subprocess.check_output(['php', 'tests/browser/filament-navigation-fixture.php', mode, *map(str, args)], text=True).strip()


def login(page, base, email, recovery=None):
    def primary():
        response = page.goto(base + '/admin/login')
        assert response.status == 200, (response.status, page.title(), page.locator('h1').all_text_contents())
        assert response.body(), ('empty guest response', response.headers.get('content-type'), response.headers.get('content-length'))
        expect(page.locator('input[name=email]')).to_be_visible()
        page.locator('input[name=email]').fill(email)
        page.locator('input[name=password]').fill('Synthetic-UX2-only!')
        page.locator('form[action$="/primary"] button').click()
    def otp(secret, offset=0):
        key = base64.b32decode(secret)
        digest = hmac.new(key, struct.pack('>Q', int(time.time() // 30) + offset), hashlib.sha1).digest()
        start = digest[-1] & 15
        return f'{(struct.unpack(">I", digest[start:start + 4])[0] & 0x7fffffff) % 1000000:06d}'
    if recovery is not None:
        primary()
        page.locator('[name=recovery]').check()
        page.locator('input[name=code]').fill(recovery)
        page.get_by_role('button', name='Підтвердити вхід').click()
        expect(page).to_have_url(base + '/admin')
        page.wait_for_function('window.__ux2Navigations > 0')
        return []
    primary()
    page.get_by_role('button', name='Створити ключ Authenticator').click()
    secret = page.locator('#totp-secret').inner_text()
    # Попередній TOTP slot потрібен для наступного login без replay. Не дати
    # межі 30 s перетворити його на slot -2 між fill і серверною перевіркою.
    if time.time() % 30 >= 26:
        time.sleep(30 - time.time() % 30 + .1)
    page.locator('input[name=code]').fill(otp(secret, -1))
    page.get_by_role('button', name='Підтвердити', exact=True).click()
    expect(page.get_by_role('heading', name='Збережіть резервні коди')).to_be_visible()
    codes = page.locator('[data-sensitive] code').all_inner_texts()
    primary()
    page.locator('input[name=code]').fill(otp(secret))
    page.get_by_role('button', name='Підтвердити вхід').click()
    expect(page).to_have_url(base + '/admin')
    page.wait_for_function('window.__ux2Navigations > 0')
    return codes


def navigate(page, action, url):
    before = page.evaluate('window.__ux2Navigations')
    action()
    expect(page).to_have_url(url)
    page.wait_for_function('(before) => window.__ux2Navigations > before', arg=before)
    livewire_ready(page)


def sidebar(page, base, path):
    link = page.locator(f'.fi-sidebar a[href="{base}{path}"]').first
    opener = page.locator('.fi-topbar-open-sidebar-btn:visible, .fi-topbar-open-collapse-sidebar-btn:visible')
    if opener.count():
        opener.first.click()
    page.wait_for_function('document.querySelector(".fi-sidebar").getBoundingClientRect().left >= -1')
    expect(link).to_be_visible()
    expect(link).to_have_attribute('wire:navigate', '')
    navigate(page, link.click, base + path)
    expect(link).to_have_attribute('aria-current', 'page')
    expect(page.locator('main')).to_be_visible()


def run_navigation(parent, base):
    f = json.loads(fixture('seed'))
    browser = parent.context.browser
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    context.add_init_script(TRACKER + NAV_TRACKER)
    page = context.new_page()
    page.set_default_timeout(15000)
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    login(page, base, 'ux2-navigation@example.invalid')
    output = Path('docs/evidence/ux2-navigation')
    output.mkdir(parents=True, exist_ok=True)
    checks = []
    week = base + f'/admin/meal-plan-versions/{f["version"]}'
    recipe = base + f'/admin/recipe-batches?version={f["recipe"]}'
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            page.goto(base + '/admin/clients')
            page.wait_for_function('window.__ux2Navigations > 0')
            page.evaluate('window.__ux2Document = "persistent"')
            documents = []
            def document_request(request):
                if request.is_navigation_request() and request.frame == page.main_frame:
                    documents.append(request.url)
            page.on('request', document_request)
            for path in ('/admin/invitations', '/admin/meal-plans', '/admin/recipe-batches', '/admin/catalog/ingredients', '/admin/clients', '/admin/recipe-batches'):
                sidebar(page, base, path)
                assert page.evaluate('window.__ux2Document === "persistent"')
                assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            navigate(page, lambda: page.get_by_role('combobox', name='Версія рецепта', exact=True).select_option(recipe), recipe)
            expect(page.locator('.batch-editor fieldset')).to_be_visible()
            with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                page.get_by_role('button', name='Оновити preview', exact=True).click()
            livewire_ready(page)
            expect(page.get_by_text('Preview готовий.', exact=False)).to_be_visible()
            if width == 1440 and theme == 'light':
                page.get_by_role('textbox', name='Назва', exact=True).fill('UX2 відредагований рецепт')
                with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                    page.get_by_role('button', name='Оновити preview', exact=True).click()
                livewire_ready(page)
                with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                    page.get_by_role('button', name='Підтвердити ручні значення й обладнання', exact=True).click()
                livewire_ready(page)
                expect(page.get_by_text('Чернетку збережено.', exact=True)).to_be_visible()
                assert fixture('title', f['recipe']) == 'UX2 відредагований рецепт'
                before = int(fixture('count'))
                navigate(page, page.get_by_role('button', name='Нова чернетка A/B/C', exact=True).click,
                         re.compile(re.escape(base) + r'/admin/recipe-batches\?version=[0-9]+'))
                assert page.url != recipe
                assert int(fixture('count')) == before + 1
                checks.append('recipe edit persisted and create redirect stays SPA; exactly one new version')
            sidebar(page, base, '/admin/meal-plans')
            navigate(page, page.locator(f'a[href="{base}/admin/meal-plans/{f["plan"]}"]').first.click, base + f'/admin/meal-plans/{f["plan"]}')
            expect(page.get_by_text('Точні версії', exact=True)).to_be_visible()
            navigate(page, page.locator(f'a[href="{week}"]').first.click, week)
            expect(page.locator('.plan-editor')).to_be_visible()
            navigate(page, page.get_by_role('link', name='Заповнити день', exact=True).first.click, week + f'/days/{f["day"]}')
            expect(page.locator('.plan-day-layout')).to_be_visible()
            page.locator('.plan-catalog-add').first.click()
            modal = page.get_by_role('dialog', name='Додати позицію', exact=True)
            try:
                expect(modal.get_by_role('heading', name='Додати позицію', exact=True)).to_be_visible()
            except Exception:
                print('UX2 JavaScript errors: ' + json.dumps(errors), flush=True)
                raise
            modal.get_by_role('button', name='Скасувати', exact=True).click()
            expect(modal.get_by_role('heading', name='Додати позицію', exact=True)).to_be_hidden()
            # Alpine handles повторно ініціалізовані на новому day DOM.
            handle = page.locator('.plan-catalog .plan-drag-handle').first
            handle.dispatch_event('dragstart', {'dataTransfer': page.evaluate_handle('new DataTransfer()')})
            expect(page.locator('.plan-meal').first).to_have_class(re.compile('plan-drop-ready'))
            handle.dispatch_event('dragend')
            assert not documents, documents
            assert page.evaluate('window.__ux2Document === "persistent"')
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.screenshot(path=str(output / f'day-{width}-{theme}.png'), full_page=True)
            page.remove_listener('request', document_request)
            checks.append(f'{width}/{theme}: sidebar, recipe selector/preview, exact week/day, modal cancel, drag lifecycle, no document navigation')
    day_url = page.url
    sidebar(page, base, '/admin/clients')
    with page.expect_response(lambda r: r.request.is_navigation_request() and r.url == day_url and r.status == 200):
        page.go_back()
    expect(page.locator('.plan-day-layout')).to_be_visible()
    # History guard запускає окремий native reload після popstate. Сам URL
    # змінюється раніше: дочекатися серверної відповіді, як і для Back.
    with page.expect_response(lambda r: r.request.is_navigation_request()
                              and r.url == base + '/admin/clients' and r.status == 200):
        page.go_forward()
    expect(page).to_have_url(base + '/admin/clients')
    expect(page.get_by_role('heading', name='Картки клієнтів', exact=True)).to_be_visible()
    page.reload()
    expect(page.get_by_role('heading', name='Картки клієнтів', exact=True)).to_be_visible()
    assert page.evaluate('document.documentElement.classList.contains("dark")')
    checks.append('authorized Back/Forward and refresh retain route/theme; cached Back rechecks server')
    page.goto(day_url)
    page.wait_for_function('window.__ux2Navigations > 0')
    # Повернення кешованої приватної сторінки перевіряє серверну idle expiry.
    previous = page.url
    sidebar(page, base, '/admin/clients')
    fixture('expire')
    with page.expect_response(lambda r: r.request.is_navigation_request() and r.status == 403):
        page.go_back()
    expect(page.locator('.plan-editor')).to_have_count(0)
    assert page.url == previous
    checks.append('cached Back after admin idle expiry: server 403, private editor absent')
    context.close()
    fixture('revoke-user')
    other = browser.new_context(viewport={'width': 390, 'height': 900})
    other.add_init_script(TRACKER + NAV_TRACKER)
    revoked = other.new_page()
    codes = login(revoked, base, 'ux2-navigation-revoke@example.invalid')
    sidebar(revoked, base, '/admin/clients')
    revoked.on('pageerror', lambda error: errors.append(str(error)))
    revoked.get_by_role('button', name='Меню користувача').click()
    revoked.get_by_role('menuitem', name='Вийти', exact=True).click()
    expect(revoked).to_have_url(base + '/admin/login')
    login(revoked, base, 'ux2-navigation-revoke@example.invalid', recovery=codes[0])
    sidebar(revoked, base, '/admin/clients')
    fixture('revoke')
    response = other.request.get(base + '/admin/invitations', headers={'X-Livewire-Navigate': 'true'})
    assert response.status == 403
    other.close()
    assert not errors, errors
    checks.append('revoked auth_version on SPA GET denied; native logout returns login')
    (output / 'checks.json').write_text(json.dumps({'result': 'PASS', 'checks': checks, 'page_errors': errors}, ensure_ascii=False, indent=2) + '\n')
    print('Filament SPA navigation PASS: ' + json.dumps(checks, ensure_ascii=False), flush=True)


if __name__ == '__main__':
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=shutil.which('chromium'), args=['--no-sandbox'])
        seed = browser.new_page()
        run_navigation(seed, 'http://127.0.0.1:8090')
        browser.close()
