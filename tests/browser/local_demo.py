"""Штатний local profile, Chromium; без обходів MFA, session injection або HAR із секретами.
Запуск після source <demo>/env.sh: python3 tests/browser/local_demo.py
"""
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import shutil
import struct
import subprocess
import time
from playwright.sync_api import sync_playwright, expect

compose = os.environ.get('TELMANFIT_COMPOSE_BROWSER') == '1'
assert compose or os.environ.get('TELMANFIT_LOCAL_DEMO') == 'true'
base = 'http://127.0.0.1:8098'
epoch_file = Path(os.environ['TELMANFIT_LOCAL_EPOCH_FILE'])
assert compose or re.fullmatch(r'/tmp/telmanfit-auth4-[a-zA-Z0-9]+/epoch', str(epoch_file))
checks = []

def otp(secret, offset=0):
    if offset == -1 and time.time() % 30 >= 26:
        time.sleep(30 - time.time() % 30 + 0.1)
    digest = hmac.new(base64.b32decode(secret), struct.pack('>Q', int(time.time() // 30) + offset), hashlib.sha1).digest()
    start = digest[-1] & 15
    return f'{(struct.unpack(">I", digest[start:start+4])[0] & 0x7fffffff) % 1000000:06d}'

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'), args=['--no-sandbox'])
    admin = browser.new_context(viewport={'width': 1440, 'height': 1100})
    client = browser.new_context(viewport={'width': 1280, 'height': 1000})
    # Реальний fence: читання незалежного файла у кожному HTTP request.
    epoch = epoch_file.read_text() if not compose else None
    try:
        if compose:
            subprocess.run(['docker', 'compose', 'run', '--rm', '--no-deps', '--entrypoint', 'php', 'setup', '-r', "rename('/run/telmanfit-epoch/epoch', '/run/telmanfit-epoch/saved');"], check=True, capture_output=True)
        else:
            epoch_file.unlink()
        assert admin.request.get(base + '/admin/login').status == 503
        assert client.request.get(base + '/api/me').status == 503
        if compose:
            assert subprocess.run(['docker', 'compose', 'exec', '-T', 'app', 'php', 'docker/local/health.php'], capture_output=True).returncode != 0
        if compose:
            subprocess.run(['docker', 'compose', 'run', '--rm', '--no-deps', '--entrypoint', 'php', 'setup', '-r', "file_put_contents('/run/telmanfit-epoch/epoch', '60605050-0000-4000-8000-000000000099');"], check=True, capture_output=True)
        else:
            epoch_file.write_text('60605050-0000-4000-8000-000000000099')
        assert admin.request.get(base + '/admin/login').status == 503
    finally:
        if compose:
            subprocess.run(['docker', 'compose', 'run', '--rm', '--no-deps', '--entrypoint', 'php', 'setup', '-r', "rename('/run/telmanfit-epoch/saved', '/run/telmanfit-epoch/epoch');"], check=True, capture_output=True)
        else:
            epoch_file.write_text(epoch)
            epoch_file.chmod(0o600)
    checks.append('Missing/mismatched independent epoch: admin/client 503; no fallback')
    page = admin.new_page()
    page.set_default_timeout(15000)
    def primary():
        page.goto(base + '/admin/login')
        page.locator('[name=email]').fill('owner@example.invalid')
        page.locator('[name=password]').fill(os.environ['DEMO_PASSWORD'])
        page.get_by_role('button', name='Увійти', exact=True).click()
        page.wait_for_url('**/admin/auth/challenge')
    primary()
    expect(page.get_by_role('heading', name='Підключення Authenticator')).to_be_visible()
    assert admin.request.get(base + '/admin/clients').status == 403
    page.get_by_role('button', name='Створити ключ Authenticator').click()
    secret = page.locator('#totp-secret').inner_text()
    page.locator('[name=code]').fill(otp(secret, -1))
    page.get_by_role('button', name='Підтвердити', exact=True).click()
    expect(page.get_by_role('heading', name='Збережіть резервні коди')).to_be_visible()
    assert admin.request.get(base + '/admin/clients').status == 403
    primary()
    page.locator('[name=code]').fill(otp(secret))
    page.get_by_role('button', name='Підтвердити вхід').click()
    page.wait_for_url('**/admin')
    expect(page.get_by_role('heading', name='Інфопанель', exact=True)).to_be_visible()
    checks.append('Admin password-only denied; enrollment, fresh password+TOTP -> Filament')
    # Анонімні дані закриті навіть коли весь стенд готовий.
    anonymous = client.request.get(base + '/api/onboarding', headers={'Accept': 'application/json'})
    assert anonymous.status == 401
    assert 'answers' not in anonymous.json()
    assert client.request.get(base + '/api/me').status in (401, 403)
    cp = client.new_page()
    cp.set_default_timeout(15000)
    cp.goto(base + '/app/register#invite=' + os.environ['DEMO_INVITE'])
    cp.locator('[name=email]').fill('client@example.invalid')
    cp.get_by_role('button', name='Отримати код', exact=True).click()
    expect(cp.locator('[name=code]')).to_be_visible()
    if compose:
        for attempt in range(30):
            result = subprocess.run(['docker', 'compose', 'exec', '-T', 'app', 'php', 'docker/local/mail.php'], capture_output=True, text=True)
            if result.returncode == 0:
                code = result.stdout.strip()
                break
            time.sleep(0.5)
        else:
            raise AssertionError('Queued local OTP delivery did not complete')
    else:
        code = subprocess.check_output(['php', 'scripts/local-demo/mail.php'], text=True).strip()
    cp.locator('[name=code]').fill(code)
    cp.get_by_role('button', name='Підтвердити', exact=True).click()
    cp.locator('[name=name]').fill('Демонстраційний клієнт')
    cp.get_by_role('button', name='Завершити реєстрацію', exact=True).click()
    expect(cp.get_by_role('heading', name='Вітаємо, Демонстраційний клієнт')).to_be_visible()
    checks.append('Invitation + synthetic email OTP -> actual client registration; anonymous API denied')
    if compose:
        assert client.request.post(base + '/api/onboarding', data={'answers': {}}, headers={'Accept': 'application/json'}).status == 419
        checks.append('Authenticated onboarding mutation without CSRF token rejected with 419')
    cp.get_by_role('link', name='Заповнити анкету').click()
    cp.locator('#onboarding-reasons').fill('Брак часу, вечірній голод, нерегулярне харчування')
    cp.locator('#onboarding-workplace').select_option('hybrid')
    cp.locator('#onboarding-meals').select_option('3')
    cp.get_by_role('button', name='Зберегти відповіді').click()
    expect(cp.get_by_text('Відповіді збережено.', exact=False)).to_be_visible()
    cp.reload()
    expect(cp.locator('#onboarding-reasons')).to_have_value('Брак часу, вечірній голод, нерегулярне харчування')
    expect(cp.locator('#onboarding-workplace')).to_have_value('hybrid')
    expect(cp.locator('#onboarding-meals')).to_have_value('3')
    checks.append('React: all three answers saved and reopened after full reload')
    page.goto(base + '/admin/clients')
    page.get_by_text('Демонстраційний клієнт', exact=True).click()
    expect(page.get_by_role('heading', name=re.compile('Картка клієнта #'))).to_be_visible()
    page.get_by_role('button', name='Анкета — 3 запитання').click()
    dialog = page.get_by_role('dialog')
    expect(dialog.get_by_label('ТОП-3 причини, що заважають схуднути')).to_have_value('Брак часу, вечірній голод, нерегулярне харчування')
    dialog.get_by_label('ТОП-3 причини, що заважають схуднути').fill('Відповідь перевірена тренером')
    dialog.locator('button[type=submit]').click()
    expect(page.get_by_text('Перші відповіді збережено', exact=True)).to_be_visible()
    page.reload()
    page.get_by_role('button', name='Анкета — 3 запитання').click()
    expect(page.get_by_role('dialog').get_by_label('ТОП-3 причини, що заважають схуднути')).to_have_value('Відповідь перевірена тренером')
    cp.reload()
    expect(cp.locator('#onboarding-reasons')).to_have_value('Відповідь перевірена тренером')
    expect(cp.locator('#onboarding-workplace')).to_have_value('hybrid')
    expect(cp.locator('#onboarding-meals')).to_have_value('3')
    checks.append('Filament client card: reads three answers, edits, saves and reopens; React sees same PostgreSQL data')
    if compose:
        subprocess.run(['docker', 'compose', 'down'], check=True, capture_output=True)
        subprocess.run(['docker', 'compose', 'up', '-d', '--wait', '--wait-timeout', '180'], check=True, capture_output=True)
        # Ті самі browser sessions + відповіді після видалення й перестворення containers.
        cp.reload()
        expect(cp.locator('#onboarding-reasons')).to_have_value('Відповідь перевірена тренером')
        page.reload()
        page.get_by_role('button', name='Анкета — 3 запитання').click()
        expect(page.get_by_role('dialog').get_by_label('ТОП-3 причини, що заважають схуднути')).to_have_value('Відповідь перевірена тренером')
        # Новий браузер: enrollment збережений, потрібен лише звичайний password + TOTP.
        fresh = browser.new_context()
        page = fresh.new_page()
        primary()
        expect(page.get_by_role('heading', name='Підключення Authenticator')).not_to_be_visible()
        if time.time() % 30 < 1:
            time.sleep(1)
        time.sleep(30 - time.time() % 30 + 0.2)
        page.locator('[name=code]').fill(otp(secret))
        page.get_by_role('button', name='Підтвердити вхід').click()
        page.wait_for_url('**/admin')
        page.goto(base + '/admin/clients')
        page.get_by_text('Демонстраційний клієнт', exact=True).click()
        page.get_by_role('button', name='Анкета — 3 запитання').click()
        checks.append('Compose down/up: persisted users, sessions, answers and MFA; fresh password+TOTP login without enrollment')
    output = Path('docs/evidence/local-compose' if compose else 'docs/evidence/local-demo')
    output.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(output / 'filament-answers.png'), full_page=True)
    cp.screenshot(path=str(output / 'client-answers.png'), full_page=True)
    (output / 'checks.json').write_text(json.dumps({'checks': checks}, ensure_ascii=False, indent=2) + '\n')
    browser.close()
print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False))
