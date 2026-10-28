"""Кухонний Filament editor; лише synthetic PG18 + штатний MFA контекст."""
import json
from pathlib import Path
import subprocess
from playwright.sync_api import expect


def run_cooking(page, base, fixture=None):
    def invoke(*args):
        if fixture:
            return fixture(*args)
        result = subprocess.run(['php', 'tests/browser/cooking-fixture.php', *map(str, args)], text=True,
                                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=False, timeout=60)
        if result.returncode != 0:
            raise AssertionError(f'Cooking fixture exited {result.returncode}: {result.stdout}')
        return result.stdout.strip().splitlines()[-1]

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
    fixture('client', version)
    client = page.context.new_page()
    client.set_default_timeout(15000)
    client_errors = []
    client.on('pageerror', lambda e: client_errors.append(str(e)))
    client.route('https://images.example.com/**', lambda route: route.fulfill(content_type='image/png', body=Path('client/src/assets/hero.png').read_bytes()))
    client.goto(base + '/app/login')
    client.get_by_label('Електронна пошта', exact=True).fill('cooking-client@example.invalid')
    client.get_by_role('button', name='Отримати код', exact=True).click()
    expect(client.get_by_label('Код із листа', exact=True)).to_be_visible()
    subprocess.check_output(['php', 'artisan', 'queue:work', '--stop-when-empty', '--tries=1', '--sleep=0'], text=True)
    code = subprocess.check_output(['php', 'tests/browser/auth5-fixture.php', 'mail', 'cooking-client@example.invalid'], text=True).strip()
    client.get_by_label('Код із листа', exact=True).fill(code)
    client.get_by_role('button', name='Підтвердити', exact=True).click()
    client.goto(base + '/app/meal-plan')
    client.get_by_role('link', name='Помічник приготування', exact=True).click()
    expect(client.get_by_role('heading', name='Помічник приготування')).to_be_visible()
    expect(client.locator('.cook-template').filter(has_text='Синтетична кухня')).to_be_visible()
    client.locator('.cook-session-choice').first.click()
    expect(client.get_by_text('Синтетичний вступ автора.')).to_be_visible()
    expect(client.get_by_text('Каструля · синтетичний fixture')).to_be_visible()
    expect(client.locator('.cook-ingredients li')).to_have_count(1)
    expect(client.get_by_role('heading', name='Що приготуємо')).to_be_visible()
    client.get_by_role('button', name='Почати', exact=True).click()
    expect(client.locator('.cook-actions .cook-timer')).to_be_visible()
    expect(client.locator('.cook-image-button img')).to_be_visible()
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            client.set_viewport_size({'width': width, 'height': 1000})
            client.emulate_media(color_scheme=theme)
            assert client.evaluate('document.documentElement.scrollWidth <= innerWidth')
            client.screenshot(path=str(output / f'client-{width}-{theme}.png'), full_page=True)
    assert not client_errors, client_errors
    client.close()
    checks.append('Filament publish/activate → ClientSession OTP → exact session/equipment/ingredients/timer/image, 1440/390/320 light/dark')
    assert not errors, errors
    (output / 'checks.json').write_text(json.dumps({'checks': checks, 'page_errors': errors}, ensure_ascii=False, indent=2) + '\n')
