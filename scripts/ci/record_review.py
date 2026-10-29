#!/usr/bin/env python3
"""Публікація реального звіту окремого reviewer; не створює/не вигадує review."""
import argparse
import json
from pathlib import Path
import subprocess

import pre_merge

REPO = 'ValtonUA/telmanfit'


def payload(report, pr):
    review = {'id': 0, 'state': 'COMMENTED', 'commit_id': report.get('head'),
              'user': {'login': 'ValtonUA', 'type': 'User'},
              'body': pre_merge.REVIEW_PREFIX + json.dumps(report, ensure_ascii=False)}
    result = pre_merge.review_evidence({'repository': REPO, 'pr': pr, 'reviews': [review]})
    if not result['attestation'] or not result['attestation']['valid']:
        raise ValueError('Некоректний report або застарілі head/base; review не опубліковано.')
    return {'event': 'COMMENT', 'commit_id': report['head'], 'body': review['body']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('pr', type=int)
    parser.add_argument('--report', type=Path, required=True)
    parser.add_argument('--publish', action='store_true')
    args = parser.parse_args()
    pr = pre_merge.api(f'repos/{REPO}/pulls/{args.pr}')[0]
    body = payload(json.loads(args.report.read_text(encoding='utf-8')), pr)
    if args.publish:
        current = pre_merge.api(f'repos/{REPO}/pulls/{args.pr}')[0]
        if (current['head']['sha'], current['base']['sha']) != (pr['head']['sha'], pr['base']['sha']):
            raise ValueError('PR змінився; публікацію скасовано.')
        result = subprocess.check_output(['gh', 'api', '--method', 'POST', f'repos/{REPO}/pulls/{args.pr}/reviews', '--input', '-'],
                                         input=json.dumps(body), text=True)
        actual = json.loads(result)
        print(json.dumps({'id': actual['id'], 'url': actual['html_url'], 'commit_id': actual['commit_id']}))
    else:
        print(json.dumps(body, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
