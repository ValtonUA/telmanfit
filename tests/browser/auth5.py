"""Chromium + справжня React SPA, synthetic encrypted queue. Без trace/HAR/HTML dumps."""
import json
import os
import re
import subprocess
import time
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'docs/evidence/auth5'
OUT.mkdir(parents=True, exist_ok=True)
BASE = 'http://127.0.0.1:8095'
EMAIL = 'auth5-new@example.invalid'
secrets = []
checks = []


def fixture(*args, payload=None):
    return subprocess.check_output(['php', 'tests/browser/auth5-fixture.php', *args], cwd=ROOT,
                                   input=json.dumps(payload) if payload else None, text=True).strip()


def delivery(email):
    subprocess.run(['php', 'artisan', 'queue:work', '--stop-when-empty', '--tries=1', '--sleep=0'], cwd=ROOT,
                   check=True, capture_output=True)
    try:
        code = fixture('mail', email)
    except subprocess.CalledProcessError:
        print('Synthetic queue health: ' + fixture('queue-health'), flush=True)
        raise
    secrets.append(code)
    return code


def totp(email=EMAIL, pending=False):
    code = fixture('totp', payload={'email': email, 'pending': pending})
    secrets.append(code)
    return code


def click(page, name, path, status=200):
    with page.expect_response(lambda r: r.url.endswith('/api/' + path) and r.request.method == 'POST') as waiting:
        page.get_by_role('button', name=name, exact=True).click()
    response = waiting.value
    assert response.status == status, (path, response.status)
    body = response.json() if response.status != 204 else {}
    if path not in ['auth/security/mfa/enroll', 'auth/security/mfa/confirm', 'auth/security/mfa/recovery']:
        assert all(secret not in json.dumps(body) for secret in secrets), 'Secret in ordinary JSON'
    return body


def raw(page, path, data=None, csrf=True):
    result = page.evaluate('''async ({path,data,csrf}) => {
      const token=document.cookie.split('; ').find(x=>x.startsWith('TELMANFIT-CLIENT-XSRF='))?.split('=').slice(1).join('=') || '';
      const r=await fetch('/api/'+path,{method:data===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(data===null?{}:{'Content-Type':'application/json',...(csrf?{'X-XSRF-TOKEN':decodeURIComponent(token)}:{})})},body:data===null?undefined:JSON.stringify(data)});
      return {status:r.status,body:r.status===204?{}:await r.json()};
    }''', {'path': path, 'data': data, 'csrf': csrf})
    assert all(secret not in json.dumps(result['body']) for secret in secrets), 'Secret in ordinary JSON'
    return result


def clean(page):
    stored = page.evaluate('JSON.stringify({local:{...localStorage},session:{...sessionStorage}})')
    assert all(secret not in stored for secret in secrets), 'Secret in browser storage'
    assert page.evaluate('async () => (await caches.keys()).length === 0')
    assert '#' not in page.url


def shot(page, name):
    for width in [320, 390, 1440]:
        page.set_viewport_size({'width': width, 'height': 900})
        assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), (name, width)
        page.screenshot(path=str(OUT / f'{name}-{width}.png'), full_page=True,
                        mask=[page.locator('input'), page.locator('.manual-key'), page.locator('.enrollment img'), page.locator('.recovery-codes')])
    page.set_viewport_size({'width': 390, 'height': 900})
    clean(page)


def login(page, email=EMAIL, recovery=None, factor=True):
    fixture('window', email)
    page.goto(BASE + '/app/login')
    page.get_by_label('Електронна пошта', exact=True).fill(email)
    click(page, 'Отримати код', 'auth/email-otp/request', 202)
    page.get_by_label('Код із листа', exact=True).fill(delivery(email))
    result = click(page, 'Підтвердити', 'auth/email-otp/verify')
    if factor:
        assert result['next'] == 'mfa_required'
        assert raw(page, 'me')['status'] == 401
        if recovery:
            page.get_by_label('Спосіб підтвердження').select_option('recovery_code')
            page.get_by_label('Резервний код', exact=True).fill(recovery)
        else:
            page.get_by_label('Код Authenticator', exact=True).fill(totp(email))
        click(page, 'Підтвердити', 'auth/mfa/verify')
    else:
        assert result['next'] == 'authenticated'
    expect(page.get_by_role('heading', name='Вітаємо, Синтетичний клієнт')).to_be_visible()
    if recovery:
        assert raw(page, 'me')['body']['security']['recovery_used'] is True
    assert page.locator('input[type=password]').count() == 0
    assert all(secret not in page.locator('body').inner_text() for secret in secrets), 'Secret retained after login'
    clean(page)


def security(page):
    page.get_by_role('link', name='Налаштування безпеки', exact=True).click()
    expect(page.get_by_role('heading', name='Безпека та вхід')).to_be_visible()
    expect(page.get_by_text('Цей пристрій', exact=True)).to_be_visible()


def reauth(page, action, email=EMAIL, recovery=None, factor=True):
    fixture('window', email)
    page.get_by_role('button', name=action, exact=True).click()
    response = click(page, 'Отримати код підтвердження', 'auth/security/reauth/request', 202)
    page.get_by_label('Код із листа', exact=True).fill(delivery(email))
    result = click(page, 'Підтвердити', 'auth/security/reauth/verify')
    if factor:
        assert result['next'] == 'mfa_required'
        # Primary без поточного MFA не є action proof.
        assert raw(page, 'auth/security/mfa/disable', {'proof_id': result['flow_id']})['status'] == 403
        if recovery:
            page.get_by_label('Спосіб підтвердження').select_option('recovery_code')
            page.get_by_label('Резервний код', exact=True).fill(recovery)
        else:
            page.get_by_label('Код Authenticator', exact=True).fill(totp(email))
        result = click(page, 'Підтвердити', 'auth/security/reauth/mfa')
    assert result['next'] == 'authorized'
    expect(page.get_by_role('button', name='Виконати дію', exact=True)).to_be_visible()
    return result['flow_id']


def enroll(page, email=EMAIL):
    result = click(page, 'Виконати дію', 'auth/security/mfa/enroll')
    secrets.append(result['secret'])
    shot(page, '05-enrollment-masked')
    page.get_by_label('Код Authenticator', exact=True).fill(totp(email, True))
    result = click(page, 'Підтвердити', 'auth/security/mfa/confirm')
    codes = result['recovery_codes']
    secrets.extend(codes)
    expect(page.locator('.recovery-codes')).to_be_visible()
    assert page.locator('.manual-key').count() == 0
    shot(page, '06-recovery-masked')
    page.get_by_role('button', name='Я зберіг / зберегла коди', exact=True).click()
    expect(page.get_by_role('heading', name='Увійдіть у свій кабінет')).to_be_visible()
    assert page.locator('.recovery-codes').count() == 0
    assert all(code not in page.locator('body').inner_text() for code in codes)
    return codes


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox']) if Path('/usr/bin/chromium').exists() else p.chromium.launch()
    context = browser.new_context(viewport={'width': 390, 'height': 900})
    page = context.new_page()
    page_errors = []
    page.on('pageerror', lambda error: page_errors.append(type(error).__name__))
    # Чинна незалежна admin cookie у тому самому browser context.
    admin = context.new_page()
    admin.goto(BASE + '/admin/login')
    admin.locator('input[name=email]').fill('auth5-admin@example.invalid')
    admin.locator('input[name=password]').fill('Synthetic-AUTH5-only!')
    with admin.expect_response(lambda r: r.url.endswith('/auth/primary')) as primary:
        admin.locator('form[action$="/primary"] button').click()
    assert primary.value.status == 302, ('admin primary', primary.value.status)
    admin.locator('input[name=code]').fill(totp('auth5-admin@example.invalid'))
    with admin.expect_response(lambda r: r.url.endswith('/auth/verify')) as verify:
        admin.locator('form[action$="/verify"] button').click()
    assert verify.value.status == 302, ('admin verify', verify.value.status)
    admin.wait_for_url('**/admin')
    checks.append('independent admin password+MFA session')

    token = fixture('invitation')
    secrets.append(token)
    page.goto(BASE + '/app/register#invite=' + token)
    expect(page.get_by_role('heading', name='Приєднайтеся до TelmanFit')).to_be_visible()
    assert page.url == BASE + '/app/register'
    shot(page, '01-register')
    page.get_by_label('Електронна пошта', exact=True).fill(EMAIL)
    page.keyboard.press('Tab')
    expect(page.get_by_role('button', name='Отримати код', exact=True)).to_be_focused()
    result = click(page, 'Отримати код', 'auth/email-otp/request', 202)
    expect(page.get_by_role('button', name=re.compile('Надіслати ще раз через [0-9]+ с'))).to_be_disabled()
    shot(page, '02-email-otp')
    first = delivery(EMAIL)
    page.get_by_label('Код із листа', exact=True).fill('00000000' if first != '00000000' else '11111111')
    click(page, 'Підтвердити', 'auth/email-otp/verify', 422)
    expect(page.get_by_role('alert')).to_be_visible()
    assert page.get_by_label('Код із листа', exact=True).input_value() == ''
    fixture('expire-otp', result['challenge_id'])
    page.get_by_label('Код із листа', exact=True).fill(first)
    click(page, 'Підтвердити', 'auth/email-otp/verify', 422)
    # Реальний browser cooldown 60s, без зміни clock React.
    expect(page.get_by_role('button', name='Надіслати код ще раз', exact=True)).to_be_enabled(timeout=65000)
    click(page, 'Надіслати код ще раз', 'auth/email-otp/request', 202)
    page.get_by_label('Код із листа', exact=True).fill(delivery(EMAIL))
    click(page, 'Підтвердити', 'auth/email-otp/verify')
    page.get_by_label('Ваше ім’я', exact=True).fill('Синтетичний клієнт')
    page.get_by_label('Ваше прізвище', exact=True).fill('Тестове')
    click(page, 'Завершити реєстрацію', 'auth/registration/complete', 201)
    expect(page.get_by_role('heading', name='Очікує початку')).to_be_visible()
    shot(page, '03-dashboard-pending')
    assert raw(page, 'auth/logout', {}, csrf=False)['status'] == 419
    assert raw(page, 'auth/security/mfa/enroll', {'proof_id': '00000000-0000-4000-8000-000000000001', 'replace': False})['status'] == 403
    admin.reload()
    assert admin.url.endswith('/admin')
    checks.extend(['registration fragment removed once', 'wrong and expired OTP', 'real resend cooldown 60s', 'pending is not active payment', 'real CSRF 419', 'forged action proof 403'])
    print('AUTH5 React registration, OTP errors/expiry/resend, CSRF and pending dashboard PASS', flush=True)
    click(page, 'Вийти', 'auth/logout', 204)
    login(page, factor=False)
    security(page)
    shot(page, '04-security')
    # 419 UI: request не повторюється автоматично, потрібне друге натискання.
    page.get_by_role('button', name='Підключити Authenticator', exact=True).click()
    fixture('window', EMAIL)
    interrupted = []
    def stale_csrf(route):
        interrupted.append(True)
        route.fulfill(status=419, content_type='application/json', body='{"code":"csrf_mismatch"}')
    page.route('**/api/auth/security/reauth/request', stale_csrf, times=1)
    click(page, 'Отримати код підтвердження', 'auth/security/reauth/request', 419)
    expect(page.get_by_role('alert')).to_contain_text('натисніть кнопку ще раз')
    assert len(interrupted) == 1
    click(page, 'Отримати код підтвердження', 'auth/security/reauth/request', 202)
    page.get_by_label('Код із листа', exact=True).fill(delivery(EMAIL))
    proof = click(page, 'Підтвердити', 'auth/security/reauth/verify')['flow_id']
    codes = enroll(page)
    checks.extend(['passwordless repeated login', '419 explicit retry and CSRF refresh', 'Authenticator QR/manual enrollment and masked one-time recovery'])
    login(page, recovery=codes[0])
    security(page)
    proof = reauth(page, 'Оновити резервні коди', recovery=codes[1])
    result = click(page, 'Виконати дію', 'auth/security/mfa/recovery')
    codes2 = result['recovery_codes']; secrets.extend(codes2)
    page.get_by_role('button', name='Я зберіг / зберегла коди', exact=True).click()
    login(page, recovery=codes2[0])
    security(page)
    reauth(page, 'Замінити Authenticator', recovery=codes2[1])
    codes3 = enroll(page)
    login(page, recovery=codes3[0])
    security(page)
    reauth(page, 'Вимкнути 2FA', recovery=codes3[1])
    click(page, 'Виконати дію', 'auth/security/mfa/disable', 204)
    expect(page.get_by_role('heading', name='Увійдіть у свій кабінет')).to_be_visible()
    login(page, factor=False)
    security(page)
    reauth(page, 'Підключити Authenticator', factor=False)
    codes4 = enroll(page)
    login(page, recovery=codes4[0])
    checks.extend(['restricted OTP to full MFA session', 'recovery login', 'recovery regeneration', 'factor replacement', 'optional disable after fresh MFA', 'new enrollment after disable'])
    print('AUTH5 React MFA enrollment/recovery/rotation/replacement/disable/reenrollment PASS', flush=True)
    # Другий справжній браузер: паралельна сесія, TOTP + старі recovery не дають обхід.
    other_context = browser.new_context(viewport={'width': 390, 'height': 900})
    other = other_context.new_page()
    login(other, factor=True)
    security(page)
    expect(page.get_by_text('Інший пристрій', exact=True)).to_be_visible()
    proof = reauth(page, 'Відкликати', factor=True)
    click(page, 'Виконати дію', 'auth/security/sessions/revoke', 204)
    assert raw(other, 'me')['status'] == 401
    login(other, factor=True)
    fixture('window', EMAIL)
    page.get_by_role('link', name='До кабінету', exact=False).click()
    security(page)
    reauth(page, 'Вийти з усіх пристроїв', factor=True)
    click(page, 'Виконати дію', 'auth/security/logout-all', 204)
    assert raw(other, 'me')['status'] == 401
    assert raw(page, 'me')['status'] == 401
    checks.extend(['opaque own session list', 'revoke real parallel session', 'logout-all invalidates both real browsers'])
    print('AUTH5 React two real browsers, own revoke and logout-all PASS', flush=True)
    login(page, recovery=codes4[1])
    security(page)
    reauth(page, 'Змінити електронну пошту', factor=True)
    page.get_by_role('button', name='Виконати дію', exact=True).click()
    changed = 'auth5-changed@example.invalid'
    page.get_by_label('Нова електронна пошта', exact=True).fill(changed)
    click(page, 'Надіслати код на нову пошту', 'auth/security/email/request', 202)
    shot(page, '07-email-change')
    page.get_by_label('Код із листа', exact=True).fill(delivery(changed))
    click(page, 'Підтвердити', 'auth/security/email/confirm', 204)
    login(page, email=changed, recovery=codes4[2])
    security(page)
    fixture('cache-outage')
    try:
        assert raw(page, 'me')['status'] == 503
        assert raw(page, 'auth/security/reauth/request', {'action': 'logout_all'})['status'] == 503
    finally:
        fixture('cache-restore')
    fixture('window', changed)
    for _ in range(10):
        assert raw(page, 'auth/email-otp/verify', {'challenge_id': '00000000-0000-4000-8000-000000000002', 'code': '00000000'})['status'] == 422
    assert raw(page, 'auth/email-otp/verify', {'challenge_id': '00000000-0000-4000-8000-000000000002', 'code': '00000000'})['status'] == 429
    fixture('expire-session', changed)
    assert raw(page, 'me')['status'] == 401
    page.goto(BASE + '/app/security')
    expect(page.get_by_role('heading', name='Увійдіть у свій кабінет')).to_be_visible()
    assert page.url.endswith('/app/login')
    checks.extend(['email change requires fresh current MFA and new email OTP', '503 central rate store fail closed', '422 and 429', 'idle expiry'])
    for state in ['revoked', 'consumed']:
        token = fixture('invitation', state); secrets.append(token)
        with page.expect_response(lambda r: r.url.endswith('/api/auth/invitations/exchange')) as exchange:
            page.goto(BASE + '/app/register#invite=' + token)
        assert exchange.value.status == 422
        expect(page.get_by_role('alert')).to_contain_text('Запрошення недоступне')
    shot(page, '08-unavailable-invitation')
    admin.reload()
    assert admin.url.endswith('/admin')
    clean(page)
    logs = Path(os.environ['AUTH5_SERVER_LOG']).read_text()
    if (ROOT / 'storage/logs/laravel.log').exists():
        logs += (ROOT / 'storage/logs/laravel.log').read_text()
    assert all(secret not in logs for secret in secrets), 'Secret in logs'
    assert not page_errors, page_errors
    result = {'result': 'PASS', 'chromium': browser.version, 'scenarios': checks,
              'widths': [320, 390, 1440], 'production_enabled': False,
              'synthetic_time_acceleration': 'Known test delivery windows cleared between independent operations; registration resend waited actual 60s.'}
    (OUT / 'results.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(result, ensure_ascii=False))
    browser.close()
