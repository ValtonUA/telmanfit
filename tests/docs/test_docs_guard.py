"""Self-tests for the documentation CI guard (no project DB or secrets)."""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import docs_guard  # noqa: E402


class DocsGuardTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.original_root = docs_guard.ROOT
        docs_guard.ROOT = self.root
        (self.root / "docs").mkdir()

    def tearDown(self) -> None:
        docs_guard.ROOT = self.original_root
        self.tmp.cleanup()

    def write(self, relative: str, content: str) -> None:
        dest = self.root / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(content, encoding="utf-8")

    def test_local_links_and_screenshots(self) -> None:
        self.write("README.md", "[Правила](docs/roadmap.md)\n![Test](docs/evidence/screen.png)\n")
        self.write("docs/roadmap.md", "# План\n")
        self.write("docs/evidence/screen.png", "not a real screenshot")
        self.assertEqual(docs_guard.validate_links(), [])
        (self.root / "docs/evidence/screen.png").unlink()
        errors = docs_guard.validate_links()
        self.assertEqual(len(errors), 1)
        self.assertIn("docs/evidence/screen.png", errors[0])

    def test_roadmap_ids_and_statuses(self) -> None:
        lines = ["# Roadmap"]
        for i in range(10):
            lines.append(f"### Stage {i} — example")
        for i in range(1, 6):
            lines.append(f"| G{i} | check | Виконано |")
        for task in sorted(docs_guard.TASK_BASELINE):
            lines.append(f"| {task} | example task | Заплановано |")
        self.write("docs/roadmap.md", "\n".join(lines))
        self.assertEqual(docs_guard.validate_roadmap(), [])
        self.write("docs/roadmap.md", "\n".join(lines + ["| T0.1 | again | Виконано |"]))
        self.assertTrue(any("duplicate task" in x for x in docs_guard.validate_roadmap()))

    def test_code_pr_needs_docs_or_meaningful_waiver(self) -> None:
        with patch.object(docs_guard.subprocess, "check_output", return_value="app/Models/Client.php\n"):
            with patch.dict(docs_guard.os.environ, {"GITHUB_EVENT_PATH": ""}):
                self.assertEqual(len(docs_guard.validate_pr_impact("base-sha")), 1)
            event = self.root / "event.json"
            event.write_text(json.dumps({"pull_request": {"body": "Docs-Impact: none — pure refactoring, public contract unchanged."}}), encoding="utf-8")
            with patch.dict(docs_guard.os.environ, {"GITHUB_EVENT_PATH": str(event)}):
                self.assertEqual(docs_guard.validate_pr_impact("base-sha"), [])

    def test_module_report_alone_is_not_canonical_status(self) -> None:
        with patch.object(docs_guard.subprocess, "check_output", return_value="app/Models/Client.php\ndocs/auth4.md\n"):
            with patch.dict(docs_guard.os.environ, {"GITHUB_EVENT_PATH": ""}):
                self.assertEqual(len(docs_guard.validate_pr_impact("base-sha")), 1)

    def test_ci_files_require_canonical_docs_or_waiver(self) -> None:
        for path in (".github/workflows/full.yml", "scripts/ci/pre_merge.py", "tests/ci/test_gate.py", "compose.yaml", "docker/local/Dockerfile"):
            with self.subTest(path=path):
                with patch.object(docs_guard.subprocess, "check_output", return_value=path + "\n"):
                    with patch.dict(docs_guard.os.environ, {"GITHUB_EVENT_PATH": ""}):
                        self.assertEqual(len(docs_guard.validate_pr_impact("base-sha")), 1)

    def test_ci_document_is_canonical_for_ci_source_changes(self) -> None:
        paths = ".github/workflows/targeted.yml\ndocs/ci.md\n"
        with patch.object(docs_guard.subprocess, "check_output", return_value=paths):
            with patch.dict(docs_guard.os.environ, {"GITHUB_EVENT_PATH": ""}):
                self.assertEqual(docs_guard.validate_pr_impact("base-sha"), [])

    def test_deletions_are_in_pr_base_impact(self) -> None:
        with patch.object(docs_guard.subprocess, "check_output", return_value="app/Models/Removed.php\n") as diff:
            with patch.dict(docs_guard.os.environ, {"GITHUB_EVENT_PATH": ""}):
                self.assertEqual(len(docs_guard.validate_pr_impact("base-sha")), 1)
            self.assertIn("--diff-filter=ACMRD", diff.call_args.args[0])
            self.assertIn("base-sha", diff.call_args.args[0])

    def test_code_pr_with_updated_roadmap(self) -> None:
        with patch.object(docs_guard.subprocess, "check_output", return_value="app/Models/Client.php\ndocs/roadmap.md\n"):
            self.assertEqual(docs_guard.validate_pr_impact("base-sha"), [])


if __name__ == "__main__":
    unittest.main()
