#!/usr/bin/env python3
"""Request Full only after the current head's automatic CI event has settled."""
import argparse
import json
import subprocess
import sys

from pre_merge import api, collect, evaluate


def request(repo, number):
    snapshot = collect(repo, number)
    result = evaluate(snapshot)
    errors = [error for error in result["errors"] if not error.startswith("full.yml:")]
    sha = snapshot["pr"]["head"]["sha"]
    runs = [run for run in snapshot["runs"]["full.yml"] if run["head_sha"] == sha]
    if not runs or any(run["status"] != "completed" for run in runs):
        errors.append("Wait for the current head's automatic Full gate run to finish before requesting Full")
    if errors:
        raise ValueError("; ".join(errors))
    current = api(f"repos/{repo}/pulls/{number}")[0]
    if (current["state"] != "open" or current.get("auto_merge")
            or (current["head"]["sha"], current["base"]["sha"]) != (sha, snapshot["pr"]["base"]["sha"])):
        raise ValueError("PR changed; repeat Fast/docs and request Full again")
    endpoint = f"repos/{repo}/issues/{number}/labels"
    if any(label["name"] == "ci:full" for label in current["labels"]):
        subprocess.run(["gh", "api", endpoint + "/ci:full", "-X", "DELETE", "--silent"], check=True)
    subprocess.run(["gh", "api", endpoint, "-X", "POST", "-f", "labels[]=ci:full", "--silent"], check=True)
    return {"requested": True, "pr": number, "head_sha": sha,
            "note": "Wait for Full success, then run pre_merge.py again; this is not merge eligibility"}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("pr", type=int)
    parser.add_argument("--repo", default="ValtonUA/telmanfit")
    args = parser.parse_args()
    try:
        result = request(args.repo, args.pr)
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError) as exc:
        print(json.dumps({"requested": False, "errors": [str(exc)]}, indent=2))
        return 1
    print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
