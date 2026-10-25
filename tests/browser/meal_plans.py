"""T4.1/T4.2: штатний Filament, точна версія, readonly history, responsive та CSRF/revocation."""
import json
import subprocess
from pathlib import Path
from playwright.sync_api import expect


def fixture(action):
    return subprocess.check_output(['php', 'tests/browser/meal-plan-fixture.php', action], text=True)


def run_meal_plans(page, base):
    f = json.loads(fixture('seed'))
    checks = []
    output = Path('docs/evidence/meal-plans')
    output.mkdir(parents=True, exist_ok=True)
    published = base + '/admin/meal-plan-versions/' + str(f['version'])
    history = fixture('hash')
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            suffix = f'{width}-{theme}'
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            page.goto(published)
            expect(page.get_by_text('Опублікована', exact=True)).to_be_visible()
            expect(page.get_by_text('100.10', exact=True).first).to_be_visible()
            expect(page.get_by_text('0.50', exact=True).first).to_be_visible()
            expect(page.get_by_text('Синтетичний прийом', exact=True).first).to_be_visible()
            for name in ('Змінити', 'Видалити', 'Опублікувати', 'Призначити', 'Копіювати'):
                expect(page.get_by_role('button', name=name, exact=True)).to_have_count(0)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            page.screenshot(path=str(output / f'published-{suffix}.png'), full_page=True)
            page.goto(base + '/admin/meal-plans/create')
            page.get_by_label('Назва', exact=False).fill('Synthetic plan ' + suffix)
            if width == 1440:
                page.get_by_role('button', name='Власник (необов’язково)', exact=False).click()
                page.get_by_role('option', name='#' + str(f['client']) + ' — Без акаунта', exact=True).click()
            page.get_by_role('button', name='Створити', exact=True).click()
            page.wait_for_url('**/admin/meal-plan-versions/*')
            expect(page.get_by_text('Чернетка', exact=True)).to_be_visible()
            expect(page.get_by_text('Чернетка ще не готова до публікації.', exact=False)).to_be_visible()
            expect(page.get_by_text('Порожня чернетка: днів ще немає.', exact=True)).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.screenshot(path=str(output / f'empty-{suffix}.png'), full_page=True)
            draft_url = page.url
            page.get_by_role('button', name='Додати 7 днів', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Додати наступний семиденний тиждень', exact=True))
            dialog.locator('input[type=checkbox][value="' + str(f['type']) + '"]').check()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            dialog.get_by_role('button', name='Додати тиждень', exact=True).click()
            expect(page.get_by_text('Структуру збережено', exact=True)).to_be_visible()
            expect(page.get_by_text('Синтетичний прийом', exact=True)).to_have_count(7)
            page.reload()
            expect(page.get_by_text('Синтетичний прийом', exact=True)).to_have_count(7)
            page.get_by_role('button', name='Додати прийом', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Додати прийом', exact=True))
            try:
                dialog.get_by_role('button', name='День', exact=False).click()
            except Exception:
                print(dialog.aria_snapshot(), flush=True)
                page.screenshot(path='/tmp/telmanfit-t42-dialog.png', full_page=True)
                raise
            page.get_by_role('option', name='Тиждень 1 · День 1', exact=True).click()
            dialog.get_by_role('button', name='Тип прийому', exact=False).click()
            page.get_by_role('option', name='Синтетичний додатковий тип', exact=True).click()
            dialog.get_by_role('button', name='Додати прийом', exact=True).click()
            expect(page.get_by_text('Синтетичний додатковий тип', exact=True)).to_be_visible()
            page.get_by_role('button', name='Видалити порожній прийом', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Видалити порожній прийом', exact=True))
            dialog.get_by_role('button', name='Прийом їжі', exact=False).click()
            page.get_by_role('option', name='День 1 · Синтетичний додатковий тип', exact=True).click()
            dialog.get_by_role('button', name='Видалити', exact=True).click()
            expect(page.get_by_text('Синтетичний додатковий тип', exact=True)).to_have_count(0)
            page.get_by_role('button', name='Змінити прийом', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Змінити прийом', exact=True))
            dialog.get_by_role('button', name='Прийом їжі', exact=False).click()
            page.get_by_role('option', name='День 1 · Синтетичний прийом', exact=True).click()
            expect(dialog.get_by_label('Назва прийому (необов’язково)', exact=False)).to_have_value('')
            dialog.get_by_label('Назва прийому (необов’язково)', exact=False).fill('Меню ' + suffix)
            dialog.get_by_label('Порядок у дні', exact=False).fill('-1')
            dialog.get_by_role('button', name='Зберегти', exact=True).click()
            expect(page.get_by_text('Меню ' + suffix, exact=True)).to_be_visible()
            page.get_by_role('button', name='Додати 7 днів', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Додати наступний семиденний тиждень', exact=True))
            dialog.locator('input[type=checkbox][value="' + str(f['type']) + '"]').check()
            dialog.get_by_role('button', name='Додати тиждень', exact=True).click()
            expect(page.get_by_text('Синтетичний прийом', exact=True)).to_have_count(14)
            page.reload()
            assert page.url == draft_url
            expect(page.get_by_text('Меню ' + suffix, exact=True)).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.screenshot(path=str(output / f'structure-{suffix}.png'), full_page=True)
            page.goto(base + '/admin/meal-plans')
            search = page.get_by_role('searchbox', name='Пошук', exact=True)
            with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                search.fill('Synthetic plan ' + suffix)
            expect(page.locator(r'[wire\:key*=".table.records."]')).to_have_count(1)
            expect(page.get_by_text('Synthetic plan ' + suffix, exact=True)).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.screenshot(path=str(output / f'list-{suffix}.png'), full_page=True)
            page.get_by_role('link', name='Перегляд', exact=True).click()
            expect(page.get_by_text('Ще не опублікована', exact=True)).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            checks.append(suffix + ': create/list/search/plan/exact version/generate 7/edit/append 14/reload/published hierarchy, no overflow')
            print('Meal plans Chromium: ' + suffix + ' PASS', flush=True)
    # Нові чернетки очікувані; історичні дані незмінні, перевіряються окремо нижче.
    page.goto(published)
    before = fixture('hash')
    page.reload()
    assert fixture('hash') == before
    assert history == before  # створення drafts зберегло історичні rows
    page.goto(draft_url)
    page.get_by_role('button', name='Додати 7 днів', exact=True).click()
    structure_raw = next(e.get_attribute('wire:snapshot') for e in page.locator('[wire\\:snapshot]').all()
                         if 'mountedActions' in json.loads(e.get_attribute('wire:snapshot'))['data'])
    structure_payload = {'components': [{'snapshot': structure_raw, 'updates': {}, 'calls': [{'method': 'callMountedAction', 'params': []}]}]}
    structure_hash = fixture('structure-hash')
    assert page.context.request.post(base + '/admin/livewire/update', data=structure_payload, headers={'X-Livewire': 'true', 'Accept': 'application/json'}).status == 419
    assert fixture('structure-hash') == structure_hash
    page.goto(base + '/admin/meal-plans/create')
    raw = next(e.get_attribute('wire:snapshot') for e in page.locator('[wire\\:snapshot]').all()
               if 'data' in json.loads(e.get_attribute('wire:snapshot'))['data'])
    csrf = page.locator('meta[name=csrf-token]').get_attribute('content')
    payload = {'components': [{'snapshot': raw, 'updates': {'data.title': 'Forged plan'}, 'calls': [{'method': 'create', 'params': []}]}]}
    headers = {'X-Livewire': 'true', 'Accept': 'application/json'}
    count = fixture('count')
    assert page.context.request.post(base + '/admin/livewire/update', data=payload, headers=headers).status == 419
    checks.append('Missing CSRF rejected 419; guest/MFA boundary shared with recipe browser')
    (output / 'checks.json').write_text(json.dumps({'checks': checks}, ensure_ascii=False, indent=2) + '\n')
    return {'payload': payload, 'csrf': csrf, 'count': count, 'version': published, 'hash': fixture('hash'), 'structure_payload': structure_payload, 'structure_hash': structure_hash}


def verify_meal_plan_revocation(page, base, state):
    # Catalog regression уже відкликав auth_version штатної MFA-сесії.
    state['payload']['_token'] = state['csrf']
    assert page.context.request.post(base + '/admin/livewire/update', data=state['payload'], headers={'X-Livewire': 'true', 'Accept': 'application/json'}).status == 403
    for path in ('/admin/meal-plans', '/admin/meal-plans/create', state['version'].removeprefix(base)):
        assert page.context.request.get(base + path).status == 403
    state['structure_payload']['_token'] = state['csrf']
    assert page.context.request.post(base + '/admin/livewire/update', data=state['structure_payload'], headers={'X-Livewire': 'true', 'Accept': 'application/json'}).status == 403
    assert fixture('structure-hash') == state['structure_hash']
    assert fixture('count') == state['count']
    assert fixture('hash') == state['hash']
    result = Path('docs/evidence/meal-plans/checks.json')
    data = json.loads(result.read_text())
    data['checks'].append('Revoked signed Livewire create/HTTP 403, unchanged plans/history/foods/assignments')
    result.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')
