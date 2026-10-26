"""T4.4: конкретні помилки, cancel, stale confirmation, publish/reload/readonly."""
import json
from pathlib import Path
from playwright.sync_api import expect
from meal_plans import fixture, management
from readiness import livewire_ready


def run_plan_publication(page, base, f, suffix, submit):
    state = json.loads(fixture('repeat-seed', f['type'], f['extra_type'], f['food']))
    version = state['version']
    url = base + '/admin/meal-plan-versions/' + str(version)
    page.goto(url)
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
    before = fixture('week-state', version)
    submit(dialog, 'Скасувати')
    assert fixture('week-state', version) == before
    management(page, 'Опублікувати')
    fixture('week-fill', version, 1)
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_role('button', name='Опублікувати', exact=True).click()
    expect(page.get_by_text('Раціон змінився.', exact=False).first).to_be_visible()
    submit(dialog, 'Скасувати')
    management(page, 'Опублікувати')
    expect(dialog).to_be_visible()
    page.screenshot(path=str(Path('docs/evidence/meal-plans') / f'publish-confirm-{suffix}.png'), full_page=True)
    submit(dialog, 'Опублікувати')
    page.reload()
    livewire_ready(page)
    expect(page.get_by_text('Опублікована', exact=True)).to_be_visible()
    expect(page.locator('.plan-day-cta')).to_have_count(0)
    expect(page.get_by_role('button', name='Опублікувати', exact=True)).to_have_count(0)
    page.goto(url + '/days/' + str(state['day']))
    expect(page.locator('[draggable=true]')).to_have_count(0)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=str(Path('docs/evidence/meal-plans') / f'publish-readonly-{suffix}.png'), full_page=True)
