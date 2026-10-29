"""Серверний план не послаблює чинні правила; admission status fail-closed."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/ci'))
import feature_rules
import publish_feature_gate as publisher


def current():
    return {'name': 'Existing rules', 'target': 'branch', 'enforcement': 'active', 'bypass_actors': [],
            'conditions': {'ref_name': {'include': ['~DEFAULT_BRANCH'], 'exclude': []}},
            'rules': [{'type': 'deletion'}, {'type': 'non_fast_forward'},
                      {'type': 'pull_request', 'parameters': {'required_approving_review_count': 0}},
                      {'type': 'required_status_checks', 'parameters': {'strict_required_status_checks_policy': True,
                       'do_not_enforce_on_create': False, 'required_status_checks': [{'context': 'Existing check', 'integration_id': 123}]}}]}


class RulesTests(unittest.TestCase):
    def test_adds_server_gate_without_bypasses_or_lost_existing_checks(self):
        original = current()
        result = feature_rules.plan(original)
        self.assertEqual(original, current())
        self.assertEqual(feature_rules.plan(result), result)
        rules = {r['type']: r for r in result['rules']}
        review = rules['pull_request']['parameters']
        self.assertEqual(review['required_approving_review_count'], 1)
        self.assertTrue(review['dismiss_stale_reviews_on_push'])
        self.assertTrue(review['require_last_push_approval'])
        self.assertTrue(review['required_review_thread_resolution'])
        checks = rules['required_status_checks']['parameters']
        names = {x['context'] for x in checks['required_status_checks']}
        self.assertTrue({'Existing check', 'Fast PR checks', 'Docs links, roadmap and PR impact',
                         'Targeted PR checks', 'Parse coordinator with Windows PowerShell 5.1',
                         'Parse coordinator with PowerShell 7', 'Feature acceptance'} <= names)
        self.assertFalse(checks['do_not_enforce_on_create'])
        self.assertEqual(result['bypass_actors'], [])

    def test_preserves_stricter_review_and_rejects_unexpected_scope(self):
        value = current()
        value['rules'][2]['parameters']['required_approving_review_count'] = 2
        self.assertEqual(feature_rules.plan(value)['rules'][2]['parameters']['required_approving_review_count'], 2)
        for key, value in [('bypass_actors', [{'actor_id': 1}]), ('target', 'tag'), ('conditions', {'ref_name': {'include': ['refs/heads/dev']}})]:
            data = current()
            data[key] = value
            with self.assertRaises(ValueError): feature_rules.plan(data)

    def test_publish_sets_pending_before_collect_and_failure_on_error(self):
        pr = {'number': 1, 'head': {'sha': 'a' * 40}}
        calls = []
        with patch.object(publisher, 'publish', side_effect=lambda *args: calls.append(args)), patch.object(publisher.pre_merge, 'collect', side_effect=ValueError('API unavailable')):
            with self.assertRaises(ValueError): publisher.refresh(pr)
        self.assertEqual([c[1] for c in calls], ['pending', 'failure'])

    def test_only_live_eligible_same_head_issues_success(self):
        pr = {'number': 1, 'head': {'sha': 'a' * 40}}
        for eligible, sha, expected in [(True, 'a' * 40, 'success'), (False, 'a' * 40, 'failure'), (True, 'b' * 40, 'failure')]:
            with patch.object(publisher, 'publish') as publish, patch.object(publisher.pre_merge, 'collect', return_value={}), patch.object(publisher.pre_merge, 'evaluate', return_value={'eligible': eligible, 'head_sha': sha, 'errors': ['unaccepted']}):
                try: publisher.refresh(pr)
                except ValueError: pass
                self.assertEqual(publish.call_args.args[1], expected)

    def test_status_workflow_executes_only_trusted_master(self):
        text = (ROOT / '.github/workflows/feature-acceptance.yml').read_text()
        self.assertIn('ref: master', text)
        self.assertNotIn('pull_request.head', text)
        self.assertNotIn('download-artifact', text)
        self.assertIn('statuses: write', text)
        self.assertIn('cancel-in-progress: false', text)
        self.assertNotIn('pull_request_target', text)


if __name__ == '__main__':
    unittest.main()
