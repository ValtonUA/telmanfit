"""GitHub REST transport. Mutations не повторюються наосліп після timeout."""
import base64
import json
import os
import subprocess
import tempfile
from urllib.parse import quote

from scripts.ci.pre_merge import REQUIRED
from scripts.ux_auto.coordinator import Blocked


class APIError(Blocked):
    pass


def clean_env():
    keys = ('PATH', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy',
            'no_proxy', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'NODE_EXTRA_CA_CERTS')
    return {k: os.environ[k] for k in keys if k in os.environ}


class GitHub:
    def __init__(self, repo, token):
        if not token:
            raise Blocked('Відсутній UX_AUTO_GH_TOKEN')
        self.repo, self._token = repo, token

    @property
    def token(self):
        return self._token() if callable(self._token) else self._token

    def call(self, method, path, body=None):
        if not path.startswith(f'repos/{self.repo}/') or '..' in path or '\n' in path:
            raise Blocked('GitHub endpoint поза repository')
        with tempfile.TemporaryDirectory(prefix='ux-auto-gh-') as directory:
            env = {**clean_env(), 'GH_TOKEN': self.token, 'GH_CONFIG_DIR': directory,
                   'GH_PROMPT_DISABLED': '1', 'GH_HOST': 'github.com'}
            command = ['gh', 'api', '--hostname', 'github.com', '--method', method, path]
            if body is not None:
                command += ['--input', '-']
            try:
                result = subprocess.run(command, input=json.dumps(body) if body is not None else None,
                                        capture_output=True, text=True, timeout=45, env=env)
            except subprocess.TimeoutExpired as exc:
                raise APIError(f'GitHub {method} timeout; потрібна reconciliation') from exc
            if result.returncode:
                # Не виводимо body/stderr, де стороння відповідь може віддзеркалити secrets.
                raise APIError(f'GitHub {method} відхилено; endpoint {path.split("?")[0]}')
            return json.loads(result.stdout) if result.stdout.strip() else None

    def pages(self, path, key=None):
        items = []
        for page in range(1, 101):
            data = self.call('GET', path + ('&' if '?' in path else '?') + f'per_page=100&page={page}')
            rows = data[key] if key else data
            items += rows
            if len(rows) < 100:
                return items
        raise Blocked('Перевищено бюджет API pagination')

    def master(self):
        return self.call('GET', f'repos/{self.repo}/git/ref/heads/master')['object']['sha']

    def pr(self, number):
        return self.call('GET', f'repos/{self.repo}/pulls/{int(number)}')

    def snapshot(self, number):
        pr = self.pr(number)
        head = pr['head']['sha']
        s = {'repository': self.repo, 'pr': pr, 'runs': {}, 'jobs': {},
             'comparison': self.call('GET', f'repos/{self.repo}/compare/{pr["base"]["sha"]}...{head}')}
        for workflow in REQUIRED:
            runs = self.pages(f'repos/{self.repo}/actions/workflows/{workflow}/runs?head_sha={head}&event=pull_request', 'workflow_runs')
            s['runs'][workflow] = runs
            for run in runs:
                s['jobs'][str(run['id'])] = self.pages(
                    f'repos/{self.repo}/actions/runs/{run["id"]}/attempts/{run["run_attempt"]}/jobs', 'jobs')
        current = self.pr(number)
        for field in ('head', 'base', 'state', 'auto_merge'):
            if current.get(field) != pr.get(field):
                raise Blocked('PR змінився під час збору CI evidence')
        return s

    def content(self, path, ref):
        return self.call('GET', f'repos/{self.repo}/contents/{quote(path, safe="/")}?ref={quote(ref, safe="")}')

    def put(self, path, branch, value, previous_sha):
        body = {'branch': branch, 'message': 'UX-AUTO: checkpoint',
                'content': base64.b64encode(json.dumps(value, ensure_ascii=False).encode()).decode()}
        if previous_sha:
            body['sha'] = previous_sha
        return self.call('PUT', f'repos/{self.repo}/contents/{path}', body)['content']['sha']
