"""Chromium + штатні MFA/Livewire; тільки synthetic fixture, без HAR/секретів у evidence."""
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import shutil
import struct
import subprocess
import time
from playwright.sync_api import sync_playwright, expect
from readiness import TRACKER, metrics as readiness_metrics

base = 'http://127.0.0.1:8090'
url = base + '/admin/recipe-batches?version=' + os.environ['BATCH_BROWSER_VERSION']
output = Path('docs/evidence/recipe-batches')
output.mkdir(parents=True, exist_ok=True)
checks = []


def otp(secret, offset=0):
    key = base64.b32decode(secret)
    digest = hmac.new(key, struct.pack('>Q', int(time.time() // 30) + offset), hashlib.sha1).digest()
    start = digest[-1] & 15
    return f'{(struct.unpack(">I", digest[start:start+4])[0] & 0x7fffffff) % 1000000:06d}'


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'), args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    context.add_init_script(TRACKER)
    page = context.new_page()
    page.set_default_timeout(15000)
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    for _ in range(50):
        try:
            page.goto(base + '/admin/login')
            break
        except Exception:
            time.sleep(.2)

    def primary():
        page.goto(base + '/admin/login')
        page.locator('[name=email]').fill('recipe-browser@example.invalid')
        page.locator('[name=password]').fill('Synthetic-Recipe-Only!')
        page.get_by_role('button', name='Увійти', exact=True).click()
        page.wait_for_url('**/admin/auth/challenge')

    assert context.request.get(url).status in (302, 403)
    guest = browser.new_context()
    for path in ('/admin/meal-plans', '/admin/meal-plans/create'):
        assert guest.request.get(base + path).status in (302, 403)
    guest.close()
    primary()
    for path in ('/admin/meal-plans', '/admin/meal-plans/create'):
        assert context.request.get(base + path).status in (302, 403)
    page.get_by_role('button', name='Створити ключ Authenticator').click()
    secret = page.locator('#totp-secret').inner_text()
    if time.time() % 30 >= 26:
        time.sleep(30 - time.time() % 30 + .1)
    page.locator('[name=code]').fill(otp(secret, -1))
    page.get_by_role('button', name='Підтвердити', exact=True).click()
    expect(page.get_by_role('heading', name='Збережіть резервні коди')).to_be_visible()
    primary()
    page.locator('[name=code]').fill(otp(secret))
    page.get_by_role('button', name='Підтвердити вхід').click()
    page.wait_for_url('**/admin')
    checks.append('Штатний password + MFA; guest editor недоступний')

    def action(target, name, exact=True):
        with target.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST') as response:
            target.get_by_role('button', name=name, exact=exact).click()
        assert response.value.status == 200
        expect(target.locator('.batch-editor fieldset')).not_to_be_disabled()
        if name == 'Оновити preview':
            expect(target.get_by_text('Preview готовий. Збереження та публікація — окремі явні дії.', exact=True)).to_be_visible()

    # Кухонний fixture додає раціон; це має відбутися до глобальних
    # structure-hash snapshots перевірок незмінності та відкликання доступу.
    from cooking import run_cooking
    run_cooking(page, base)

    from meal_plans import run_meal_plans, verify_meal_plan_revocation
    meal_plan_browser_state = run_meal_plans(page, base)

    from food_catalog import run_catalog
    run_catalog(page, base)
    checks.append('PR D: catalog create/edit/conversions/security, 1440/390/320 light/dark')

    # C3: окремі legacy entrypoints, read-only preview/cancel та явний Refresh.
    c3_output = Path('docs/evidence/legacy-conversion')
    c3_output.mkdir(parents=True, exist_ok=True)
    def legacy_fixture(*args):
        return subprocess.check_output(['php', 'tests/browser/legacy-conversion-fixture.php', *map(str, args)], text=True).strip()

    def legacy_action(name):
        with page.expect_response(lambda r: '/livewire/update' in r.url and r.request.method == 'POST') as response:
            page.get_by_role('button', name=name, exact=True).click()
        assert response.value.status == 200

    c3_checks = []
    c3_hashes = []
    for kind in ('draft', 'published'):
        source_id = legacy_fixture(kind)
        source_url = base + '/admin/recipe-batches?version=' + source_id
        action_name = 'Створити нову версію A/B/C' if kind == 'published' else 'Перетворити чернетку на A/B/C'
        original_hash = legacy_fixture('hash', source_id)
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                page.set_viewport_size({'width': width, 'height': 1000})
                page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
                page.goto(source_url)
                expect(page.get_by_role('button', name=action_name, exact=True)).to_be_visible()
                expect(page.get_by_text('Це лише preview.', exact=False)).to_have_count(0)
                legacy_action(action_name)
                expect(page.get_by_text('B=7×A', exact=False)).to_be_visible()
                expect(page.get_by_text('B: 1050.00 г', exact=True)).to_be_visible()
                expect(page.get_by_text('C: 525.00 г', exact=True)).to_be_visible()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
                assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
                page.screenshot(path=str(c3_output / f'{kind}-{width}-{theme}.png'), full_page=True)
                legacy_action('Скасувати конвертацію')
                assert legacy_fixture('hash', source_id) == original_hash
                c3_checks.append(f'{kind}: {width}px {theme}, preview/cancel, source hash unchanged')
        page.set_viewport_size({'width': 1440, 'height': 1000})
        page.goto(source_url)
        legacy_action(action_name)
        page.once('dialog', lambda dialog: dialog.dismiss())
        page.get_by_role('button', name='Підтвердити перехід на A/B/C', exact=True).click()
        # Обробники встановлюються по одному для cancel/confirm.
        assert legacy_fixture('hash', source_id) == original_hash
        page.once('dialog', lambda dialog: dialog.accept())
        legacy_action('Підтвердити перехід на A/B/C')
        expect(page.get_by_role('alert')).to_contain_text('Явно прийміть')
        page.get_by_text('Приймаю поточні джерела КБЖВ', exact=False).click()
        page.get_by_text('Переглянув A/B/C', exact=False).click()
        page.once('dialog', lambda dialog: dialog.accept())
        with page.expect_navigation():
            legacy_action('Підтвердити перехід на A/B/C')
        expect(page.get_by_label('A грами 1', exact=True)).to_have_value('150.00')
        page.reload()
        expect(page.get_by_label('A грами 1', exact=True)).to_have_value('150.00')
        if kind == 'published':
            assert page.url != source_url
            assert legacy_fixture('hash', source_id) == original_hash
        c3_hashes.append({'kind': kind, 'source_id': source_id, 'before': original_hash, 'after': legacy_fixture('hash', source_id), 'result_url_path': page.url.removeprefix(base)})
        c3_checks.append(f'{kind}: native confirm/cancel, missing acceptance rejected, explicit conversion, redirect/reload')
    invalid_id = legacy_fixture('invalid')
    original_hash = legacy_fixture('hash', invalid_id)
    page.goto(base + '/admin/recipe-batches?version=' + invalid_id)
    legacy_action('Перетворити чернетку на A/B/C')
    expect(page.get_by_role('alert')).to_contain_text('Токен посилається')
    assert legacy_fixture('hash', invalid_id) == original_hash
    c3_checks.append('Invalid token: concrete refusal; original hash unchanged')
    (c3_output / 'checks.json').write_text(json.dumps({'checks': c3_checks, 'source_hashes': c3_hashes}, ensure_ascii=False, indent=2) + '\n')
    checks.append('C3: 12 responsive/theme previews, confirm/cancel, negative review/token, redirect/reload, immutable source hashes')
    page.set_viewport_size({'width': 1440, 'height': 1000})

    page.goto(url)
    action(page, 'Оновити preview')
    expect(page.get_by_test_id('week-0')).to_contain_text('1050.00 g')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('525.00 g')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Режим week 1', exact=True).select_option('manual')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Кількість week 1', exact=True).fill('1000.00')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('500.00 g')
    action(page, 'Підтвердити ручні значення й обладнання')
    page.reload()
    action(page, 'Оновити preview')
    expect(page.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
    checks.append('150→1050→525; manual B=1000→C=500; save/reload')

    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Режим half_week 1', exact=True).select_option('manual')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Кількість half_week 1', exact=True).fill('600.00')
    action(page, 'Підтвердити ручні значення й обладнання')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('A грами 1', exact=True).fill('160.00')
    expect(page.get_by_test_id('week-0')).to_contain_text('1000.00 g')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('600.00 g')
    action(page, 'Зберегти чернетку')
    page.reload()
    action(page, 'Оновити preview')
    expect(page.get_by_label('Кількість half_week 1', exact=True)).to_have_value('600.00')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('button', name='Повернути кількість до авто', exact=True).first.click()
    expect(page.get_by_test_id('week-0')).to_contain_text('1120.00 g')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('600.00 g')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('button', name='Повернути кількість до авто', exact=True).click()
    expect(page.get_by_test_id('half_week-0')).to_contain_text('560.00 g')
    action(page, 'Зберегти чернетку')
    # Повернути manual B для подальшого explicit refresh scenario.
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Режим week 1', exact=True).select_option('manual')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Кількість week 1', exact=True).fill('1000.00')
    action(page, 'Підтвердити ручні значення й обладнання')
    checks.append('Manual C=600 зберігається після A=160/reload; reset B=1120, reset C=560')

    second = context.new_page()
    second.goto(url)
    action(second, 'Оновити preview')
    action(page, 'Зберегти чернетку')
    action(second, 'Зберегти чернетку')
    expect(second.get_by_role('alert')).to_contain_text('Конфлікт ревізій')
    checks.append('Дві вкладки: stale revision не перезаписує першу')
    second.close()

    # Signed preview cannot silently accept a reference change after its response.
    subprocess.run(['php', 'tests/browser/recipe-batch-fixture.php', 'catalog-change'], check=True)
    action(page, 'Зберегти чернетку')
    expect(page.get_by_role('alert')).to_contain_text('Preview застарів')
    action(page, 'Оновити preview')
    expect(page.get_by_text('Довідник змінився · потрібна явна згода', exact=True)).to_be_visible()
    action(page, 'Прийняти diff і Refresh')
    expect(page.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
    checks.append('Stale source response → відмова; explicit diff/Refresh зберігає manual')

    action(page, 'Додати інгредієнт')
    action(page, 'Зберегти чернетку')
    page.reload()
    action(page, 'Оновити preview')
    expect(page.get_by_text('Неповний розрахунок:', exact=False)).to_be_visible()
    expect(page.get_by_label('Інгредієнт 2', exact=True)).to_have_value('')
    checks.append('Pending row: save/reload; неповний підсумок')

    expect(page.get_by_test_id('equipment-half_week-0')).to_contain_text('4.00')
    tool_token = page.get_by_text('Токен: {{equipment:', exact=False).inner_text()
    action(page, 'Обладнання B вручну')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Обладнання B кількість 1', exact=True).fill('2.00')
    action(page, 'Підтвердити ручні значення й обладнання')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Обладнання A кількість 1', exact=True).fill('2.00')
    expect(page.get_by_test_id('equipment-week-0')).to_contain_text('2.00')
    action(page, 'Зберегти чернетку')
    page.reload()
    action(page, 'Оновити preview')
    expect(page.get_by_label('Обладнання B кількість 1', exact=True)).to_have_value('2.00')
    assert page.get_by_text('Токен: {{equipment:', exact=False).inner_text() == tool_token
    action(page, 'Підтвердити ручні значення й обладнання')
    action(page, 'Повернути обладнання B до авто')
    expect(page.get_by_test_id('equipment-week-0')).to_contain_text('14.00')
    action(page, 'Зберегти чернетку')
    checks.append('Обладнання: ceil C=4; manual B=2, зміна A/reload зберігає ID/token/override; ack/reset B=14')

    # B preset — explicit author choice; C remains exact grams.
    action(page, 'Повернути кількість до авто')
    page.get_by_label('Пресет B 1', exact=True).select_option('nearest100')
    page.once('dialog', lambda dialog: dialog.accept())
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('button', name='Застосувати й підтвердити пресет B', exact=True).first.click()
    expect(page.get_by_test_id('week-0')).to_contain_text('1100.00 g')
    expect(page.get_by_test_id('half_week-0')).to_contain_text('550.00 g')
    action(page, 'Зберегти чернетку')
    checks.append('Явний nearest 100 г B=1100 → exact C=550; preview до Save')

    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            page.set_viewport_size({'width': width, 'height': 1000})
            page.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            page.reload()
            action(page, 'Оновити preview')
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), (width, theme)
            assert page.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            page.screenshot(path=str(output / f'editor-{width}-{theme}.png'), full_page=True)
            checks.append(f'{width}px {theme}: no overflow')
    page.set_viewport_size({'width': 1440, 'height': 1000})
    page.once('dialog', lambda dialog: dialog.accept())
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('button', name='Видалити інгредієнт', exact=True).nth(1).click()
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Режим week 1', exact=True).select_option('manual')
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Кількість week 1', exact=True).fill('1000.00')
    action(page, 'Підтвердити ручні значення й обладнання')
    source_text = page.locator('textarea').first.input_value()
    source_tool = page.get_by_text('Токен: {{equipment:', exact=False).inner_text()
    page.locator('input[type=checkbox]').check()
    page.once('dialog', lambda dialog: dialog.accept())
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_role('button', name='Опублікувати A/B/C', exact=True).click()
    expect(page.get_by_role('button', name='Створити нову версію A/B/C', exact=True)).to_be_visible()
    assert page.locator('.batch-editor fieldset').count() == 0
    before = subprocess.check_output(['php', 'tests/browser/recipe-batch-fixture.php', 'source-state'], text=True)
    source_rows_before = json.loads(subprocess.check_output(['php', 'tests/browser/recipe-batch-fixture.php', 'source-state-json'], text=True))
    published_tab = context.new_page()
    published_tab.goto(url)
    page.once('dialog', lambda dialog: dialog.accept())
    page.get_by_role('button', name='Створити нову версію A/B/C', exact=True).click()
    page.wait_for_url(lambda u: '/admin/recipe-batches?version=' in u and u != url)
    copy_url = page.url
    expect(page.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
    assert page.locator('textarea').first.input_value() != source_text
    assert page.get_by_text('Токен: {{equipment:', exact=False).inner_text() != source_tool
    action(page, 'Оновити preview')
    expect(page.get_by_text('Ручне значення потребує підтвердження:', exact=False)).to_be_visible()
    published_tab.once('dialog', lambda dialog: dialog.accept())
    with published_tab.expect_response(lambda r: '/livewire/update' in r.url):
        published_tab.get_by_role('button', name='Створити нову версію A/B/C', exact=True).click()
    expect(published_tab.get_by_role('alert')).to_contain_text('уже має чернетку')
    # Зміна каталогу має бути відмінною від попереднього browser Refresh.
    subprocess.run(['php', 'tests/browser/recipe-batch-fixture.php', 'catalog-change-again'], check=True)
    page.reload()
    action(page, 'Оновити preview')
    action(page, 'Зберегти чернетку')
    expect(page.get_by_role('alert')).to_contain_text('явно прийміть diff')
    action(page, 'Оновити preview')
    action(page, 'Прийняти diff і Refresh')
    expect(page.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
    action(page, 'Підтвердити ручні значення й обладнання')
    page.reload()
    assert page.url == copy_url
    expect(page.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
    after = subprocess.check_output(['php', 'tests/browser/recipe-batch-fixture.php', 'source-state'], text=True)
    assert before == after
    checks.append('C1: publish → clone з новими ingredient/equipment tokens, manual/review/reload; duplicate draft rejected; explicit Refresh; published source unchanged')
    page.screenshot(path=str(output / 'clone-1440-dark.png'), full_page=True)
    # C2: явний owner, confirm, окрема картка; джерело вже stale після C1.
    expect(published_tab.get_by_label('Клієнт для копії', exact=True)).to_have_value('')
    published_tab.once('dialog', lambda dialog: dialog.accept())
    with published_tab.expect_response(lambda r: '/livewire/update' in r.url):
        published_tab.get_by_role('button', name='Створити персональну копію A/B/C', exact=True).click()
    expect(published_tab.get_by_role('alert')).to_contain_text('Оберіть клієнта')
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            published_tab.set_viewport_size({'width': width, 'height': 1000})
            published_tab.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            published_tab.reload()
            assert published_tab.evaluate('document.documentElement.scrollWidth <= innerWidth'), (width, theme)
            assert published_tab.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            published_tab.screenshot(path=str(output / f'personal-action-{width}-{theme}.png'), full_page=True)
    owner_value = published_tab.get_by_label('Клієнт для копії', exact=True).locator('option').nth(1).get_attribute('value')
    published_tab.get_by_label('Клієнт для копії', exact=True).select_option(owner_value)
    published_tab.once('dialog', lambda dialog: dialog.dismiss())
    published_tab.get_by_role('button', name='Створити персональну копію A/B/C', exact=True).click()
    assert published_tab.url == url
    published_tab.once('dialog', lambda dialog: dialog.accept())
    published_tab.get_by_role('button', name='Створити персональну копію A/B/C', exact=True).click()
    published_tab.wait_for_url(lambda u: '/admin/recipe-batches?version=' in u and u != url and u != copy_url)
    personal_url = published_tab.url
    expect(published_tab.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
    assert published_tab.locator('textarea').first.input_value() != source_text
    assert published_tab.get_by_text('Токен: {{equipment:', exact=False).inner_text() != source_tool
    action(published_tab, 'Оновити preview')
    action(published_tab, 'Зберегти чернетку')
    expect(published_tab.get_by_role('alert')).to_contain_text('явно прийміть diff')
    action(published_tab, 'Оновити preview')
    action(published_tab, 'Прийняти diff і Refresh')
    action(published_tab, 'Підтвердити ручні значення й обладнання')
    for width in (1440, 390, 320):
        for theme in ('light', 'dark'):
            published_tab.set_viewport_size({'width': width, 'height': 1000})
            published_tab.evaluate('(theme) => localStorage.setItem("filament:theme:mode", theme)', theme)
            published_tab.reload()
            action(published_tab, 'Оновити preview')
            expect(published_tab.get_by_label('Кількість week 1', exact=True)).to_have_value('1000.00')
            assert published_tab.evaluate('document.documentElement.scrollWidth <= innerWidth'), (width, theme)
            assert published_tab.evaluate('document.documentElement.classList.contains("dark")') == (theme == 'dark')
            published_tab.screenshot(path=str(output / f'personal-editor-{width}-{theme}.png'), full_page=True)
    duplicate = context.new_page()
    duplicate.goto(url)
    duplicate.get_by_label('Клієнт для копії', exact=True).select_option(owner_value)
    duplicate.once('dialog', lambda dialog: dialog.accept())
    with duplicate.expect_response(lambda r: '/livewire/update' in r.url):
        duplicate.get_by_role('button', name='Створити персональну копію A/B/C', exact=True).click()
    expect(duplicate.get_by_role('alert')).to_contain_text('вже має чернетку')
    duplicate.close()
    published_tab.locator('input[type=checkbox]').check()
    published_tab.once('dialog', lambda dialog: dialog.accept())
    with published_tab.expect_response(lambda r: '/livewire/update' in r.url):
        published_tab.get_by_role('button', name='Опублікувати A/B/C', exact=True).click()
    expect(published_tab.get_by_role('button', name='Створити персональну копію A/B/C', exact=True)).to_be_visible()
    # Для персонального published дозволений тільки його owner; вибір знову порожній.
    expect(published_tab.get_by_label('Клієнт для копії', exact=True)).to_have_value('')
    assert published_tab.get_by_label('Клієнт для копії', exact=True).locator('option').count() == 2
    source_rows_after = json.loads(subprocess.check_output(['php', 'tests/browser/recipe-batch-fixture.php', 'source-state-json'], text=True))
    assert source_rows_before == source_rows_after, {table: {'before': source_rows_before[table], 'after': source_rows_after[table]} for table in source_rows_before if source_rows_before[table] != source_rows_after[table]}
    assert before == subprocess.check_output(['php', 'tests/browser/recipe-batch-fixture.php', 'source-state'], text=True)
    checks.append('C2: explicit owner/confirm/cancel, redirect, remap, frozen→Refresh/manual review, duplicate rejection, publish/reload; original unchanged; 1440/390/320 light/dark action+editor')
    published_tab.close()
    from food_catalog import verify_catalog_revocation
    from meal_plans import fixture as plan_fixture
    meal_plan_browser_state['count'] = plan_fixture('count')
    verify_catalog_revocation(page, base)
    verify_meal_plan_revocation(page, base, meal_plan_browser_state)
    checks.append("PR D: CSRF 419, revoked signed Livewire/HTTP 403, unchanged catalog hash")
    assert not errors, errors
    browser.close()
output.joinpath('checks.json').write_text(json.dumps({'checks': checks, 'page_errors': errors}, ensure_ascii=False, indent=2) + '\n')
print('Recipe editor browser PASS: ' + str(len(checks)) + ' checks', flush=True)
print('CI_BROWSER_READINESS ' + json.dumps(readiness_metrics), flush=True)
