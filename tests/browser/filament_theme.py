"""Перевірка штатних стилів у новому local Compose stack після справжнього MFA/OTP.
Викликається local_demo.py за TELMANFIT_FILAMENT_THEME_BROWSER=1.
Без збереження сесій, паролів, TOTP, invitation URL або мережевих bodies.
"""
import json
import os
import re
from pathlib import Path
import time
from playwright.sync_api import expect


def audit(page, secret, password, otp):
    baseline = os.environ.get('TELMANFIT_FILAMENT_BASELINE') == '1'
    output = Path('docs/evidence/filament-theme')
    output.mkdir(parents=True, exist_ok=True)
    failures = []
    assets = set()
    errors = []
    results = []
    base = 'http://127.0.0.1:8098'
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.on('console', lambda message: errors.append(message.text) if message.type == 'error' else None)

    def response(r):
        if r.request.resource_type in ('stylesheet', 'script', 'font'):
            if r.status >= 400:
                failures.append({'path': r.url.split('?')[0].split('#')[0], 'status': r.status})
            assets.add(r.url.split('?')[0])
    page.on('response', response)
    page.on('requestfailed', lambda r: failures.append({'path': r.url.split('?')[0].split('#')[0], 'error': r.failure})
            if r.resource_type in ('stylesheet', 'script', 'font') else None)

    def capture(name, width, theme):
        page.wait_for_timeout(250)
        metrics = page.evaluate('''() => {
            const style = s => {const e=document.querySelector(s); if(!e) return null;
                const c=getComputedStyle(e); return {display:c.display, padding:c.padding,
                    background:c.backgroundColor, color:c.color, radius:c.borderRadius, gap:c.rowGap};};
            return {viewport:innerWidth, scroll:document.documentElement.scrollWidth,
                dark:document.documentElement.classList.contains('dark'),
                input:style('main .fi-input-wrp, [role=dialog] .fi-input-wrp'), rawInput:style('main input[name=password]'),
                button:style('main .fi-btn'), field:style('main .fi-fo-field, [role=dialog] .fi-fo-field'),
                table:style('main .fi-ta-table'), modal:style('[role=dialog] .fi-modal-window'),
                theme:[...document.styleSheets].map(s=>s.href).filter(s=>s && s.includes('/build/assets/theme-'))};
        }''')
        if not baseline:
            assert metrics['scroll'] <= width, (name, metrics)
        assert metrics['dark'] == (theme == 'dark'), (name, metrics)
        assert metrics['theme'], (name, metrics)
        if name in ('invitations', 'edit', 'questionnaire') and not baseline:
            assert metrics['input'] and metrics['input']['radius'] == '8px', metrics
            assert metrics['field']['gap'] == '8px', metrics
            if name == 'invitations':
                assert metrics['rawInput']['padding'] != '0px', metrics
        page.screenshot(path=str(output / f'{"before" if baseline else "after"}-{name}-{width}-{theme}.png'), full_page=True)
        results.append({'page': name, 'width': width, 'theme': theme, 'metrics': metrics})
        print(f'Filament {name}: {width}px {theme}', flush=True)

    page.goto(base + '/admin/clients')
    page.get_by_text('Демонстраційний клієнт', exact=True).click()
    card_url = page.url
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            page.set_viewport_size({'width': width, 'height': 1000})
            # Штатне збережене налаштування Filament; reload застосовує його до всіх сторінок.
            page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            page.goto(base + '/admin')
            expect(page.get_by_role('heading', name='Інфопанель', exact=True)).to_be_visible()
            capture('dashboard', width, theme)
            page.goto(base + '/admin/invitations')
            expect(page.get_by_role('button', name='Створити запрошення', exact=True)).to_be_visible()
            capture('invitations', width, theme)
            page.goto(base + '/admin/clients')
            expect(page.locator('.fi-ta-row').first).to_be_visible()
            capture('clients', width, theme)
            page.goto(card_url)
            capture('card', width, theme)
            page.get_by_role('button', name='Анкета — 3 запитання').click()
            dialog = page.get_by_role('dialog')
            expect(dialog.get_by_label('ТОП-3 причини, що заважають схуднути')).to_be_visible()
            capture('questionnaire', width, theme)
            dialog.get_by_label('ТОП-3 причини, що заважають схуднути').fill('Відповідь перевірена тренером')
            dialog.locator('button[type=submit]').click()
            expect(page.get_by_text('Перші відповіді збережено', exact=True)).to_be_visible()
            page.goto(card_url + '/edit')
            expect(page.get_by_role('button', name='Зберегти', exact=True)).to_be_visible()
            capture('edit', width, theme)
            coach = page.get_by_role('button', name=re.compile('Відповідальний тренер'))
            coach.click()
            page.get_by_role('combobox', name=re.compile('Відповідальний тренер.*Пошук')).fill('Локальний')
            expect(page.get_by_role('option').filter(has_text='Локальний адміністратор').first).to_be_visible()
            capture('dropdown', width, theme)
            page.get_by_role('option').filter(has_text='Локальний адміністратор').first.click()
            # Штатний select статусу та збереження тієї самої картки.
            select = page.locator('.fi-select-input').filter(has=page.locator('option[value="pending"]'))
            if select.count():
                select.select_option('pending')
            page.get_by_role('button', name='Зберегти', exact=True).click()
            expect(page.get_by_text('Збережено', exact=True)).to_be_visible()
            capture('notification', width, theme)
    if not baseline:
        # Два окремі fresh TOTP: replay guard лишається активним.
        def fresh_code():
            time.sleep(30 - time.time() % 30 + 0.2)
            return otp(secret)
        page.goto(base + '/admin/invitations')
        form = page.locator('form[action$="/create"]')
        form.locator('select').select_option(index=1)
        form.locator('[name=password]').fill(password)
        form.locator('[name=code]').fill(fresh_code())
        with page.expect_response(lambda r: r.url.endswith('/admin/auth/invitations/create')) as created:
            form.get_by_role('button', name='Створити запрошення', exact=True).click()
        assert created.value.status == 201
        expect(page.locator('#invitation-url')).to_be_visible()
        assert page.locator('#invitation-url').input_value().startswith(base + '/app/register#invite=')
        page.get_by_role('link', name='До списку запрошень').click()
        row = page.locator('.fi-ta-records .fi-ta-row').first
        invitation_id = row.locator('td').first.inner_text().replace('ID', '').strip()
        expect(row).to_contain_text('Чинне')
        form = page.locator('form[action$="/revoke"]')
        form.locator('[name=invitation_id]').fill(invitation_id)
        form.locator('[name=password]').fill(password)
        form.locator('[name=code]').fill(fresh_code())
        with page.expect_response(lambda r: r.url.endswith('/admin/auth/invitations/revoke')) as revoked:
            form.get_by_role('button', name='Відкликати запрошення', exact=True).click()
        assert revoked.value.status == 200
        expect(page.get_by_text('Запрошення відкликано.', exact=True)).to_be_visible()
        page.get_by_role('link', name='До списку запрошень').click()
        expect(page.locator('.fi-ta-records .fi-ta-row').first).to_contain_text('Відкликано')
    assert not failures, failures
    assert not errors, errors
    assert any('/js/filament/' in url for url in assets), assets
    (output / ('before.json' if baseline else 'checks.json')).write_text(json.dumps({
        'browser': page.context.browser.version, 'results': results, 'asset_failures': failures,
        'page_errors': errors, 'asset_paths': sorted(assets),
        'invitation_create_revoke': not baseline}, ensure_ascii=False, indent=2) + '\n')
    page.goto(card_url)
    print(f'Filament theme: {len(results)} views; asset errors=0; baseline={baseline}', flush=True)
