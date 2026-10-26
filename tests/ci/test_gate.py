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
        "base": {"ref": "master", "sha": "b" * 40},
        "head": {"sha": sha, "ref": "ci/example", "repo": {"full_name": "ValtonUA/telmanfit"}},
    }, "comparison": {"status": "ahead"}, "runs": {}, "jobs": {}}
    for index, (workflow, names) in enumerate(gate.REQUIRED.items(), 1):
        snapshot["runs"][workflow] = [{
            "id": index, "head_sha": sha, "event": "pull_request",
            "path": f".github/workflows/{workflow}", "head_branch": "ci/example",
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

    def test_full_is_optional_for_regular_changes_but_required_for_critical_paths(self):
        s = green()
        s["pr"]["labels"] = []
        s["runs"].pop("full.yml")
        self.assertTrue(gate.evaluate(s)["eligible"])
        s["changed_files"] = [".github/workflows/targeted.yml"]
        self.assert_blocked(s)

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
        self.assertTrue(gate.evaluate(s)["eligible"])
        s["pr"]["head"]["sha"] = "0" * 40
        self.assert_blocked(s)

    def test_new_commit_invalidates_old_success(self):
        s = green()
        s["pr"]["head"]["sha"] = "c" * 40
        self.assert_blocked(s)

    def test_every_non_success_run_and_job_is_blocked(self):
        for status in ("failure", "cancelled", "skipped", "neutral", "timed_out", None):
            for layer in ("run", "job", "step"):
                with self.subTest(status=status, layer=layer):
                    s = green()
                    target = s["runs"]["full.yml"][0] if layer == "run" else s["jobs"]["1"][0]
                    if layer == "step":
                        target = target["steps"][0]
                    target["conclusion"] = status
                    self.assert_blocked(s)

    def test_pending_cannot_inherit_success(self):
        s = green()
        s["runs"]["full.yml"][0]["status"] = "in_progress"
        self.assert_blocked(s)

    def test_latest_attempt_and_latest_run_take_precedence(self):
        s = green()
        s["runs"]["full.yml"][0]["run_attempt"] = 2
        self.assert_blocked(s)
        s = green()
        newer = copy.deepcopy(s["runs"]["full.yml"][0])
        newer.update(id=999, conclusion="cancelled")
        s["runs"]["full.yml"].append(newer)
        self.assert_blocked(s)

    def test_foreign_pr_branch_event_workflow_sha_and_base(self):
        for field, value in (("head_branch", "other"), ("event", "workflow_dispatch"),
                             ("pull_requests", [{"number": 99}]), ("path", ".github/workflows/fake.yml")):
            s = green()
            s["runs"]["full.yml"][0][field] = value
            self.assert_blocked(s)
        s = green()
        s["jobs"]["1"][0]["head_sha"] = "c" * 40
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
        for workflow in ("fast.yml", "docs-health.yml", "targeted.yml"):
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
        self.assertEqual(set(self.workflows), {"fast.yml", "full.yml", "docs-health.yml", "targeted.yml", "stage1.yml", "php-runtime.yml", "local-compose.yml"})
        for name, text in self.workflows.items():
            with self.subTest(name=name):
                self.assertIn("permissions:\n  contents: read\n", text)
                self.assertNotIn("pull_request_target", text)
                self.assertNotIn("secrets:", text)
                self.assertNotRegex(text, r"continue-on-error|if: false|paths-ignore:|(?m:^    paths:)")
                for action in re.findall(r"uses: (\S+)", text):
                    self.assertTrue(action.startswith("./.github/workflows/") or re.fullmatch(r"[\w/-]+@[a-f0-9]{40}", action), action)
        for name in ("stage1.yml", "php-runtime.yml", "local-compose.yml"):
            self.assertIn("  workflow_call:", self.workflows[name])
            self.assertNotIn("  pull_request:", self.workflows[name])
            self.assertNotIn("  push:", self.workflows[name])
            self.assertIn("ref: ${{ inputs.head }}", self.workflows[name])
            self.assertNotRegex(self.workflows[name], r"(?m)^    if:", name)

    def test_full_is_explicit_and_failed_dependencies_never_pass(self):
        text = self.workflows["full.yml"]
        self.assertIn("types: [opened, synchronize, reopened, labeled]", text)
        self.assertEqual(text.count("if: github.event.label.name == 'ci:full'"), 4)
        self.assertIn("needs: [stage1, runtime, compose, docs]", text)
        self.assertIn("if: always()", text)
        self.assertIn("all(job['result'] == 'success' for job in results.values())", text)
        self.assertIn("if: github.event.label.name != 'ci:full'", text)
        self.assertIn("if: github.event.label.name == 'ci:full'", text)
        self.assertIn('git merge-base --is-ancestor "$BASE_SHA" HEAD', text)
        self.assertIn('scripts/docs_guard.py --pr-base "$BASE_SHA"', text)
        for name in ("full.yml", "fast.yml", "docs-health.yml"):
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

    def test_full_preserves_existing_regressions(self):
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
