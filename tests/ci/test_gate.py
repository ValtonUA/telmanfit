"""Regression checks for stale/partial/foreign CI and workflow coverage loss."""
import copy
import importlib.util
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("pre_merge", ROOT / "scripts/ci/pre_merge.py")
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def green():
    sha = "a" * 40
    snapshot = {"repository": "ValtonUA/telmanfit", "pr": {
        "number": 42, "state": "open", "auto_merge": None, "labels": [{"name": "ci:full"}],
        "user": {"login": "author"}, "base": {"ref": "master", "sha": "b" * 40},
        "head": {"sha": sha, "ref": "feature/example", "repo": {"full_name": "ValtonUA/telmanfit"}},
    }, "comparison": {"status": "ahead"}, "runs": {}, "jobs": {}}
    snapshot["master_sha"] = "b" * 40
    snapshot["reviews"] = [{"id": 1, "user": {"login": "reviewer", "type": "User"}, "state": "APPROVED", "commit_id": sha}]
    snapshot["comments"] = [{"id": 1, "user": {"login": "ValtonUA", "type": "User"}, "body": "TelmanFit-Accept: " + sha + " " + "b" * 40, "created_at": "2026-10-29T00:00:00Z"}]
    for index, (workflow, names) in enumerate(gate.REQUIRED.items(), 1):
        snapshot["runs"][workflow] = [{
            "id": index, "head_sha": sha, "event": "pull_request",
            "display_title": "TelmanFit CI " + sha + " " + "b" * 40,
            "path": f".github/workflows/{workflow}", "head_branch": "feature/example",
            "pull_requests": [{"number": 42}], "run_attempt": 1,
            "status": "completed", "conclusion": "success", "html_url": "https://github.com/run",
        }]
        snapshot["jobs"][str(index)] = [dict(
            name=name, head_sha=sha, status="completed", conclusion="success", run_attempt=1,
            steps=[{"name": "Regression", "conclusion": "success"}],
        ) for name in sorted(names)]
    return snapshot


class GateTests(unittest.TestCase):
    def assert_blocked(self, snapshot):
        self.assertFalse(gate.evaluate(snapshot)["eligible"])

    def test_final_head_positive_without_merging(self):
        self.assertTrue(gate.evaluate(green())["eligible"])

    def test_full_label_and_critical_changes_never_add_a_gate(self):
        self.assertEqual(set(gate.REQUIRED), {"fast.yml", "docs-health.yml", "targeted.yml", "local-update-powershell-compat.yml", "feature-environments.yml"})
        for changed in (".github/workflows/targeted.yml", "composer.lock",
                        "database/migrations/critical.php", "app/Http/Middleware/Auth.php"):
            with self.subTest(changed=changed):
                s = green()
                s["changed_files"] = [changed]
                s["pr"]["labels"] = [{"name": "ci:full"}]
                self.assertTrue(gate.evaluate(s)["eligible"])

    def test_targeted_check_is_required_and_fail_closed(self):
        for status in ("failure", "cancelled", "skipped", None):
            s = green()
            run = s["runs"]["targeted.yml"][0]
            run["conclusion"] = status
            self.assert_blocked(s)

    def test_real_github_fixture_passes_then_stale_head_blocks(self):
        import json
        s = json.loads((ROOT / "docs/evidence/ci-cost-1/gate-fixture.json").read_text())
        # The historical fixture predates the always-required targeted workflow.
        fast = copy.deepcopy(s["runs"]["fast.yml"][0])
        fast.update(id=999999, path=".github/workflows/targeted.yml")
        s["runs"]["targeted.yml"] = [fast]
        fast_job = copy.deepcopy(s["jobs"][str(s["runs"]["fast.yml"][0]["id"])][0])
        target_jobs = []
        for name in ("Select required PostgreSQL/browser security coverage",
                     "regressions / PostgreSQL and browser regressions",
                     "Targeted PR checks"):
            job = copy.deepcopy(fast_job)
            job["name"] = name
            target_jobs.append(job)
        s["jobs"]["999999"] = target_jobs
        # Історичні CI докази самі по собі більше не дають права merge.
        self.assertFalse(gate.evaluate(s)["eligible"])
        s["pr"]["head"]["sha"] = "0" * 40
        self.assert_blocked(s)

    def test_current_master_and_draft_are_checked(self):
        s = green()
        s["master_sha"] = "c" * 40
        self.assert_blocked(s)
        s = green()
        s["pr"]["draft"] = True
        self.assert_blocked(s)
        self.assertTrue(gate.evaluate(s, "preview")["eligible"])

    def test_product_acceptance_is_exact_and_only_owner(self):
        for change in ("missing", "foreign", "bot", "old_head", "old_base", "reject", "quoted"):
            s = green()
            c = s["comments"][0]
            if change == "missing": s["comments"] = []
            elif change == "foreign": c["user"]["login"] = "reviewer"
            elif change == "bot": c["user"]["type"] = "Bot"
            elif change == "old_head": c["body"] = c["body"].replace("a" * 40, "c" * 40)
            elif change == "old_base": c["body"] = c["body"].replace("b" * 40, "c" * 40)
            elif change == "reject": c["body"] = c["body"].replace("Accept", "Reject")
            elif change == "quoted": c["body"] = "> " + c["body"]
            with self.subTest(change=change):
                self.assert_blocked(s)
                self.assertTrue(gate.evaluate(s, "preview")["eligible"])

    def test_latest_owner_decision_and_review_invalidation(self):
        s = green()
        c = copy.deepcopy(s["comments"][0])
        c.update(id=2, created_at="2026-10-29T01:00:00Z", body=c["body"].replace("Accept", "Reject"))
        s["comments"].append(c)
        self.assert_blocked(s)
        for field, value in (("state", "DISMISSED"), ("commit_id", "c" * 40)):
            s = green()
            s["reviews"][0][field] = value
            self.assert_blocked(s)
        s = green()
        s["reviews"][0]["user"]["login"] = "author"
        self.assert_blocked(s)
        s = green()
        s["reviews"].append({**s["reviews"][0], "id": 2, "state": "CHANGES_REQUESTED"})
        self.assert_blocked(s)
        for branch in ("dev", "master", "fix/test"):
            s = green()
            s["pr"]["head"]["ref"] = branch
            self.assert_blocked(s)

    def process_review(self, snapshot):
        import json
        data = {'version': 1, 'head': snapshot['pr']['head']['sha'], 'base': snapshot['pr']['base']['sha'],
                'implementation_session': 'implementation-session', 'review_session': 'separate-read-only-reviewer',
                'read_only': True, 'verdict': 'APPROVED',
                'summary': 'Separate reviewer inspected admission, isolation and bootstrap code against the acceptance criteria.',
                'checks': ['Read the full feature diff and negative regression cases.'],
                'limitations': ['Shared GitHub account cannot cryptographically prove session separation.'],
                'blocking_findings': []}
        review = {'id': 10, 'state': 'COMMENTED', 'commit_id': data['head'],
                  'user': {'login': 'ValtonUA', 'type': 'User'}, 'body': gate.REVIEW_PREFIX + json.dumps(data)}
        snapshot['reviews'] = [review]
        return data, review

    def test_real_process_contract_needs_no_second_account_but_requires_report(self):
        s = green()
        s['pr']['user']['login'] = 'ValtonUA'
        self.process_review(s)
        result = gate.evaluate(s, 'preview')
        self.assertTrue(result['eligible'], result)
        self.assertEqual(result['review']['method'], 'session-attestation')
        self.assertTrue(gate.evaluate(s)['eligible'])

    def test_process_attestation_rejects_stale_self_missing_or_untrusted_evidence(self):
        import json
        for change in ('head', 'base', 'self', 'readonly', 'summary', 'checks', 'limitations', 'findings', 'verdict', 'malformed', 'foreign', 'bot', 'dismissed'):
            s = green()
            data, review = self.process_review(s)
            if change in ('head', 'base'): data[change] = 'c' * 40
            elif change == 'self': data['review_session'] = data['implementation_session']
            elif change == 'readonly': data['read_only'] = False
            elif change == 'summary': data['summary'] = 'PASS'
            elif change in ('checks', 'limitations'): data[change] = []
            elif change == 'findings': data['blocking_findings'] = ['Blocker remains']
            elif change == 'verdict': data['verdict'] = 'CHANGES_REQUESTED'
            elif change == 'foreign': review['user']['login'] = 'outsider'
            elif change == 'bot': review['user']['type'] = 'Bot'
            elif change == 'dismissed': review['state'] = 'DISMISSED'
            review['body'] = gate.REVIEW_PREFIX + (json.dumps(data) if change != 'malformed' else '{bad')
            with self.subTest(change=change):
                self.assert_blocked(s)
                self.assertFalse(gate.evaluate(s, 'preview')['eligible'])

    def test_latest_process_revocation_and_formal_request_block_both_paths(self):
        import json
        for verdict in ('REVOKED', 'CHANGES_REQUESTED'):
            s = green()
            formal = s['reviews'][0]
            data, review = self.process_review(s)
            data['verdict'] = verdict
            s['reviews'] += [{**review, 'id': 11, 'body': gate.REVIEW_PREFIX + json.dumps(data)}, formal]
            self.assert_blocked(s)
        s = green()
        request = {**s['reviews'][0], 'state': 'CHANGES_REQUESTED'}
        self.process_review(s)
        s['reviews'].append(request)
        self.assert_blocked(s)


    def test_original_ci_base_is_immutable_and_missing_or_stale_blocks(self):
        for title in (None, 'TelmanFit CI ' + 'a' * 40 + ' ' + 'c' * 40):
            s = green()
            for workflow in s['runs']:
                s['runs'][workflow][0]['display_title'] = title
            self.assert_blocked(s)
        s = green()
        # A live PR link may report the new base; the original event was old.
        s['pr']['base']['sha'] = s['master_sha'] = 'c' * 40
        for workflow in s['runs']:
            s['runs'][workflow][0]['pull_requests'] = [{'number': 42, 'base': {'sha': 'c' * 40}}]
        self.process_review(s)
        s['comments'][0]['body'] = s['comments'][0]['body'].replace('b' * 40, 'c' * 40)
        result = gate.evaluate(s)
        self.assertEqual(len(result['errors']), len(gate.REQUIRED))
        self.assertTrue(all('original CI event head/base' in e for e in result['errors']))

    def test_new_commit_invalidates_old_success(self):
        s = green()
        s["pr"]["head"]["sha"] = "c" * 40
        self.assert_blocked(s)

    def test_every_non_success_run_and_job_is_blocked(self):
        for status in ("failure", "cancelled", "skipped", "neutral", "timed_out", None):
            for layer in ("run", "job", "step"):
                with self.subTest(status=status, layer=layer):
                    s = green()
                    target = s["runs"]["targeted.yml"][0] if layer == "run" else s["jobs"]["3"][0]
                    if layer == "step":
                        target = target["steps"][0]
                    target["conclusion"] = status
                    self.assert_blocked(s)

    def test_intentionally_skipped_targeted_regression_is_allowed(self):
        s = green()
        job = next(j for j in s["jobs"]["3"]
                   if j["name"] == "regressions / PostgreSQL and browser regressions")
        job["name"] = "regressions"
        job["status"] = "completed"
        job["conclusion"] = "skipped"
        job["steps"] = []
        self.assertTrue(gate.evaluate(s)["eligible"])

    def test_pending_cannot_inherit_success(self):
        s = green()
        s["runs"]["targeted.yml"][0]["status"] = "in_progress"
        self.assert_blocked(s)

    def test_latest_attempt_and_latest_run_take_precedence(self):
        s = green()
        s["runs"]["targeted.yml"][0]["run_attempt"] = 2
        self.assert_blocked(s)
        s = green()
        newer = copy.deepcopy(s["runs"]["targeted.yml"][0])
        newer.update(id=999, conclusion="cancelled")
        s["runs"]["targeted.yml"].append(newer)
        self.assert_blocked(s)

    def test_foreign_pr_branch_event_workflow_sha_and_base(self):
        for field, value in (("head_branch", "other"), ("event", "workflow_dispatch"),
                             ("pull_requests", [{"number": 99}]), ("path", ".github/workflows/fake.yml")):
            s = green()
            s["runs"]["targeted.yml"][0][field] = value
            self.assert_blocked(s)
        s = green()
        s["jobs"]["3"][0]["head_sha"] = "c" * 40
        self.assert_blocked(s)
        s = green()
        s["comparison"]["status"] = "diverged"
        self.assert_blocked(s)

    def test_missing_duplicate_extra_or_skipped_jobs(self):
        for change in ("missing", "duplicate", "extra"):
            s = green()
            if change == "missing":
                s["jobs"]["1"].pop()
            else:
                job = copy.deepcopy(s["jobs"]["1"][0])
                if change == "extra":
                    job["name"] = "Unreviewed job"
                s["jobs"]["1"].append(job)
            self.assert_blocked(s)

    def test_missing_steps_are_not_evidence(self):
        s = green()
        s["jobs"]["1"][0]["steps"] = []
        self.assert_blocked(s)

    def test_old_gh_paginated_json_is_supported(self):
        from unittest.mock import patch
        with patch.object(gate.subprocess, "check_output", return_value='{"page": 1}\n{"page": 2}\n'):
            self.assertEqual(gate.api("endpoint"), [{"page": 1}, {"page": 2}])

    def test_fast_docs_and_auto_merge_are_mandatory(self):
        for workflow in gate.REQUIRED:
            s = green()
            s["runs"][workflow] = []
            self.assert_blocked(s)
        s = green()
        s["pr"]["auto_merge"] = {"enabled_by": "someone"}
        self.assert_blocked(s)


class WorkflowTests(unittest.TestCase):
    def setUp(self):
        self.workflows = {p.name: p.read_text() for p in (ROOT / ".github/workflows").glob("*.yml")}

    def test_triggers_permissions_and_pins(self):
        self.assertEqual(set(self.workflows), {"fast.yml", "docs-health.yml", "targeted.yml", "stage1.yml", "php-runtime.yml", "local-compose.yml", "local-update-powershell-compat.yml", "feature-acceptance.yml", "feature-environments.yml"})
        self.assertFalse((ROOT / ".github/workflows/full.yml").exists())
        self.assertFalse((ROOT / "scripts/ci/request_full.py").exists())
        for name, text in self.workflows.items():
            with self.subTest(name=name):
                self.assertIn("permissions:\n  contents: read\n", text)
                self.assertNotIn("pull_request_target", text)
                self.assertNotIn("secrets:", text)
                self.assertNotRegex(text, r"continue-on-error|if: false|paths-ignore:|(?m:^    paths:)")
                for action in re.findall(r"(?m)^\s*(?:-\s*)?uses: (\S+)", text):
                    self.assertTrue(action.startswith("./.github/workflows/") or re.fullmatch(r"[\w/-]+@[a-f0-9]{40}", action), action)
        for name in ("stage1.yml", "php-runtime.yml", "local-compose.yml"):
            self.assertIn("  workflow_call:", self.workflows[name])
            self.assertNotIn("  pull_request:", self.workflows[name])
            self.assertNotIn("  push:", self.workflows[name])
            self.assertIn("ref: ${{ inputs.head }}", self.workflows[name])
            self.assertNotRegex(self.workflows[name], r"(?m)^    if:", name)

    def test_required_workflows_capture_original_event_pair_in_run_name(self):
        for workflow in gate.REQUIRED:
            self.assertIn('run-name: TelmanFit CI ${{ github.event.pull_request.head.sha }} ${{ github.event.pull_request.base.sha }}', self.workflows[workflow])

    def test_full_ci_and_manual_trigger_are_absent(self):
        self.assertNotIn("full.yml", self.workflows)
        self.assertFalse((ROOT / "scripts/ci/request_full.py").exists())
        self.assertEqual(set(gate.REQUIRED), {"fast.yml", "docs-health.yml", "targeted.yml", "local-update-powershell-compat.yml", "feature-environments.yml"})
        for name in ("fast.yml", "docs-health.yml", "targeted.yml"):
            self.assertIn("cancel-in-progress: true", self.workflows[name])
            self.assertIn("github.event.pull_request.number", self.workflows[name])

    def test_fast_has_real_smoke_and_no_heavy_suite(self):
        text = self.workflows["fast.yml"]
        for command in ("composer validate --strict", "composer audit --locked", "php artisan test --display-all-issues", "php vendor/bin/pint --test", "npm run lint --prefix client", "npm run build --prefix client", "npm run build"):
            self.assertIn(command, text)
        self.assertNotRegex(text, r"AUTH\d|docker compose build|run-stage1.sh|playwright install")
        targeted = self.workflows["targeted.yml"]
        self.assertIn("name: Targeted PR regressions", targeted)
        self.assertIn("name: Targeted PR checks", targeted)
        self.assertIn("needs: [scope, regressions]", targeted)
        self.assertIn("groups: ${{ needs.scope.outputs.groups }}", targeted)
        self.assertIn("groups", self.workflows["stage1.yml"])
        self.assertIn("python -m playwright install chromium", self.workflows["stage1.yml"])
        self.assertNotIn("install --with-deps chromium", self.workflows["stage1.yml"])
        docs = self.workflows["docs-health.yml"]
        self.assertIn('scripts/docs_guard.py --pr-base "$BASE_SHA"', docs)
        self.assertIn("ref: ${{ github.event.pull_request.head.sha || github.sha }}", docs)

    def test_local_test_assets_remain_available(self):
        text = self.workflows["stage1.yml"]
        self.assertIn("python -m unittest discover -s tests/browser -p 'test_*.py' -v", text)
        self.assertIn("python3 scripts/ci/stage1_groups.py", text)
        for command in ("bash tests/pg/test-runner.sh", "bash tests/pg/run-isolated-pg.sh", "bash experiments/auth1/run.sh"):
            self.assertIn(command, text)
        self.assertIn("php artisan filament:upgrade --ansi", text)
        self.assertNotIn("AUTH6B6_ONLY", text)
        runtime = self.workflows["php-runtime.yml"]
        self.assertIn("php: ['8.4.27', '8.5.12']", runtime)
        self.assertIn("AUTH6B6_ONLY=1 tests/pg/run-stage1.sh", runtime)
        self.assertIn("php vendor/bin/pint --test", runtime)
        # SQLite тести під TELMANFIT_PG_TEST мають інші synthetic bindings,
        # тому їх не можна визнати дублем matrix лише за назвами методів.
        runner = (ROOT / "tests/pg/run-stage1.sh").read_text()
        self.assertIn('DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test "${phpunit_args[@]}" --display-all-issues', runner)
        compose = self.workflows["local-compose.yml"]
        for command in ("docker compose build --pull --no-cache", "synthetic-context-exclusion-probe", "Reject host credentials", "TELMANFIT_FILAMENT_THEME_BROWSER=1 python tests/browser/local_compose.py", "docker compose down --volumes --remove-orphans"):
            self.assertIn(command, compose)


if __name__ == "__main__":
    unittest.main()
