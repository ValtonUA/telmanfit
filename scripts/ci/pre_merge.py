#!/usr/bin/env python3
"""Read-only fail-closed CI gate. Run from a reviewed, trusted checkout; never merges."""
from __future__ import annotations

import argparse
import json
import subprocess
import sys

REQUIRED = {
    "full.yml": {
        "stage1 / PostgreSQL and browser regressions",
        "runtime / PHP 8.4.27 security", "runtime / PHP 8.5.12 security",
        "compose / Cold build and browser acceptance",
        "Full documentation and base", "Full PR gate",
    },
    "fast.yml": {"Fast PR checks"},
    "docs-health.yml": {"Docs links, roadmap and PR impact"},
    "targeted.yml": {"Targeted PR checks"},
}
ALWAYS_REQUIRED = {"fast.yml", "docs-health.yml", "targeted.yml"}
FULL_TRIGGER_PREFIXES = (
    ".github/workflows/", "scripts/ci/", "database/migrations/",
    "tests/pg/", "experiments/auth", "app/Services/Auth/",
    "app/Http/Middleware/",
)
FULL_TRIGGER_FILES = {
    "composer.json", "composer.lock", "package-lock.json",
    "client/package-lock.json", "Dockerfile", "compose.yaml", "docker-compose.yml",
}


def full_required(snapshot):
    labels = {label.get("name") for label in snapshot["pr"].get("labels", [])}
    if "ci:full" in labels:
        return True
    return any(
        path.startswith(FULL_TRIGGER_PREFIXES) or path in FULL_TRIGGER_FILES
        for path in snapshot.get("changed_files", [])
    )



def api(path):
    raw = subprocess.check_output(["gh", "api", "--paginate", path], text=True)
    # --slurp is absent from older gh releases. Decode concatenated JSON pages.
    pages = []
    decoder = json.JSONDecoder()
    while raw.strip():
        page, end = decoder.raw_decode(raw.lstrip())
        pages.append(page)
        raw = raw.lstrip()[end:]
    return pages


def evaluate(snapshot):
    errors = []
    pr = snapshot["pr"]
    repo = snapshot["repository"]
    sha = pr["head"]["sha"]
    if pr["state"] != "open" or pr["base"]["ref"] != "master":
        errors.append("PR must be open against master")
    if pr["head"]["repo"]["full_name"] != repo:
        errors.append("This gate requires a branch in the same repository")
    if pr.get("auto_merge"):
        errors.append("Disable auto-merge; this fallback requires an explicit SHA-locked merge")
    if snapshot["comparison"]["status"] not in {"ahead", "identical"}:
        errors.append("Current master is not included in tested head; update branch and repeat Full")
    evidence = {}
    required_workflows = set(ALWAYS_REQUIRED)
    if full_required(snapshot):
        required_workflows.add("full.yml")
    for workflow in required_workflows:
        required = REQUIRED[workflow]
        runs = [r for r in snapshot["runs"].get(workflow, [])
                if r["head_sha"] == sha and r["event"] == "pull_request"
                and r["path"] == f".github/workflows/{workflow}"
                and r["head_branch"] == pr["head"]["ref"]
                and any(p["number"] == pr["number"] for p in r["pull_requests"])]
        if not runs:
            errors.append(f"{workflow}: no run on current PR head {sha}")
            continue
        run = max(runs, key=lambda r: r["id"])
        jobs = snapshot["jobs"].get(str(run["id"]), [])
        names = [j["name"] for j in jobs]
        if run["status"] != "completed" or run["conclusion"] != "success":
            errors.append(f"{workflow}: latest run {run['id']} is not completed/success")
        if set(names) != required or len(names) != len(required):
            errors.append(f"{workflow}: missing/extra/duplicate jobs: {names}")
        for job in jobs:
            if (job["status"] != "completed" or job["conclusion"] != "success"
                    or job["head_sha"] != sha or job["run_attempt"] != run["run_attempt"]):
                errors.append(f"{workflow}: invalid job {job['name']}")
            # A green job with any skipped test/build is insufficient.
            if not job.get("steps"):
                errors.append(f"{workflow}: missing steps for {job['name']}")
            for step in job.get("steps", []):
                if step["conclusion"] != "success":
                    errors.append(f"{workflow}: non-success step {step['name']}")
        evidence[workflow] = {"run_id": run["id"], "attempt": run["run_attempt"],
                              "url": run["html_url"]}
    return {"eligible": not errors, "repository": repo, "pr": pr["number"],
            "head_sha": sha, "base_sha": pr["base"]["sha"],
            "evidence": evidence, "errors": errors}


def collect(repo, number):
    pr = api(f"repos/{repo}/pulls/{number}")[0]
    sha = pr["head"]["sha"]
    snapshot = {"repository": repo, "pr": pr, "runs": {}, "jobs": {},
                "changed_files": [f["filename"] for page in api(
                    f"repos/{repo}/pulls/{number}/files?per_page=100") for f in page]}
    snapshot["comparison"] = api(f"repos/{repo}/compare/{pr['base']['sha']}...{sha}")[0]
    for workflow in REQUIRED:
        pages = api(f"repos/{repo}/actions/workflows/{workflow}/runs?head_sha={sha}&event=pull_request&per_page=100")
        runs = [r for page in pages for r in page["workflow_runs"]]
        snapshot["runs"][workflow] = runs
        for run in runs:
            pages = api(f"repos/{repo}/actions/runs/{run['id']}/attempts/{run['run_attempt']}/jobs?per_page=100")
            snapshot["jobs"][str(run["id"])] = [j for page in pages for j in page["jobs"]]
    # Detect races while making API requests. Merge must additionally use --match-head-commit.
    current = api(f"repos/{repo}/pulls/{number}")[0]
    if (current["head"]["sha"], current["base"]["sha"], current["state"], current.get("auto_merge")) != (
            sha, pr["base"]["sha"], pr["state"], pr.get("auto_merge")):
        raise ValueError("PR changed during verification; rerun gate")
    return snapshot


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("pr", type=int)
    parser.add_argument("--repo", default="ValtonUA/telmanfit")
    parser.add_argument("--save-snapshot")
    args = parser.parse_args()
    try:
        snapshot = collect(args.repo, args.pr)
        if args.save_snapshot:
            with open(args.save_snapshot, "w") as file:
                json.dump(snapshot, file, indent=2)
        result = evaluate(snapshot)
    except (KeyError, ValueError, OSError, subprocess.CalledProcessError) as exc:
        result = {"eligible": False, "errors": [str(exc)]}
    print(json.dumps(result, indent=2))
    return 0 if result["eligible"] else 1


if __name__ == "__main__":
    sys.exit(main())
