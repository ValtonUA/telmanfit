"""Регресії трьох P1 review: slices, stage test policy, external oracle boundary."""
from copy import deepcopy
import functools
import http.server
import importlib.util
import json
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import patch

import test_ux_auto_live as fixture
from scripts.ux_auto import continuation as k
from scripts.ux_auto import coordinator as c
from scripts.ux_auto.backend import Backend
from scripts.ux_auto.live import advance
from scripts.ux_auto.oracle_runner import fingerprint, verify
from scripts.ux_auto.store import Session
from scripts.ux_auto.worker import Workers, validate_changes

ROOT = Path(__file__).resolve().parents[2]


class ReviewTests(unittest.TestCase):
    def setUp(self):
        self.f = fixture.LiveTests()
        self.f.setUp()

    def backend(self, session):
        result = Backend(self.f.api, session, self.f.workers, self.f.cfg)
        result.fetch = lambda sha: None
        result.ready = lambda node: None
        from scripts.ci.pre_merge import evaluate
        result.trusted_gate = lambda n: evaluate(self.f.api.snapshot(n))
        return result

    def slice(self, session):
        elapsed = [-5000]
        def clock():
            elapsed[0] += 5000
            return elapsed[0]
        k.claim(session)
        return advance(self.f.cfg, session, self.f.workers, self.f.api,
                       backend=self.backend(session), monotonic=clock, sleeper=lambda n: None)

    def next_owner(self):
        session = Session(self.f.api, self.f.cfg)
        session.acquire()
        return session

    def test_one_start_many_jobs_six_stages_no_manual_run_or_duplicate(self):
        session = self.f.session
        self.assertFalse(k.eligible(self.f.cfg, session.value))
        k.arm(session)  # єдиний owner start
        jobs = 0
        while k.eligible(self.f.cfg, session.value):
            result = self.slice(session)
            jobs += 1
            session.release()
            _, saved = session.read()
            if not k.eligible(self.f.cfg, saved):
                break
            session = self.next_owner()  # schedule, не новий run/arm
        self.assertGreater(jobs, 1)
        self.assertTrue(c.summary(saved['checkpoint'])['complete'])
        self.assertFalse(saved['continuation']['armed'])
        self.assertEqual(saved['continuation']['slices'], jobs)
        self.assertEqual(len(self.f.workers.calls), 12)
        self.assertEqual(len(self.f.api.prs), 6)
        self.assertEqual(len([r for r in self.f.api.calls if r[1].endswith('/merge')]), 6)
        self.assertGreater(saved['spend']['total'], 0)

    def test_waiting_ci_survives_job_end_without_new_coder_invocation(self):
        s = self.f.session
        k.arm(s)
        self.slice(s)
        self.f.api.pending = True
        first = len(self.f.workers.calls)
        s.release()
        s = self.next_owner()
        self.assertEqual(self.slice(s)['status'], 'yielded')
        self.assertEqual(len(self.f.workers.calls), first)
        self.assertTrue(k.eligible(self.f.cfg, s.value))
        self.f.api.pending = False
        self.slice(s)
        self.assertTrue(any(p['merged'] for p in self.f.api.prs.values()))

    def test_crash_after_publish_before_checkpoint_does_not_reinvoke_code(self):
        s = self.f.session
        k.arm(s)
        running = c.tick(s.value['checkpoint'], self.backend(s), self.f.cfg['spec_sha'])
        s.update(lambda v: v.__setitem__('checkpoint', running))
        c.tick(running, self.backend(s), self.f.cfg['spec_sha'])  # external write, checkpoint crash
        self.assertEqual(len(self.f.workers.calls), 1)
        # Simulate hard job kill: lease лишився, наступний scheduler не краде живий lease.
        _, durable = s.read()
        self.assertGreater(durable['lease']['expires'], time.time())
        value = deepcopy(s.value)
        value['lease']['expires'] = 0
        s.save(value)
        restarted = self.next_owner()
        self.slice(restarted)
        self.assertEqual(len([call for call in self.f.workers.calls if not call[2].get('reviewer')]), 1)
        with self.assertRaises(c.Blocked):
            s.guard()

    def test_failed_ci_blocks_and_disarms_scheduler(self):
        s = self.f.session
        k.arm(s)
        self.f.api.failed = True
        for _ in range(10):
            self.slice(s)
            if not k.eligible(self.f.cfg, s.value):
                break
        self.assertFalse(s.value['continuation']['armed'])
        self.assertTrue(any(stage['state'] == 'blocked' for stage in s.value['checkpoint']['stages']))
        self.assertEqual(len(self.f.api.prs), 1)
        self.assertFalse(any(p['merged'] for p in self.f.api.prs.values()))

    def test_disabled_paused_blocked_unarmed_and_slice_age_budget_fail_closed(self):
        s = self.f.session
        k.arm(s)
        for kind in ('disabled', 'paused', 'blocked', 'unarmed', 'slices', 'age'):
            cfg, data = deepcopy(self.f.cfg), deepcopy(s.value)
            if kind == 'disabled':
                cfg['enabled'] = False
            elif kind == 'paused':
                data['paused'] = True
            elif kind == 'blocked':
                data['checkpoint']['stages'][0]['state'] = 'blocked'
            elif kind == 'unarmed':
                data['continuation']['armed'] = False
            elif kind == 'slices':
                data['continuation']['slices'] = k.MAX_SLICES
            else:
                data['continuation']['started'] = time.time() - k.MAX_AGE - 1
            self.assertFalse(k.eligible(cfg, data), kind)

    def test_scheduled_continuation_never_arms_or_activates(self):
        text = (ROOT / '.github/workflows/ux-auto-live.yml').read_text()
        self.assertIn("cron: '*/5 * * * *'", text)
        self.assertIn("github.event_name == 'schedule' && 'continue'", text)
        self.assertIn("vars.UX_AUTO_LIVE_ENABLED == 'true'", text)
        self.assertIn('cancel-in-progress: false', text)
        self.assertFalse(k.eligible(self.f.cfg, self.f.session.value))

    def test_stage_specific_test_allowlist_positive_and_negative(self):
        allowed = {
            'UX-1': ['tests/Feature/SpaFallbackTest.php', 'tests/Unit/ClientNavigationTest.php'],
            'UX-2': ['tests/Feature/AdminLocaleTest.php', 'tests/Unit/UserPanelAccessTest.php'],
            'UX-3': ['tests/MealPlans/PlanFixture.php', 'tests/Unit/MealPlanModelsTest.php', 'tests/Feature/MealPlanCreationTest.php'],
            'UX-4': ['tests/Unit/MealPlanDisplayTest.php', 'tests/MealPlans/PlanFixture.php'],
            'UX-5': ['tests/Unit/MealItemPortionTest.php', 'tests/Feature/MealItemEditingTest.php', 'tests/Recipes/BatchFixture.php'],
            'UX-6': ['tests/MealPlans/AssignmentFixture.php', 'tests/Feature/MealPlanCopyDayTest.php'],
        }
        for stage, paths in allowed.items():
            for path in paths:
                entry = fixture.change(stage)
                entry['path'] = path
                validate_changes(stage, [entry])
            for path in ('tests/ci/test_gate.py', 'scripts/ci/pre_merge.py', '.github/workflows/fast.yml',
                         'phpunit.auth4.xml', 'tests/Unit/PhpRuntimePolicyTest.php', 'tests/pg/run-stage1.sh',
                         'tests/Feature/LocalComposeEpochTest.php', 'tests/MealPlans/nested/Escape.php', '.env',
                         'database/migrations/unsafe.php'):
                entry = fixture.change(stage)
                entry['path'] = path
                with self.assertRaises(c.Blocked, msg=(stage, path)):
                    validate_changes(stage, [entry])

    def test_forged_candidate_report_or_old_oracle_never_authorizes_merge(self):
        self.f.ready()
        original = self.f.workers.browser
        def forged(*args):
            report = original(*args)
            report.pop('oracle_sha')
            return report
        self.f.workers.browser = forged
        self.f.step()
        self.assertEqual(self.f.state['stages'][0]['state'], 'blocked')
        self.assertFalse(any(p['merged'] for p in self.f.api.prs.values()))
        for report in ({'head': 'a'*40}, {'head': 'a'*40, 'oracle_sha': 'old', 'oracle_mode': 'external', 'observations': ['PASS']}):
            with self.assertRaises(c.Blocked):
                verify(report, 'a'*40)

    def test_browser_never_invokes_candidate_harness(self):
        # Реальний worker boundary: candidate harness існує, але не виконується.
        cfg = self.f.cfg
        worker = Workers(cfg, ROOT, 'not-used')
        ref = __import__('subprocess').check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
        with patch('scripts.ux_auto.oracle_runner.browser', side_effect=c.Blocked('trusted runner failed')) as oracle:
            with self.assertRaisesRegex(c.Blocked, 'trusted runner failed'):
                worker.browser('UX-3', ref, ref)
            oracle.assert_called_once()

    def test_oracle_app_has_no_output_and_oracle_has_no_candidate_mount(self):
        from scripts.ux_auto.oracle_runner import browser
        calls = []
        def execute(command, **kwargs):
            calls.append(command)
            if 'logs' in command:
                return 'PostgreSQL init process complete; ready for start up.'
            if 'SHOW server_version_num' in command:
                return '180006'
            if 'start' in command and '--attach' in command:
                raise c.Blocked('intentional oracle assertion failure')
            return ''
        worker = Workers(self.f.cfg, ROOT, '', execute=execute)
        with tempfile.TemporaryDirectory() as workspace, tempfile.TemporaryDirectory() as out:
            with patch('scripts.ux_auto.oracle_runner.prepare_fixtures'):
                with self.assertRaisesRegex(c.Blocked, 'intentional'):
                    browser(worker, workspace, out, 'UX-3', 'a'*40)
            app = next(cmd for cmd in calls if 'create' in cmd and '/control/oracle/app.py' in cmd)
            oracle = next(cmd for cmd in calls if 'create' in cmd and '/control/oracle/assertions.py' in cmd)
            self.assertNotIn('dst=/out', ' '.join(app))
            self.assertNotIn(workspace, ' '.join(oracle))
            self.assertNotIn('dst=/workspace', ' '.join(oracle))
            self.assertIn('readonly', ' '.join(oracle))
            self.assertIn('dst=/out', ' '.join(oracle))
            self.assertEqual(len([cmd for cmd in calls if 'rm' in cmd]), 4)
            self.assertFalse(Path(out, 'browser.json').exists())
