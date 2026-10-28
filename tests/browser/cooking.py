"""Кухонний Filament editor; лише synthetic PG18 + штатний MFA контекст."""
import json
from pathlib import Path
import subprocess
from playwright.sync_api import expect


def run_cooking(page, base, fixture=None):
    def invoke(*args):
        if fixture:
            return fixture(*args)
        return subprocess.check_output(['php', 'tests/browser/cooking-fixture.php', *map(str, args)], text=True).strip()

    version = invoke()
    url = base + '/admin/cooking-templates/' + str(version)
    guest = page.context.browser.new_context()
    assert guest.request.get(url).status in (302, 403)
    guest.close()
    assert page.context.request.post(base + '/admin/livewire/update', data='{}', headers={'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Livewire': 'true'}).status == 419
    page.goto(url)
    expect(page.get_by_text('Попередній перегляд збереженого мегарецепта', exact=True)).to_be_visible()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    output = Path('docs/evidence/cooking-editor')
    output.mkdir(parents=True, exist_ok=True)
    checks = []
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate("theme => {localStorage.setItem('theme', theme); document.documentElement.classList.toggle('dark', theme === 'dark')}", theme)
            page.screenshot(path=str(output / f'{width}-{theme}.png'), full_page=True)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2')
    checks.append('1440/390/320 light/dark, без горизонтального overflow')
    page.set_viewport_size({'width': 1440, 'height': 1000})
    # Редактор етапів — справжній Filament modal/repeater, доступний з сесії.
    page.get_by_role('button', name='Сесія / етапи', exact=True).last.click()
    modal = page.get_by_role('dialog').filter(has=page.get_by_text('Етапи', exact=True))
    expect(modal.get_by_role('textbox', name='Назва етапу')).to_be_visible()
    modal.get_by_role('button', name='Дублювати', exact=True).first.click()
    expect(modal.get_by_role('textbox', name='Назва етапу')).to_have_count(2)
    modal.get_by_role('textbox', name='Назва етапу').nth(1).fill('Фасування та зберігання')
    modal.get_by_role('button', name='Перемістити вгору', exact=True).and_(page.locator(':visible:enabled')).first.click()
    modal.get_by_role('button', name='Згорнути', exact=True).first.click()
    modal.locator('button[type=submit]').click()
    expect(page.locator('.fi-modal-open')).to_have_count(0)
    expect(page.get_by_text('Фасування та зберігання', exact=True)).to_be_visible()
    checks.append('Filament repeaters: clone/reorder/collapse/save етапів')
    # Стара вкладка не може підтвердити змінений aggregate.
    invoke('touch', version)
    page.get_by_role('button', name='Підтвердити безпеку', exact=True).click()
    modal = page.get_by_role('dialog').filter(has=page.get_by_text('Технологічна вода не підставляється токенами', exact=True))
    expect(modal.get_by_role('checkbox')).to_have_count(4)
    for checkbox in modal.get_by_role('checkbox').all():
        checkbox.check()
    modal.locator('button[type=submit]').click()
    expect(page.locator('.fi-modal-open')).to_have_count(0)
    expect(page.get_by_text('Чернетка змінилася або вже опублікована.', exact=True)).to_be_visible()
    checks.append('Стара вкладка: review відхилено після конкурентного edit')
    page.reload()
    page.get_by_role('button', name='Підтвердити безпеку', exact=True).click()
    modal = page.get_by_role('dialog').filter(has=page.get_by_text('Технологічна вода не підставляється токенами', exact=True))
    expect(modal.get_by_role('checkbox')).to_have_count(4)
    for checkbox in modal.get_by_role('checkbox').all():
        checkbox.check()
    modal.locator('button[type=submit]').click()
    expect(page.locator('.fi-modal-open')).to_have_count(0)
    expect(page.get_by_text('Поточну збережену редакцію підтверджено автором.', exact=True)).to_be_visible()
    page.get_by_role('button', name='Опублікувати', exact=True).click()
    page.get_by_role('alertdialog').get_by_role('button', name='Опублікувати', exact=True).click()
    expect(page.locator('.fi-modal-open')).to_have_count(0)
    expect(page.get_by_text('Опублікована (незмінна)', exact=False)).to_be_visible()
    expect(page.get_by_role('button', name='Сесія / етапи', exact=True)).to_have_count(0)
    page.get_by_role('button', name='Активувати цю версію', exact=True).click()
    page.get_by_role('alertdialog').get_by_role('button', name='Активувати', exact=True).click()
    expect(page.get_by_text('Чинна версія цього слота: #' + str(version), exact=True)).to_be_visible()
    checks.append('Review → publish (readonly) → окрема activation')
    assert not errors, errors
    (output / 'checks.json').write_text(json.dumps({'checks': checks, 'page_errors': errors}, ensure_ascii=False, indent=2) + '\n')
