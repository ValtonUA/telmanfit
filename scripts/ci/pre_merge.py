#!/usr/bin/env python3
"""Read-only fail-closed CI gate. Run from a reviewed, trusted checkout; never merges."""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys

REQUIRED = {
    "fast.yml": {"Fast PR checks"},
    "docs-health.yml": {"Docs links, roadmap and PR impact"},
    "targeted.yml": {
        "Select required PostgreSQL/browser security coverage",
        "regressions / PostgreSQL and browser regressions",
        "Targeted PR checks",
    },
    "local-update-powershell-compat.yml": {
        "Parse coordinator with Windows PowerShell 5.1", "Parse coordinator with PowerShell 7",
    },
    "feature-environments.yml": {"Feature environment checks"},
}


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


def evaluate(snapshot, purpose="merge"):
    if purpose not in {"preview", "merge"}:
        raise ValueError("Unknown gate purpose")
    errors = []
    pr = snapshot["pr"]
    repo = snapshot["repository"]
    sha = pr["head"]["sha"]
    if snapshot.get("master_sha") != pr["base"]["sha"]:
        errors.append("PR base is not current master")
    if not pr["head"]["ref"].startswith("feature/"):
        errors.append("Only feature/* branches may target master")
    if not pr.get("user", {}).get("login"):
        errors.append("PR author identity is missing")
    reviews = {}
    for review in sorted(snapshot.get("reviews", []), key=lambda r: r["id"]):
        if review["state"] != "COMMENTED":
            reviews[review["user"]["login"]] = review
    independent = [r for login, r in reviews.items()
                   if login != pr.get("user", {}).get("login")
                   and r["state"] == "APPROVED" and r["commit_id"] == sha
                   and r["user"].get("type") == "User"]
    if not independent or any(r["state"] == "CHANGES_REQUESTED" for r in reviews.values()):
        errors.append("Independent approval on current head is required; resolve requested changes")
    if purpose == "merge":
        if pr.get("draft", False):
            errors.append("Draft PR cannot be merged")
        owner = repo.split("/")[0]
        decisions = []
        for comment in snapshot.get("comments", []):
            if comment["user"]["login"] != owner or comment["user"].get("type") != "User":
                continue
            match = re.fullmatch(r"TelmanFit-(Accept|Reject): ([a-f0-9]{40}) ([a-f0-9]{40})", comment["body"].strip())
            if match and (match[2], match[3]) == (sha, pr["base"]["sha"]):
                decisions.append((comment.get("updated_at", comment["created_at"]), comment["id"], match[1]))
        if not decisions or max(decisions)[2] != "Accept":
            errors.append("Owner product acceptance for exact head and base is required")
    if pr["state"] != "open" or pr["base"]["ref"] != "master":
        errors.append("PR must be open against master")
    if pr["head"]["repo"]["full_name"] != repo:
        errors.append("This gate requires a branch in the same repository")
    if pr.get("auto_merge"):
        errors.append("Disable auto-merge; this fallback requires an explicit SHA-locked merge")
    if snapshot["comparison"]["status"] not in {"ahead", "identical"}:
        errors.append("Current master is not included in tested head; update branch and repeat required checks")
    evidence = {}
    for workflow, required in REQUIRED.items():
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
        names = [
            "regressions / PostgreSQL and browser regressions"
            if (workflow == "targeted.yml" and j["name"] == "regressions"
                and j["status"] == "completed" and j["conclusion"] == "skipped")
            else j["name"]
            for j in jobs
        ]
        if run["status"] != "completed" or run["conclusion"] != "success":
            errors.append(f"{workflow}: latest run {run['id']} is not completed/success")
        if set(names) != required or len(names) != len(required):
            errors.append(f"{workflow}: missing/extra/duplicate jobs: {names}")
        for job in jobs:
            if (workflow == "targeted.yml"
                    and job["name"] in {"regressions", "regressions / PostgreSQL and browser regressions"}
                    and job["status"] == "completed" and job["conclusion"] == "skipped"):
                # Targeted gate proves the diff selected no PG/browser scope.
                continue
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
    return {"eligible": not errors, "purpose": purpose, "repository": repo, "pr": pr["number"],
            "head_sha": sha, "base_sha": pr["base"]["sha"],
            "evidence": evidence, "errors": errors}


def collect(repo, number):
    pr = api(f"repos/{repo}/pulls/{number}")[0]
    sha = pr["head"]["sha"]
    snapshot = {"repository": repo, "pr": pr, "runs": {}, "jobs": {}}
    snapshot["master_sha"] = api(f"repos/{repo}/git/ref/heads/master")[0]["object"]["sha"]
    snapshot["reviews"] = [r for page in api(f"repos/{repo}/pulls/{number}/reviews?per_page=100") for r in page]
    snapshot["comments"] = [c for page in api(f"repos/{repo}/issues/{number}/comments?per_page=100") for c in page]
    snapshot["comparison"] = api(f"repos/{repo}/compare/{pr['base']['sha']}...{sha}")[0]
    for workflow in REQUIRED:
        pages = api(f"repos/{repo}/actions/workflows/{workflow}/runs?head_sha={sha}&event=pull_request&per_page=100")
        runs = [r for page in pages for r in page["workflow_runs"]]
        snapshot["runs"][workflow] = runs
        for run in runs:
            pages = api(f"repos/{repo}/actions/runs/{run['id']}/attempts/{run['run_attempt']}/jobs?per_page=100")
            snapshot["jobs"][str(run["id"])] = [j for page in pages for j in page["jobs"]]
    for key, endpoint in (("reviews", f"pulls/{number}/reviews"), ("comments", f"issues/{number}/comments")):
        latest = [item for page in api(f"repos/{repo}/{endpoint}?per_page=100") for item in page]
        if latest != snapshot[key]:
            raise ValueError("Review/acceptance changed during verification; rerun gate")
    # Detect races while making API requests. Merge must additionally use --match-head-commit.
    current_master = api(f"repos/{repo}/git/ref/heads/master")[0]["object"]["sha"]
    if current_master != snapshot["master_sha"]:
        raise ValueError("Master changed during verification; rerun gate")
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
    parser.add_argument("--purpose", choices=("preview", "merge"), default="merge")
    args = parser.parse_args()
    try:
        snapshot = collect(args.repo, args.pr)
        if args.save_snapshot:
            with open(args.save_snapshot, "w") as file:
                json.dump(snapshot, file, indent=2)
        result = evaluate(snapshot, args.purpose)
    except (KeyError, ValueError, OSError, subprocess.CalledProcessError) as exc:
        result = {"eligible": False, "errors": [str(exc)]}
    print(json.dumps(result, indent=2))
    return 0 if result["eligible"] else 1


if __name__ == "__main__":
    sys.exit(main())
