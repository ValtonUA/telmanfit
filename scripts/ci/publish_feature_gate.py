#!/usr/bin/env python3
"""Довірений default-branch workflow оновлює admission status усіх відкритих feature PR."""
import json
import os
from pathlib import Path
import subprocess

import pre_merge

REPO = 'ValtonUA/telmanfit'
CONTEXT = 'Feature acceptance'


def publish(sha, state, description):
    payload = {'state': state, 'context': CONTEXT, 'description': description[:140],
               'target_url': f'https://github.com/{REPO}/actions/runs/{os.environ["GITHUB_RUN_ID"]}'}
    subprocess.run(['gh', 'api', '--method', 'POST', f'repos/{REPO}/statuses/{sha}', '--input', '-'],
                   input=json.dumps(payload), text=True, check=True, stdout=subprocess.DEVNULL)


def refresh(pr):
    sha = pr['head']['sha']
    publish(sha, 'pending', 'Перевірка exact-head CI, незалежного review та продуктового приймання')
    try:
        result = pre_merge.evaluate(pre_merge.collect(REPO, pr['number']))
        if result['head_sha'] != sha:
            raise ValueError('PR head змінився; новий head потребує окремої перевірки')
        publish(sha, 'success' if result['eligible'] else 'failure',
                'Review, приймання та technical gate: PASS' if result['eligible'] else '; '.join(result['errors']))
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError):
        publish(sha, 'failure', 'Не вдалося підтвердити актуальні докази; merge заблоковано')
        raise


def main():
    event = json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text())
    if event['repository']['full_name'] != REPO:
        raise ValueError('Невідомий репозиторій')
    if os.environ['GITHUB_EVENT_NAME'] == 'issue_comment' and 'pull_request' not in event['issue']:
        return
    # Одна global concurrency queue; кожна подія перевіряє всі PR, тому coalescing
    # pending jobs не втрачає відкликання приймання іншого PR.
    prs = [pr for page in pre_merge.api(f'repos/{REPO}/pulls?state=open&base=master&per_page=100') for pr in page]
    failures = []
    for pr in prs:
        if pr['head']['ref'].startswith('feature/') and pr['head']['repo']['full_name'] == REPO:
            try:
                refresh(pr)
            except (ValueError, KeyError, OSError, subprocess.CalledProcessError) as error:
                failures.append(str(error))
    if failures:
        raise ValueError('; '.join(failures))


if __name__ == '__main__':
    main()
