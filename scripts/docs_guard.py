#!/usr/bin/env python3
"""TelmanFit documentation guard: stdlib-only, read-only, no network access.

Checks canonical Markdown links, roadmap task statuses, and (for pull requests)
that code changes are accompanied by documentation changes or a documented waiver.
It cannot check semantic truth: a reviewer must compare documentation with code.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from collections import Counter
from pathlib import Path
from urllib.parse import unquote

ROOT = Path(__file__).resolve().parents[1]
# Verify all tracked Markdown, not only canonical docs: module reports and their
# screenshot links are part of the documentation graph.
ALLOWED_STATUSES = {"Виконано", "Частково", "Очікує рішення", "Заплановано"}
# Baseline IDs protect against a task silently disappearing during editing.
TASK_BASELINE = {
    f"T{stage}.{n}"
    for stage, maximum in enumerate((4, 6, 6, 5, 6, 5, 5, 5, 4, 5))
    for n in range(1, maximum + 1)
}
LINK = re.compile(r"\[[^\]]+\]\(([^)]+)\)")
TASK_ROW = re.compile(r"^\|\s*(T\d+\.\d+)\s*\|")
GATE_ROW = re.compile(r"^\|\s*(G\d+)\s*\|")
DOCS_IMPACT_NONE = re.compile(
    r"^\s*Docs-Impact:\s*none\s*[—–:-]\s*(.+)\s*$", re.I | re.M
)


def validate_links() -> list[str]:
    errors = []
    paths = sorted(
        list(ROOT.glob("*.md"))
        + list((ROOT / "docs").rglob("*.md"))
        + list((ROOT / ".github").glob("*.md"))
    )
    for doc in paths:
        name = str(doc.relative_to(ROOT))
        # Ignore code fences: paths in examples are not actionable links.
        content = re.sub(r"(?ms)^\s*```.*?^\s*```\s*$", "", doc.read_text(encoding="utf-8"))
        for match in LINK.finditer(content):
            target = match.group(1).strip().split()[0]
            if target.startswith(("http://", "https://", "mailto:", "#", "data:")):
                continue
            relative = unquote(target.split("#", 1)[0].split("?", 1)[0])
            if not relative:
                continue
            resolved = (doc.parent / relative).resolve()
            if not resolved.is_relative_to(ROOT) or not resolved.is_file():
                line = content[: match.start()].count("\n") + 1
                errors.append(f"{name}:{line}: invalid local link {target}")
    return errors


def validate_roadmap() -> list[str]:
    errors = []
    content = (ROOT / "docs/roadmap.md").read_text(encoding="utf-8")
    seen: list[str] = []
    gates: list[str] = []
    for line in content.splitlines():
        m = TASK_ROW.match(line)
        if m:
            seen.append(m.group(1))
            status = line.rsplit("|", 2)[1].strip()
            if status not in ALLOWED_STATUSES:
                errors.append(f"roadmap: {m.group(1)} has invalid status: {status!r}")
        g = GATE_ROW.match(line)
        if g:
            gates.append(g.group(1))
    duplicates = sorted(k for k, n in Counter(seen).items() if n > 1)
    for task in duplicates:
        errors.append(f"roadmap: duplicate task {task}")
    for task in sorted(TASK_BASELINE - set(seen)):
        errors.append(f"roadmap: missing baseline task {task}; update baseline only with reviewed scope change")
    if set(gates) != {f"G{i}" for i in range(1, 6)}:
        errors.append(f"roadmap: expected gates G1–G5, got {sorted(set(gates))}")
    stages = re.findall(r"(?m)^### Stage (\d+)\b", content)
    if sorted(stages) != [str(i) for i in range(10)]:
        errors.append(f"roadmap: expected ten Stage headings 0–9, got {stages}")
    return errors


def is_implementation(path: str) -> bool:
    dirs = (
        "app/", "client/src/", "routes/", "database/migrations/",
        "database/sql/", "database/seeders/", "config/", "bootstrap/",
        "resources/views/", "lang/",
    )
    files = {
        "composer.json", "composer.lock", "package.json", "package-lock.json",
        "client/package.json", "client/package-lock.json",
        "client/vite.config.ts", "vite.config.js",
    }
    return path.startswith(dirs) or path in files


def is_canonical_document(path: str) -> bool:
    if path in {"docs/roadmap.md", "docs/project-status.md", "docs/architecture.md",
                "docs/business-rules.md", "docs/entity-catalog.md", "docs/database.dbml"}:
        return True
    return path.startswith("docs/") and path.endswith((".md", ".sql"))


def validate_pr_impact(base: str) -> list[str]:
    try:
        paths = subprocess.check_output(
            ["git", "-C", str(ROOT), "diff", "--name-only", "--diff-filter=ACMR", base, "HEAD"],
            text=True, stderr=subprocess.STDOUT,
        ).splitlines()
    except subprocess.CalledProcessError as exc:
        return [f"Cannot inspect PR diff from {base}: {exc.output.strip()}"]
    source = [p for p in paths if is_implementation(p)]
    if not source or any(is_canonical_document(p) for p in paths):
        return []
    body = ""
    event_path = os.environ.get("GITHUB_EVENT_PATH", "")
    if event_path and Path(event_path).is_file():
        event = json.loads(Path(event_path).read_text(encoding="utf-8"))
        body = (event.get("pull_request") or {}).get("body") or ""
    waiver = DOCS_IMPACT_NONE.search(body)
    if waiver and len(waiver.group(1).strip()) >= 20 and "пояснення" not in waiver.group(1).lower():
        return []
    listed = ", ".join(source[:7]) + (", ..." if len(source) > 7 else "")
    return [
        "Source changes require a relevant docs/*.md update in the same PR, "
        "or a justified 'Docs-Impact: none — <20+ characters explaining why>' "
        f"in the PR description. Changed: {listed}"
    ]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pr-base", default=None, help="GitHub PR base SHA for docs-impact check")
    args = parser.parse_args()
    errors = validate_links() + validate_roadmap()
    if args.pr_base:
        errors.extend(validate_pr_impact(args.pr_base))
    for problem in errors:
        print("DOCS-ERROR:", problem, file=sys.stderr)
    if errors:
        print(f"Documentation guard: FAIL ({len(errors)} errors)", file=sys.stderr)
        return 1
    print("Documentation guard: PASS (canonical links, Stage/T/G, PR docs impact)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
