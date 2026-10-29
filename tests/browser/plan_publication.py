"""T4.4: конкретні помилки, cancel, stale confirmation, publish/reload/readonly."""
import json
from pathlib import Path
from playwright.sync_api import expect
from meal_plans import fixture, management
from readiness import livewire_ready


def run_plan_publication(page, base, f, suffix, submit, from_day=False):
    state = json.loads(fixture('repeat-seed', f['type'], f['extra_type'], f['food']))
    version = state['version']
    url = base + '/admin/meal-plan-versions/' + str(version)
    entry = url + '/days/' + str(state['day']) if from_day else url
    page.goto(entry)
    before = fixture('week-state', version)
    management(page, 'Опублікувати')
    expect(page.get_by_text('День 8', exact=False).first).to_be_visible()
    assert fixture('week-state', version) == before
    fixture('publication-fill', version)
    page.reload()
    management(page, 'Опублікувати')
    # Filament confirmation має zero-height wrapper і fixed window; перевіряємо видиме вікно.
    dialog = page.get_by_role('alertdialog').filter(has=page.get_by_role('heading', name='Опублікувати версію раціону', exact=True)).locator('.fi-modal-window')
    expect(dialog).to_be_visible()
    expect(dialog.get_by_text('Перевірено всі тижні, дні, прийоми, позиції та грамування.', exact=False)).to_be_visible()
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    before = fixture('week-state', version)
    submit(dialog, 'Скасувати')
    assert fixture('week-state', version) == before
    management(page, 'Опублікувати')
    fixture('week-fill', version, 8 if from_day else 1)
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_role('button', name='Опублікувати', exact=True).click()
    expect(page.get_by_text('Раціон змінився.', exact=False).first).to_be_visible()
    submit(dialog, 'Скасувати')
    management(page, 'Опублікувати')
    expect(dialog).to_be_visible()
    page.screenshot(path=str(Path('docs/evidence/meal-plans') / f'publish-confirm-{suffix}.png'), full_page=True)
    with page.expect_response(lambda r: '/livewire/update' in r.url) as response:
        dialog.get_by_role('button', name='Опублікувати', exact=True).click()
    assert response.value.status == 200
    # SPA redirect завершується після HTTP commit; чекати кінцеву адресу та DOM.
    expect(page).to_have_url(url)
    expect(page.get_by_text('Опубліковано · лише перегляд', exact=True)).to_be_visible()
    page.reload()
    livewire_ready(page)
    expect(page.get_by_text('Опубліковано · лише перегляд', exact=True)).to_be_visible()
    expect(page.locator('.plan-day-cta')).to_have_count(0)
    expect(page.get_by_role('button', name='Опублікувати', exact=True)).to_have_count(0)
    page.goto(url + '/days/' + str(state['day']))
    expect(page.locator('[draggable=true]')).to_have_count(0)
    expect(page.get_by_role('button', name='Опублікувати', exact=True)).to_have_count(0)
    expect(page.get_by_role('heading', name='Каталог страв і продуктів', exact=True)).to_have_count(0)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=str(Path('docs/evidence/meal-plans') / f'publish-readonly-{suffix}.png'), full_page=True)
