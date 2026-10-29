#!/usr/bin/env python3
"""Best-effort local Codex safety hook for clearly destructive Bash commands.

Not a security boundary: GitHub/API, non-Bash tools and untrusted hook configs
need independent authorization and server-side controls.
"""
from __future__ import annotations

import json
import re
import sys

# Narrow, explicit patterns: do not block normal test/build/development work.
DANGEROUS = (
    (re.compile(r"\bgit(?:\.exe)?\s+push\b[^\n;&|]*?(?:\s|^)(?:--force(?:-with-lease)?|-f)(?=\s|$|[;&|])", re.I), "force-push"),
    (re.compile(r"\bgit(?:\.exe)?\s+reset\s+--hard\b", re.I), "git reset --hard"),
    (re.compile(r"\bgit(?:\.exe)?\s+clean\s+-(?=[a-z]*f)[a-z]+\b", re.I), "git clean -f"),
    (re.compile(r"\b(?:php\s+artisan|artisan)\s+(?:db:wipe|migrate:fresh|migrate:refresh)\b", re.I), "destructive database reset"),
)


def decision(payload: object) -> dict[str, object]:
    if not isinstance(payload, dict) or payload.get("tool_name") != "Bash":
        return {}
    tool_input = payload.get("tool_input")
    if not isinstance(tool_input, dict) or not isinstance(tool_input.get("command"), str):
        return {}
    command = tool_input["command"]
    for pattern, reason in DANGEROUS:
        if pattern.search(command):
            return {"hookSpecificOutput": {
                "hookEventName": "PreToolUse",
                "permissionDecision": "deny",
                "permissionDecisionReason": f"TelmanFit: {reason} заборонено в автоматичному Bash. Використайте окремий погоджений безпечний процес.",
            }}
    return {}


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except (ValueError, UnicodeError):
        # Hook errors do not imply a secure denial; surface and fail visibly.
        print("TelmanFit hook: invalid JSON input", file=sys.stderr)
        return 2
    result = decision(payload)
    if result:
        print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
