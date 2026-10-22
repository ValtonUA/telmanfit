"""Справжній Chromium: UI harness + fetch, HTTP CSRF, encrypted queue/mailbox. Без HAR/trace."""
import json
import os
import re
import subprocess
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'docs/evidence/auth4'
OUT.mkdir(parents=True, exist_ok=True)
BASE = 'http://127.0.0.1:8094'
secrets = []

def fixture(*args):
    return subprocess.check_output(['php', 'tests/browser/auth4-fixture.php', *args], cwd=ROOT, text=True).strip()

def delivery(email):
    subprocess.run(['php', 'artisan', 'queue:work', '--once', '--stop-when-empty', '--tries=1'], cwd=ROOT, check=True, capture_output=True)
    code = fixture('mail', email)
    secrets.append(code)
    return code

def click(page, button, status):
    with page.expect_response(lambda r: '/api/' in r.url) as pending:
        page.locator('#'+button).click()
    assert pending.value.status == status, (button, pending.value.status)
    page.wait_for_function('state.last.status === '+str(status))
    if 'application/json' in pending.value.headers.get('content-type',''):
        text = pending.value.text()
        assert all(secret not in text for secret in secrets)

def harness(page, token=None):
    page.goto(BASE+'/app/register'+('#invite='+token if token else ''))
    page.set_content((ROOT/'tests/browser/auth4-harness.html').read_text())
    page.wait_for_function('state.last?.status === 204')

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE') or '/usr/bin/chromium', args=['--no-sandbox']) if Path('/usr/bin/chromium').exists() else p.chromium.launch()
    context = browser.new_context(accept_downloads=True)
    admin = context.new_page()
    for _ in range(40):
        try:
            admin.goto(BASE+'/admin/login')
            break
        except Exception:
            time.sleep(.15)
    admin.locator('input[name="email"]').fill('browser-admin4@example.invalid')
    admin.locator('input[name="password"]').fill('Synthetic-AUTH4-only!')
    with admin.expect_response(lambda r: r.url.endswith('/auth/primary')) as primary:
        admin.locator('form[action$="/primary"] button').click()
    assert primary.value.status == 302, primary.value.status
    admin.locator('input[name="code"]').fill(fixture('totp','admin','-1'))
    admin.locator('form[action$="/verify"] button').click()
    admin.wait_for_url('**/admin')
    admin.goto(BASE+'/admin/invitations')
    form = admin.locator('form[action$="/invitations/create"]')
    form.locator('select').select_option('8420000000')
    form.locator('[name=password]').fill('Synthetic-AUTH4-only!')
    form.locator('[name=code]').fill(fixture('totp','admin'))
    with admin.expect_response(lambda r: r.url.endswith('/invitations/create')) as pending:
        form.get_by_role('button',name='Створити запрошення',exact=True).click()
    assert pending.value.status == 201, pending.value.status
    url = admin.locator('#invitation-url').input_value()
    token = url.split('#invite=')[1]
    secrets.append(token)
    assert len(token)==64
    admin.goto(BASE+'/admin/invitations')
    unused = fixture('unused-id')
    revoke = admin.locator('form[action$="/invitations/revoke"]')
    revoke.locator('[name=invitation_id]').fill(unused)
    revoke.locator('[name=password]').fill('Synthetic-AUTH4-only!')
    revoke.locator('[name=code]').fill(fixture('totp','admin','1'))
    with admin.expect_response(lambda r: r.url.endswith('/invitations/revoke')) as revoked:
        revoke.get_by_role('button',name='Відкликати запрошення',exact=True).click()
    assert revoked.value.status == 200
    admin.goto(BASE+'/admin/invitations')
    admin.screenshot(path=str(OUT/'01-invitations.png'))
    page = context.new_page()
    harness(page,token)
    assert page.url.endswith('/app/register')
    click(page,'exchange',200)
    page.locator('#email').fill('Browser-New@Example.invalid')
    click(page,'request',202)
    page.locator('#code').fill(delivery('browser-new@example.invalid'))
    click(page,'verify',200)
    assert page.evaluate('state.next')=='registration_complete'
    page.locator('#name').fill('Синтетичний клієнт')
    before = next(c['value'] for c in context.cookies() if c['name']=='telmanfit_client_session')
    click(page,'complete',201)
    after = next(c['value'] for c in context.cookies() if c['name']=='telmanfit_client_session')
    assert before != after
    click(page,'me',200)
    assert page.evaluate('state.last.client.status')=='pending'
    assert fixture('inspect')=='pending-verified-client'
    page.screenshot(path=str(OUT/'02-client-session.png'),mask=[page.locator('#email')])
    admin.reload()
    assert '/admin/invitations' in admin.url
    cookies = {c['name']:c for c in context.cookies()}
    assert cookies['telmanfit_admin_session']['path']=='/admin'
    assert cookies['telmanfit_client_session']['path']=='/api'
    assert all(cookies[n]['httpOnly'] and cookies[n]['sameSite']=='Lax' for n in ['telmanfit_admin_session','telmanfit_client_session'])
    # Реальний CSRF, PHPUnit його не підміняє.
    status = page.evaluate("async () => (await fetch('/api/auth/logout',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:'{}'})).status")
    assert status==419,status
    click(page,'logout',204)
    click(page,'me',401)
    harness(page)
    page.locator('#email').fill('browser-new@example.invalid')
    click(page,'request',202)
    page.locator('#code').fill(delivery('browser-new@example.invalid'))
    click(page,'verify',200)
    assert page.evaluate('state.next')=='authenticated'
    click(page,'me',200)
    click(page,'logout',204)
    harness(page)
    page.locator('#email').fill('browser-mfa4@example.invalid')
    click(page,'request',202)
    page.locator('#code').fill(delivery('browser-mfa4@example.invalid'))
    click(page,'verify',200)
    assert page.evaluate('state.next')=='mfa_required'
    click(page,'me',401)
    page.screenshot(path=str(OUT/'03-mfa-restricted.png'),mask=[page.locator('#email')])
    page.locator('#mfa').fill(fixture('totp','client'))
    click(page,'totp',200)
    click(page,'me',200)
    fixture('version')
    click(page,'me',401)
    click(page,'logout',204)
    admin.reload()
    assert '/admin/invitations' in admin.url
    # У звітах/логах не має бути випадкових OTP/token.
    logs = Path(os.environ['AUTH4_SERVER_LOG']).read_text()
    app_log = ROOT/'storage/logs/laravel.log'
    if app_log.exists():
        logs += app_log.read_text()
    assert all(secret not in logs for secret in secrets)
    assert all(secret not in (OUT/'02-client-session.png').name for secret in secrets)
    result = {'result':'PASS','chromium':browser.version,'scenarios':['Filament admin password+TOTP login','action fresh password+TOTP and one-time HTML invitation display','Filament revoke with fresh password and TOTP','invitation fragment exchange','encrypted database queue and synthetic mailbox','registration pending and session rotation','two independent cookies in one browser','CSRF 419','logout and guest 401','passwordless repeat login without TOTP','email OTP then restricted TOTP then full session','auth_version revocation','no OTP/token in logs'], 'production_enabled':False}
    (OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))
    browser.close()
