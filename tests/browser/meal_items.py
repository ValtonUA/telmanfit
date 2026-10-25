"""T4.3: реальні Filament actions поверх єдиного writer, без SQL writes із браузера."""
import re
from pathlib import Path
from playwright.sync_api import expect


def run_meal_items(page, base, version_url, f, suffix, submit):
    output = Path('docs/evidence/meal-plans')
    output.mkdir(parents=True, exist_ok=True)

    def open_action(label, danger=False):
        page.get_by_role('button', name='Позиції', exact=True).click()
        page.get_by_text(label, exact=True).click()
        dialog = page.get_by_role('alertdialog' if danger else 'dialog').filter(has=page.get_by_text(label, exact=True))
        expect(dialog.get_by_role('heading', name=label, exact=True)).to_be_visible()
        return dialog

    def select(dialog, label, option):
        dialog.get_by_role('button' if label in ('Прийом їжі', 'Позиція') else 'combobox', name=label, exact=False).click()
        with page.expect_response(lambda r: '/livewire/update' in r.url):
            page.get_by_role('option', name=option, exact=True).click()
        page.wait_for_load_state('networkidle')

    dialog = open_action('Додати позицію')
    select(dialog, 'Прийом їжі', 'День 1 · Синтетичний прийом')
    dialog.get_by_role('button', name='Продукт або опублікований рецепт', exact=False).click()
    search = page.get_by_role('searchbox').last
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        search.fill('No-such-synthetic-food-64')
    expect(page.get_by_role('option')).to_have_count(0)
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        search.fill('Синтетичний інгредієнт')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('option', name='Інгредієнт · Синтетичний інгредієнт', exact=True).click()
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_label('Споживана кількість', exact=False).fill('0.50')
        dialog.get_by_label('Порядок у прийомі', exact=False).click()
    expect(dialog.get_by_text('На споживану кількість:', exact=False)).to_contain_text('0.500000 ккал')
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.screenshot(path=str(output / f'item-add-{suffix}.png'))
    submit(dialog, 'Додати')
    page.reload()
    expect(page.get_by_text('0.50', exact=True)).to_be_visible()

    dialog = open_action('Змінити позицію')
    select(dialog, 'Позиція', 'День 1 · Синтетичний прийом · Синтетичний інгредієнт · 0.50 г')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        dialog.get_by_label('Споживана кількість', exact=False).fill('1.25')
        dialog.get_by_label('Порядок у прийомі', exact=False).click()
    expect(dialog.get_by_text('На споживану кількість:', exact=False)).to_contain_text('1.250000 ккал')
    page.screenshot(path=str(output / f'item-edit-{suffix}.png'))
    submit(dialog, 'Зберегти')
    dialog = open_action('Перенести позицію')
    select(dialog, 'Позиція', 'День 1 · Синтетичний прийом · Синтетичний інгредієнт · 1.25 г')
    select(dialog, 'Прийом їжі', 'День 2 · Синтетичний прийом')
    dialog.get_by_label('Порядок у прийомі', exact=False).fill('-2')
    submit(dialog, 'Перенести')
    dialog = open_action('Видалити позицію', True)
    select(dialog, 'Позиція', 'День 2 · Синтетичний прийом · Синтетичний інгредієнт · 1.25 г')
    expect(dialog.get_by_text('Дію неможливо скасувати.', exact=False)).to_be_visible()
    page.screenshot(path=str(output / f'item-delete-{suffix}.png'))
    submit(dialog, 'Скасувати')
    expect(page.get_by_text('1.25', exact=True)).to_be_visible()
    dialog = open_action('Видалити позицію', True)
    select(dialog, 'Позиція', 'День 2 · Синтетичний прийом · Синтетичний інгредієнт · 1.25 г')
    submit(dialog, 'Видалити позицію')
    expect(page.get_by_text('1.25', exact=True)).to_have_count(0)
    for format_name, divisor_name, kind in [
        ('A — одна порція', 'A/1 — ціла одна порція', 'A/1'),
        ('B — партія на 7 порцій', 'B/7 — одна сьома партії', 'B/7'),
        ('C — половина тижневої партії', 'C/3 — одна третина половини партії', 'C/3'),
        ('C — половина тижневої партії', 'C/4 — одна четверта половини партії', 'C/4'),
    ]:
        dialog = open_action('Додати позицію')
        select(dialog, 'Прийом їжі', 'День 1 · Синтетичний прийом')
        dialog.get_by_role('button', name='Продукт або опублікований рецепт', exact=False).click()
        with page.expect_response(lambda r: '/livewire/update' in r.url):
            page.get_by_role('searchbox').last.fill('Синтетичний batch writer')
        with page.expect_response(lambda r: '/livewire/update' in r.url):
            page.get_by_role('option', name=re.compile('Рецепт · Синтетичний batch writer · версія 1')).click()
        select(dialog, 'Формат фізичної партії', format_name)
        select(dialog, 'Поділ партії на порції', divisor_name)
        with page.expect_response(lambda r: '/livewire/update' in r.url):
            dialog.get_by_label('Споживана кількість', exact=False).fill('0.50')
            dialog.get_by_label('Порядок у прийомі', exact=False).click()
        expect(dialog.get_by_text('На споживану кількість:', exact=False)).to_contain_text('Формат: ' + kind)
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        page.screenshot(path=str(output / f'item-{kind.replace("/", "-")}-{suffix}.png'))
        submit(dialog, 'Додати')
        page.reload()
        expect(page.get_by_text(kind + ' · ', exact=False)).to_be_visible()
        dialog = open_action('Видалити позицію', True)
        select(dialog, 'Позиція', re.compile('День 1 · Синтетичний прийом · Синтетичний batch writer · 0.50'))
        submit(dialog, 'Видалити позицію')
    assert page.url == version_url
    print('Meal items Chromium: ' + suffix + ' search/empty/add/edit/move/delete cancel+confirm/reload PASS', flush=True)
