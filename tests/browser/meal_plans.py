"""T4.1/T4.2: штатний Filament, точна версія, readonly history, responsive та CSRF/revocation."""
import json
from contextlib import nullcontext
import subprocess
from pathlib import Path
from playwright.sync_api import expect


def fixture(action, *args):
    return subprocess.check_output(['php', 'tests/browser/meal-plan-fixture.php', action, *map(str, args)], text=True)


def run_meal_plans(page, base):
    f = json.loads(fixture('seed'))
    checks = []
    output = Path('docs/evidence/meal-plans')
    output.mkdir(parents=True, exist_ok=True)
    published = base + '/admin/meal-plan-versions/' + str(f['version'])
    history = fixture('hash')

    def submit(dialog, label):
        with (page.expect_navigation(wait_until='networkidle') if label != 'Скасувати' else nullcontext()):
            with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST') as response:
                dialog.get_by_role('button', name=label, exact=True).click()
        assert response.value.status == 200
        page.wait_for_load_state('networkidle')
        dialog.wait_for(state='hidden')

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
            submit(dialog, 'Додати тиждень')
            expect(page.get_by_text('Структуру збережено', exact=True)).to_be_visible()
            expect(page.get_by_text('Синтетичний прийом', exact=True)).to_have_count(7)
            page.reload()
            expect(page.get_by_text('Синтетичний прийом', exact=True)).to_have_count(7)
            page.get_by_role('button', name='Додати прийом', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Додати прийом', exact=True))
            dialog.get_by_role('combobox', name='День', exact=False).click()
            page.get_by_role('option', name='Тиждень 1 · День 1', exact=True).click()
            dialog.get_by_role('combobox', name='Тип прийому', exact=False).click()
            page.get_by_role('option', name='Синтетичний додатковий тип', exact=True).click()
            submit(dialog, 'Додати прийом')
            expect(page.get_by_text('Синтетичний додатковий тип', exact=True)).to_be_visible()
            page.get_by_role('button', name='Видалити порожній прийом', exact=True).click()
            dialog = page.get_by_role('alertdialog').filter(has=page.get_by_text('Видалити порожній прийом', exact=True))
            dialog.get_by_role('combobox', name='Прийом їжі', exact=False).click()
            with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                page.get_by_role('option', name='День 1 · Синтетичний додатковий тип', exact=True).click()
            submit(dialog, 'Видалити')
            expect(page.get_by_text('Синтетичний додатковий тип', exact=True)).to_have_count(0)
            page.get_by_role('button', name='Змінити прийом', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Змінити прийом', exact=True))
            expect(dialog.get_by_label('Назва прийому (необов’язково)', exact=False)).to_be_disabled()
            dialog.get_by_role('combobox', name='Прийом їжі', exact=False).click()
            with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                page.get_by_role('option', name='День 1 · Синтетичний прийом', exact=True).click()
            expect(dialog.get_by_label('Назва прийому (необов’язково)', exact=False)).to_be_enabled()
            expect(dialog.get_by_label('Назва прийому (необов’язково)', exact=False)).to_have_value('')
            dialog.get_by_label('Назва прийому (необов’язково)', exact=False).fill('Меню ' + suffix)
            dialog.get_by_label('Порядок у дні', exact=False).fill('-1')
            submit(dialog, 'Зберегти')
            expect(page.get_by_text('Меню ' + suffix, exact=True)).to_be_visible()
            page.get_by_role('button', name='Додати 7 днів', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Додати наступний семиденний тиждень', exact=True))
            dialog.locator('input[type=checkbox][value="' + str(f['type']) + '"]').check()
            submit(dialog, 'Додати тиждень')
            expect(page.get_by_text('Синтетичний прийом', exact=True)).to_have_count(14)
            page.reload()
            assert page.url == draft_url
            expect(page.get_by_text('Меню ' + suffix, exact=True)).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.screenshot(path=str(output / f'structure-{suffix}.png'), full_page=True)
            version_id = int(draft_url.rsplit('/', 1)[1])
            page.get_by_role('button', name='Додати 7 днів', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Додати наступний семиденний тиждень', exact=True))
            dialog.locator('input[type=checkbox][value="' + str(f['type']) + '"]').check()
            submit(dialog, 'Додати тиждень')
            before_delete = json.loads(fixture('week-fill', version_id, 8))
            page.reload()
            page.get_by_role('button', name='Видалити тиждень', exact=True).click()
            page.get_by_text('Тиждень 2 · Позицій: 2', exact=True).click()
            dialog = page.get_by_role('alertdialog').filter(has=page.get_by_text('Видалити тиждень', exact=True))
            expect(dialog.get_by_text('Позицій: 2.', exact=False)).to_be_visible()
            expect(dialog.get_by_text('Дію неможливо скасувати.', exact=False)).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.screenshot(path=str(output / f'week-confirm-{suffix}.png'))
            submit(dialog, 'Скасувати')
            assert json.loads(fixture('week-state', version_id)) == before_delete
            page.get_by_role('button', name='Видалити тиждень', exact=True).click()
            page.get_by_text('Тиждень 2 · Позицій: 2', exact=True).click()
            dialog = page.get_by_role('alertdialog').filter(has=page.get_by_text('Видалити тиждень', exact=True))
            submit(dialog, 'Видалити тиждень')
            after_delete = json.loads(fixture('week-state', version_id))
            expected_days = [dict(d, day_number=d['day_number'] - (7 if d['day_number'] > 14 else 0))
                             for d in before_delete['days'] if not 8 <= d['day_number'] <= 14]
            assert after_delete['days'] == expected_days
            kept_ids = {d['id'] for d in expected_days}
            assert after_delete['meals'] == [m for m in before_delete['meals'] if m['meal_plan_day_id'] in kept_ids]
            assert after_delete['items'] == []
            expect(page.get_by_text('Синтетичний прийом', exact=True)).to_have_count(14)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.screenshot(path=str(output / f'week-renumbered-{suffix}.png'), full_page=True)
            page.get_by_role('button', name='Видалити тиждень', exact=True).click()
            with page.expect_navigation(wait_until='networkidle'):
                with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST') as response:
                    page.get_by_text('Тиждень 2 · Порожній', exact=True).click()
            assert response.value.status == 200
            page.wait_for_load_state('networkidle')
            expect(page.get_by_role('alertdialog')).to_have_count(0)
            expect(page.get_by_role('dialog')).to_have_count(0)
            expect(page.get_by_text('Синтетичний прийом', exact=True)).to_have_count(7)
            assert [d['day_number'] for d in json.loads(fixture('week-state', version_id))['days']] == list(range(1, 8))
            page.get_by_role('button', name='Додати 7 днів', exact=True).click()
            dialog = page.get_by_role('dialog').filter(has=page.get_by_text('Додати наступний семиденний тиждень', exact=True))
            dialog.locator('input[type=checkbox][value="' + str(f['type']) + '"]').check()
            submit(dialog, 'Додати тиждень')
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
            checks.append(suffix + ': create/list/search/plan/exact version/generate/edit/append 21/filled week cancel+confirm/middle renumber stable IDs/empty week no modal/reload/published hierarchy, no overflow')
            print('Meal plans Chromium: ' + suffix + ' PASS', flush=True)
    # Нові чернетки очікувані; історичні дані незмінні, перевіряються окремо нижче.
    page.goto(published)
    before = fixture('hash')
    page.reload()
    assert fixture('hash') == before
    assert history == before  # створення drafts зберегло історичні rows
    fixture('week-fill', version_id, 1)
    page.goto(draft_url)
    page.get_by_role('button', name='Видалити тиждень', exact=True).click()
    page.get_by_text('Тиждень 1 · Позицій: 2', exact=True).click()
    expect(page.get_by_role('alertdialog').get_by_text('Позицій: 2.', exact=False)).to_be_visible()
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
    checks.append('Missing CSRF rejects signed week-delete/create 419; guest/MFA boundary shared with recipe browser')
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
    data['checks'].append('Revoked signed confirmed week-delete/create/HTTP 403, unchanged structure/plans/history/foods/assignments')
    result.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')
