"""Реальний Chromium; baseline і регресія на синтетичних даних нової PostgreSQL."""
import json
from pathlib import Path
import re
import sys
from playwright.sync_api import sync_playwright, expect

phase = sys.argv[1]
output = Path('docs/screenshots/ux-1')
output.mkdir(parents=True, exist_ok=True)
results = []
measurements = {}

def fit(page, name):
    print(f'{page.viewport_size["width"]}: {name}', flush=True)
    metrics = page.evaluate("""() => ({
        document: document.documentElement.scrollWidth,
        viewport: innerWidth,
        table: [...document.querySelectorAll('.fi-ta-content-ctn')].map(e => ({width: e.clientWidth, scroll: e.scrollWidth})),
        outsideActions: [...document.querySelectorAll('main a, main button, main input, main select')]
            .filter(e => e.checkVisibility() && e.getBoundingClientRect().width > 0)
            .filter(e => {const r=e.getBoundingClientRect(); return r.left < -1 || r.right > innerWidth + 1})
            .map(e => e.textContent.trim() || e.getAttribute('aria-label')),
    })""")
    assert metrics['document'] <= metrics['viewport'], (name, metrics)
    assert not metrics['outsideActions'], (name, metrics)
    assert all(t['scroll'] <= t['width'] + 1 for t in metrics['table']), (name, metrics)
    measurements.setdefault(str(page.viewport_size['width']), {})[name] = metrics
    return metrics

def localized(page):
    assert page.locator('html').get_attribute('lang') == 'uk'
    labels = page.evaluate("""() => [...document.querySelectorAll('[aria-label], input[placeholder], button, label')]
        .filter(e => e.checkVisibility()).map(e => [e.getAttribute('aria-label'),e.getAttribute('placeholder'),e.textContent].filter(Boolean).join(' ')).join(' ')""")
    assert not re.search(r'\b(Search|Filter|Next|Previous|Save|Cancel|Edit|View|Loading|Close|Breadcrumb|Clear selection|Sidebar navigation)\b', labels), labels

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox'])
    for width in [320, 390, 1440]:
        context = browser.new_context(viewport={'width': width, 'height': 900}, device_scale_factor=1, color_scheme='light')
        requests = []
        errors = []
        responses = []
        page = context.new_page()
        page.set_default_timeout(10000)
        page.on('request', lambda request: requests.append(request.url))
        page.on('pageerror', lambda error: errors.append(str(error)))
        def log_response(response):
            if response.request.method == 'POST' and 'livewire' in response.url:
                try:
                    responses.append({'status': response.status, 'request': response.request.post_data, 'body': response.text()})
                except Exception:
                    pass
        page.on('response', log_response)
        try:
            page.goto('http://127.0.0.1:8091/admin/login')
            if phase == 'after':
                localized(page)
                fit(page, 'login')
                page.screenshot(path=str(output / f'after-login-{width}.png'), full_page=True)
            page.locator('input[type=email]').fill('admin@example.test')
            page.locator('input[type=password]').fill('Synthetic-UX1-only!')
            page.locator('button[type=submit]').click()
            page.wait_for_url('**/admin')
            if phase == 'after':
                expect(page.get_by_role('heading', name='Інфопанель', exact=True)).to_be_visible()
                avatar = page.locator('img[src$="admin-avatar.svg"]').first
                expect(avatar).to_be_visible()
                assert avatar.evaluate('(e) => e.complete && e.naturalWidth > 0')
                page.get_by_role('button', name='Меню користувача').click()
                expect(page.get_by_text('Вийти', exact=True).first).to_be_visible()
                page.keyboard.press('Escape')
                localized(page)
            page.goto('http://127.0.0.1:8091/admin/clients')
            rows = page.locator('.fi-ta-row')
            expect(rows).to_have_count(10)
            if phase == 'after':
                localized(page)
                expect(page.get_by_role('heading', name='Картки клієнтів', exact=True)).to_be_visible()
                assert rows.first.locator('.client-name').inner_text().find('Олександра') >= 0
                for text, color in [('Очікує','gray'),('Активний','success'),('Призупинений','warning'),('Завершений','info')]:
                    selector = '.fi-badge:not(.fi-color)' if color == 'gray' else f'.fi-badge.fi-color-{color}'
                    expect(rows.locator(selector).filter(has_text=text).first).to_be_visible()
                if width < 640:
                    expect(rows.first.locator('.fi-ta-cell').filter(has_text='client14@example.test')).to_be_hidden()
                    assert rows.first.evaluate('(e) => getComputedStyle(e).display') == 'grid'
                    assert rows.nth(1).evaluate('(e) => e.getBoundingClientRect().height') < 200
                else:
                    assert page.locator('.fi-ta-table').evaluate('(e) => getComputedStyle(e).display') == 'table'
                metrics = fit(page, 'list')
            else:
                metrics = {'document':page.evaluate('document.documentElement.scrollWidth'),'viewport':width}
            page.screenshot(path=str(output / f'{phase}-clients-{width}.png'), full_page=True)
            if phase == 'after' and width == 390:
                page.screenshot(path=str(output / 'after-clients-390-viewport.png'))
            if phase == 'after':
                # Справжній Livewire-пошук за прихованим email; потім порожній стан.
                search = page.locator('.fi-ta-search-field input')
                search.fill('client14@example.test')
                expect(rows).to_have_count(1)
                expect(rows.first).to_contain_text('Олександра')
                localized(page)
                search.fill('відсутній-запис')
                expect(page.get_by_text('Клієнтів не знайдено', exact=True)).to_be_visible()
                fit(page, 'empty')
                search.fill('')
                expect(rows).to_have_count(10)
                # Фільтр і його скидання через видимі елементи керування.
                page.get_by_role('button', name='Фільтр', exact=True).click()
                filters = page.locator('.fi-ta-filters')
                filters.locator('select').select_option('paused')
                filters.get_by_role('button', name='Застосувати фільтри').click()
                expect(rows).to_have_count(3)
                assert all('Призупинений' in text for text in rows.all_inner_texts())
                localized(page)
                fit(page, 'filtered')
                page.keyboard.press('Escape')
                page.get_by_role('button', name='Очистити фільтри', exact=True).click()
                expect(rows).to_have_count(10)
                # Сортування: select на mobile, заголовок колонки на desktop.
                if width < 640:
                    sorting = page.locator('.fi-ta-table-stacked-sorting select')
                    sorting.nth(0).select_option('id')
                    expect(rows.first.locator('.client-id .fi-ta-cell-content')).to_have_text('1')
                    sorting.nth(1).select_option('desc')
                    expect(rows.first.locator('.client-id .fi-ta-cell-content')).to_have_text('14')
                    fit(page, 'sorting')
                else:
                    page.get_by_role('button', name='ID картки', exact=True).click()
                    expect(rows.first.locator('.client-id .fi-ta-cell-content')).to_have_text('1')
                    page.get_by_role('button', name='ID картки', exact=True).click()
                    expect(rows.first.locator('.client-id .fi-ta-cell-content')).to_have_text('14')
                page.get_by_role('button', name='Наступна', exact=True).click()
                expect(rows).to_have_count(4)
                fit(page, 'pagination')
                page.get_by_role('button', name='Попередня', exact=True).click()
                expect(rows).to_have_count(10)
                # Перегляд і перехід у редагування саме діями картки.
                rows.first.get_by_role('link', name='Перегляд', exact=True).click()
                page.wait_for_url('**/admin/clients/14')
                expect(page.get_by_role('heading', name='Картка клієнта #14', exact=True)).to_be_visible()
                expect(page.get_by_text('client14@example.test', exact=True)).to_be_visible()
                expect(page.locator('.fi-badge.fi-color-success').filter(has_text='Активний')).to_be_visible()
                localized(page)
                fit(page, 'view')
                page.screenshot(path=str(output / f'after-view-{width}.png'), full_page=True)
                page.get_by_role('link', name='Змінити', exact=True).click()
                page.wait_for_url('**/admin/clients/14/edit')
                localized(page)
                fit(page, 'edit')
                page.screenshot(path=str(output / f'after-edit-{width}.png'), full_page=True)
                page.get_by_role('combobox', name=re.compile('Статус супроводу')).select_option('paused')
                page.get_by_role('button', name='Зберегти', exact=True).click()
                expect(page.get_by_text('Збережено', exact=True)).to_be_visible(timeout=15000)
                localized(page)
                page.reload()
                expect(page.get_by_role('combobox', name=re.compile('Статус супроводу'))).to_have_value('paused')
                # Відновлення синтетичного запису для наступної ширини.
                page.get_by_role('combobox', name=re.compile('Статус супроводу')).select_option('active')
                page.get_by_role('button', name='Зберегти', exact=True).click()
                expect(page.get_by_text('Збережено', exact=True)).to_be_visible(timeout=15000)
                # Пошук тренера виконує реальний запит, підпис опцій український.
                coach = page.get_by_role('button', name=re.compile('Відповідальний тренер'))
                # Обов’язковість тренера: справжня помилка форми, потім відновлення.
                coach.locator('..').get_by_role('button', name='Очистити вибір', exact=True).click()
                page.get_by_role('button', name='Зберегти', exact=True).click()
                expect(page.get_by_text(re.compile('Поле «.*тренер.*» обов’язкове', re.I))).to_be_visible()
                localized(page)
                fit(page, 'validation')
                page.screenshot(path=str(output / f'after-validation-{width}.png'), full_page=True)
                coach.click()
                page.get_by_role('combobox', name=re.compile('Відповідальний тренер.*Пошук')).fill('Тестова')
                expect(page.get_by_role('option').filter(has_text='Тестова Тренерка').first).to_be_visible()
                localized(page)
                fit(page, 'coach-search')
                page.get_by_role('option').filter(has_text='Тестова Тренерка').first.click()
                for close in page.get_by_role('button', name='Закрити сповіщення', exact=True).all():
                    if close.is_visible():
                        close.click()
                page.get_by_role('button', name='Зберегти', exact=True).click()
                expect(page.get_by_text('Збережено', exact=True)).to_be_visible(timeout=15000)
                page.keyboard.press('Escape')
                assert not any('ui-avatars.com' in url for url in requests)
                assert not errors, errors
            results.append({'width':width,'layout':metrics,'measurements':measurements.get(str(width), {}),'chromium':browser.version,'checks':'baseline' if phase == 'before' else 'passed','page_errors':errors,'external_avatar_requests':sum('ui-avatars.com' in url for url in requests)})
        except Exception:
            print('Browser errors:', errors, flush=True)
            Path('/tmp/ux1-responses.json').write_text(json.dumps(responses, ensure_ascii=False))
            page.screenshot(path=f'/tmp/ux1-failure-{width}.png', full_page=True)
            Path('/tmp/ux1-failure.html').write_text(page.content())
            Path('/tmp/ux1-failure-aria.txt').write_text(page.locator('body').aria_snapshot())
            raise
        finally:
            context.close()
    browser.close()
(output / f'{phase}-results.json').write_text(json.dumps(results, ensure_ascii=False, indent=2) + '\n')
print(json.dumps(results, ensure_ascii=False))
