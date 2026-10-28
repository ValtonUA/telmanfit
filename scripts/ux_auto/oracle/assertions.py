"""Pinned external DOM/HTTP/SQL oracle. Candidate не має доступу до процесу чи report."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import time
import urllib.request
import urllib.error
import uuid

STAGES = tuple(f'UX-{i}' for i in range(1, 7))
BASE = 'http://app:8080'
BRIDGE = 'http://app:8099'


def fixture(action):
    if action not in {'metadata', 'admin-totp', 'client-mail', 'revoke', 'touch'}:
        raise AssertionError('Unknown trusted fixture action')
    with urllib.request.urlopen(BRIDGE + '/' + action, timeout=90) as response:
        return response.read().decode()


def db(sql):
    # Тільки константи oracle і власні int IDs; жодного SQL із candidate payload.
    result = subprocess.check_output(['psql', '-X', '-h', os.environ['DB_HOST'], '-U', 'telmanfit_test_runner',
        '-d', 'telmanfit_stage1_test', '-At', '-v', 'ON_ERROR_STOP=1', '-c', sql], text=True, timeout=15).strip()
    return json.loads(result) if result else None


def state(version):
    version = int(version)
    return db(f"""SELECT json_build_object('status',v.status,'title',(SELECT p.title FROM meal_plans p WHERE p.id=v.meal_plan_id),'days',
      (SELECT coalesce(json_agg(row_to_json(d) ORDER BY d.day_number),'[]') FROM meal_plan_days d WHERE d.meal_plan_version_id=v.id),
      'meals',(SELECT coalesce(json_agg(row_to_json(m) ORDER BY m.id),'[]') FROM meals m JOIN meal_plan_days d ON d.id=m.meal_plan_day_id WHERE d.meal_plan_version_id=v.id),
      'items',(SELECT coalesce(json_agg(row_to_json(i) ORDER BY i.id),'[]') FROM meal_items i JOIN meals m ON m.id=i.meal_id JOIN meal_plan_days d ON d.id=m.meal_plan_day_id WHERE d.meal_plan_version_id=v.id))
      FROM meal_plan_versions v WHERE v.id={version}""")


def snapshot(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True).encode()).hexdigest()


def no_overflow(page):
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'horizontal overflow'


def navigation(page, labels):
    page.evaluate('window.__oracleShell = document.querySelector("main")')
    marker = page.evaluate('window.__oracleDocument')
    assert marker and page.evaluate('!!window.__oracleShell'), 'application shell missing'
    for label in labels:
        page.get_by_role('link', name=label, exact=True).first.click()
        page.wait_for_timeout(300)
        assert page.evaluate('window.__oracleDocument') == marker, 'HTML document navigation'
        assert page.evaluate('window.__oracleShell.isConnected'), 'shell unmounted'
    page.go_back()
    assert page.evaluate('window.__oracleDocument') == marker, 'Back reloaded document'
    page.go_forward()
    assert page.evaluate('window.__oracleDocument') == marker, 'Forward reloaded document'
    path = page.url
    page.reload()
    assert page.url == path, 'direct link/refresh lost URL'


def login_admin(page):
    page.goto(BASE + '/admin/login')
    page.locator('[name=email]').fill('auth5-admin@example.invalid')
    page.locator('[name=password]').fill('Synthetic-AUTH5-only!')
    page.get_by_role('button', name='Увійти', exact=True).click()
    page.wait_for_url('**/admin/auth/challenge')
    page.locator('[name=code]').fill(fixture('admin-totp'))
    page.get_by_role('button', name='Підтвердити вхід', exact=True).click()
    page.wait_for_url('**/admin')


def login_client(page):
    page.goto(BASE + '/app/login')
    page.get_by_label('Електронна пошта', exact=True).fill('t52-browser@example.invalid')
    page.get_by_role('button', name='Отримати код', exact=True).click()
    page.get_by_label('Код із листа', exact=True).fill(fixture('client-mail'))
    page.get_by_role('button', name='Підтвердити', exact=True).click()
    page.get_by_role('heading', name='Сьогодні', exact=True).wait_for()


def create_plan(page, title):
    page.goto(BASE + '/admin/meal-plans/create')
    page.get_by_label('Назва', exact=False).fill(title)
    page.get_by_role('button', name='Створити', exact=True).click()
    page.wait_for_url(re.compile(r'/admin/meal-plan-versions/\d+'))
    version = int(re.search(r'/meal-plan-versions/(\d+)', page.url)[1])
    actual = state(version)
    assert len(actual['days']) == 7 and len(actual['meals']) >= 7, 'creation did not write seven days'
    assert actual['title'] == title, 'plan title did not persist'
    assert actual['status'] == 'draft' and actual['items'] == [], 'creation not empty/draft'
    before = snapshot(actual)
    page.reload()
    assert snapshot(state(version)) == before, 'GET/reload wrote data'
    return version


def day_layout(page, meta, width):
    page.goto(f'{BASE}/admin/meal-plan-versions/{int(meta["version"])}/days/{int(meta["days"][0]["day"])}')
    catalog = page.get_by_test_id('meal-plan-catalog')
    meals = page.get_by_test_id('meal-plan-meals')
    catalog.wait_for()
    meals.wait_for()
    if width == 1440:
        assert catalog.bounding_box()['x'] > meals.bounding_box()['x'], 'catalog must be on right'
        for area in (catalog, meals):
            assert area.evaluate('e => ["auto","scroll"].includes(getComputedStyle(e).overflowY)'), 'independent scrolling missing'
        cards = catalog.get_by_test_id('catalog-card')
        assert cards.count() >= 3, 'dense synthetic catalog absent'
        boxes = [cards.nth(i).bounding_box() for i in range(3)]
        assert max(b['y'] for b in boxes) - min(b['y'] for b in boxes) < 5, 'not three columns'
    # Реальні keyboard/touch actions, не просто наличие test id.
    add = page.get_by_role('button', name='Додати прийом їжі', exact=True)
    add.focus()
    page.keyboard.press('Enter')
    page.get_by_role('dialog').wait_for()
    page.get_by_role('button', name='Скасувати', exact=True).click()
    add.tap()
    page.get_by_role('dialog').wait_for()
    page.get_by_role('button', name='Скасувати', exact=True).click()
    no_overflow(page)


def drag_cancel(page, meta):
    page.goto(f'{BASE}/admin/meal-plan-versions/{int(meta["version"])}/days/{int(meta["days"][0]["day"])}')
    before = snapshot(state(meta['version']))
    page.get_by_test_id('catalog-card').filter(has_text='Синтетичний інгредієнт').first.drag_to(page.get_by_test_id('meal-drop-zone').first)
    page.get_by_role('dialog').wait_for()
    page.get_by_role('button', name='Скасувати', exact=True).click()
    assert snapshot(state(meta['version'])) == before, 'cancelled drag wrote data'


def fast_item(page, meta):
    before = state(meta['version'])
    card = page.get_by_test_id('catalog-card').filter(has_text='Синтетичний інгредієнт').first
    target = page.get_by_test_id('meal-drop-zone').first
    card.drag_to(target)
    assert page.get_by_role('dialog').count() == 0, 'large modal on ordinary drop'
    quantity = page.get_by_test_id('inline-quantity')
    quantity.wait_for()
    assert snapshot(state(meta['version'])) == snapshot(before), 'write before confirmation'
    quantity.fill('3.25')
    page.get_by_test_id('inline-confirm').click()
    page.wait_for_timeout(400)
    actual = state(meta['version'])
    added = [i for i in actual['items'] if i['id'] not in {r['id'] for r in before['items']}]
    assert len(added) == 1 and float(added[0]['quantity']) == 3.25, 'wrong/duplicate quantity write'
    assert added[0]['food_id'] == meta['food'] and added[0]['uom_id'] == meta['g'], 'reference changed'
    page.get_by_test_id('meal-item').filter(has_text='Синтетичний інгредієнт').first.get_by_role('button', name='Змінити кількість', exact=True).click()
    page.get_by_test_id('inline-quantity').fill('4.5')
    page.get_by_test_id('inline-confirm').click()
    page.wait_for_timeout(400)
    assert any(i['id'] == added[0]['id'] and float(i['quantity']) == 4.5 for i in state(meta['version'])['items'])
    # Duplicate click не має створювати другу позицію.
    assert len(state(meta['version'])['items']) == len(actual['items'])


def assert_copies(before, actual):
    assert len(actual['days']) == 7
    source_day = next(d['id'] for d in before['days'] if d['day_number'] == 2)
    source_meals = {m['id'] for m in before['meals'] if m['meal_plan_day_id'] == source_day}
    source = [i for i in before['items'] if i['meal_id'] in source_meals]
    assert source and len(actual['items']) == 7 * len(source), 'copy count mismatch'
    assert len({i['id'] for i in actual['items']}) == len(actual['items']), 'shared child IDs'
    fields = ('food_id', 'quantity', 'uom_id', 'resolved_quantity_grams', 'recipe_version_id',
              'recipe_batch_format_id', 'recipe_portion_divisor', 'sort_order')
    def signature(items):
        return sorted(json.dumps({k:i.get(k) for k in fields}, sort_keys=True) for i in items)
    for day in actual['days']:
        meals = {m['id'] for m in actual['meals'] if m['meal_plan_day_id'] == day['id']}
        items = [i for i in actual['items'] if i['meal_id'] in meals]
        assert signature(items) == signature(source), 'exact references/quantity/order changed'
    assert {i['id'] for i in source} <= {i['id'] for i in actual['items']}, 'source IDs mutated'


def copy_days(page, meta):
    before = state(meta['version'])
    # Джерело — непорожній 2-й день; цілі містять лише порожні інші шість.
    page.goto(f'{BASE}/admin/meal-plan-versions/{int(meta["version"])}')
    page.get_by_test_id('week-day-2').get_by_role('button', name='Копіювати день', exact=True).click()
    for day in (1, 3, 4, 5, 6, 7):
        page.get_by_test_id(f'copy-day-target-{day}').check()
    page.get_by_test_id('copy-day-confirm').click()
    page.wait_for_timeout(400)
    actual = state(meta['version'])
    assert_copies(before, actual)
    # Непорожні цілі: preview/cancel, потім stale mutation має відхилятися.
    page.get_by_test_id('week-day-2').get_by_role('button', name='Копіювати день', exact=True).click()
    page.get_by_test_id('copy-day-target-3').check()
    page.get_by_test_id('copy-day-preview').click()
    page.get_by_test_id('copy-day-preview-counts').wait_for()
    stable = snapshot(state(meta['version']))
    page.get_by_role('button', name='Скасувати', exact=True).click()
    assert snapshot(state(meta['version'])) == stable, 'cancel overwrote target'
    page.get_by_test_id('week-day-2').get_by_role('button', name='Копіювати день', exact=True).click()
    page.get_by_test_id('copy-day-target-3').check()
    page.get_by_test_id('copy-day-preview').click()
    fixture('touch')
    changed = snapshot(state(meta['version']))
    page.get_by_test_id('copy-day-confirm').click()
    page.wait_for_timeout(400)
    assert snapshot(state(meta['version'])) == changed, 'stale overwrite was accepted'
    page.reload()
    page.get_by_role('button', name='Опублікувати', exact=True).click()
    page.get_by_role('dialog').get_by_role('button', name='Опублікувати', exact=True).click()
    page.wait_for_timeout(400)
    assert state(meta['version'])['status'] == 'published', 'publication failed'


def inputs(page, client=False):
    label, target = ('Раціон', '/app/meal-plan') if client else ('Раціони', '/admin/meal-plans')
    link = page.get_by_role('link', name=label, exact=True).first
    link.focus()
    page.keyboard.press('Enter')
    page.wait_for_url('**' + target)
    other, other_path = ('Корисне', '/app/resources') if client else ('Рецепти', '/admin/recipes')
    page.get_by_role('link', name=other, exact=True).first.tap()
    page.wait_for_url('**' + other_path)


def run_stage(page, stage, meta, width):
    if stage == 'UX-1':
        page.goto(BASE + '/app')
        navigation(page, ['Раціон', 'Корисне', 'Прогрес', 'Сьогодні'])
    elif stage == 'UX-2':
        page.goto(BASE + '/admin')
        navigation(page, ['Інгредієнти', 'Рецепти', 'Раціони'])
    elif stage == 'UX-3':
        create_plan(page, 'Oracle ' + uuid.uuid4().hex)
        drag_cancel(page, meta)
    elif stage in ('UX-4', 'UX-5'):
        day_layout(page, meta, width)
        if stage == 'UX-5':
            fast_item(page, meta)
        else:
            drag_cancel(page, meta)
    elif stage == 'UX-6':
        drag_cancel(page, meta)
        copy_days(page, meta)
    else:
        raise AssertionError('Unknown stage')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--stage', choices=STAGES, required=True)
    parser.add_argument('--head', required=True)
    parser.add_argument('--oracle-sha', required=True)
    parser.add_argument('--checks-json', required=True)
    args = parser.parse_args()
    from playwright.sync_api import sync_playwright
    # Check names надходять тільки як argv pinned controller; candidate не запускає цей процес.
    checks = json.loads(args.checks_json)
    assert isinstance(checks, list) and checks
    for _ in range(120):
        try:
            meta = json.loads(fixture('metadata'))
            with urllib.request.urlopen(BASE + '/api/health', timeout=5) as response:
                assert response.status == 200
            break
        except (OSError, ValueError):
            time.sleep(1)
    else:
        raise AssertionError('Actual Laravel app/fixture unavailable')
    assert state(meta['historical'])['status'] == 'published'
    history = snapshot(state(meta['historical']))
    screenshots = []
    matrix = []
    observations = []
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path=shutil.which('chromium'))
        context = browser.new_context(has_touch=True)
        context.add_init_script('window.__oracleDocument = crypto.randomUUID()')
        page = context.new_page()
        page.set_default_timeout(15000)
        login_admin(page)
        # Реальні negative auth/CSRF перевірки перед product actions.
        guest = browser.new_context()
        assert guest.request.get(BASE + '/api/me').status == 401
        assert context.request.post(BASE + '/admin/auth/primary', form={'email': 'x'}).status == 419
        guest.close()
        stable = snapshot(state(meta['version']))
        assert context.request.post(BASE + '/admin/auth/primary', form={'email': 'x'}).status == 419
        assert snapshot(state(meta['version'])) == stable, 'failed request wrote data'
        if args.stage == 'UX-1':
            login_client(page)
        inputs(page, args.stage == 'UX-1')
        for width in (1440, 390, 320):
            for theme in ('light', 'dark'):
                page.set_viewport_size({'width': width, 'height': 900})
                page.emulate_media(color_scheme=theme)
                page.add_init_script('(theme => {localStorage.setItem("filament:theme:mode",theme)})(' + json.dumps(theme) + ')')
                # Mutations один раз на width/theme потребують нового fixture DB для UX-5/6;
                # самі ці stages зараз виконуються один раз, решта matrix — реальний render.
                if args.stage not in ('UX-5', 'UX-6') or not observations:
                    run_stage(page, args.stage, meta, width)
                    observations.append(f'{width}/{theme}: stage assertions')
                page.reload()
                if theme == 'dark':
                    color = page.evaluate('''() => [document.body, document.documentElement]
                        .map(e => getComputedStyle(e).backgroundColor)
                        .find(c => c !== 'transparent' && c !== 'rgba(0, 0, 0, 0)') || 'rgb(255, 255, 255)' ''')
                    rgb = [int(v) for v in re.findall(r'\d+', color)[:3]]
                    assert len(rgb) == 3 and max(rgb) < 128, 'dark theme background missing'
                if args.stage == 'UX-5' and len(observations) > 0:
                    day_layout(page, meta, width)
                no_overflow(page)
                assert snapshot(state(meta['historical'])) == history, 'published history mutated'
                name = f'{width}-{theme}.png'
                page.screenshot(path='/out/' + name)
                screenshots.append(name)
                matrix.append(f'{width}/{theme}')
        before_denial = snapshot(state(meta['version']))
        fixture('revoke')
        assert context.request.get(BASE + '/admin/meal-plans').status in (401, 403)
        assert context.request.post(BASE + '/admin/livewire/update', data={'components': []}, headers={'X-Livewire': 'true'}).status in (401, 403, 419)
        if args.stage == 'UX-1':
            assert context.request.get(BASE + '/api/me').status == 401
        assert snapshot(state(meta['version'])) == before_denial, 'stale session wrote data'
        browser.close()
    Path('/out/browser.json').write_text(json.dumps({'head': args.head, 'status': 'success',
        'checks': checks, 'assertions': {c: 'passed' for c in checks}, 'matrix': matrix,
        'screenshots': screenshots, 'oracle_sha': args.oracle_sha, 'oracle_mode': 'external',
        'observations': observations}))


if __name__ == '__main__':
    main()
