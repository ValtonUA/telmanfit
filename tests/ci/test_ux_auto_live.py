"""Live adapter contracts із synthetic GitHub/Codex; не зовнішній E2E proof."""
import base64
from copy import deepcopy
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from scripts.ux_auto import coordinator as c
from scripts.ux_auto.api_proxy import Budget
from scripts.ux_auto.backend import Backend
from scripts.ux_auto.github import APIError, GitHub
from scripts.ux_auto import live_config as cfg
from scripts.ux_auto.store import Session, seed
from scripts.ux_auto.worker import Workers, validate_changes, validate_browser_files, files_from_workspace, checkout
from scripts.ux_auto.mock import green_ci

ROOT = Path(__file__).resolve().parents[2]


def config():
    value = json.loads((ROOT / 'scripts/ux_auto/live.example.json').read_text())
    value['spec_sha'] = c.trusted_spec_sha()
    value['trusted_ref'] = 'a' * 40
    value['identity'] = cfg.identity(value)
    return value


def change(stage):
    path = 'client/src/ux-auto.ts' if stage == 'UX-1' else 'app/Filament/UXAuto.php'
    if stage == 'UX-2':
        path = 'app/Providers/Filament/UXAuto.php'
    if stage == 'SANDBOX':
        path = 'ux-auto-probe.txt'
    content = 'UX-AUTO fixture ' + stage
    return {'path': path, 'content': content, 'sha256': hashlib.sha256(content.encode()).hexdigest()}


class FakeGitHub:
    """REST-shaped fixture: Contents SHA CAS, branch FF, PR/merge receipts і comments."""
    def __init__(self, config):
        self.repo, self.token = config['repository'], 'synthetic-token'
        self.master_sha = 'b' * 40
        self.files = {'coordinator.json': [c.digest(seed(config)), seed(config)]}
        self.refs, self.prs, self.comments, self.calls = {}, {}, [], []
        self.merge_parents = {}
        self.pending = False
        self.failed = False
        self.merge_timeout = False
        self.branch_timeout = False
        self.pr_timeout = False
        self.snapshot_race = False

    def content(self, path, ref):
        rev, value = self.files[path]
        return {'sha': rev, 'content': base64.b64encode(json.dumps(value).encode()).decode()}

    def put(self, path, branch, value, previous_sha):
        old = self.files.get(path)
        if (old[0] if old else None) != previous_sha:
            raise APIError('409 CAS conflict')
        revision = c.digest(value)
        self.files[path] = [revision, deepcopy(value)]
        return revision

    def master(self):
        return self.master_sha

    def pr(self, number):
        raw = deepcopy(self.prs[number])
        if not raw['merged']:
            raw['base']['sha'] = self.master_sha
        return raw

    def pages(self, path, key=None):
        if '/git/matching-refs/' in path:
            return [{'ref': 'refs/heads/' + name, 'object': {'sha': sha}} for name, sha in self.refs.items()]
        if '/pulls?' in path:
            branch = path.split('head=')[1].split('&')[0].split(':', 1)[1]
            return [self.pr(n) for n, p in self.prs.items() if p['head']['ref'] == branch]
        if '/comments' in path:
            return deepcopy(self.comments)
        raise AssertionError(path)

    def call(self, method, path, body=None):
        self.calls.append((method, path, deepcopy(body)))
        if method == 'GET' and '/git/commits/' in path:
            return {'tree': {'sha': 'c' * 40}, 'parents': self.merge_parents.get(path.rsplit('/', 1)[-1], [])}
        if method == 'GET' and '/compare/' in path:
            return {'status': 'ahead'}
        if method == 'POST' and path.endswith(('/git/blobs', '/git/trees', '/git/commits')):
            return {'sha': c.digest(body)[:40]}
        if method == 'POST' and path.endswith('/git/refs'):
            branch = body['ref'].removeprefix('refs/heads/')
            if branch in self.refs:
                raise APIError('already exists')
            self.refs[branch] = body['sha']
            if self.branch_timeout:
                self.branch_timeout = False
                raise APIError('timeout after branch write')
            return {}
        if method == 'PATCH' and '/git/refs/heads/' in path:
            if body['force'] is not False:
                raise AssertionError('force push')
            self.refs[path.split('/heads/')[1]] = body['sha']
            for p in self.prs.values():
                if p['head']['ref'] == path.split('/heads/')[1]:
                    p['head']['sha'] = body['sha']
            return {}
        if method == 'POST' and path.endswith('/pulls'):
            number = len(self.prs) + 100
            raw = {'number': number, 'node_id': str(number), 'body': body['body'], 'draft': True,
                   'state': 'open', 'merged': False, 'mergeable': True, 'auto_merge': None,
                   'html_url': f'https://github.com/{self.repo}/pull/{number}',
                   'head': {'sha': self.refs[body['head']], 'ref': body['head'], 'repo': {'full_name': self.repo}},
                   'base': {'sha': self.master_sha, 'ref': 'master', 'repo': {'full_name': self.repo}}}
            self.prs[number] = raw
            if self.pr_timeout:
                self.pr_timeout = False
                raise APIError('timeout after PR created')
            return deepcopy(raw)
        if method == 'PUT' and path.endswith('/merge'):
            number = int(path.split('/pulls/')[1].split('/')[0])
            pr = self.prs[number]
            if pr['head']['sha'] != body['sha'] or body['merge_method'] != 'merge':
                raise APIError('head mismatch')
            parents = [{'sha': self.master_sha}, {'sha': body['sha']}]
            self.master_sha = c.digest(body)[:40]
            self.merge_parents[self.master_sha] = parents
            pr.update(merged=True, state='closed', merge_commit_sha=self.master_sha)
            if self.merge_timeout:
                raise APIError('timeout after merge')
            return {'merged': True}
        if method == 'POST' and '/comments' in path:
            self.comments.append(body)
            return body
        if method == 'PUT' and '/contents/evidence/' in path:
            return {'content': {'sha': c.digest(body)}}
        raise AssertionError((method, path, body))

    def snapshot(self, number):
        pr = self.pr(number)
        normalized = {'number': number, 'head': pr['head']['sha'], 'base': 'master',
                      'base_sha': pr['base']['sha'], 'branch': pr['head']['ref']}
        snapshot = green_ci(normalized)
        snapshot['repository'] = self.repo
        snapshot['pr'] = pr
        if self.pending:
            snapshot['runs']['fast.yml'][0].update(status='waiting', conclusion=None)
        if self.failed:
            snapshot['runs']['fast.yml'][0].update(conclusion='failure')
        return snapshot


class FixtureWorkers:
    def __init__(self):
        self.calls = []
        self.source = ROOT
        self.findings = []
        self.browser_failed = False
        self.crash_code = False

    def invocation(self, stage, base, key, **kwargs):
        self.calls.append((stage, key, kwargs))
        if self.crash_code:
            raise c.Blocked('fixture API ambiguous crash')
        if kwargs.get('reviewer'):
            return {'invocation': key, 'readonly': True, 'findings': self.findings,
                    'checks': sorted(c.REVIEW_CHECKS), 'evidence': [change(stage)['path'] + ':1:fixture assertion reviewed']}
        return {'invocation': key, 'changes': [change(stage)]}

    def browser(self, stage, head, ref):
        return {'head': head, 'status': 'failure' if self.browser_failed else 'success',
                'checks': c.acceptance(stage), 'matrix': [f'{w}/{t}' for w in (1440, 390, 320) for t in ('light', 'dark')],
                'screenshots': [f'fixture-{i}.png' for i in range(6)]}


class LiveTests(unittest.TestCase):
    def setUp(self):
        self.cfg = config()
        self.cfg['enabled'] = True
        self.api = FakeGitHub(self.cfg)
        self.session = Session(self.api, self.cfg)
        value = self.api.files['coordinator.json'][1]
        value.update(paused=False, activation=self.cfg['identity'], sandbox={
            'config_sha': self.cfg['identity'], 'status': 'success', 'repository': self.cfg['sandbox_repository'],
            'ci': {'fixture': True}, 'review': {'fixture': True}, 'browser': {'fixture': True}, 'merge_sha': 'd' * 40})
        self.api.files['coordinator.json'][0] = c.digest(value)
        self.session.acquire()
        self.workers = FixtureWorkers()
        self.backend = Backend(self.api, self.session, self.workers, self.cfg)
        self.backend.fetch = lambda sha: None
        self.backend.ready = lambda node: None
        self.backend.trusted_gate = lambda n: __import__('scripts.ci.pre_merge', fromlist=['evaluate']).evaluate(self.api.snapshot(n))
        self.state = c.initial(self.cfg['spec_sha'])
        self.state['mode'] = 'live'

    def step(self):
        self.state = c.tick(self.state, self.backend, self.cfg['spec_sha'])
        return self.state['stages'][0]

    def ready(self):
        for _ in range(3):
            self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'verifying')

    def finish(self):
        for _ in range(100):
            old = deepcopy(self.state)
            self.step()
            if old == self.state:
                break
        return c.summary(self.state)

    def test_full_six_stage_live_adapter_contract_and_restart(self):
        for _ in range(30):
            previous = deepcopy(self.state)
            self.step()
            self.session.update(lambda v: v.__setitem__('checkpoint', self.state))
            if previous == self.state:
                break
        self.assertTrue(c.summary(self.state)['complete'])
        self.assertEqual(len(self.api.prs), 6)
        self.assertEqual(len(self.workers.calls), 12)
        self.assertEqual(len([call for call in self.api.calls if call[1].endswith('/merge')]), 6)
        calls = deepcopy(self.api.calls)
        self.step()
        self.assertEqual(calls, self.api.calls)
        loaded = Session(self.api, self.cfg)
        _, value = loaded.read()
        self.assertEqual(value['checkpoint'], self.state)
        self.assertTrue(all(s['verified']['head'] == s['pr']['head'] for s in self.state['stages']))

    def test_disabled_config_or_missing_sandbox_no_api_writes(self):
        for mode in ('disabled', 'receipt'):
            self.setUp()
            if mode == 'disabled':
                self.cfg['enabled'] = False
            else:
                self.session.update(lambda v: v.__setitem__('sandbox', None))
            self.step()
            self.step()
            self.assertEqual(self.state['stages'][0]['state'], 'blocked')
            self.assertFalse(self.workers.calls)
            self.assertFalse(self.api.calls)

    def test_ci_wait_does_not_consume_coder_attempt_then_success(self):
        self.ready()
        self.api.pending = True
        before = deepcopy(self.state)
        self.step()
        self.assertEqual(before, self.state)
        self.assertEqual(len(self.workers.calls), 1)
        self.api.pending = False
        self.assertTrue(self.finish()['complete'])

    def test_wait_timeout_and_failed_ci(self):
        self.ready()
        self.api.pending = True
        self.backend.clock = lambda: 10**12
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'blocked')
        self.setUp()
        self.ready()
        self.api.failed = True
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'fixing')
        self.assertEqual(len(self.workers.calls), 1)

    def test_review_and_browser_negative_stop(self):
        for field in ('findings', 'browser_failed'):
            self.setUp()
            setattr(self.workers, field, ['blocking'] if field == 'findings' else True)
            self.finish()
            self.assertEqual(self.state['stages'][0]['state'], 'blocked')
            self.assertFalse(any(p['merged'] for p in self.api.prs.values()))

    def test_branch_and_pr_timeouts_reconcile_without_duplicates(self):
        self.api.branch_timeout = self.api.pr_timeout = True
        self.assertTrue(self.finish()['complete'])
        self.assertEqual(len(self.api.prs), 6)
        self.assertEqual(len(self.workers.calls), 12)

    def test_merge_timeout_and_crash_before_checkpoint_reconcile(self):
        self.ready()
        old = deepcopy(self.state)
        self.api.merge_timeout = True
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'merged')
        self.state = old
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'merged')
        self.assertEqual(len([x for x in self.api.calls if x[1].endswith('/merge')]), 1)

    def test_external_base_head_or_branch_never_overwritten(self):
        self.step()
        self.step()
        pr = self.state['stages'][0]['pr']
        self.api.prs[pr['number']]['head']['sha'] = 'e' * 40
        self.step()
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'blocked')
        self.assertFalse(any(x[1].endswith('/merge') for x in self.api.calls))

    def test_lease_fencing_expiry_cas_and_owner_pause(self):
        other = Session(self.api, self.cfg)
        with self.assertRaises(c.Blocked):
            other.acquire()
        self.session.clock = lambda: 10**12
        with self.assertRaises(c.Blocked):
            self.session.guard()
        self.setUp()
        other = Session(self.api, self.cfg, maintenance=True)
        other.revision, value = other.read()
        value['paused'] = True
        other.save(value)
        with self.assertRaises(c.Blocked):
            self.session.guard()
        with self.assertRaises(c.Blocked):
            self.session.save({**self.session.value, 'paused': False})

    def test_budget_persisted_no_duplicate_api_after_ambiguous_crash(self):
        self.workers.crash_code = True
        self.step()
        self.step()
        self.assertEqual(len(self.workers.calls), 1)
        spent = self.session.value['spend']['total']
        self.state['stages'][0]['state'] = 'running'
        self.step()
        self.assertEqual(len(self.workers.calls), 1)
        self.assertEqual(spent, self.session.value['spend']['total'])
        fresh = Session(self.api, self.cfg)
        _, value = fresh.read()
        self.assertEqual(spent, value['spend']['total'])

    def test_total_and_daily_caps(self):
        for field in ('total_micro_usd', 'daily_micro_usd'):
            self.setUp()
            self.cfg['limits'][field] = 1
            self.step()
            self.step()
            self.assertEqual(self.state['stages'][0]['state'], 'blocked')
            self.assertFalse(self.workers.calls)

    def test_patch_path_traversal_gates_workflows_secrets_symlink_hash(self):
        for path in ('../AGENTS.md', '/tmp/escape', '.github/workflows/fast.yml', 'scripts/ci/pre_merge.py',
                     '.env', 'database/migrations/new.php', 'tests/pg/run-stage1.sh', 'client/src/.hidden'):
            entry = change('UX-1')
            entry['path'] = path
            with self.assertRaises(c.Blocked):
                validate_changes('UX-1', [entry])
        entry = change('UX-1')
        entry['sha256'] = '0' * 64
        with self.assertRaises(c.Blocked):
            validate_changes('UX-1', [entry])
        with self.assertRaises(c.Blocked):
            validate_changes('UX-1', [change('UX-1')] * 2)

    def test_proxy_enforces_model_request_input_output_budget_and_no_remote_tools(self):
        budget = Budget(self.cfg)
        raw = json.dumps({'model': self.cfg['model'], 'max_output_tokens': 10**9, 'store': True}).encode()
        result = json.loads(budget.request(raw))
        self.assertEqual(result['max_output_tokens'], self.cfg['limits']['output_tokens'])
        self.assertFalse(result['store'])
        for _ in range(self.cfg['limits']['requests'] - 1):
            budget.request(raw)
        with self.assertRaises(ValueError):
            budget.request(raw)
        for body in ({'model': 'wrong'}, {'model': self.cfg['model'], 'tools': [{'type': 'web_search'}]}):
            with self.assertRaises(ValueError):
                Budget(self.cfg).request(json.dumps(body).encode())
        with self.assertRaises(ValueError):
            Budget(self.cfg).request(b'x' * (self.cfg['limits']['input_bytes'] + 1))

    def test_config_rejects_unpinned_or_invalid_budget(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / 'config.json'
            file.write_text(json.dumps(self.cfg))
            self.assertEqual(cfg.load(file)['spec_sha'], self.cfg['spec_sha'])
            for key, value in [('worker_image', 'image:latest'), ('trusted_ref', 'master'), ('sandbox_repository', c.REPOSITORY)]:
                bad = deepcopy(self.cfg)
                bad[key] = value
                file.write_text(json.dumps(bad))
                with self.assertRaises(c.Blocked):
                    cfg.load(file)
            bad = deepcopy(self.cfg)
            bad['limits']['requests'] = 0
            file.write_text(json.dumps(bad))
            with self.assertRaises(c.Blocked):
                cfg.load(file)

    def test_worker_argv_roles_no_socket_no_secret_or_write_token_cleanup(self):
        calls = []
        def execute(command, **kwargs):
            calls.append((command, kwargs))
            return ''
        worker = Workers(self.cfg, ROOT, 'synthetic-openai-secret', execute=execute)
        with tempfile.TemporaryDirectory() as directory:
            worker.isolated(directory, directory, ['codex', 'exec'], prompt='test', readonly=True, api=True)
        argv = '\n'.join(' '.join(c) for c, _ in calls)
        self.assertNotIn('synthetic-openai-secret', argv)
        self.assertNotIn('synthetic-token', argv)
        self.assertNotIn('docker.sock,dst=', argv)
        self.assertNotIn('--privileged', argv)
        self.assertIn('--internal', argv)
        self.assertIn('dst=/workspace,readonly', argv)
        self.assertIn('no-new-privileges', argv)
        self.assertIn('--network', argv)
        self.assertEqual(len([c for c, _ in calls if 'rm' in c]), 3)

    def test_worker_failure_cleanup(self):
        calls = []
        def execute(command, **kwargs):
            calls.append(command)
            if 'start' in command and '--attach' in command:
                raise c.Blocked('synthetic timeout')
            return ''
        worker = Workers(self.cfg, ROOT, 'fixture', execute=execute)
        with tempfile.TemporaryDirectory() as directory, self.assertRaises(c.Blocked):
            worker.isolated(directory, directory, ['codex'], api=True)
        self.assertEqual(len([c for c in calls if 'rm' in c]), 3)

    def test_gh_transport_secret_not_in_argv_and_timeout_not_retried(self):
        api = GitHub(c.REPOSITORY, 'fake-sensitive-value')
        captured = []
        def transport(command, **kwargs):
            captured.append((command, kwargs))
            return subprocess.CompletedProcess(command, 0, '{"ok":true}', '')
        with patch('scripts.ux_auto.github.subprocess.run', side_effect=transport):
            self.assertEqual(api.call('POST', f'repos/{c.REPOSITORY}/pulls', {'x': 1}), {'ok': True})
        command, options = captured[0]
        self.assertNotIn('fake-sensitive-value', str(command))
        self.assertEqual(options['env']['GH_TOKEN'], 'fake-sensitive-value')
        self.assertNotIn('R2_SECRET_ACCESS_KEY', options['env'])
        with patch('scripts.ux_auto.github.subprocess.run', side_effect=subprocess.TimeoutExpired('gh', 1)) as mocked:
            with self.assertRaises(APIError):
                api.call('POST', f'repos/{c.REPOSITORY}/pulls', {})
            self.assertEqual(mocked.call_count, 1)
        with self.assertRaises(c.Blocked):
            api.call('GET', 'repos/other/repo/pulls')

    def test_browser_artifacts_strict_matrix_png_width_and_assertions(self):
        report = FixtureWorkers().browser('UX-1', 'a' * 40, None)
        report['assertions'] = {k: 'passed' for k in c.acceptance('UX-1')}
        with tempfile.TemporaryDirectory() as directory:
            for i, name in enumerate(report['screenshots']):
                (Path(directory) / name).write_bytes(b'\x89PNG\r\n\x1a\n' + b'0'*8
                    + (1440, 390, 320)[i//2].to_bytes(4, 'big') + (800).to_bytes(4, 'big'))
            self.assertEqual(len(validate_browser_files(directory, report, 'UX-1', 'a'*40)['artifacts']), 6)
            report['assertions']['auth'] = 'skipped'
            with self.assertRaises(c.Blocked):
                validate_browser_files(directory, report, 'UX-1', 'a'*40)

    def test_real_local_git_candidate_includes_new_files_and_rejects_symlink(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'clone'
            ref = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
            checkout(ROOT, ref, target)
            (target / 'client/src/ux-auto-new.ts').write_text('export const fixture = true;')
            result = files_from_workspace(target, ROOT, ref)
            validate_changes('UX-1', result)
            self.assertEqual(result[0]['path'], 'client/src/ux-auto-new.ts')
            (target / 'client/src/ux-auto-link.ts').symlink_to('/etc/passwd')
            with self.assertRaises(c.Blocked):
                files_from_workspace(target, ROOT, ref)

    def test_app_token_refresh_scopes_key_is_not_argv_and_no_fallback(self):
        from scripts.ux_auto.app_token import AppToken
        import io
        replies = []
        def answer(request, **kwargs):
            body = json.loads(request.data)
            self.assertEqual(body['repository_ids'], [123])
            self.assertEqual(body['permissions']['actions'], 'read')
            self.assertNotIn('administration', body['permissions'])
            replies.append(request)
            return io.BytesIO(json.dumps({'token': 'refreshed-' + str(len(replies)),
                                         'expires_at': '1970-01-01T01:00:00Z'}).encode())
        now = [100]
        provider = AppToken(1, 2, 123, 'synthetic-private-key', clock=lambda: now[0])
        def sign(command, **kwargs):
            self.assertNotIn('synthetic-private-key', str(command))
            self.assertEqual(Path(command[-1]).read_text(), 'synthetic-private-key')
            return subprocess.CompletedProcess(command, 0, b'signature', b'')
        with patch('scripts.ux_auto.app_token.subprocess.run', side_effect=sign), patch(
                'scripts.ux_auto.app_token.urllib.request.urlopen', side_effect=answer):
            self.assertEqual(provider(), 'refreshed-1')
            self.assertEqual(provider(), 'refreshed-1')
            now[0] = 3490
            with self.assertRaises(c.Blocked):
                provider()  # Нова відповідь вже надто близько до expiry; не повертає stale token.
        self.assertEqual(len(replies), 2)

    def test_sandbox_backend_writes_only_sandbox_repo_and_no_issue_106(self):
        self.cfg['enabled'] = False
        self.api.repo = self.cfg['sandbox_repository']
        self.backend = Backend(self.api, self.session, self.workers, self.cfg, sandbox=True)
        self.backend.fetch = lambda sha: None
        self.backend.ready = lambda node: None
        self.backend.trusted_gate = lambda n: __import__('scripts.ci.pre_merge', fromlist=['evaluate']).evaluate(self.api.snapshot(n))
        pr = self.backend.publish('SANDBOX', 1, 'sandbox-code-fixture', None)
        proof = self.backend.evidence('SANDBOX')
        c.check_evidence('SANDBOX', pr, proof, self.cfg['spec_sha'], repository=self.cfg['sandbox_repository'])
        receipt = self.backend.merge('SANDBOX', pr['head'], pr['base_sha'], 'sandbox-merge-fixture')
        self.assertTrue(self.backend.confirm('SANDBOX', receipt))
        self.assertFalse(self.api.comments)
        self.assertTrue(all('/issues/106/' not in call[1] for call in self.api.calls))
        self.assertEqual(list(self.session.value['stages']), ['SANDBOX'])
        self.assertFalse(self.session.value['checkpoint'].get('live_authorized', False))

    def test_activation_requires_fresh_external_merged_receipt_and_explicit_enabled(self):
        from scripts.ux_auto.live import activate
        self.cfg['enabled'] = False
        self.api.prs[123] = {'merged': True, 'head': {'sha': 'a' * 40}, 'merge_commit_sha': 'd' * 40}
        self.session.update(lambda value: value['sandbox'].update(pr=123, head='a'*40, time=10))
        with self.assertRaises(c.Blocked):
            activate(self.cfg, self.session, self.api)
        import time
        self.session.update(lambda value: value['sandbox'].update(time=time.time()))
        with self.assertRaises(c.Blocked):
            activate(self.cfg, self.session, self.api)
        self.cfg['enabled'] = True
        activate(self.cfg, self.session, self.api)
        self.assertTrue(self.session.value['checkpoint']['live_authorized'])
        self.assertEqual(self.session.value['activation'], self.cfg['identity'])

    def test_live_workflow_default_disabled_owner_dispatch_and_pinned_code(self):
        text = (ROOT / '.github/workflows/ux-auto-live.yml').read_text()
        self.assertIn("if: vars.UX_AUTO_LIVE_ENABLED == 'true'", text)
        self.assertIn('ref: ${{ vars.UX_AUTO_TRUSTED_REF }}', text)
        self.assertIn('workflow_dispatch:', text)
        self.assertNotIn('pull_request:', text)
        self.assertNotIn('schedule:', text)
        self.assertIn('persist-credentials: false', text)
        self.assertIn('contents: read', text)
        self.assertNotIn('contents: write', text)

    def test_proxy_rejects_unbounded_previous_response_context(self):
        for field in ('previous_response_id', 'conversation'):
            with self.assertRaises(ValueError):
                Budget(self.cfg).request(json.dumps({'model': self.cfg['model'], field: 'unbounded-server-state'}).encode())

    def test_fresh_gate_base_race_prevents_merge(self):
        self.ready()
        def gate(number):
            snapshot = self.api.snapshot(number)
            result = __import__('scripts.ci.pre_merge', fromlist=['evaluate']).evaluate(snapshot)
            self.api.master_sha = 'f' * 40
            return result
        self.backend.trusted_gate = gate
        self.step()
        self.assertEqual(self.state['stages'][0]['state'], 'blocked')
        self.assertFalse(any(call[1].endswith('/merge') for call in self.api.calls))

    def test_candidate_git_clean_filter_never_runs_in_publisher(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'clone'
            marker = Path(directory) / 'host-code-execution'
            ref = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
            checkout(ROOT, ref, target)
            subprocess.run(['git', '-C', str(target), 'config', 'filter.evil.clean', f'touch {marker}; cat'], check=True)
            (target / '.git/info/attributes').write_text('*.ts filter=evil\n')
            (target / 'client/src/ux-auto-new.ts').write_text('export const fixture = true;')
            changes = files_from_workspace(target, ROOT, ref)
            validate_changes('UX-1', changes)
            self.assertFalse(marker.exists())

    def test_real_reviewer_requires_existing_file_line_and_reason(self):
        from scripts.ux_auto.worker import validate_review, read_report
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'candidate.py'
            path.write_text('assert True\n')
            report = {'checks': sorted(c.REVIEW_CHECKS), 'findings': [], 'evidence': ['candidate.py:1:Concrete assertion checked']}
            validate_review(directory, report)
            for evidence in ('PASS', 'candidate.py:99:missing line evidence', '../escape:1:arbitrary data outside scope', 'candidate.py:1:PASS'):
                report['evidence'] = [evidence]
                with self.assertRaises(c.Blocked):
                    validate_review(directory, report)
            link = Path(directory) / 'report.json'
            link.symlink_to('/etc/passwd')
            with self.assertRaises(c.Blocked):
                read_report(link)

    def test_proxy_rejects_remote_unpriced_content(self):
        for body in ({'prompt': {'id': 'stored-id'}}, {'input': [{'file_id': 'file-big'}]},
                     {'input': [{'image_url': 'https://remote/image.png'}]}, {'input': [{'type': 'compaction'}]}):
            with self.assertRaises(ValueError):
                Budget(self.cfg).request(json.dumps({'model': self.cfg['model'], **body}).encode())
