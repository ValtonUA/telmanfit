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

assert os.environ.get('TELMANFIT_LOCAL_DEMO') == 'true'
base = 'http://127.0.0.1:8098'
epoch_file = Path(os.environ['TELMANFIT_LOCAL_EPOCH_FILE'])
assert re.fullmatch(r'/tmp/telmanfit-auth4-[a-zA-Z0-9]+/epoch', str(epoch_file))
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
    epoch = epoch_file.read_text()
    try:
        epoch_file.unlink()
        assert admin.request.get(base + '/admin/login').status == 503
        assert client.request.get(base + '/api/me').status == 503
        epoch_file.write_text('60605050-0000-4000-8000-000000000099')
        assert admin.request.get(base + '/admin/login').status == 503
    finally:
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
    code = subprocess.check_output(['php', 'scripts/local-demo/mail.php'], text=True).strip()
    cp.locator('[name=code]').fill(code)
    cp.get_by_role('button', name='Підтвердити', exact=True).click()
    cp.locator('[name=name]').fill('Демонстраційний клієнт')
    cp.get_by_role('button', name='Завершити реєстрацію', exact=True).click()
    expect(cp.get_by_role('heading', name='Вітаємо, Демонстраційний клієнт')).to_be_visible()
    checks.append('Invitation + synthetic email OTP -> actual client registration; anonymous API denied')
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
    output = Path('docs/evidence/local-demo')
    output.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(output / 'filament-answers.png'), full_page=True)
    cp.screenshot(path=str(output / 'client-answers.png'), full_page=True)
    (output / 'checks.json').write_text(json.dumps({'checks': checks}, ensure_ascii=False, indent=2) + '\n')
    browser.close()
print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False))
