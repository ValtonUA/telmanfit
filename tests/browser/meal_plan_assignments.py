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
output = Path('/tmp/telmanfit-t45-browser')
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
        page.locator('[name=email]').fill('assignment-browser@example.invalid')
        page.locator('[name=password]').fill('Synthetic-Assignment-Only!')
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

    from readiness import livewire_click
    from meal_plans import settle_overlays

    def fixture(*args):
        return subprocess.check_output(['php', 'tests/browser/meal-plan-assignment-fixture.php', *map(str, args)], text=True)

    def click(name, method='callMountedAction'):
        livewire_click(page, page.get_by_role('button', name=name, exact=True), method)

    def form(name, start):
        click(name, 'mountAction')
        dialog = page
        dialog.get_by_role('button', name='Опублікована версія', exact=False).click()
        page.get_by_role('option').filter(has_text='Синтетичний раціон призначення').first.click()
        dialog.get_by_label('Дата початку', exact=False).fill(start)
        click('Перевірити наслідки')
        expect(page.get_by_text('Підтвердження призначення', exact=True)).to_be_visible()
        settle_overlays(page)

    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            client = fixture('client').strip()
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            page.goto(base + '/admin/clients/' + client)
            page.get_by_role('link', name='Раціони клієнта', exact=True).click()
            expect(page.get_by_text('Раціони ще не призначені', exact=True)).to_be_visible()
            form('Призначити раціон', f['today'])
            before = fixture('state', client)
            assert json.loads(before)['assignments'] == []
            page.screenshot(path=str(output / f'first-{width}-{theme}.png'), full_page=True)
            click('Скасувати', 'unmountAction')
            assert fixture('state', client) == before
            form('Призначити раціон', f['today'])
            click('Так, підтвердити призначення')
            expect(page.get_by_text('Безстроково', exact=True)).to_be_visible()
            form('Замінити раціон', f['tomorrow'])
            expect(page.get_by_text('Завершиться', exact=False)).to_be_visible()
            page.screenshot(path=str(output / f'replace-{width}-{theme}.png'), full_page=True)
            click('Так, підтвердити призначення')
            state = json.loads(fixture('state', client))
            assert len(state['assignments']) == len(state['events']) == 2
            assert state['assignments'][0]['replaced_by_assignment_id'] == state['assignments'][1]['id']
            assert state['assignments'][0]['end_date'] == f['today']
            page.reload()
            expect(page.get_by_text('Безстроково', exact=True)).to_be_visible()
            # Штатний Filament table має локальний horizontal scroll на вузькому екрані.
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            checks.append(f'{width}px {theme}: first/cancel/confirm/replace/history/reload')

    client = fixture('client').strip()
    page.goto(base + '/admin/clients/' + client + '/meal-plans')
    form('Призначити раціон', f['today'])
    fixture('stale', client, f['version'])
    before = fixture('state', client)
    click('Так, підтвердити призначення')
    expect(page.get_by_text('Розклад або картка клієнта змінилися.', exact=False)).to_be_visible()
    assert fixture('state', client) == before
    click('Скасувати', 'unmountAction')
    client = fixture('client').strip()
    page.goto(base + '/admin/clients/' + client + '/meal-plans')
    form('Призначити раціон', f['today'])
    before = fixture('state', client)
    # Підписаний Livewire snapshot із цієї сторінки; штатний CSRF middleware.
    snapshot = next(e.get_attribute('wire:snapshot') for e in page.locator('[wire\\:snapshot]').all()
                    if 'mountedActions' in json.loads(e.get_attribute('wire:snapshot'))['data'])
    token = page.locator('meta[name=csrf-token]').get_attribute('content')
    payload = {'_token': 'invalid', 'components': [{'snapshot': snapshot, 'updates': {}, 'calls': [{'path': '', 'method': 'callMountedAction', 'params': []}]}]}
    response = context.request.post(base + '/admin/livewire/update', data=payload, headers={'X-Livewire': 'true', 'Accept': 'application/json'})
    assert response.status == 419
    fixture('revoke')
    payload['_token'] = token
    response = context.request.post(base + '/admin/livewire/update', data=payload, headers={'X-Livewire': 'true', 'Accept': 'application/json'})
    assert response.status == 403
    assert fixture('state', client) == before
    checks.append('stale confirmation, signed CSRF419/revoked403, unchanged history')
    assert errors == [], errors
    (output / 'checks.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print(json.dumps(checks, ensure_ascii=False))
    browser.close()
