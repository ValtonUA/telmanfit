"""Справжні процеси: bounded overlap, fail-fast, власні process groups/cleanup."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('stage1_groups', ROOT / 'scripts/ci/stage1_groups.py')
groups = importlib.util.module_from_spec(spec)
spec.loader.exec_module(groups)


class GroupTests(unittest.TestCase):
    def test_private_worker_environment_cannot_inherit_tokens_or_wrong_mode(self):
        inherited = {name: '1' for name in groups.SELECTORS}
        inherited.update(GH_TOKEN='synthetic-token', GITHUB_TOKEN='synthetic-token', PATH='/usr/bin')
        with patch.dict(os.environ, inherited, clear=True):
            for name in groups.GROUPS:
                env = groups.worker_environment(name, Path('/tmp/private') / name, 'a' * 32)
                self.assertNotIn('GH_TOKEN', env)
                self.assertNotIn('GITHUB_TOKEN', env)
                self.assertEqual({k: env[k] for k in groups.SELECTORS if k in env}, groups.GROUPS[name])
                self.assertEqual(env['VIEW_COMPILED_PATH'], f'/tmp/private/{name}/views')
                self.assertEqual(env['STAGE1_PHPUNIT_CACHE'], f'/tmp/private/{name}/phpunit')

    def test_every_runner_mode_is_cleaned_from_worker_environment(self):
        import re
        runner = (ROOT / 'tests/pg/run-stage1.sh').read_text()
        modes = set(re.findall(r'\$\{([A-Z0-9_]+):-', runner))
        modes -= {'DOCKER_HOST', 'DOCKER_CONTEXT', 'STAGE1_CI_GROUP', 'STAGE1_PHPUNIT_CACHE'}
        self.assertFalse(modes - groups.SELECTORS, modes - groups.SELECTORS)

    def test_cancellation_during_spawn_still_registers_and_reaps_pid(self):
        with tempfile.TemporaryDirectory() as directory:
            real_popen = subprocess.Popen
            spawned = []
            def cancel_at_spawn(*args, **kwargs):
                process = real_popen(*args, **kwargs)
                spawned.append(process)
                groups.CANCELLED = 15
                return process
            results = {}
            with patch.object(groups, 'CANCELLED', 0), \
                 patch.object(groups.subprocess, 'Popen', side_effect=cancel_at_spawn):
                with self.assertRaises(InterruptedError):
                    groups.run_phase({'startup': [sys.executable, '-c', 'import time;time.sleep(10)']},
                                     {'startup': os.environ.copy()},
                                     {'startup': Path(directory) / 'startup.out'}, results)
            self.assertEqual(results['startup']['status'], 'cancelled')
            self.assertIsNotNone(spawned[0].poll())

    def test_remote_docker_overrides_are_rejected_before_any_query(self):
        for key in ['DOCKER_HOST', 'DOCKER_CONTEXT']:
            with patch.dict(os.environ, {key: 'synthetic-remote'}, clear=True), \
                 patch.object(groups.subprocess, 'check_output') as query:
                with self.assertRaises(RuntimeError):
                    groups.require_local_docker()
                query.assert_not_called()

    def test_only_unix_context_is_accepted(self):
        with patch.dict(os.environ, {}, clear=True), \
             patch.object(groups.subprocess, 'check_output', return_value='tcp://synthetic.invalid:2375'):
            with self.assertRaises(RuntimeError):
                groups.require_local_docker()
        with patch.dict(os.environ, {}, clear=True), \
             patch.object(groups.subprocess, 'check_output', return_value='unix:///var/run/docker.sock'):
            groups.require_local_docker()

    def test_missing_failed_cancelled_pending_or_forged_success_are_rejected(self):
        green = {name: {'status': 'success', 'exit_code': 0, 'completed_at': 'now'}
                 for name in groups.GROUPS}
        groups.require_complete(green)
        for state in ['failure', 'cancelled', 'running', 'not_started', 'skipped']:
            changed = {k: dict(v) for k, v in green.items()}
            changed['recipe']['status'] = state
            with self.assertRaises(RuntimeError):
                groups.require_complete(changed)
        for field, value in [('exit_code', 1), ('completed_at', None)]:
            changed = {k: dict(v) for k, v in green.items()}
            changed['recipe'][field] = value
            with self.assertRaises(RuntimeError):
                groups.require_complete(changed)
        del green['recipe']
        with self.assertRaises(RuntimeError):
            groups.require_complete(green)

    def test_selected_group_runs_only_its_bounded_phase(self):
        selected, phases = groups.select_groups('recipe,b5')
        self.assertEqual(tuple(selected), ('recipe', 'b5'))
        self.assertEqual(phases, (('b5',), ('recipe',)))
        self.assertEqual(set(groups.select_groups('all')[0]), set(groups.GROUPS))
        with self.assertRaises(ValueError):
            groups.select_groups('recipe,unknown')
        with self.assertRaises(ValueError):
            groups.select_groups('recipe,recipe')

    def test_all_required_groups_in_bounded_phases(self):
        self.assertEqual(groups.PHASES, (('day', 'b5'), ('recipe', 'baseline'), ('assignment', 'b3')))
        self.assertEqual(set(groups.GROUPS), {'day', 'assignment', 'recipe', 'baseline', 'b5', 'b3'})
        self.assertEqual(groups.GROUPS['baseline'], {
            'AUTH3_BROWSER': '1', 'AUTH4_BROWSER': '1', 'AUTH5_BROWSER': '1',
            'MEAL_PLAN_ASSIGNMENT_BROWSER': '1', 'ASSIGNMENT_BROWSER_PORT': '8098',
        })
        self.assertEqual(groups.GROUPS['recipe'], {'RECIPE_BATCH_ONLY': '1', 'RECIPE_BATCH_BROWSER': '1'})
        self.assertEqual(groups.GROUPS['b5'], {'AUTH6B5_ONLY': '1'})
        self.assertEqual(groups.GROUPS['b3'], {'AUTH6B_ONLY': '1'})

    def test_real_workers_overlap_and_have_private_process_groups(self):
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            source = '''import os, pathlib, time, sys, json
root=pathlib.Path(sys.argv[1]);name=sys.argv[2];peer=sys.argv[3]
(root/name).write_text(str(os.getpgrp()==os.getpid()))
deadline=time.monotonic()+5
while not (root/peer).exists():
 if time.monotonic()>deadline:sys.exit(9)
 time.sleep(.01)
print('observed peer')
'''
            commands = {n: [sys.executable, '-c', source, directory, n, p]
                        for n, p in [('first', 'second'), ('second', 'first')]}
            logs = {n: work / (n + '.out') for n in commands}
            results = {}
            groups.run_phase(commands, {n: os.environ.copy() for n in commands}, logs, results)
            self.assertTrue(all(r['status'] == 'success' for r in results.values()))
            self.assertTrue(all((work / n).read_text() == 'True' for n in commands))
            self.assertTrue(all('observed peer' in p.read_text() for p in logs.values()))

    def test_failure_cancels_sibling_and_runs_its_cleanup(self):
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            sibling = '''import pathlib,signal,sys,time
root=pathlib.Path(sys.argv[1])
def stop(*args):
 (root/'cleaned').write_text('own cleanup');sys.exit(143)
signal.signal(signal.SIGTERM,stop)
(root/'started').write_text('yes')
while True:time.sleep(.01)
'''
            failing = '''import pathlib,time,sys
root=pathlib.Path(sys.argv[1]);deadline=time.monotonic()+5
while not (root/'started').exists():
 if time.monotonic()>deadline:sys.exit(8)
 time.sleep(.01)
sys.exit(23)
'''
            commands = {'fail': [sys.executable, '-c', failing, directory],
                        'sibling': [sys.executable, '-c', sibling, directory]}
            logs = {n: work / (n + '.out') for n in commands}
            results = {}
            with self.assertRaisesRegex(RuntimeError, 'failed: 23'):
                groups.run_phase(commands, {n: os.environ.copy() for n in commands}, logs, results)
            self.assertEqual(results['fail']['exit_code'], 23)
            self.assertEqual(results['sibling']['status'], 'cancelled')
            self.assertTrue((work / 'cleaned').exists())
            for item in results.values():
                with self.assertRaises(ProcessLookupError):
                    os.kill(item['pid'], 0)

    def test_container_recovery_never_removes_foreign_label(self):
        group = 'a' * 32
        container = 'b' * 64
        with patch.object(groups.subprocess, 'check_output', side_effect=[container + '\n', 'foreign\n']), \
             patch.object(groups.subprocess, 'run') as remove:
            with self.assertRaisesRegex(ValueError, 'foreign'):
                groups.cleanup_containers(group)
            remove.assert_not_called()

    def test_caller_cancellation_terminates_worker_group(self):
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            worker = work / 'worker.py'
            worker.write_text('''import signal,sys,time,pathlib,os
root=pathlib.Path(sys.argv[1])
def stop(*args):
 (root/'cleaned').write_text('yes');sys.exit(143)
signal.signal(signal.SIGTERM,stop)
(root/'pid').write_text(str(os.getpid()))
while True:time.sleep(.01)
''')
            driver = work / 'driver.py'
            driver.write_text('''import sys,os,pathlib,signal
sys.path.insert(0,sys.argv[2])
import stage1_groups as g
signal.signal(signal.SIGTERM,g.interrupted)
root=pathlib.Path(sys.argv[1])
try:
 g.run_phase({'worker':[sys.executable,str(root/'worker.py'),str(root)]},
             {'worker':os.environ.copy()},{'worker':root/'worker.out'}, {})
except InterruptedError:sys.exit(143)
''')
            process = subprocess.Popen([sys.executable, str(driver), directory, str(ROOT / 'scripts/ci')])
            self.addCleanup(lambda: process.poll() is None and process.kill())
            import time
            deadline = time.monotonic() + 5
            while not (work / 'pid').exists() and time.monotonic() < deadline:
                time.sleep(.01)
            self.assertTrue((work / 'pid').exists())
            process.terminate()
            self.assertEqual(process.wait(timeout=8), 143)
            self.assertTrue((work / 'cleaned').exists())
            with self.assertRaises(ProcessLookupError):
                os.kill(int((work / 'pid').read_text()), 0)

    def test_recovers_only_verified_own_container(self):
        group = 'a' * 32
        container = 'b' * 64
        with patch.object(groups.subprocess, 'check_output', side_effect=[container + '\n', group + '\n']), \
             patch.object(groups.subprocess, 'run') as remove:
            self.assertEqual(groups.cleanup_containers(group), [container])
            self.assertEqual(remove.call_args.args[0], ['docker', 'rm', '-f', container])

    def test_invalid_group_cannot_query_docker(self):
        with patch.object(groups.subprocess, 'check_output') as query:
            with self.assertRaises(ValueError):
                groups.cleanup_containers('')
            query.assert_not_called()
