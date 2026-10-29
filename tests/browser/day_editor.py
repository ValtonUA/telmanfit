"""UX-4E: справжній DnD, touch pointer adapter, явні порції та доступні команди."""
import json
from pathlib import Path
from playwright.sync_api import expect
from meal_plans import fixture


def run_day_editor(page, base, f, suffix, submit, compact_only=False):
    output = Path('docs/evidence/meal-plans')
    state = json.loads(fixture('editor-seed', f['type'], f['extra_type'], f['food']))
    overview = base + '/admin/meal-plan-versions/' + str(state['version'])
    page.goto(overview)
    expect(page.locator('.plan-day')).to_have_count(7)
    expect(page.locator('.plan-day-cta')).to_have_count(7)
    expect(page.locator('.plan-day-body button')).to_have_count(0)
    expect(page.get_by_role('link', name='Редагувати день', exact=True)).to_have_count(7 if suffix.startswith('1440') else 1)
    if suffix.startswith('1440'):
        boxes = page.locator('.plan-day').evaluate_all('(els) => els.map(e => e.getBoundingClientRect().top)')
        assert len(set(boxes)) == 1
        assert len(page.locator('.plan-day:visible').all()) == 7
    else:
        expect(page.locator('.plan-day:visible')).to_have_count(1)
        page.locator('.plan-mobile-picker select').select_option(str(state['day'] + 1))
        expect(page.locator('.plan-day:visible')).to_have_count(1)
        page.locator('.plan-mobile-picker select').select_option(str(state['day']))
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.evaluate('scrollTo(0,0)')
    page.screenshot(path=str(output / f'week-dense-{suffix}.png'), full_page=True)
    page.get_by_role('button', name='Наступний тиждень', exact=True).click()
    expect(page.get_by_role('link', name='Заповнити день', exact=True)).to_have_count(7 if suffix.startswith('1440') else 1)
    expect(page.locator('.plan-day-body h3')).to_have_count(0)
    page.evaluate('scrollTo(0,0)')
    page.screenshot(path=str(output / f'week-empty-{suffix}.png'), full_page=True)
    page.get_by_role('link', name='Заповнити день', exact=True).first.click()
    expect(page.get_by_role('heading', name='Прийоми дня', exact=True)).to_be_visible()
    expect(page.locator('.plan-meal')).to_have_count(2)
    page.get_by_role('link', name='До огляду тижня', exact=True).click()
    expect(page.get_by_text('Тиждень 2 / 2', exact=True)).to_be_visible()
    page.get_by_role('button', name='Попередній тиждень', exact=True).click()
    page.get_by_role('link', name='Редагувати день', exact=True).first.click()
    expect(page.get_by_role('heading', name='Каталог страв і продуктів', exact=True)).to_be_visible()
    day_url = page.url
    columns = page.locator('.plan-catalog-list').evaluate("e => getComputedStyle(e).gridTemplateColumns.split(' ').length")
    assert columns == (3 if suffix.startswith('1440') else 1)
    if suffix.startswith('1440'):
        expect(page.get_by_role('button', name='Далі', exact=True)).to_be_enabled()
        assert page.locator('.plan-catalog').evaluate('(e) => e.scrollHeight > e.clientHeight')
        assert page.locator('.plan-meals-panel').evaluate('(e) => e.scrollHeight > e.clientHeight')
        document_y = page.evaluate('scrollY')
        catalog_panel = page.locator('.plan-catalog')
        catalog_panel.hover()
        page.mouse.wheel(0, 350)
        page.wait_for_function("document.querySelector('.plan-catalog').scrollTop > 0")
        assert page.locator('.plan-meals-panel').evaluate('(e) => e.scrollTop === 0')
        assert page.evaluate('scrollY') == document_y
        catalog_panel.evaluate('(e) => e.scrollTop = 0')
        meals_panel = page.locator('.plan-meals-panel')
        meals_panel.hover()
        page.mouse.wheel(0, 350)
        page.wait_for_function("document.querySelector('.plan-meals-panel').scrollTop > 0")
        assert catalog_panel.evaluate('(e) => e.scrollTop === 0')
        assert page.evaluate('scrollY') == document_y
        meals_panel.evaluate('(e) => e.scrollTop = 0')
        page.set_viewport_size({'width': 1024, 'height': 1000})
        columns = page.locator('.plan-catalog-list').evaluate("e => getComputedStyle(e).gridTemplateColumns.split(' ').length")
        assert columns == 2
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        page.set_viewport_size({'width': 1440, 'height': 1000})
    source = page.locator('[data-drop-meal="' + str(state['meals'][0]) + '"].plan-meal')
    target = page.locator('[data-drop-meal="' + str(state['meals'][1]) + '"].plan-meal')
    expect(source.locator('[data-item]')).to_have_count(3)
    search = page.get_by_label('Пошук каталогу', exact=False)
    search.fill('nothing-synthetic-UX-4E')
    expect(page.get_by_text('Нічого не знайдено.', exact=False)).to_be_visible()
    search.fill('Синтетична страва UX-4')
    expect(search).to_be_focused()
    food = page.locator('[data-food="' + str(state['food']) + '"]')
    expect(food).to_be_visible()
    def add_food(card, meal, touch_input=True):
        page.get_by_label('Додати до прийому', exact=True).select_option(str(meal))
        button = card.locator('.plan-catalog-add')
        with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST') as response:
            if suffix.startswith('320'):
                button.focus()
                page.keyboard.press('Enter')
            elif suffix.startswith('390') and touch_input:
                button.scroll_into_view_if_needed()
                box = button.bounding_box()
                page.touchscreen.tap(box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
            else:
                button.click()
        add_dialog = page.get_by_role('dialog').filter(has=page.get_by_role('heading', name='Додати позицію', exact=True))
        assert response.value.status == 200
        expect(add_dialog.get_by_role('heading', name='Додати позицію', exact=True)).to_be_visible()
        meal_name = 'Синтетичний прийом' if meal == state['meals'][0] else 'Синтетичний додатковий тип'
        expect(add_dialog.get_by_role('button', name='Прийом їжі', exact=False)).to_contain_text(meal_name)
        return add_dialog

    before = fixture('week-state', state['version'])
    dialog = add_food(food, state['meals'][1])
    expect(dialog.get_by_label('Споживана кількість', exact=False)).to_have_value('')
    assert fixture('week-state', state['version']) == before
    submit(dialog, 'Скасувати')
    assert fixture('week-state', state['version']) == before
    dialog = add_food(food, state['meals'][0])
    dialog.get_by_label('Споживана кількість', exact=False).fill('0.50')
    dialog.get_by_role('combobox', name='Одиниця', exact=False).click()
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('option', name='gram', exact=True).click()
    expect(dialog.get_by_text('На споживану кількість:', exact=False)).to_contain_text('1 ккал')
    submit(dialog, 'Додати')
    expect(source.locator('[data-item]')).to_have_count(4)
    page.reload()
    expect(source.locator('[data-item]')).to_have_count(4)
    items = source.locator('[data-item]').evaluate_all('(els) => els.map(e => Number(e.dataset.item))')
    moved = items[-1]
    # Реальне перенесення тієї самої persisted позиції.
    row = page.locator('[data-item="' + str(moved) + '"]')
    if suffix.startswith('1440'):
        row.locator('.plan-drag-handle').evaluate('(e) => window.scrollTo(0, scrollY + e.getBoundingClientRect().top - 250)')
        with page.expect_response(lambda r: '/livewire/update' in r.url and any(call['method'] == 'placeItem' for comp in r.request.post_data_json.get('components', []) for call in comp.get('calls', []))):
            row.locator('.plan-drag-handle').drag_to(target.locator('h3'))
        page.wait_for_function("!Alpine.$data(document.querySelector('.plan-editor')).busy")
    else:
        row.locator('summary').click()
        row.get_by_role('combobox').select_option(str(state['meals'][1]))
    expect(target.locator('[data-item="' + str(moved) + '"]')).to_have_count(1)
    expect(source.locator('[data-item]')).to_have_count(3)
    assert abs(page.locator('.fi-topbar').bounding_box()['y']) <= 1
    page.screenshot(path=str(output / f'day-after-drop-{suffix}.png'))
    # Keyboard reorder та повторний DnD використовують свіжий snapshot після morph.
    row = target.locator('[data-item="' + str(moved) + '"]')
    if suffix.startswith('1440'):
        row.locator('.plan-drag-handle').evaluate('(e) => window.scrollTo(0, scrollY + e.getBoundingClientRect().top - 650)')
        row.locator('.plan-drag-handle').drag_to(target.locator('[data-item]').nth(2))
        expect(target.locator('[data-item]').nth(2)).to_have_attribute('data-item', str(moved))
    else:
        row.locator('summary').click()
        row.get_by_role('button', name='Вище:', exact=False).focus()
        page.keyboard.press('Enter')
        expect(target.locator('[data-item]').nth(2)).to_have_attribute('data-item', str(moved))
        # Trusted Chromium touch input проходить через той самий Pointer adapter.
        handle = target.locator('[data-item="' + str(moved) + '"] .plan-drag-handle')
        handle.evaluate('(e) => window.scrollTo(0, scrollY + e.getBoundingClientRect().top - 650)')
        dest = target.locator('[data-item]').nth(1)
        origin = handle.bounding_box()
        box = dest.bounding_box()
        touch = page.context.new_cdp_session(page)
        touch.send('Emulation.setTouchEmulationEnabled', {'enabled': True, 'maxTouchPoints': 1})
        touch.send('Input.dispatchTouchEvent', {'type': 'touchStart', 'touchPoints': [{'x': origin['x'] + 15, 'y': origin['y'] + 15}]})
        touch.send('Input.dispatchTouchEvent', {'type': 'touchMove', 'touchPoints': [{'x': box['x'] + 20, 'y': box['y'] + 20}]})
        touch.send('Input.dispatchTouchEvent', {'type': 'touchEnd', 'touchPoints': []})
        touch.send('Emulation.setTouchEmulationEnabled', {'enabled': False})
        touch.detach()
        expect(target.locator('[data-item]').nth(1)).to_have_attribute('data-item', str(moved))
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    assert page.locator('.fi-no-notification').count() <= 1
    for toast in page.locator('.fi-no-notification:visible').all():
        assert toast.bounding_box()['y'] >= page.locator('.fi-topbar').bounding_box()['height']
    page.evaluate('scrollTo(0,0)')
    page.screenshot(path=str(output / f'day-editor-{suffix}.png'), full_page=True)
    if compact_only:
        page.get_by_role('link', name='До огляду тижня', exact=True).click()
        expect(page.get_by_text('Тиждень 1 / 2', exact=True)).to_be_visible()
        print('UX-4 compact browser path ' + suffix + ': find, target, touch/keyboard/mouse add, persist, return to week PASS', flush=True)
        return
    page.get_by_label('Тип каталогу', exact=False).select_option('recipe_version')
    page.get_by_label('Пошук каталогу', exact=False).fill('Синтетичний batch writer')
    batch_food = page.locator('.plan-catalog-item').filter(has_text='Синтетичний batch writer').first
    expect(batch_food).to_be_visible()
    for fmt, divisor, label in [('A — одна порція', 'A/1 — ціла одна порція', 'A/1'), ('B — партія на 7 порцій', 'B/7 — одна сьома партії', 'B/7'), ('C — половина тижневої партії', 'C/3 — одна третина половини партії', 'C/3'), ('C — половина тижневої партії', 'C/4 — одна четверта половини партії', 'C/4')]:
        dialog = add_food(batch_food, state['meals'][0], touch_input=False)
        dialog.get_by_role('combobox', name='Формат фізичної партії', exact=False).click()
        with page.expect_response(lambda r: '/livewire/update' in r.url):
            page.get_by_role('option', name=fmt, exact=True).click()
        dialog.get_by_role('combobox', name='Поділ партії на порції', exact=False).click()
        with page.expect_response(lambda r: '/livewire/update' in r.url):
            page.get_by_role('option', name=divisor, exact=True).click()
        dialog.get_by_label('Споживана кількість', exact=False).fill('0.50')
        dialog.get_by_role('combobox', name='Одиниця', exact=False).click()
        with page.expect_response(lambda r: '/livewire/update' in r.url):
            page.get_by_role('option', name='порція', exact=True).click()
        expect(dialog.get_by_text('На споживану кількість:', exact=False)).to_contain_text('Формат: ' + label)
        page.screenshot(path=str(output / f'day-portion-{label.replace("/", "-")}-{suffix}.png'))
        submit(dialog, 'Додати')
        expect(source.get_by_text(label + ' · ', exact=False)).to_be_visible()
        page.reload()
    assert page.url.split('?')[0] == day_url.split('?')[0]
    expect(page.get_by_label('Пошук каталогу', exact=False)).to_have_value('Синтетичний batch writer')
    expect(page.get_by_label('Тип каталогу', exact=False)).to_have_value('recipe_version')
    page.get_by_role('link', name='До огляду тижня', exact=True).click()
    expect(page.get_by_text('Тиждень 1 / 2', exact=True)).to_be_visible()
    print('UX-4E Chromium ' + suffix + ': dense/empty/exact week, catalog search, copy cancel/confirm, mouse/pointer/button move/reorder, A/B/C explicit portions and reload PASS', flush=True)
