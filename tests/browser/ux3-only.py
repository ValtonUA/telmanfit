"""UX-3 browser acceptance on the disposable PG18 fixture with real MFA."""
import base64
import hashlib
import hmac
import shutil
import struct
import time

from playwright.sync_api import expect, sync_playwright
from meal_plans import run_meal_plans
from readiness import TRACKER

BASE = 'http://127.0.0.1:8090'


def otp(secret, offset=0):
    key = base64.b32decode(secret)
    digest = hmac.new(key, struct.pack('>Q', int(time.time() // 30) + offset), hashlib.sha1).digest()
    start = digest[-1] & 15
    return f'{(struct.unpack(">I", digest[start:start + 4])[0] & 0x7fffffff) % 1000000:06d}'


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'), args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    context.add_init_script(TRACKER)
    page = context.new_page()
    page.set_default_timeout(15000)
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    for _ in range(50):
        try:
            page.goto(BASE + '/admin/login')
            break
        except Exception:
            time.sleep(.2)

    def primary():
        page.goto(BASE + '/admin/login')
        page.locator('[name=email]').fill('recipe-browser@example.invalid')
        page.locator('[name=password]').fill('Synthetic-Recipe-Only!')
        page.get_by_role('button', name='Увійти', exact=True).click()
        page.wait_for_url('**/admin/auth/challenge')

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
    run_meal_plans(page, BASE)
    assert not errors, errors
    print('UX-3 standalone Chromium acceptance PASS', flush=True)
    context.close()
    browser.close()
