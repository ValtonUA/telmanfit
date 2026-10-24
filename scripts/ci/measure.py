#!/usr/bin/env python3
"""Collect real Actions job/step duration and per-job rounded Ubuntu minutes."""
import argparse
import datetime as dt
import json
import math
import subprocess


def api(path):
    return json.loads(subprocess.check_output(["gh", "api", path], text=True))


def seconds(start, end):
    if not start or not end:
        return None
    # GitHub skipped placeholders can report completed_at before started_at.
    # They have no runner execution; never subtract time from real jobs.
    return max(0, (dt.datetime.fromisoformat(end.replace("Z", "+00:00")) -
                   dt.datetime.fromisoformat(start.replace("Z", "+00:00"))).total_seconds())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("runs", type=int, nargs="+")
    parser.add_argument("--repo", default="ValtonUA/telmanfit")
    args = parser.parse_args()
    results = []
    for run_id in args.runs:
        run = api(f"repos/{args.repo}/actions/runs/{run_id}")
        jobs = []
        page = 1
        while True:
            batch = api(f"repos/{args.repo}/actions/runs/{run_id}/attempts/{run['run_attempt']}/jobs?per_page=100&page={page}")["jobs"]
            jobs.extend(batch)
            if len(batch) < 100:
                break
            page += 1
        for job in jobs:
            job["seconds"] = seconds(job["started_at"], job["completed_at"])
            job["job_minutes"] = job["seconds"] / 60 if job["seconds"] is not None else None
            job["rounded_ubuntu_minutes_estimate"] = math.ceil(job["job_minutes"]) if job["job_minutes"] is not None else None
            for step in job["steps"]:
                step["seconds"] = seconds(step.get("started_at"), step.get("completed_at"))
        # GitHub deprecated the run timing endpoint; report actual response, not invented billing.
        billing = subprocess.run(["gh", "api", f"repos/{args.repo}/actions/runs/{run_id}/timing"], capture_output=True, text=True)
        results.append({"id": run_id, "url": run["html_url"], "name": run["name"],
                        "event": run["event"], "head_sha": run["head_sha"],
                        "conclusion": run["conclusion"], "attempt": run["run_attempt"],
                        "jobs": jobs, "timing_api": json.loads(billing.stdout) if billing.returncode == 0 else {"unavailable": billing.stdout.strip()}})
    print(json.dumps(results, indent=2))


if __name__ == "__main__":
    main()
