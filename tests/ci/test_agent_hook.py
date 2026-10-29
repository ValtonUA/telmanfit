"""Regression tests for the optional local Codex PreToolUse guard."""
from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / ".codex/hooks/pre_tool_policy.py"
SPEC = importlib.util.spec_from_file_location("pre_tool_policy", SCRIPT)
assert SPEC and SPEC.loader
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class AgentHookTests(unittest.TestCase):
    def test_blocks_obviously_destructive_bash_commands(self) -> None:
        for command in (
            "git push origin feature --force",
            "git push -f origin feature",
            "git push --force-with-lease origin feature",
            "git reset --hard HEAD",
            "git clean -fd",
            "php artisan db:wipe",
            "php artisan migrate:fresh --force",
            "php artisan migrate:refresh",
            "git status && php artisan db:wipe",
            "git status\ngit reset --hard",
            "git -C /tmp/repo push origin --force",
            "git push origin +main:main",
            "git clean --force -d",
            "git reset HEAD --hard",
            "env CI=1 php ./artisan migrate:fresh",
            "sudo git -c color.ui=false clean -fd",
        ):
            with self.subTest(command=command):
                result = module.decision({"tool_name": "Bash", "tool_input": {"command": command}})
                self.assertEqual(result["hookSpecificOutput"]["permissionDecision"], "deny")

    def test_allows_normal_work(self) -> None:
        for command in (
            "git status --short",
            "printf '%s' 'git reset --hard'",
            "git -C /tmp/repo push origin feature",
            "git push origin feat/agent-policy",
            "git clean -nd",
            "git diff --check",
            "php artisan migrate:status",
            "python3 scripts/ci/pre_merge.py 17 --repo ValtonUA/telmanfit",
            "php artisan test",
            "npm run lint --prefix client",
        ):
            with self.subTest(command=command):
                self.assertEqual(module.decision({"tool_name": "Bash", "tool_input": {"command": command}}), {})

    def test_ignores_other_tools_and_malformed_payloads(self) -> None:
        self.assertEqual(module.decision({"tool_name": "apply_patch", "tool_input": {"command": "git reset --hard"}}), {})
        self.assertEqual(module.decision({"tool_name": "Bash", "tool_input": {}}), {})
        self.assertEqual(module.decision(None), {})

    def test_cli_json_contract(self) -> None:
        result = subprocess.run(
            [sys.executable, str(SCRIPT)],
            input=json.dumps({"tool_name": "Bash", "tool_input": {"command": "git reset --hard HEAD"}}),
            text=True, capture_output=True, check=True,
        )
        payload = json.loads(result.stdout)
        self.assertEqual(payload["hookSpecificOutput"]["hookEventName"], "PreToolUse")
        self.assertEqual(payload["hookSpecificOutput"]["permissionDecision"], "deny")


if __name__ == "__main__":
    unittest.main()
