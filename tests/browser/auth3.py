"""Реальний Chromium/HTTP, без trace/HAR/HTML dumps із секретами."""
import base64
import hashlib
import hmac
import json
import os
import struct
import shutil
import re
import subprocess
import time
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

base = 'http://127.0.0.1:8093'
output = Path('docs/evidence/auth3')
output.mkdir(parents=True, exist_ok=True)
checks = []
generated_otps = []

def otp(secret, offset=0):
    if offset == -1 and time.time() % 30 >= 26:
        time.sleep(30 - time.time() % 30 + 0.1)
    key = base64.b32decode(secret)
    digest = hmac.new(key, struct.pack('>Q', int(time.time() // 30) + offset), hashlib.sha1).digest()
    start = digest[-1] & 15
    code = f'{(struct.unpack(">I", digest[start:start+4])[0] & 0x7fffffff) % 1000000:06d}'
    generated_otps.append(code)
    return code

stage = 'login-enrollment'
try:
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'), args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 1280, 'height': 900})
        page = context.new_page()
        page.set_default_timeout(15000)
        def shot(name):
            page.screenshot(path=str(output / (name + '.png')), full_page=True,
                            mask=page.locator('[data-sensitive]').all())
        def primary():
            page.goto(base + '/admin/login')
            page.locator('[name=email]').fill('browser-auth3@example.invalid')
            page.locator('[name=password]').fill('Synthetic-AUTH3-only!')
            page.get_by_role('button', name='Увійти', exact=True).click()
            page.wait_for_url('**/admin/auth/challenge')
        page.goto(base + '/admin/login')
        assert context.request.post(base + '/admin/auth/primary', form={'email': 'x', 'password': 'x'}).status == 419
        checks.append('CSRF: POST without token = 419')
        shot('01-login')
        primary()
        expect(page.get_by_role('heading', name='Підключення Authenticator')).to_be_visible()
        assert context.request.get(base + '/admin/clients').status == 403
        assert context.request.post(base + '/admin/livewire/update', data={'components': []}, headers={'X-Livewire':'true'}).status in (403, 419)
        checks.append('Password-only enrollment session: business HTTP and direct Livewire denied')
        shot('02-restricted-enrollment')
        page.get_by_role('button', name='Створити ключ Authenticator').click()
        secret = page.locator('#totp-secret').inner_text()
        assert len(secret) == 32
        csrf = page.locator('input[name=_token]').first.input_value()
        denied_json = context.request.post(base + '/admin/auth/enroll', data={'_token':csrf}, headers={'Accept':'application/json'})
        assert denied_json.status == 406 and secret not in denied_json.text()
        checks.append('Enrollment secret JSON response forbidden = 406')
        shot('03-enrollment-masked')
        page.locator('[name=code]').fill(otp(secret, -1))
        page.get_by_role('button', name='Підтвердити', exact=True).click()
        expect(page.get_by_role('heading', name='Збережіть резервні коди')).to_be_visible()
        codes = page.locator('[data-sensitive] code').all_inner_texts()
        assert len(codes) == 3
        shot('04-recovery-masked')
        assert context.request.get(base + '/admin/clients').status == 403
        checks.append('Enrollment confirmed: codes shown once; new full login required')
        primary()
        expect(page.get_by_role('heading', name='Другий фактор')).to_be_visible()
        prior_cookies = context.cookies()
        page.locator('[name=code]').fill(otp(secret))
        page.get_by_role('button', name='Підтвердити вхід').click()
        page.wait_for_url('**/admin')
        expect(page.get_by_role('heading', name='Інфопанель', exact=True)).to_be_visible()
        cookies = context.cookies()
        old_cookie = next(c for c in prior_cookies if c['name'] == 'telmanfit_admin_session')
        new_cookie = next(c for c in cookies if c['name'] == 'telmanfit_admin_session')
        assert old_cookie['value'] != new_cookie['value']
        assert new_cookie['httpOnly'] and new_cookie['path'] == '/admin' and new_cookie['sameSite'] == 'Lax'
        shot('05-filament')
        checks.append('Fresh password + TOTP: Filament reached; session rotated; scoped HttpOnly cookie')
        stale = browser.new_context()
        stale.add_cookies([old_cookie])
        assert stale.request.get(base + '/admin/clients').status == 403
        stale.close()
        page.goto(base + '/admin/clients')
        expect(page.get_by_role('heading', name='Картки клієнтів', exact=True)).to_be_visible()
        search = page.locator('input[type=search]').first
        with page.expect_response(lambda r: '/admin/livewire/update' in r.url and r.request.method == 'POST') as result:
            search.fill('синтетичний')
        assert result.value.status == 200
        expect(page.get_by_text('Синтетичний клієнт AUTH-3', exact=True)).to_be_visible()
        checks.append('Authenticated real Filament Livewire update = 200')
        shot('06-clients')
        stage = 'filament-validation'
        edit_url = page.get_by_role('row').filter(has=page.get_by_text('Синтетичний клієнт AUTH-3', exact=True)).get_by_role('link', name='Змінити', exact=True).get_attribute('href')
        page.goto(edit_url)
        coach = page.get_by_role('button', name=re.compile('Відповідальний тренер'))
        coach.locator('..').get_by_role('button', name='Очистити вибір', exact=True).click()
        with page.expect_response(lambda r: '/admin/livewire/update' in r.url and r.request.method == 'POST') as invalid_form:
            page.get_by_role('button', name='Зберегти', exact=True).click()
        assert invalid_form.value.status == 200
        expect(page.get_by_text(re.compile('Поле «.*тренер.*» обов’язкове', re.I))).to_be_visible()
        shot('08-filament-validation')
        checks.append('Real Filament edit form: invalid coach retains field validation via Livewire 200')
        form_snapshot = next(raw for raw in [element.get_attribute('wire:snapshot') for element in page.locator('[wire\\:snapshot]').all()]
                             if 'record' in json.loads(raw)['data'] and 'data' in json.loads(raw)['data'])
        form_csrf = page.locator('meta[name=csrf-token]').get_attribute('content')
        stage = 'filament-missing-record'
        subprocess.run(['php', 'tests/browser/auth3-fixture.php', 'delete-card'], check=True, capture_output=True)
        missing = context.request.post(base + '/admin/livewire/update', data={
            '_token':form_csrf, 'components':[{'snapshot':form_snapshot, 'updates':{}, 'calls':[{'method':'save', 'params':[]}]}]},
            headers={'X-Livewire':'true', 'Accept':'application/json'})
        assert missing.status == 404
        assert context.request.get(base + '/admin/clients/2147483647/edit').status == 404
        assert context.request.get(base + '/admin/clients/create').status == 403
        checks.append('Authorized HTTP/real Filament Livewire: missing record = 404; forbidden creation = 403')
        page.goto(base + '/admin/clients')
        snapshot = page.locator('[wire\\:snapshot]').first.get_attribute('wire:snapshot')
        csrf = page.locator('meta[name=csrf-token]').get_attribute('content')
        subprocess.run(['php', 'tests/browser/auth3-fixture.php', 'revoke'], check=True, capture_output=True)
        response = context.request.post(base + '/admin/livewire/update', data={
            '_token':csrf, 'components':[{'snapshot':snapshot, 'updates':{}, 'calls':[]}]},
            headers={'X-Livewire':'true', 'Accept':'application/json'})
        assert response.status == 403
        assert context.request.get(base + '/admin/clients').status == 403
        page.goto(base + '/admin/clients')
        shot('07-revoked')
        checks.append('auth_version bump: stale signed Livewire snapshot and HTTP business access = 403')
        primary()
        page.locator('[name=code]').fill(codes[0])
        page.locator('[name=recovery]').check()
        page.get_by_role('button', name='Підтвердити вхід').click()
        page.wait_for_url('**/admin')
        expect(page.get_by_role('heading', name='Інфопанель', exact=True)).to_be_visible()
        checks.append('Repeat full login using one-time recovery as second factor succeeds')
        primary()
        page.locator('[name=code]').fill(codes[0])
        page.locator('[name=recovery]').check()
        page.get_by_role('button', name='Підтвердити вхід').click()
        expect(page.get_by_role('alert')).to_be_visible()
        assert context.request.get(base + '/admin/clients').status == 403
        checks.append('Consumed recovery code rejected on repeat login')
        primary()
        page.locator('[name=code]').fill(codes[1])
        page.locator('[name=recovery]').check()
        page.get_by_role('button', name='Підтвердити вхід').click()
        page.wait_for_url('**/admin')
        stage = 'assurance-outage'
        outage_snapshot = page.locator('[wire\\:snapshot]').first.get_attribute('wire:snapshot')
        outage_csrf = page.locator('meta[name=csrf-token]').get_attribute('content')
        subprocess.run(['php', 'tests/browser/auth3-fixture.php', 'gate-down'], check=True, capture_output=True)
        try:
            assert context.request.get(base + '/admin/clients').status == 503
            outage_update = context.request.post(base + '/admin/livewire/update', data={
                '_token':outage_csrf, 'components':[{'snapshot':outage_snapshot, 'updates':{}, 'calls':[]}]},
                headers={'X-Livewire':'true', 'Accept':'application/json'})
            assert outage_update.status == 503
            checks.append('Real unavailable PostgreSQL assurance relation: HTTP and signed Livewire = 503, fail closed')
        finally:
            subprocess.run(['php', 'tests/browser/auth3-fixture.php', 'gate-up'], check=True, capture_output=True)
        other = browser.new_context()
        assert other.request.get(base + '/admin/clients').status == 403
        other.close()
        page.goto(base + '/admin')
        page.get_by_role('button', name='Меню користувача').click()
        page.get_by_role('menuitem', name='Вийти', exact=True).click()
        page.wait_for_url('**/admin/login')
        assert context.request.get(base + '/admin/clients').status == 403
        checks.append('Native Filament logout destroys session and returns to restricted login')
        # Логи перевіряються в пам'яті; не друкувати знайдені значення.
        log = Path(os.environ['AUTH3_SERVER_LOG']).read_text()
        app_log = Path('storage/logs/laravel.log')
        if app_log.exists():
            log += app_log.read_text()
        assert secret not in log and all(code not in log for code in codes)
        assert all(not re.search(r'(?<![0-9])' + code + r'(?![0-9])', log) for code in generated_otps)
        checks.append('No TOTP secret, OTP or recovery codes in server/application logs')
        report = {'status':'passed', 'chromium':browser.version, 'checks':checks, 'screenshots':'real Chromium; sensitive elements masked at capture'}
        (output / 'results.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
        print(json.dumps(report, ensure_ascii=False))
        context.close()
        browser.close()
except Exception as error:
    import traceback
    line = traceback.extract_tb(error.__traceback__)[-1].lineno
    raise SystemExit('AUTH-3 browser check failed at ' + stage + ', line ' + str(line) + '; sensitive diagnostics suppressed') from None
