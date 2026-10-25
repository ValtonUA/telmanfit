"""Негативні й асинхронні Chromium перевірки test-only render readiness."""
import shutil
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import json
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


class BrowserGroupCleanupTests(unittest.TestCase):
    def test_cancellation_closes_actual_chromium_descendants(self):
        root = Path(__file__).resolve().parents[2]
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            (work / 'browser.py').write_text('''import os,sys,json,subprocess,shutil,time
from pathlib import Path
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=shutil.which('chromium'),args=['--no-sandbox'])
 page=browser.new_page();page.set_content('<p>synthetic cleanup probe</p>')
 table=[list(map(int,line.split())) for line in subprocess.check_output(['ps','-eo','pid=,ppid='],text=True).splitlines()]
 pids={os.getpid()}
 while True:
  children={pid for pid,parent in table if parent in pids}
  if children<=pids:break
  pids|=children
 Path(sys.argv[1]).write_text(json.dumps(sorted(pids)))
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
            process = subprocess.Popen([sys.executable, str(work / 'driver.py'), directory, str(root / 'scripts/ci')])
            self.addCleanup(lambda: process.poll() is None and process.kill())
            deadline = time.monotonic() + 10
            while not (work / 'pids').exists() and time.monotonic() < deadline:
                time.sleep(.02)
            self.assertTrue((work / 'pids').exists())
            pids = json.loads((work / 'pids').read_text())
            self.assertGreater(len(pids), 3)
            process.terminate()
            self.assertEqual(process.wait(timeout=8), 143)
            deadline = time.monotonic() + 5
            def live():
                # Завершені zombies не тримають browser/DB ресурсів; init може reap пізніше.
                active = []
                for pid in pids:
                    try:
                        status = Path(f'/proc/{pid}/stat').read_text().split(') ')[1].split()[0]
                        if status != 'Z': active.append(pid)
                    except FileNotFoundError:
                        pass
                return active
            while live() and time.monotonic() < deadline:
                time.sleep(.05)
            self.assertEqual(live(), [])
