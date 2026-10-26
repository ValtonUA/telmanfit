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
from readiness import TRACKER, metrics as readiness_metrics

base = 'http://127.0.0.1:' + os.environ.get('ASSIGNMENT_BROWSER_PORT', '8090')
f = json.loads(os.environ['ASSIGNMENT_BROWSER_FIXTURE'])
url = base + '/admin/clients/' + str(f['client']) + '/meal-plans'
output = Path('/tmp/telmanfit-t46-browser')
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
    context.add_init_script(TRACKER)
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
        page.locator('[name=email]').fill('copy-browser@example.invalid')
        page.locator('[name=password]').fill('Synthetic-Copy-Only!')
        page.get_by_role('button', name='Увійти', exact=True).click()
        page.wait_for_url('**/admin/auth/challenge')

    assert context.request.get(url).status in (302, 403)
    guest = browser.new_context()
    for path in ('/admin/meal-plans', '/admin/meal-plans/create'):
        assert guest.request.get(base + path).status in (302, 403)
    guest.close()
    primary()
    for path in ('/admin/meal-plans', '/admin/meal-plans/create'):
        assert context.request.get(base + path).status in (302, 403)
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

    from readiness import livewire_ready
    from meal_plans import management, settle_overlays
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('(v) => localStorage.setItem("filament:theme:mode",v)', theme)
            page.goto(base + '/admin/meal-plan-versions/' + str(f['version']))
            management(page, 'Створити персональну копію')
            page.get_by_role('button', name='Клієнт', exact=False).last.click()
            page.get_by_role('option').filter(has_text='Клієнт #' + str(f['client']) + ' —').click()
            page.get_by_label('Назва копії', exact=False).fill('Персональна копія ' + str(width) + theme)
            page.get_by_role('button', name='Перевірити копію', exact=True).click()
            expect(page.get_by_text('Призначення не створюється.', exact=False)).to_be_visible()
            settle_overlays(page)
            page.screenshot(path=str(output / f'copy-{width}-{theme}.png'), full_page=True)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.get_by_role('button', name='Скасувати', exact=True).click()
            management(page, 'Створити персональну копію')
            page.get_by_role('button', name='Клієнт', exact=False).last.click()
            page.get_by_role('option').filter(has_text='Клієнт #' + str(f['client']) + ' —').click()
            page.get_by_role('button', name='Перевірити копію', exact=True).click()
            expect(page.get_by_text('Призначення не створюється.', exact=False)).to_be_visible()
            with page.expect_navigation(wait_until='load'):
                page.get_by_role('button', name='Так, створити копію', exact=True).click()
            assert page.url != base + '/admin/meal-plan-versions/' + str(f['version'])
            expect(page.get_by_text('Чернетка', exact=True)).to_be_visible()
    assert not errors, errors
    print('CoW Chromium 1440/390/320 light/dark preview/cancel/confirm/navigation PASS')
    browser.close()
