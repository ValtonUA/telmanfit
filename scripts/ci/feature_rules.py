#!/usr/bin/env python3
"""Підготувати/активувати ruleset після merge прийнятого bootstrap PR."""
import argparse
import copy
import json
import subprocess

import pre_merge

REPO = 'ValtonUA/telmanfit'
STATUS = 'Feature acceptance'


def plan(current):
    if current['target'] != 'branch' or current.get('bypass_actors'):
        raise ValueError('Потрібен branch ruleset без bypass actors')
    if not set(current['conditions']['ref_name']['include']) & {'~DEFAULT_BRANCH', 'refs/heads/master'}:
        raise ValueError('Ruleset не захищає master')
    result = {key: copy.deepcopy(current[key]) for key in ('name', 'target', 'conditions', 'bypass_actors', 'rules')}
    result['enforcement'] = 'active'
    by_type = {rule['type']: rule for rule in result['rules']}
    reviews = by_type['pull_request']['parameters']
    reviews.update(required_approving_review_count=max(1, reviews['required_approving_review_count']),
                   dismiss_stale_reviews_on_push=True, require_last_push_approval=True,
                   required_review_thread_resolution=True, allowed_merge_methods=['merge'])
    checks = by_type['required_status_checks']['parameters']
    checks['strict_required_status_checks_policy'] = True
    names = {item['context'] for item in checks['required_status_checks']}
    required = {name for workflow, jobs in pre_merge.REQUIRED.items() for name in jobs
                if workflow != 'targeted.yml'} | {'Targeted PR checks', STATUS}
    for name in sorted(required - names):
        checks['required_status_checks'].append({'context': name, 'integration_id': 15368})
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--ruleset', type=int, default=25788875)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    current = pre_merge.api(f'repos/{REPO}/rulesets/{args.ruleset}')[0]
    desired = plan(current)
    if args.apply:
        # До merge bootstrap workflow ще не існує в master: не створювати deadlock.
        for path in ('.github/workflows/feature-acceptance.yml', 'scripts/ci/publish_feature_gate.py', 'scripts/ci/pre_merge.py'):
            pre_merge.api(f'repos/{REPO}/contents/{path}?ref=master')
        live = pre_merge.api(f'repos/{REPO}/rulesets/{args.ruleset}')[0]
        if live != current:
            raise ValueError('Ruleset змінився; повторіть планування')
        subprocess.run(['gh', 'api', '--method', 'PUT', f'repos/{REPO}/rulesets/{args.ruleset}', '--input', '-'],
                       input=json.dumps(desired), text=True, check=True, stdout=subprocess.DEVNULL)
        actual = pre_merge.api(f'repos/{REPO}/rulesets/{args.ruleset}')[0]
        if {key: actual[key] for key in desired} != desired:
            raise ValueError('Read-back ruleset не підтвердив запис')
    print(json.dumps(desired, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
