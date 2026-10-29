"""UX-3 browser acceptance on the disposable PG18 fixture with real MFA."""
import base64
import hashlib
import hmac
import json
import os
import shutil
import struct
import subprocess
from pathlib import Path
import time
from contextlib import nullcontext

from playwright.sync_api import expect, sync_playwright
from meal_plans import run_meal_plans, verify_meal_plan_revocation
from readiness import TRACKER

BASE = 'http://127.0.0.1:8090'


def otp(secret, offset=0):
    key = base64.b32decode(secret)
    digest = hmac.new(key, struct.pack('>Q', int(time.time() // 30) + offset), hashlib.sha1).digest()
    start = digest[-1] & 15
    return f'{(struct.unpack(">I", digest[start:start + 4])[0] & 0x7fffffff) % 1000000:06d}'


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'), args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1000}, has_touch=True)
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

    assert context.request.get(BASE + '/admin/meal-plans').status in (302, 403)
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
    if os.environ.get('UX4_WEEK_BROWSER_ONLY') == '1':
        from meal_plans import fixture
        from week_repetition import run_week_repetition

        def submit(dialog, label):
            with (page.expect_navigation(wait_until='load') if label != 'Скасувати' and '/days/' not in page.url else nullcontext()):
                with page.expect_response(lambda response: '/livewire/update' in response.url and response.request.method == 'POST') as response:
                    dialog.get_by_role('button', name=label, exact=True).click()
            assert response.value.status == 200
            dialog.wait_for(state='hidden')

        seed = json.loads(fixture('seed'))
        repeat_seed = json.loads(fixture('repeat-seed', seed['type'], seed['extra_type'], seed['food']))
        page.set_viewport_size({'width': 390, 'height': 844})
        day_url = BASE + '/admin/meal-plan-versions/' + str(repeat_seed['version']) + '/days/' + str(repeat_seed['day'])
        page.goto(day_url)
        expect(page.get_by_role('button', name='Опублікувати', exact=True)).to_have_count(0)
        page.goto(BASE + '/admin/meal-plan-versions/' + str(repeat_seed['version']))
        publish = page.get_by_role('button', name='Опублікувати', exact=True)
        if not any(publish.nth(index).is_visible() for index in range(publish.count())):
            raise AssertionError('Publish action is not visible on the 390px week overview:\n'+page.locator('body').aria_snapshot()[-8000:])
        page.set_viewport_size({'width': 1440, 'height': 1000})
        page.goto(BASE + '/admin/meal-plan-versions/' + str(repeat_seed['version']))
        from meal_plans import management
        management(page, 'Позиції')
        page.get_by_role('menuitem', name='Додати позицію', exact=True).click()
        position_dialog = page.get_by_role('dialog').filter(has=page.get_by_role('heading', name='Додати позицію', exact=True))
        expect(position_dialog.get_by_role('heading', name='Додати позицію', exact=True)).to_be_visible()
        submit(position_dialog, 'Скасувати')
        management(page, 'Додати прийом')
        meal_dialog = page.get_by_role('dialog').filter(has=page.get_by_role('heading', name='Додати прийом', exact=True))
        expect(meal_dialog.get_by_role('heading', name='Додати прийом', exact=True)).to_be_visible()
        submit(meal_dialog, 'Скасувати')
        run_week_repetition(page, BASE, seed, '1440-light', submit)
        print('UX-4 week actions (positions + repetition) Chromium regression PASS', flush=True)
    elif os.environ.get('UX4_BROWSER_ONLY') == '1':
        from meal_plans import fixture
        from day_editor import run_day_editor

        def submit(dialog, label):
            with (page.expect_navigation(wait_until='load') if label != 'Скасувати' and '/days/' not in page.url else nullcontext()):
                with page.expect_response(lambda response: '/livewire/update' in response.url and response.request.method == 'POST') as response:
                    dialog.get_by_role('button', name=label, exact=True).click()
            assert response.value.status == 200
            dialog.wait_for(state='hidden')

        seed = json.loads(fixture('seed'))
        only_suffixes = set(filter(None, os.environ.get('UX4_BROWSER_SUFFIX', '').split(',')))
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                suffix = f'{width}-{theme}'
                if only_suffixes and suffix not in only_suffixes:
                    continue
                page.set_viewport_size({'width': width, 'height': 1000})
                page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
                run_day_editor(page, BASE, seed, suffix, submit, compact_only=True)
                assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
                print(f'UX-4 standalone Chromium: {suffix} PASS', flush=True)
    else:
        state = run_meal_plans(page, BASE)
        for width in (1440, 390, 320):
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('localStorage.setItem("filament:theme:mode", "dark")')
            page.goto(BASE + '/admin/meal-plan-versions/' + str(state['publication_version']) + '/days/' + str(state['publication_day']))
            expect(page.get_by_role('heading', name='Каталог страв і продуктів', exact=True)).to_be_visible()
            assert page.evaluate('document.documentElement.classList.contains("dark")')
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.screenshot(path=str(Path('docs/evidence/meal-plans') / f'day-editor-{width}-dark.png'), full_page=True)
            print(f'Day editor Chromium: {width}-dark responsive PASS', flush=True)
    if os.environ.get('UX4_BROWSER_ONLY') != '1' and os.environ.get('UX4_WEEK_BROWSER_ONLY') != '1':
        headers = {'X-Livewire': 'true', 'Accept': 'application/json'}
        assert context.request.post(BASE + '/admin/livewire/update', data=state['payload'], headers=headers).status == 419
        subprocess.run(['php', 'tests/browser/food-catalog-fixture.php', 'revoke'], check=True)
        verify_meal_plan_revocation(page, BASE, state)
    assert not errors, errors
    if os.environ.get('UX4_WEEK_BROWSER_ONLY') == '1':
        pass
    elif os.environ.get('UX4_BROWSER_ONLY') == '1':
        print('UX-4 browser-only Chromium acceptance PASS', flush=True)
    else:
        print('UX-3 standalone Chromium acceptance PASS', flush=True)
    context.close()
    browser.close()
