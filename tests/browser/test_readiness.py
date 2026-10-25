"""Негативні й асинхронні Chromium перевірки test-only render readiness."""
import shutil
import unittest

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError
from readiness import TRACKER, livewire_ready


class ReadinessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.playwright = sync_playwright().start()
        cls.browser = cls.playwright.chromium.launch(
            executable_path=shutil.which('chromium'), args=['--no-sandbox'])

    @classmethod
    def tearDownClass(cls):
        cls.browser.close()
        cls.playwright.stop()

    def setUp(self):
        self.context = self.browser.new_context()
        self.addCleanup(self.context.close)
        self.page = self.context.new_page()
        self.page.set_default_timeout(3000)

    def tracked_page(self):
        self.page.set_content('<p id="result">before</p>')
        self.page.evaluate(TRACKER)
        self.page.evaluate('''() => {
            window.Livewire = {hook: (name, callback) => {window.commitHook = callback}};
            document.dispatchEvent(new Event('livewire:init'));
        }''')

    def test_waits_for_render_and_all_pending_commits(self):
        self.tracked_page()
        self.page.evaluate('''() => {
            const callbacks = [];
            for (let i = 0; i < 2; i++) {
                window.commitHook({succeed: cb => callbacks.push(cb), fail: () => {}});
            }
            callbacks[0]();
            setTimeout(() => {
                document.querySelector('#result').textContent = 'rendered';
                callbacks[1]();
            }, 100);
        }''')
        livewire_ready(self.page)
        self.assertEqual(self.page.locator('#result').inner_text(), 'rendered')
        self.assertEqual(self.page.evaluate('window.__ciLivewire.rendered'), 2)
        self.assertEqual(self.page.evaluate('window.__ciLivewire.pending'), 0)

    def test_failed_commit_is_not_ready(self):
        self.tracked_page()
        self.page.evaluate('''() => {
            window.commitHook({succeed: () => {}, fail: callback => callback()});
        }''')
        self.page.set_default_timeout(250)
        with self.assertRaises(PlaywrightTimeoutError):
            livewire_ready(self.page)

    def test_missing_tracker_is_not_ready(self):
        self.page.set_content('<p>no Livewire</p>')
        self.page.set_default_timeout(250)
        with self.assertRaises(PlaywrightTimeoutError):
            livewire_ready(self.page)
