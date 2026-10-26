"""PR D: Chromium через справжню Filament/MFA сесію, лише synthetic PG18."""
import json
from pathlib import Path
from playwright.sync_api import expect
from readiness import livewire_ready


def run_catalog(page, base):
    output = Path('docs/evidence/food-catalog')
    output.mkdir(parents=True, exist_ok=True)
    checks = []

    def submit(label):
        with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST') as response:
            page.get_by_role('button', name=label, exact=True).click()
        assert response.value.status == 200
        livewire_ready(page)

    def choose(label, text):
        combo = page.get_by_role('button', name=label, exact=False)
        combo.click()
        page.get_by_role('option', name=text, exact=True).click()

    # Справжній порожній каталог, до створення synthetic категорій.
    page.goto(base + '/admin/catalog/kitchen-equipment-categories')
    expect(page.get_by_text('Довідник порожній або немає результатів', exact=True)).to_be_visible()
    checks.append('Порожній каталог: ручне створення без seeds')

    responsive_rows = {}
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            suffix = f'{width}-{theme}'
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            page.goto(base + '/admin/catalog/kitchen-equipment-categories')
            search = page.get_by_placeholder('Пошук')
            if search.count():
                with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                    search.fill('No matching synthetic category')
            expect(page.get_by_text('Довідник порожній або немає результатів', exact=True)).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            page.screenshot(path=str(output / f'empty-{suffix}.png'), full_page=True)
            if theme == 'dark':
                row = responsive_rows[width]
                page.goto(row['food_url'])
                expect(page.get_by_label('Назва', exact=False)).to_have_value(row['food_name'])
                expect(page.get_by_label('Грамів в одиниці', exact=False)).to_have_value('30.00')
                expect(page.get_by_label('За замовчуванням', exact=False)).to_be_checked()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                assert page.evaluate('document.documentElement.classList.contains("dark")')
                page.screenshot(path=str(output / f'ingredient-{suffix}.png'), full_page=True)

                page.goto(row['equipment_url'])
                expect(page.get_by_label('Назва', exact=False)).to_have_value(row['equipment_name'])
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                assert page.evaluate('document.documentElement.classList.contains("dark")')
                page.screenshot(path=str(output / f'equipment-{suffix}.png'), full_page=True)

                page.goto(base + '/admin/catalog/ingredients')
                search = page.get_by_placeholder('Пошук')
                with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                    search.fill(row['food_name'])
                expect(page.locator(r'[wire\:key*=".table.records."]')).to_have_count(1)
                expect(page.get_by_text(row['food_name'], exact=True)).to_be_visible()
                expect(page.get_by_role('button', name='Видалити', exact=True)).to_have_count(0)
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                page.screenshot(path=str(output / f'list-{suffix}.png'), full_page=True)
                checks.append(f'{suffix}: dark responsive forms, search, immutable conversion/default display, no overflow/delete')
                print('Catalog Chromium: ' + suffix + ' responsive PASS', flush=True)
                continue
            # Категорії продуктів/рецептів/обладнання: створення і редагування.
            for slug in ('ingredient-categories', 'recipe-categories', 'kitchen-equipment-categories'):
                page.goto(base + '/admin/catalog/' + slug + '/create')
                page.get_by_label('Назва', exact=False).fill(f'Synthetic {slug} {suffix}')
                submit('Створити')
                page.wait_for_url('**/edit')
                page.get_by_label('Назва', exact=False).fill(f'Synthetic {slug} edited {suffix}')
                submit('Зберегти')
                page.reload()
                expect(page.get_by_label('Назва', exact=False)).to_have_value(f'Synthetic {slug} edited {suffix}')
            # Додаткова synthetic UOM; жодного SKU/packaging.
            page.goto(base + '/admin/catalog/uoms/create')
            page.get_by_label('Код', exact=False).fill('synthetic-' + suffix)
            page.get_by_label('Позначення', exact=False).fill('Synthetic unit ' + suffix)
            page.get_by_label('Тип одиниці', exact=False).select_option('count')
            submit('Створити')
            page.wait_for_url('**/edit')
            expect(page.get_by_label('Код', exact=False)).to_be_disabled()
            # Продукт, decimal validation, optional category, factor/default.
            page.goto(base + '/admin/catalog/ingredients/create')
            page.get_by_label('Назва', exact=False).fill('Synthetic food ' + suffix)
            for label, value in [('Калорійність, ккал / 100 г', 'NaN'), ('Білки, г / 100 г', '10.00'), ('Жири, г / 100 г', '2.00'), ('Вуглеводи, г / 100 г', '5.00')]:
                page.get_by_label(label, exact=False).fill(value)
            submit('Створити')
            expect(page.get_by_text('Запис не збережено', exact=True)).to_be_visible()
            page.get_by_label('Калорійність, ккал / 100 г', exact=False).fill('123.45')
            choose('Категорія (необов’язково)', f'Synthetic ingredient-categories edited {suffix}')
            page.get_by_role('button', name='Додати одиницю', exact=True).click()
            choose('Одиниця', 'Synthetic unit ' + suffix)
            page.get_by_label('Грамів в одиниці', exact=False).fill('25.00')
            page.get_by_label('За замовчуванням', exact=False).check()
            submit('Створити')
            page.wait_for_url('**/edit')
            food_url = page.url
            food_name = 'Synthetic food edited ' + suffix
            page.get_by_label('Назва', exact=False).fill(food_name)
            page.get_by_label('Грамів в одиниці', exact=False).fill('30.00')
            submit('Зберегти')
            page.reload()
            expect(page.get_by_label('Грамів в одиниці', exact=False)).to_have_value('30.00')
            expect(page.get_by_label('За замовчуванням', exact=False)).to_be_checked()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            page.evaluate("window.scrollTo(0, 0)")
            page.screenshot(path=str(output / f'ingredient-{suffix}.png'), full_page=True)
            # Обладнання не має recipe min_value/scaling attributes.
            page.goto(base + '/admin/catalog/kitchen-equipment/create')
            page.get_by_label('Назва', exact=False).fill('Synthetic equipment ' + suffix)
            choose('Категорія (необов’язково)', f'Synthetic kitchen-equipment-categories edited {suffix}')
            submit('Створити')
            page.wait_for_url('**/edit')
            equipment_url = page.url
            equipment_name = 'Synthetic equipment edited ' + suffix
            page.get_by_label('Назва', exact=False).fill(equipment_name)
            submit('Зберегти')
            page.reload()
            expect(page.get_by_label('Назва', exact=False)).to_have_value('Synthetic equipment edited ' + suffix)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            page.evaluate("window.scrollTo(0, 0)")
            page.screenshot(path=str(output / f'equipment-{suffix}.png'), full_page=True)
            # Список, пошук, мобільна таблиця та відсутність Delete.
            page.goto(base + '/admin/catalog/ingredients')
            search = page.get_by_placeholder('Пошук')
            with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST'):
                search.fill('Synthetic food edited ' + suffix)
            expect(page.locator(r'[wire\:key*=".table.records."]')).to_have_count(1)
            expect(page.get_by_text('Synthetic food edited ' + suffix, exact=True)).to_be_visible()
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            expect(page.get_by_role('button', name='Видалити', exact=True)).to_have_count(0)
            page.evaluate("window.scrollTo(0, 0)")
            page.screenshot(path=str(output / f'list-{suffix}.png'), full_page=True)
            page.goto(food_url)
            responsive_rows[width] = {
                'food_url': food_url, 'food_name': food_name,
                'equipment_url': equipment_url, 'equipment_name': equipment_name,
            }
            print('Catalog Chromium: ' + suffix + ' PASS', flush=True)
            checks.append(f'{suffix}: 3 categories create/edit, UOM immutable code, ingredient validation/create/edit, conversion/default/reload, equipment create/edit, search, no overflow/delete')

    # Дві вкладки: CAS відхиляє stale writer із видимим поясненням.
    other = page.context.new_page()
    other.goto(page.url)
    page.get_by_label('Назва', exact=False).fill('Synthetic concurrent first')
    submit('Зберегти')
    other.get_by_label('Назва', exact=False).fill('Synthetic concurrent stale')
    other.get_by_role('button', name='Зберегти', exact=True).click()
    expect(other.get_by_text('Довідник змінився.', exact=False)).to_be_visible()
    other.close()
    link = page.get_by_role('link', name='Рецепти A/B/C', exact=True)
    toggle = page.get_by_role('button', name='Розгорнути бокову панель', exact=True)
    if toggle.is_visible():
        toggle.click()
    link.click()
    expect(page.get_by_role('heading', name='Рецепти A/B/C', exact=True)).to_be_visible()
    checks.append('CAS two tabs: stale rejected; navigation to existing recipe editor')
    (output / 'checks.json').write_text(json.dumps({'checks': checks}, ensure_ascii=False, indent=2) + '\n')


def verify_catalog_revocation(page, base):
    import subprocess
    page.goto(base + '/admin/catalog/ingredients')
    edit = page.get_by_role('link', name='Змінити', exact=True).first.get_attribute('href')
    page.goto(edit)
    snapshot = next(raw for raw in [e.get_attribute('wire:snapshot') for e in page.locator('[wire\\:snapshot]').all()]
                    if 'catalogRevision' in json.loads(raw)['data'])
    csrf = page.locator('meta[name=csrf-token]').get_attribute('content')
    payload = {'components': [{'snapshot': snapshot, 'updates': {'data.name': 'Forged revoked catalog write'}, 'calls': [{'method': 'save', 'params': []}]}]}
    headers = {'X-Livewire': 'true', 'Accept': 'application/json'}
    before = subprocess.check_output(['php', 'tests/browser/food-catalog-fixture.php', 'hash'], text=True)
    assert page.context.request.post(base + '/admin/livewire/update', data=payload, headers=headers).status == 419
    subprocess.run(['php', 'tests/browser/food-catalog-fixture.php', 'revoke'], check=True)
    payload['_token'] = csrf
    assert page.context.request.post(base + '/admin/livewire/update', data=payload, headers=headers).status == 403
    for slug in ('ingredients', 'ingredient-categories', 'recipe-categories', 'uoms', 'kitchen-equipment-categories', 'kitchen-equipment'):
        assert page.context.request.get(base + '/admin/catalog/' + slug).status == 403
        assert page.context.request.get(base + '/admin/catalog/' + slug + '/create').status == 403
    assert page.context.request.get(edit).status == 403
    assert before == subprocess.check_output(['php', 'tests/browser/food-catalog-fixture.php', 'hash'], text=True)
    path = Path('docs/evidence/food-catalog/checks.json')
    result = json.loads(path.read_text())
    result['checks'].append('Real session/MFA/CSRF: missing CSRF 419, revoked signed Livewire Save and all catalog HTTP/create/edit 403; catalog hash unchanged')
    path.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
