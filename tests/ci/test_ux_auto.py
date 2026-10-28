"""Негативні acceptance-тести неактивного координатора та crash recovery."""
from copy import deepcopy
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from scripts.ux_auto import coordinator as c
from scripts.ux_auto.mock import MockBackend
from scripts.ci.pre_merge import REQUIRED

ROOT = Path(__file__).resolve().parents[2]
SPEC = hashlib.sha256((ROOT / 'scripts/ux_auto/spec.json').read_bytes()).hexdigest()


class CoordinatorTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name)
        self.backend = MockBackend(self.path / 'remote.json', SPEC)
        self.state = c.initial(SPEC)

    def step(self):
        self.state = c.tick(self.state, self.backend, SPEC)
        return self.state['stages'][0]

    def ready(self):
        for _ in range(3):
            self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'verifying')
        pr = self.backend.current('UX-1')
        return pr, self.backend.evidence('UX-1')

    def finish(self):
        for _ in range(c.MAX_TICKS):
            old = deepcopy(self.state)
            self.step()
            if self.state == old:
                break
        return c.summary(self.state)

    def assert_rejected(self, pr, evidence):
        with self.assertRaises((c.Blocked, KeyError, TypeError)):
            c.check_evidence('UX-1', pr, evidence, SPEC)

    def test_six_stages_success_and_duplicate_runs(self):
        self.assertTrue(self.finish()['complete'])
        self.assertEqual(len(self.backend.data['prs']), 6)
        self.assertEqual(len(self.backend.data['receipts']), 6)
        self.assertEqual(len(self.backend.data['operations']), 12)
        saved = deepcopy(self.backend.data)
        self.finish()
        self.assertEqual(saved, self.backend.data)
        for i, s in enumerate(self.state['stages']):
            if i:
                self.assertEqual(s['pr']['base_sha'], self.state['stages'][i-1]['merge_sha'])

    def test_restart_after_every_transition(self):
        for _ in range(40):
            old = deepcopy(self.state)
            self.step()
            c.atomic_json(self.path / 'state.json', self.state)
            self.state = json.loads((self.path / 'state.json').read_text())
            self.backend = MockBackend(self.path / 'remote.json', SPEC)
            if self.state == old:
                break
        self.assertTrue(c.summary(self.state)['complete'])
        self.assertEqual(len(self.backend.data['operations']), 12)

    def test_crash_after_publish_before_local_commit(self):
        self.step()
        self.step()
        self.state = c.initial(SPEC)
        self.step()
        self.step()
        self.assertEqual(len(self.backend.data['prs']), 1)
        self.assertEqual(len(self.backend.data['operations']), 1)

    def test_crash_after_merge_before_local_commit(self):
        self.ready()
        before = deepcopy(self.state)
        self.step()
        self.assertEqual(len(self.backend.data['receipts']), 1)
        self.state = before
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'merged')
        self.assertEqual(len(self.backend.data['receipts']), 1)
        self.assertTrue(self.finish()['complete'])

    def test_retry_feedback_same_pr_new_sha_new_evidence(self):
        self.backend.scenario = 'retry'
        self.assertTrue(self.finish()['complete'])
        self.assertTrue(all(s['attempt'] == 2 for s in self.state['stages']))
        feedback = [v for v in self.backend.data['feedback'].values() if v]
        self.assertEqual(len(feedback), 6)
        for s in self.state['stages']:
            self.assertEqual(s['verified']['head'], s['pr']['head'])

    def test_failure_retry_budget_stop_and_no_reset(self):
        self.backend.scenario = 'failure'
        self.assertFalse(self.finish()['complete'])
        s = self.state['stages'][0]
        self.assertEqual((s['state'], s['attempt']), ('blocked', 3))
        self.assertIn('Вичерпано', s['reason'])
        self.assertFalse(self.backend.data['receipts'])
        self.assertTrue(all(s['state'] == 'pending' for s in self.state['stages'][1:]))
        with self.assertRaises(c.Blocked):
            c.resume(self.state, SPEC)

    def test_pending_approval_does_not_merge(self):
        self.backend.scenario = 'approval'
        self.finish()
        self.assertEqual(self.state['stages'][0]['state'], 'blocked')
        self.assertFalse(self.backend.data['receipts'])

    def test_head_and_base_race_no_next_stage(self):
        for field in ('head', 'base_sha'):
            with self.subTest(field=field):
                self.setUp()
                self.ready()
                self.backend.data['prs']['UX-1'][field] = 'f' * 40
                self.step()
                self.assertEqual(self.state['stages'][0]['state'], 'blocked')
                self.assertEqual(self.state['stages'][1]['state'], 'pending')
        self.setUp()
        self.ready()
        self.backend.scenario = 'race'
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'blocked')
        self.assertFalse(self.backend.data['receipts'])

    def test_unconfirmed_merge_cannot_advance(self):
        self.backend.scenario = 'unconfirmed'
        self.finish()
        self.assertEqual(self.state['stages'][0]['state'], 'blocked')
        self.assertEqual(self.state['stages'][1]['state'], 'pending')
        self.backend.scenario = 'success'
        self.state = c.resume(self.state, SPEC)
        self.assertTrue(self.finish()['complete'])
        self.assertEqual(len(self.backend.data['operations']), 12)

    def test_pause_resume_and_recover_block(self):
        self.state['paused'] = True
        self.assertEqual(c.tick(self.state, self.backend, SPEC), self.state)
        self.state = c.resume(self.state, SPEC)
        self.ready()
        self.backend.data['prs']['UX-1']['head'] = 'e' * 40
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'blocked')
        self.state = c.resume(self.state, SPEC)
        self.assertTrue(self.finish()['complete'])

    def test_ci_missing_failed_stale_skipped_wrong_event_attempt(self):
        pr, evidence = self.ready()
        for workflow in REQUIRED:
            for mode in ('missing', 'failure', 'stale', 'skipped', 'event', 'attempt'):
                with self.subTest(workflow=workflow, mode=mode):
                    e = deepcopy(evidence)
                    run = e['ci']['runs'][workflow][0]
                    if mode == 'missing':
                        e['ci']['runs'][workflow] = []
                    elif mode in ('failure', 'skipped'):
                        run['conclusion'] = mode
                    elif mode == 'stale':
                        run['head_sha'] = '0' * 40
                    elif mode == 'event':
                        run['event'] = 'workflow_dispatch'
                    else:
                        run['run_attempt'] = 2
                    self.assert_rejected(pr, e)

    def test_pr_scope_base_fork_payload_and_conflict_rejected(self):
        pr, evidence = self.ready()
        for field, value in [('stage', 'UX-7'), ('base', 'develop'), ('repository', 'evil/fork'),
                             ('branch', 'arbitrary'), ('conflicts', True), ('head', '$(touch /tmp/evil)'),
                             ('spec_sha', 'b' * 64)]:
            p = deepcopy(pr)
            p[field] = value
            self.assert_rejected(p, evidence)
        for field, value in [('auto_merge', None), ('auto_merge', {'enabled': True})]:
            e = deepcopy(evidence)
            e['ci']['pr'][field] = value
            if value:
                self.assert_rejected(pr, e)
        e = deepcopy(evidence)
        e['ci']['comparison']['status'] = 'diverged'
        self.assert_rejected(pr, e)

    def test_review_not_plain_pass_independent_readonly_and_exact(self):
        pr, evidence = self.ready()
        for field, value in [('head', '0' * 40), ('base', '0' * 40), ('spec_sha', '0' * 64),
                             ('readonly', False), ('invocation', pr['coder_invocation']),
                             ('invocation', ''), ('findings', ['blocking']), ('checks', []), ('evidence', [])]:
            e = deepcopy(evidence)
            e['review'][field] = value
            self.assert_rejected(pr, e)
        self.assert_rejected(pr, {**evidence, 'review': 'PASS'})

    def test_browser_failed_missing_matrix_assertions_screenshots_stale(self):
        pr, evidence = self.ready()
        for field, value in [('head', '0' * 40), ('status', 'skipped'), ('checks', []),
                             ('matrix', ['1440/light']), ('screenshots', [])]:
            e = deepcopy(evidence)
            e['browser'][field] = value
            self.assert_rejected(pr, e)

    def test_corrupt_spec_state_multiple_active_and_out_of_order(self):
        for mutation in ('spec', 'multiple', 'order', 'budget', 'stage'):
            s = deepcopy(self.state)
            if mutation == 'spec':
                s['spec_sha'] = '0' * 64
            elif mutation == 'multiple':
                s['stages'][0]['state'] = s['stages'][1]['state'] = 'running'
            elif mutation == 'order':
                s['stages'][1].update(state='merged', merge_sha='a' * 40)
            elif mutation == 'budget':
                s['stages'][0]['attempt'] = 4
            else:
                s['stages'][0]['id'] = 'UX-7'
            with self.assertRaises(c.Blocked):
                c.tick(s, self.backend, SPEC)

    def test_atomic_state_and_exclusive_lock(self):
        path = self.path / 'state.json'
        c.atomic_json(path, self.state)
        with patch('os.replace', side_effect=OSError('synthetic disk error')):
            with self.assertRaises(OSError):
                c.atomic_json(path, {'bad': True})
        self.assertEqual(json.loads(path.read_text()), self.state)
        with c.locked(path):
            with self.assertRaises(c.Blocked):
                with c.locked(path):
                    pass
        with c.locked(path):
            pass

    def test_cli_live_and_ambiguous_trigger_fail_closed(self):
        for arguments in (['live'], ['simulate', '--trigger', 'issue_comment'],
                          ['simulate', '--trigger', '$(evil)']):
            result = subprocess.run(['python3', '-m', 'scripts.ux_auto.coordinator', *arguments,
                                     '--directory', str(self.path)], cwd=ROOT, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((self.path / 'state.json').exists())

    def test_cli_durable_resume_scenario_binding_and_reports(self):
        command = ['python3', '-m', 'scripts.ux_auto.coordinator', 'simulate', '--directory', str(self.path)]
        first = subprocess.run(command, cwd=ROOT, capture_output=True)
        second = subprocess.run(command, cwd=ROOT, capture_output=True)
        self.assertEqual((first.returncode, second.returncode), (0, 0))
        self.assertEqual(first.stdout, second.stdout)
        report = json.loads(first.stdout)
        self.assertTrue(report['complete'])
        self.assertFalse(report['live_enabled'])
        wrong = subprocess.run([*command, '--scenario', 'failure'], cwd=ROOT, capture_output=True)
        self.assertNotEqual(wrong.returncode, 0)

    def test_prompt_uses_pinned_scope_and_no_event_payload(self):
        prompt = c.stage_prompt('UX-3')
        self.assertIn('MOCK ONLY. Stage UX-3', prompt)
        self.assertIn('AGENTS.md', prompt)
        self.assertIn('meal_types', prompt)
        self.assertEqual(c.trusted_spec_sha(), SPEC)
        with self.assertRaises(c.Blocked):
            c.stage_prompt('$(evil)')

    def test_workflow_has_no_live_write_secrets_or_unpinned_actions(self):
        import re
        workflow = (ROOT / '.github/workflows/ux-auto.yml').read_text()
        self.assertNotIn('pull_request_target', workflow)
        self.assertNotIn('secrets.', workflow)
        self.assertNotIn(': write', workflow)
        self.assertNotIn('codex-action', workflow)
        self.assertIn('contents: read', workflow)
        for action in re.findall(r'uses: (\S+)', workflow):
            self.assertRegex(action, r'@[0-9a-f]{40}$')
