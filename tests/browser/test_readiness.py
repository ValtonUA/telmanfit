"""Негативні й асинхронні Chromium перевірки test-only render readiness."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import json
import unittest

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError
from readiness import TRACKER, livewire_click, livewire_ready


class ReadinessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.playwright = sync_playwright().start()
        cls.browser = cls.playwright.chromium.launch(args=['--no-sandbox'])

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

    def action_page(self, action_status=200, action_method='mountAction'):
        methods = []

        def respond(route):
            method = route.request.post_data_json['components'][0]['calls'][0]['method']
            methods.append(method)
            route.fulfill(status=503 if method == 'pullNotificationsFromSession' else action_status,
                          content_type='application/json', body='{}')

        self.page.route('http://synthetic.invalid/', lambda route: route.fulfill(
            content_type='text/html', body='<button>Action</button><p id="result">before</p>'))
        self.page.route('**/livewire/update', respond)
        self.page.goto('http://synthetic.invalid/')
        self.page.evaluate(TRACKER)
        self.page.evaluate('''(method) => {
            window.Livewire = {hook: (name, callback) => {window.commitHook = callback}};
            document.dispatchEvent(new Event('livewire:init'));
            const send = async method => {
                const response = await fetch('/livewire/update', {method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({components: [{calls: [{method, params: []}]}]})});
                await response.text();
                return response;
            };
            document.querySelector('button').onclick = async () => {
                // Фонова відповідь гарантовано приходить до commit дії.
                await send('pullNotificationsFromSession');
                let succeed, fail;
                window.commitHook({succeed: cb => {succeed = cb}, fail: cb => {fail = cb}});
                const response = await send(method);
                if (!response.ok) { fail(); return; }
                document.querySelector('#result').textContent = 'rendered';
                succeed();
            };
        }''', action_method)
        return methods

    def test_action_wait_ignores_unrelated_notifications_response(self):
        methods = self.action_page()
        livewire_click(self.page, self.page.get_by_role('button', name='Action'), 'mountAction')
        self.assertEqual(methods, ['pullNotificationsFromSession', 'mountAction'])
        self.assertEqual(self.page.locator('#result').inner_text(), 'rendered')
        self.assertEqual(self.page.evaluate('window.__ciLivewire.rendered'), 1)

    def test_failed_action_is_not_hidden_by_background_response(self):
        self.action_page(action_status=503)
        with self.assertRaises(AssertionError):
            livewire_click(self.page, self.page.get_by_role('button', name='Action'), 'mountAction')

    def test_unrelated_method_cannot_satisfy_action_wait(self):
        self.action_page(action_method='otherAction')
        self.page.set_default_timeout(250)
        with self.assertRaises(PlaywrightTimeoutError):
            livewire_click(self.page, self.page.get_by_role('button', name='Action'), 'mountAction')


class BrowserGroupCleanupTests(unittest.TestCase):
    def test_cancellation_closes_actual_chromium_descendants(self):
        root = Path(__file__).resolve().parents[2]
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            (work / 'browser.py').write_text('''import os,sys,json,subprocess,time
from pathlib import Path
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 print('launching Chromium',flush=True)
 browser=p.chromium.launch(args=['--no-sandbox'],timeout=30000)
 print('Chromium launched',flush=True)
 page=browser.new_page();page.set_content('<p>synthetic cleanup probe</p>')
 print('synthetic page loaded',flush=True)
 table=[list(map(int,line.split())) for line in subprocess.check_output(['ps','-eo','pid=,ppid='],text=True).splitlines()]
 pids={os.getpid()}
 while True:
  children={pid for pid,parent in table if parent in pids}
  if children<=pids:break
  pids|=children
 marker=Path(sys.argv[1]);marker.with_suffix('.tmp').write_text(json.dumps(sorted(pids)));os.replace(marker.with_suffix('.tmp'),marker)
 while True:time.sleep(.1)
''')
            (work / 'driver.py').write_text('''import os,sys,signal,pathlib
sys.path.insert(0,sys.argv[2])
import stage1_groups as g
signal.signal(signal.SIGTERM,g.interrupted)
root=pathlib.Path(sys.argv[1])
try:g.run_phase({'browser':[sys.executable,str(root/'browser.py'),str(root/'pids')]},
                {'browser':os.environ.copy()},{'browser':root/'browser.out'}, {})
except InterruptedError:sys.exit(143)
''')
            with (work / 'driver.out').open('w') as driver_log:
                process = subprocess.Popen([sys.executable, str(work / 'driver.py'), directory, str(root / 'scripts/ci')],
                                           stdout=driver_log, stderr=subprocess.STDOUT)
                try:
                    # Cold bundled Chromium може стартувати повільніше за системний binary.
                    # Це лише startup budget; terminate/cleanup перевірки не послаблені.
                    deadline = time.monotonic() + 45
                    while (not (work / 'pids').exists() and process.poll() is None
                           and time.monotonic() < deadline):
                        time.sleep(.02)
                    diagnostics = (work / 'browser.out').read_text() if (work / 'browser.out').exists() else 'worker not started'
                    self.assertTrue((work / 'pids').exists(), diagnostics)
                    pids = json.loads((work / 'pids').read_text())
                    self.assertGreater(len(pids), 3)
                    process.terminate()
                    self.assertEqual(process.wait(timeout=8), 143)
                    deadline = time.monotonic() + 5
                    def live():
                        # Zombies вже не тримають browser/DB ресурсів; init reap може бути пізніше.
                        active = []
                        for pid in pids:
                            try:
                                status = Path(f'/proc/{pid}/stat').read_text().split(') ')[1].split()[0]
                                if status != 'Z': active.append(pid)
                            except (FileNotFoundError, ProcessLookupError):
                                pass
                        return active
                    while live() and time.monotonic() < deadline:
                        time.sleep(.05)
                    self.assertEqual(live(), [])
                finally:
                    if process.poll() is None:
                        process.terminate()
                        try:
                            process.wait(timeout=8)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=3)
                    diagnostics = '\n'.join((work / name).read_text(errors='replace')
                                            for name in ['driver.out', 'browser.out'] if (work / name).exists())
                    output = root / 'tests/pg/out'
                    output.mkdir(parents=True, exist_ok=True)
                    (output / 'browser-cleanup-probe.out').write_text(diagnostics)
