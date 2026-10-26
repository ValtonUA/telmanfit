"""T4.3R: copy, explicit replacement/cancel, independent day edit, stale modal."""
import json
from pathlib import Path
from playwright.sync_api import expect
from meal_plans import fixture, management, settle_overlays
from readiness import livewire_ready


def run_week_repetition(page, base, f, suffix, submit):
    state = json.loads(fixture('repeat-seed', f['type'], f['extra_type'], f['food']))
    version = state['version']
    url = base + '/admin/meal-plan-versions/' + str(version)
    page.goto(url)
    before = fixture('week-edit-empty', version, 8, 'Авторський прийом без страв', -9)
    management(page, 'Повторити тиждень')
    dialog = page.get_by_role('dialog').filter(has=page.get_by_role('heading', name='Повторити тиждень', exact=True))
    expect(dialog.get_by_label('Вихідний тиждень', exact=False)).to_have_value('1')
    dialog.get_by_label('Кількість тижнів загалом', exact=False).fill('4')
    dialog.get_by_label('Кількість тижнів загалом', exact=False).blur()
    expect(dialog.get_by_text('Тиждень 1 → цільові тижні 2–4.', exact=False)).to_be_visible()
    assert fixture('week-state', version) == before
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=str(Path('docs/evidence/meal-plans') / f'repeat-form-{suffix}.png'), full_page=True)
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_role('button', name='Повторити', exact=True).click()
    livewire_ready(page)
    confirm = page.get_by_role('alertdialog').filter(has=page.get_by_role('heading', name='Замінити цільові тижні', exact=True))
    expect(confirm.get_by_text('тиждень 2 — прийомів: 14, позицій: 0', exact=False)).to_be_visible()
    expect(confirm.get_by_text('тиждень 3 —', exact=False)).to_have_count(0)
    assert fixture('week-state', version) == before
    settle_overlays(page)
    page.screenshot(path=str(Path('docs/evidence/meal-plans') / f'repeat-empty-confirm-{suffix}.png'), full_page=True)
    submit(confirm, 'Скасувати')
    assert fixture('week-state', version) == before
    management(page, 'Повторити тиждень')
    dialog.get_by_label('Кількість тижнів загалом', exact=False).fill('4')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_role('button', name='Повторити', exact=True).click()
    expect(confirm.get_by_text('тиждень 2 — прийомів: 14, позицій: 0', exact=False)).to_be_visible()
    stale_empty = fixture('week-edit-empty', version, 8, 'Нова правка без страв', 7)
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        confirm.get_by_role('button', name='Так, замінити цільові тижні', exact=True).click()
    expect(page.get_by_text('Раціон змінився.', exact=False).first).to_be_visible()
    assert fixture('week-state', version) == stale_empty
    submit(confirm, 'Скасувати')
    management(page, 'Повторити тиждень')
    dialog.get_by_label('Кількість тижнів загалом', exact=False).fill('4')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_role('button', name='Повторити', exact=True).click()
    expect(confirm.get_by_text('тиждень 2 — прийомів: 14, позицій: 0', exact=False)).to_be_visible()
    submit(confirm, 'Так, замінити цільові тижні')
    copied = json.loads(fixture('week-state', version))
    assert len(copied['days']) == 28
    assert len(copied['items']) == 4 * len(json.loads(before)['items'])
    page.reload()
    assert json.loads(fixture('week-state', version)) == copied
    days = {d['day_number']: d['id'] for d in copied['days']}
    page.goto(url + '/days/' + str(days[15]))
    row = page.locator('[data-item]').first
    item = int(row.get_attribute('data-item'))
    row.locator('summary').click()
    row.get_by_role('button', name='Змінити', exact=True).click()
    edit = page.get_by_role('dialog').filter(has=page.get_by_role('heading', name='Змінити позицію', exact=True))
    edit.get_by_label('Споживана кількість', exact=False).fill('9.25')
    submit(edit, 'Зберегти')
    changed = json.loads(fixture('week-state', version))
    assert [i for i in copied['items'] if i['id'] != item] == [i for i in changed['items'] if i['id'] != item]
    assert next(i for i in changed['items'] if i['id'] == item)['quantity'] == '9.25'
    page.goto(url)
    management(page, 'Повторити тиждень')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_role('button', name='Повторити', exact=True).click()
    livewire_ready(page)
    confirm = page.get_by_role('alertdialog').filter(has=page.get_by_role('heading', name='Замінити цільові тижні', exact=True))
    expect(confirm.get_by_role('heading', name='Замінити цільові тижні', exact=True)).to_be_visible()
    raw = next(e.get_attribute('wire:snapshot') for e in page.locator('[wire\\:snapshot]').all()
               if 'mountedActions' in json.loads(e.get_attribute('wire:snapshot'))['data'])
    payload = {'components': [{'snapshot': raw, 'updates': {}, 'calls': [{'method': 'callMountedAction', 'params': []}]}]}
    assert page.context.request.post(base + '/admin/livewire/update', data=payload, headers={'X-Livewire': 'true', 'Accept': 'application/json'}).status == 419
    expect(confirm.get_by_text('тиждень 2 — прийомів: 14, позицій: 42; тиждень 3 — прийомів: 14, позицій: 42; тиждень 4 — прийомів: 14, позицій: 42', exact=False)).to_be_visible()
    assert json.loads(fixture('week-state', version)) == changed
    settle_overlays(page)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=str(Path('docs/evidence/meal-plans') / f'repeat-confirm-{suffix}.png'), full_page=True)
    submit(confirm, 'Скасувати')
    assert json.loads(fixture('week-state', version)) == changed
    page.goto(url + '/days/' + str(days[15]))
    management(page, 'Повторити тиждень')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_role('button', name='Повторити', exact=True).click()
    livewire_ready(page)
    with page.expect_navigation(wait_until='load'):
        submit(confirm, 'Так, замінити цільові тижні')
    page.wait_for_url(lambda target: '/days/' not in target and '/admin/meal-plan-versions/' in target, wait_until='load')
    replaced = json.loads(fixture('week-state', version))
    source_meals = {m['id'] for m in copied['meals'] if m['meal_plan_day_id'] in set(days[n] for n in range(1, 8))}
    assert [i for i in replaced['items'] if i['meal_id'] in source_meals] == [i for i in copied['items'] if i['meal_id'] in source_meals]
    assert item not in {i['id'] for i in replaced['items']}
    management(page, 'Повторити тиждень')
    fixture('week-fill', version, 8)
    stale = fixture('week-state', version)
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_role('button', name='Повторити', exact=True).click()
    expect(page.get_by_text('Раціон змінився.', exact=False).first).to_be_visible()
    assert fixture('week-state', version) == stale
    submit(dialog, 'Скасувати')
    page.reload()
    # Якщо жодного цільового тижня ще немає, перший submit одразу створює копії.
    single = json.loads(fixture('repeat-single-seed', f['type'], f['extra_type'], f['food']))
    page.goto(base + '/admin/meal-plan-versions/' + str(single['version']))
    management(page, 'Повторити тиждень')
    dialog.get_by_label('Кількість тижнів загалом', exact=False).fill('4')
    submit(dialog, 'Повторити')
    assert len(json.loads(fixture('week-state', single['version']))['days']) == 28
    return payload
