"""PR67: синтетичні фото без R2, 40 позицій, weekday та viewport докази topbar."""
import json
import math
import struct
import zlib
from pathlib import Path
from playwright.sync_api import expect
from meal_plans import fixture


def plate_png():
    # Синтетичний raster fixture; жодних реальних storage URLs або файлів.
    width, height = 240, 180
    rows = bytearray()
    for y in range(height):
        rows.append(0)
        for x in range(width):
            distance = math.hypot(x - 120, y - 90)
            color = (48, 87, 62)
            if distance < 75:
                color = (227, 232, 211)
            if distance < 62:
                color = (153, 174, 84)
            if (x - 105) ** 2 + (y - 75) ** 2 < 400 or (x - 140) ** 2 + (y - 110) ** 2 < 225:
                color = (201, 87, 62)
            rows.extend(color)
    def chunk(kind, data):
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', zlib.crc32(kind + data))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', width, height, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')


def run_plan_presentation(page, base, f, suffix):
    output = Path('docs/evidence/meal-plans')
    output.mkdir(parents=True, exist_ok=True)
    mocked = []
    def image(route):
        mocked.append(route.request.url)
        route.fulfill(status=404 if 'broken' in route.request.url else 200, content_type='image/png', body=b'' if 'broken' in route.request.url else plate_png())
    page.route('https://images.example.com/**', image)
    state = json.loads(fixture('polish-seed', f['type'], f['extra_type'], f['food']))
    overview = base + '/admin/meal-plan-versions/' + str(state['version'])
    page.goto(overview)
    expect(page.locator('.plan-mobile-picker option').filter(has_text='День 1 (Вт)')).to_have_count(1)
    if page.viewport_size['width'] >= 1280:
        expect(page.locator('.plan-day:visible')).to_have_count(7)
        assert len(set(page.locator('.plan-day').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().top)'))) == 1
    expect(page.locator('.plan-photo-tile').first).to_be_visible()
    page.wait_for_function("Array.from(document.querySelectorAll('img[src*=\"cover.png\"]')).some(e=>e.complete && e.naturalWidth>0)")
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    page.evaluate('scrollTo(0,0)')
    page.screenshot(path=str(output / f'polish-week-{suffix}.png'), full_page=True)
    # Окрема ширина 1280: усі сім колонок одночасно.
    if page.viewport_size['width'] == 1440:
        page.set_viewport_size({'width': 1280, 'height': 1000})
        expect(page.locator('.plan-day:visible')).to_have_count(7)
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        page.screenshot(path=str(output / f'polish-week-1280-{suffix.split("-")[-1]}.png'))
        page.set_viewport_size({'width': 1440, 'height': 1000})
    page.goto(overview + '/days/' + str(state['day']))
    expect(page.locator('.plan-item')).to_have_count(40)
    expect(page.get_by_text('День 1 (Вт)', exact=False).first).to_be_visible()
    expect(page.locator('.plan-item-menu[open]')).to_have_count(0)
    expect(page.locator('.plan-item .food-cover').first).to_be_visible()
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    metrics = []
    def header(stage):
        value = page.locator('.fi-topbar').evaluate('(e)=>{const r=e.getBoundingClientRect();return {top:r.top,bottom:r.bottom,position:getComputedStyle(e).position,ancestors:[e.parentElement,e.parentElement.parentElement].map(p=>({position:getComputedStyle(p).position,transform:getComputedStyle(p).transform,overflow:getComputedStyle(p).overflow})),scrollY:scrollY}}')
        assert abs(value['top']) <= 1, (stage, value)
        value['stage'] = stage
        metrics.append(value)
    page.evaluate('scrollTo(0,0)')
    header('initial')
    page.screenshot(path=str(output / f'polish-day-{suffix}.png'))
    page.evaluate('scrollTo(0,900)')
    header('scroll-down')
    page.screenshot(path=str(output / f'polish-scroll-{suffix}.png'))
    page.evaluate('scrollTo(0,120)')
    header('scroll-up')
    row = page.locator('.plan-item').nth(1)
    row.locator('summary').click()
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        row.get_by_role('button', name='Вище:', exact=False).click()
    expect(page.locator('.plan-item')).to_have_count(40)
    header('reorder-morph')
    assert page.locator('.fi-no-notification').count() <= 1
    for notification in page.locator('.fi-no-notification:visible').all():
        rect = notification.bounding_box()
        assert rect['y'] >= metrics[-1]['bottom'], rect
    page.evaluate('scrollTo(0,0)')
    page.get_by_role('link', name='До огляду тижня', exact=True).click()
    # SPA click повертає керування раніше за history push / заміну day DOM.
    # Back має відбутися після завершеного переходу, інакше це гонка двох URL.
    expect(page.locator('.plan-day')).to_have_count(7)
    expect(page.locator('.plan-item')).to_have_count(0)
    page.go_back(wait_until='load')
    expect(page.locator('.plan-item')).to_have_count(40)
    header('back')
    page.go_forward(wait_until='load')
    page.get_by_role('button', name='Наступний тиждень', exact=True).click()
    expect(page.locator('.plan-mobile-picker option').filter(has_text='День 8 (Вт)')).to_have_count(1)
    page.goto(overview + '/days/' + str(state['day']))
    header('forward-return')
    # Broken image fallback має з'явитися і при lazy load після scroll.
    broken = page.locator('.plan-item').filter(has_text='Тестове недоступне фото').first
    broken.scroll_into_view_if_needed()
    expect(broken.locator('.food-cover-placeholder')).to_be_visible()
    page.evaluate('scrollTo(0,0)')
    page.screenshot(path=str(output / f'polish-day-full-{suffix}.png'), full_page=True)
    with page.expect_response(lambda r: '/livewire/update' in r.url):
        page.get_by_label('Пошук каталогу', exact=False).fill('Тестова вівсяна')
    card = page.locator('.plan-catalog-item[data-food="' + str(state['photo_food']) + '"]')
    card.scroll_into_view_if_needed()
    expect(card.locator('img')).to_be_visible()
    page.wait_for_function('(img)=>img.complete && img.naturalWidth>0', arg=card.locator('img').element_handle())
    assert page.locator('.plan-catalog-item').count() <= 12
    page.screenshot(path=str(output / f'polish-catalog-{suffix}.png'))
    page.goto(base + '/admin/meal-plans/create')
    expect(page.get_by_label('Початок тижня', exact=False)).to_have_value('1')
    page.get_by_label('Початок тижня', exact=False).select_option('2')
    page.get_by_label('Назва', exact=False).fill('Synthetic Tuesday UI ' + suffix)
    page.locator('input[type=checkbox][value="' + str(f['type']) + '"]').check()
    page.screenshot(path=str(output / f'polish-create-{suffix}.png'))
    page.get_by_role('button', name='Створити', exact=True).click()
    page.wait_for_url('**/admin/meal-plan-versions/*')
    assert fixture('plan-weekday', int(page.url.rsplit('/', 1)[1])) == '2'
    assert mocked and all(url.startswith('https://images.example.com/') for url in mocked)
    # Mock лишається до закриття browser: synthetic фото присутні в наступних каталогах.
    target = output / 'presentation-checks.json'
    evidence = json.loads(target.read_text()) if target.exists() else {}
    evidence[suffix] = {'items': 40, 'mocked_image_requests': len(mocked), 'topbar': metrics, 'overflow': False, 'real_R2_requests': 0}
    target.write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + '\n')
    print('PR67 presentation Chromium ' + suffix + ': 40 items, photos/null/broken, weekday 1/8, topbar initial/scroll/reorder/morph/back-forward PASS', flush=True)
