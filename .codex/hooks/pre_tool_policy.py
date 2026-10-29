#!/usr/bin/env python3
"""Best-effort guard for explicit destructive shell commands, not a security boundary."""
from __future__ import annotations

import json
import re
import shlex
import sys


def dangerous_command(command: str) -> str | None:
    # Parse simple shell command segments. Expansions, scripts and alternate tools
    # remain outside this guard; the sandbox and server controls are authoritative.
    try:
        lexer = shlex.shlex(command, posix=True, punctuation_chars=';&|()\n')
        lexer.whitespace = ' \t\r'
        lexer.whitespace_split = True
        lexer.commenters = '#'
        tokens = list(lexer)
    except ValueError:
        return None
    segments: list[list[str]] = [[]]
    for token in tokens:
        if re.fullmatch(r'[;&|()\n]+', token):
            segments.append([])
        else:
            segments[-1].append(token)
    for args in segments:
        if not args:
            continue
        # Common wrappers and assignments; do not interpret arbitrary shell code.
        while args and (args[0] in ('command', 'sudo', 'env') or re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*=.*', args[0])):
            args = args[1:]
        if not args:
            continue
        executable = args[0].replace('\\', '/').rsplit('/', 1)[-1].lower()
        if executable in ('git', 'git.exe'):
            rest = args[1:]
            while rest and rest[0].startswith('-'):
                option = rest.pop(0)
                if option in ('-C', '-c', '--git-dir', '--work-tree', '--namespace') and rest:
                    rest.pop(0)
            if not rest:
                continue
            action, options = rest[0], rest[1:]
            if action == 'push' and any(x in ('-f', '--force', '--force-with-lease') or x.startswith(('--force-with-lease=', '+')) or (x.startswith('-') and not x.startswith('--') and 'f' in x[1:]) for x in options):
                return 'force-push'
            if action == 'reset' and '--hard' in options:
                return 'git reset --hard'
            if action == 'clean' and any(x == '--force' or (x.startswith('-') and not x.startswith('--') and 'f' in x[1:]) for x in options):
                return 'git clean --force'
        if executable in ('php', 'php.exe') and len(args) > 2 and args[1].replace('\\', '/').rsplit('/', 1)[-1] == 'artisan':
            args = args[1:]
            executable = 'artisan'
        if executable == 'artisan' and len(args) > 1 and args[1] in ('db:wipe', 'migrate:fresh', 'migrate:refresh'):
            return 'destructive database reset'
    return None


def decision(payload: object) -> dict[str, object]:
    if not isinstance(payload, dict) or payload.get('tool_name') != 'Bash':
        return {}
    tool_input = payload.get('tool_input')
    if not isinstance(tool_input, dict) or not isinstance(tool_input.get('command'), str):
        return {}
    reason = dangerous_command(tool_input['command'])
    if reason:
        return {'hookSpecificOutput': {
            'hookEventName': 'PreToolUse',
            'permissionDecision': 'deny',
            'permissionDecisionReason': f'TelmanFit: {reason} заборонено в автоматичному Bash. Використайте окремий погоджений безпечний процес.',
        }}
    return {}


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except (ValueError, UnicodeError):
        print('TelmanFit hook: invalid JSON input', file=sys.stderr)
        return 2
    result = decision(payload)
    if result:
        print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
