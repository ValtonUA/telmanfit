"""Chromium + штатні MFA/Livewire; тільки synthetic fixture, без HAR/секретів у evidence."""
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import shutil
import struct
import subprocess
import time
from playwright.sync_api import sync_playwright, expect

base = 'http://127.0.0.1:8097'
url = base + '/admin/recipe-batches?version=' + os.environ['BATCH_BROWSER_VERSION']
output = Path('docs/evidence/recipe-batches')
output.mkdir(parents=True, exist_ok=True)
checks = []


def otp(secret, offset=0):
    key = base64.b32decode(secret)
    digest = hmac.new(key, struct.pack('>Q', int(time.time() // 30) + offset), hashlib.sha1).digest()
    start = digest[-1] & 15
    return f'{(struct.unpack(">I", digest[start:start+4])[0] & 0x7fffffff) % 1000000:06d}'


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'), args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    page = context.new_page()
    page.set_default_timeout(15000)
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    for _ in range(50):
        try:
            page.goto(base + '/admin/login')
            break
        except Exception:
            time.sleep(.2)

    def primary():
        page.goto(base + '/admin/login')
        page.locator('[name=email]').fill('recipe-browser@example.invalid')
        page.locator('[name=password]').fill('Synthetic-Recipe-Only!')
        page.get_by_role('button', name='Увійти', exact=True).click()
        page.wait_for_url('**/admin/auth/challenge')

    assert context.request.get(url).status in (302, 403)
    primary()
    page.get_by_role('button', name='Створити ключ Authenticator').click()
    secret = page.locator('#totp-secret').inner_text()
    if time.time() % 30 >= 26:
        time.sleep(30 - time.time() % 30 + .1)
    page.locator('[name=code]').fill(otp(secret, -1))
    page.get_by_role('button', name='Підтвердити', exact=True).click()
    expect(page.get_by_role('heading', name='Збережіть резервні коди')).to_be_visible()
    primary()
    page.locator('[name=code]').fill(otp(secret))
    page.get_by_role('button', name='Підтвердити вхід').click()
    page.wait_for_url('**/admin')
    checks.append('Штатний password + MFA; guest editor недоступний')

    def action(target, name, exact=True):
        with target.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST') as response:
            target.get_by_role('button', name=name, exact=exact).click()
        assert response.value.status == 200
        expect(target.locator('.batch-editor fieldset')).not_to_be_disabled()
        if name == 'Оновити preview':
            expect(target.get_by_text('Preview готовий. Збереження та публікація — окремі явні дії.', exact=True)).to_be_visible()

    page.goto(url)
    action(page, 'Оновити preview')
    expect(page.get_by_test_id('week-0')).to_contain_text('1050.00 g')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('525.00 g')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Режим week 1', exact=True).select_option('manual')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Кількість week 1', exact=True).fill('1000.00')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('500.00 g')
    action(page, 'Підтверджую manual review і зберігаю')
    page.reload()
    action(page, 'Оновити preview')
    expect(page.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
    checks.append('150→1050→525; manual B=1000→C=500; save/reload')

    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Режим half_week 1', exact=True).select_option('manual')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Кількість half_week 1', exact=True).fill('600.00')
    action(page, 'Підтверджую manual review і зберігаю')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('A грами 1', exact=True).fill('160.00')
    expect(page.get_by_test_id('week-0')).to_contain_text('1000.00 g')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('600.00 g')
    action(page, 'Зберегти чернетку')
    page.reload()
    action(page, 'Оновити preview')
    expect(page.get_by_label('Кількість half_week 1', exact=True)).to_have_value('600.00')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('button', name='Reset to auto', exact=True).first.click()
    expect(page.get_by_test_id('week-0')).to_contain_text('1120.00 g')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('600.00 g')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('button', name='Reset to auto', exact=True).click()
    expect(page.get_by_test_id('half_week-0')).to_contain_text('560.00 g')
    action(page, 'Зберегти чернетку')
    # Повернути manual B для подальшого explicit refresh scenario.
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Режим week 1', exact=True).select_option('manual')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Кількість week 1', exact=True).fill('1000.00')
    action(page, 'Підтверджую manual review і зберігаю')
    checks.append('Manual C=600 зберігається після A=160/reload; reset B=1120, reset C=560')

    second = context.new_page()
    second.goto(url)
    action(second, 'Оновити preview')
    action(page, 'Зберегти чернетку')
    action(second, 'Зберегти чернетку')
    expect(second.get_by_role('alert')).to_contain_text('Конфлікт ревізій')
    checks.append('Дві вкладки: stale revision не перезаписує першу')
    second.close()

    # Signed preview cannot silently accept a reference change after its response.
    subprocess.run(['php', 'tests/browser/recipe-batch-fixture.php', 'catalog-change'], check=True)
    action(page, 'Зберегти чернетку')
    expect(page.get_by_role('alert')).to_contain_text('Preview застарів')
    action(page, 'Оновити preview')
    expect(page.get_by_text('Довідник змінився · потрібна явна згода', exact=True)).to_be_visible()
    action(page, 'Прийняти diff і Refresh')
    expect(page.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
    checks.append('Stale source response → відмова; explicit diff/Refresh зберігає manual')

    action(page, 'Додати інгредієнт')
    action(page, 'Зберегти чернетку')
    page.reload()
    action(page, 'Оновити preview')
    expect(page.get_by_text('Неповний розрахунок:', exact=False)).to_be_visible()
    expect(page.get_by_label('Інгредієнт 2', exact=True)).to_have_value('')
    checks.append('Pending row: save/reload; неповний підсумок')

    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            page.reload()
            action(page, 'Оновити preview')
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), (width, theme)
            assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            page.screenshot(path=str(output / f'editor-{width}-{theme}.png'), full_page=True)
            checks.append(f'{width}px {theme}: no overflow')
    assert not errors, errors
    browser.close()
output.joinpath('checks.json').write_text(json.dumps({'checks': checks, 'page_errors': errors}, ensure_ascii=False, indent=2) + '\n')
print('Recipe editor browser PASS: ' + str(len(checks)) + ' checks', flush=True)
