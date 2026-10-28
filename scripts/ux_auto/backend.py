"""Live backend до наявного tick(): GitHub publish, незалежні workers і locked merge."""
import base64
from copy import deepcopy
import hashlib
import json
from pathlib import Path
import subprocess
import time
import tempfile

from scripts.ci.pre_merge import evaluate
from scripts.ux_auto.coordinator import Blocked, Waiting, check_evidence, digest
from scripts.ux_auto.github import APIError, clean_env
from scripts.ux_auto.live_config import authorize
from scripts.ux_auto.worker import ROOT, run, validate_changes


class Backend:
    def __init__(self, api, session, workers, config, clock=time.time, sandbox=False):
        self.api, self.session, self.workers, self.config, self.clock = api, session, workers, config, clock
        self.spec_sha = config['spec_sha']
        self.sandbox = sandbox
        self.repo = config['sandbox_repository'] if sandbox else config['repository']

    def effect(self):
        self.session.guard()
        if self.sandbox:
            if self.api.repo != self.config['sandbox_repository']:
                raise Blocked('Sandbox repository mismatch')
        else:
            authorize(self.config, self.session.value)

    def remember(self, key, **values):
        self.session.operation(key, values)

    def fetch(self, sha):
        # Credentials лише Git subprocess controller, не URL, argv або clone config worker.
        encoded = base64.b64encode(('x-access-token:' + self.api.token).encode()).decode()
        env = {**clean_env(), 'GIT_CONFIG_COUNT': '1',
               'GIT_CONFIG_KEY_0': 'http.https://github.com/.extraheader',
               'GIT_CONFIG_VALUE_0': 'AUTHORIZATION: basic ' + encoded,
               'GIT_TERMINAL_PROMPT': '0'}
        run(['git', '-c', 'core.hooksPath=/dev/null', '-C', str(self.workers.source),
             'fetch', '--no-tags', 'https://github.com/' + self.repo + '.git', sha], env=env)

    def publish(self, stage, attempt, key, feedback):
        self.effect()
        operation = self.session.value['operations'].get(key, {})
        if operation.get('result'):
            return deepcopy(operation['result'])
        if 'code' not in operation:
            self.session.reserve(key)
            base = self.api.master()
            self.fetch(base)
            previous = self.session.value['stages'].get(stage, {}).get('changes')
            coded = self.workers.invocation(stage, base, key, feedback=feedback, changes=previous)
            validate_changes(stage, coded['changes'])
            self.remember(key, code=coded, base=base)
            operation = self.session.value['operations'][key]
        changes, base = operation['code']['changes'], operation['base']
        validate_changes(stage, changes)
        branch = f'ux-auto/{stage.lower()}'
        prior = self.session.value['stages'].get(stage)
        if 'commit' not in operation:
            self.effect()
            base_commit = self.api.call('GET', f'repos/{self.repo}/git/commits/{base}')
            entries = []
            for change in changes:
                self.effect()
                blob = self.api.call('POST', f'repos/{self.repo}/git/blobs',
                                     {'content': change['content'], 'encoding': 'utf-8'})
                entries.append({'path': change['path'], 'mode': '100644', 'type': 'blob', 'sha': blob['sha']})
            tree = self.api.call('POST', f'repos/{self.repo}/git/trees',
                                 {'base_tree': base_commit['tree']['sha'], 'tree': entries})
            parents = list(dict.fromkeys(([prior['pr']['head']] if prior else []) + [base]))
            commit = self.api.call('POST', f'repos/{self.repo}/git/commits',
                                  {'message': f'{stage}: UX-AUTO attempt {attempt}\n\nOperation: {digest(key)}',
                                   'tree': tree['sha'], 'parents': parents})
            # Persist intent ДО branch write: reconcile після timeout, жодного force push.
            self.remember(key, commit=commit['sha'])
            operation = self.session.value['operations'][key]
        target = operation['commit']
        refs = self.api.pages(f'repos/{self.repo}/git/matching-refs/heads/{branch}')
        current = next((r['object']['sha'] for r in refs if r['ref'] == 'refs/heads/' + branch), None)
        self.effect()
        if current != target:
            if prior:
                if current != prior['pr']['head']:
                    raise Blocked('Stage branch змінилася стороннім процесом; publication заблоковано')
                self.api.call('PATCH', f'repos/{self.repo}/git/refs/heads/{branch}', {'sha': target, 'force': False})
            else:
                if current is not None:
                    raise Blocked('Stage branch уже існує без ownership у durable state')
                try:
                    self.api.call('POST', f'repos/{self.repo}/git/refs', {'ref': 'refs/heads/' + branch, 'sha': target})
                except APIError:
                    refs = self.api.pages(f'repos/{self.repo}/git/matching-refs/heads/{branch}')
                    if not any(r['ref'] == 'refs/heads/' + branch and r['object']['sha'] == target for r in refs):
                        raise
        marker = f'<!-- UX-AUTO:{stage}:{self.spec_sha} -->'
        prs = self.api.pages(f'repos/{self.repo}/pulls?state=all&head={self.repo.split("/")[0]}:{branch}&base=master')
        owned = [p for p in prs if marker in (p.get('body') or '') and p['state'] == 'open']
        if len(owned) > 1 or any(marker not in (p.get('body') or '') for p in prs):
            raise Blocked('Неоднозначна ownership stage PR')
        if owned:
            pr = owned[0]
        elif prior:
            raise Blocked('Stage PR закрито; не створювати дубль')
        else:
            self.effect()
            try:
                pr = self.api.call('POST', f'repos/{self.repo}/pulls', {
                    'head': branch, 'base': 'master', 'draft': True, 'title': f'{stage}: UX-AUTO',
                    'body': marker + '\n\nIssue #106. Автономний stage; merge лише після required gates.\n\n'
                            'Docs-Impact: docs/roadmap.md, docs/project-status.md та предметний UX-контракт.'})
            except APIError:
                candidates = self.api.pages(f'repos/{self.repo}/pulls?state=open&head={self.repo.split("/")[0]}:{branch}')
                found = [p for p in candidates if marker in (p.get('body') or '')]
                if len(found) != 1:
                    raise
                pr = found[0]
        # GitHub може повертати mergeable:null, доки асинхронно перевіряє PR.
        raw = self.api.pr(pr['number'])
        if raw.get('mergeable') is None:
            raise Waiting()
        normalized = self.normalize(stage, raw, operation['code']['invocation'])
        if normalized['head'] != target or normalized['base_sha'] != base:
            raise Blocked('Publish head/base race')
        data = {'pr': normalized, 'changes': changes, 'code_key': key, 'wait_started': self.clock()}
        self.session.update(lambda state: state['stages'].__setitem__(stage, data))
        self.remember(key, result=normalized)
        self.write_status(stage, 'pr_open', normalized)
        return normalized

    def normalize(self, stage, raw, invocation):
        if (raw['head']['repo']['full_name'] != self.repo or raw['base']['repo']['full_name'] != self.repo
                or raw['base']['ref'] != 'master' or raw['head']['ref'] != f'ux-auto/{stage.lower()}'
                or raw.get('auto_merge') or raw.get('mergeable') is not True):
            raise Blocked('PR identity/base/conflict/auto-merge порушення')
        return {'stage': stage, 'repository': self.repo, 'number': raw['number'], 'url': raw['html_url'],
                'base': 'master', 'base_sha': raw['base']['sha'], 'branch': raw['head']['ref'],
                'head': raw['head']['sha'], 'conflicts': False, 'spec_sha': self.spec_sha,
                'coder_invocation': invocation}

    def current(self, stage):
        known = self.session.value['stages'][stage]['pr']
        raw = self.api.pr(known['number'])
        if raw.get('merged'):
            intent = self.session.value['operations'].get(f'merge:{stage}:{known["head"]}', {})
            if not intent.get('proof') or raw['head']['sha'] != known['head']:
                raise Blocked('Merged PR без durable перевіреного merge intent')
            return deepcopy(known)
        if raw.get('mergeable') is None:
            raise Waiting()
        return self.normalize(stage, raw, known['coder_invocation'])

    def evidence(self, stage):
        data = self.session.value['stages'][stage]
        pr = data['pr']
        raw = self.api.pr(pr['number'])
        merge_key = f'merge:{stage}:{pr["head"]}'
        if raw.get('merged'):
            intent = self.session.value['operations'].get(merge_key, {})
            if not intent.get('proof'):
                raise Blocked('Невідомий merged PR')
            return deepcopy(intent['proof'])
        snapshot = self.api.snapshot(pr['number'])
        gate = evaluate(snapshot)
        latest = [max(rows, key=lambda r: r['id']) for rows in snapshot['runs'].values() if rows]
        if not gate['eligible'] and (not all(snapshot['runs'].values())
                or any(r['status'] != 'completed' for r in latest)):
            if self.clock() - data['wait_started'] > self.config['limits']['ci_wait_seconds']:
                raise Blocked('CI відсутній/очікує approval або timeout; потрібна перевірка App permissions')
            raise Waiting()
        if not gate['eligible']:
            return {'ci': snapshot}
        review_key = f'review:{stage}:{pr["head"]}:{pr["base_sha"]}'
        cached = self.session.value['operations'].get(review_key, {})
        if not cached.get('review'):
            self.effect()
            self.session.reserve(review_key)
            self.fetch(pr['head'])
            review = self.workers.invocation(stage, pr['head'], review_key,
                feedback={'base': pr['base_sha'], 'head': pr['head'], 'task': 'git diff base...head; незалежний review'}, reviewer=True)
            if not any(any(e.startswith(change['path'] + ':') for change in data['changes'])
                       for e in review.get('evidence', [])):
                raise Blocked('Reviewer не навів доказів із жодного зміненого файла')
            review.update(head=pr['head'], base=pr['base_sha'], spec_sha=self.spec_sha)
            self.remember(review_key, review=review)
        review = self.session.value['operations'][review_key]['review']
        if review.get('findings'):
            return {'ci': snapshot, 'review': review}
        browser_key = f'browser:{stage}:{pr["head"]}'
        if not self.session.value['operations'].get(browser_key, {}).get('browser'):
            self.effect()
            self.fetch(pr['head'])
            browser = self.workers.browser(stage, pr['head'], pr['head'])
            self.remember(browser_key, browser=browser)
        browser = deepcopy(self.session.value['operations'][browser_key]['browser'])
        png_data = browser.pop('png_data', {})
        urls = []
        for name, content in png_data.items():
            path = f'evidence/{pr["head"]}/{name}'
            self.effect()
            expected = hashlib.sha256(base64.b64decode(content)).hexdigest()
            try:
                self.session.api.call('PUT', f'repos/{self.config["repository"]}/contents/{path}', {
                    'branch': self.config['state_branch'], 'message': 'UX-AUTO: browser evidence', 'content': content})
            except APIError:
                existing = self.session.api.content(path, self.config['state_branch'])
                if hashlib.sha256(base64.b64decode(existing['content'])).hexdigest() != expected:
                    raise Blocked('Screenshot publication conflict')
            urls.append(f'https://github.com/{self.config["repository"]}/blob/{self.config["state_branch"]}/{path}')
        if urls:
            browser['artifact_urls'] = urls
        proof = {'ci': snapshot, 'review': review, 'browser': browser}
        # Persist на transport-bound SHA, не з PR body або artifact self-attestation.
        self.remember(merge_key, proof=proof)
        return proof

    def trusted_gate(self, number):
        env = {**clean_env(), 'GH_TOKEN': self.api.token, 'GH_PROMPT_DISABLED': '1', 'GH_HOST': 'github.com'}
        output = run(['python3', str(ROOT / 'scripts/ci/pre_merge.py'), str(number), '--repo', self.repo],
                     env=env, timeout=180, cwd=ROOT)
        result = json.loads(output)
        if not result['eligible']:
            raise Blocked('Довірений pre_merge.py відхилив merge')
        return result

    def merge(self, stage, head, base, key):
        self.effect()
        pr = self.session.value['stages'][stage]['pr']
        raw = self.api.pr(pr['number'])
        if not raw.get('merged'):
            if self.api.master() != base or self.current(stage) != pr:
                raise Blocked('Head/base змінилися перед merge')
            proof = self.evidence(stage)
            check_evidence(stage, pr, proof, self.spec_sha, repository=self.repo)
            gate = self.trusted_gate(pr['number'])
            if gate['head_sha'] != head or gate['base_sha'] != base:
                raise Blocked('Gate SHA mismatch')
            self.effect()
            if self.api.master() != base:
                raise Blocked('Base race після gate')
            # Draft → ready окремою GraphQL mutation, без запуску PR code.
            if raw.get('draft'):
                self.ready(raw['node_id'])
            try:
                self.api.call('PUT', f'repos/{self.repo}/pulls/{pr["number"]}/merge',
                              {'sha': head, 'merge_method': 'merge'})
            except APIError:
                # Timeout не є дозволом на новий merge: GET receipt вирішує ambiguity.
                pass
            raw = self.api.pr(pr['number'])
        receipt = {'merged': raw.get('merged') is True, 'head': raw['head']['sha'],
                   'pr': raw['number'], 'merge_sha': raw.get('merge_commit_sha')}
        if not self.confirm(stage, receipt):
            raise Blocked('GitHub merge receipt не підтверджено')
        self.remember(key, receipt=receipt)
        self.write_status(stage, 'merged', pr, receipt['merge_sha'])
        return receipt

    def ready(self, node_id):
        # Виконується поза repo REST helper, без загального GraphQL доступу від workers.
        env = {**clean_env(), 'GH_TOKEN': self.api.token, 'GH_PROMPT_DISABLED': '1', 'GH_HOST': 'github.com'}
        run(['gh', 'api', 'graphql', '-f', 'query=mutation($id:ID!){markPullRequestReadyForReview(input:{pullRequestId:$id}){pullRequest{isDraft}}}',
             '-f', 'id=' + node_id], env=env)

    def confirm(self, stage, receipt):
        pr = self.session.value['stages'][stage]['pr']
        raw = self.api.pr(pr['number'])
        if (receipt.get('merged') is not True or raw.get('merged') is not True
                or raw['head']['sha'] != pr['head'] or receipt['head'] != pr['head']
                or raw.get('merge_commit_sha') != receipt.get('merge_sha') or receipt.get('pr') != pr['number']):
            return False
        commit = self.api.call('GET', f'repos/{self.repo}/git/commits/{receipt["merge_sha"]}')
        if {p['sha'] for p in commit['parents']} != {pr['head'], pr['base_sha']}:
            return False  # Base race або сторонній merge method: не запускати наступний stage.
        comparison = self.api.call('GET', f'repos/{self.repo}/compare/{receipt["merge_sha"]}...{self.api.master()}')
        return comparison['status'] in {'ahead', 'identical'}

    def write_status(self, stage, status, pr=None, merge_sha=None, reason=None):
        report = {'stage': stage, 'status': status, 'pr': (pr or {}).get('url'),
                  'tested_sha': (pr or {}).get('head'), 'merge_sha': merge_sha, 'reason': reason}
        report['ci_review_browser'] = self.session.value['operations'].get(
            f'merge:{stage}:{(pr or {}).get("head")}', {}).get('proof')
        marker = '<!-- ux-auto-status:' + digest(report) + ' -->'
        if self.sandbox:
            self.session.update(lambda value: value['reports'].__setitem__(stage, report))
            return
        comments = self.api.pages(f'repos/{self.repo}/issues/106/comments')
        if not any(marker in (c.get('body') or '') for c in comments):
            self.effect()
            self.api.call('POST', f'repos/{self.repo}/issues/106/comments',
                          {'body': marker + '\n```json\n' + json.dumps(report, ensure_ascii=False)[:40000] + '\n```'})
        self.session.update(lambda value: value['reports'].__setitem__(stage, report))
