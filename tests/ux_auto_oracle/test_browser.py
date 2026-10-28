"""Реальний Chromium тестує trusted assertions на навмисно зламаних synthetic UI."""
import functools
import http.server
import importlib.util
import json
import shutil
import tempfile
import threading
from pathlib import Path
import unittest
from unittest.mock import patch

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('trusted_assertions', ROOT / 'scripts/ux_auto/oracle/assertions.py')
a = importlib.util.module_from_spec(spec)
spec.loader.exec_module(a)


class OracleBrowserTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.root = Path(cls.temp.name)
        class Handler(http.server.SimpleHTTPRequestHandler):
            def log_message(self, *_):
                pass
            def do_GET(self):
                self.path = '/index.html'
                return super().do_GET()
        cls.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), functools.partial(Handler, directory=cls.temp.name))
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = f'http://127.0.0.1:{cls.server.server_port}'
        cls.playwright = sync_playwright().start()
        cls.browser = cls.playwright.chromium.launch(headless=True, executable_path=shutil.which('chromium'))

    @classmethod
    def tearDownClass(cls):
        cls.browser.close()
        cls.playwright.stop()
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()
        cls.temp.cleanup()

    def page(self, html):
        (self.root / 'index.html').write_text('<meta charset="utf-8">' + html)
        context = self.browser.new_context(has_touch=True)
        self.addCleanup(context.close)
        context.add_init_script('window.__oracleDocument=crypto.randomUUID()')
        page = context.new_page()
        page.set_default_timeout(2500)
        page.goto(self.base)
        return page

    def test_navigation_real_dom_positive_and_document_reload_negative(self):
        html = '<main><a href="/one">One</a><a href="/two">Two</a></main>'
        html += '<script>document.querySelectorAll("a").forEach(a=>a.onclick=e=>{e.preventDefault();history.pushState({},"",a.href)})</script>'
        a.navigation(self.page(html), ['One', 'Two'])
        with self.assertRaisesRegex(AssertionError, 'document navigation'):
            a.navigation(self.page('<main><a href="/one">One</a></main>'), ['One'])

    def test_claimed_pass_and_fake_seven_day_dom_cannot_replace_sql_state(self):
        page = self.page("""<main>PASS: seven days created<label>Назва<input></label>
            <button onclick="history.pushState({},'', '/admin/meal-plan-versions/7')">Створити</button></main>""")
        with patch.object(a, 'BASE', self.base), patch.object(a, 'state', return_value={'status':'draft','days':[],'meals':[],'items':[]}):
            with self.assertRaisesRegex(AssertionError, 'seven days'):
                a.create_plan(page, 'Fixture')

    def test_wrong_desktop_layout_is_rejected_by_computed_geometry(self):
        page = self.page('<main><div data-testid="meal-plan-catalog" style="position:absolute;left:0">Catalog</div>'
                         '<div data-testid="meal-plan-meals" style="position:absolute;left:700px">Meals</div></main>')
        with patch.object(a, 'BASE', self.base):
            with self.assertRaisesRegex(AssertionError, 'catalog must be on right'):
                a.day_layout(page, {'version':1,'days':[{'day':1}]}, 1440)

    def test_drop_with_wrong_database_quantity_fails_external_assertion(self):
        page = self.page('''<main><div draggable="true" data-testid="catalog-card">Синтетичний інгредієнт</div>
        <div style="height:100px" data-testid="meal-drop-zone" ondragover="event.preventDefault()" ondrop="event.preventDefault();document.querySelector('input').hidden=false">Drop</div>
        <input hidden data-testid="inline-quantity"><button data-testid="inline-confirm">Add</button></main>''')
        before = {'items':[]}
        wrong = {'items':[{'id':1,'food_id':4,'uom_id':2,'quantity':2}]}
        with patch.object(a, 'state', side_effect=[before,before,wrong]):
            with self.assertRaisesRegex(AssertionError, 'quantity write'):
                a.fast_item(page, {'version':1,'food':4,'g':2})

    def test_copy_with_shared_ids_fails_despite_claimed_seven_days(self):
        boxes = ''.join(f'<input type="checkbox" data-testid="copy-day-target-{n}">' for n in (1,3,4,5,6,7))
        page = self.page('<main><div data-testid="week-day-2"><button>Копіювати день</button></div>'
                         +boxes+'<button data-testid="copy-day-confirm">Confirm</button></main>')
        wrong = {'days':[{'id':n,'day_number':n} for n in range(1,8)],
                 'meals':[{'id':n,'meal_plan_day_id':n} for n in range(1,8)],
                 'items':[{'id':1,'meal_id':2,'food_id':4,'uom_id':2,'quantity':2}]*7}
        before = {'days':[{'id':2,'day_number':2}], 'meals':[{'id':2,'meal_plan_day_id':2}],
                  'items':[{'id':1,'meal_id':2,'food_id':4,'uom_id':2,'quantity':2}]}
        with patch.object(a, 'BASE', self.base), patch.object(a, 'state', side_effect=[before,wrong]):
            with self.assertRaises(AssertionError):
                a.copy_days(page, {'version':1,'food':4,'g':2})

    def test_horizontal_overflow_is_actual_dom_failure(self):
        page = self.page('<main style="width:9999px">PASS report is irrelevant</main>')
        with self.assertRaisesRegex(AssertionError, 'overflow'):
            a.no_overflow(page)
