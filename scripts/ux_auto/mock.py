"""Фіктивний зовнішній світ із окремим durable журналом і без GitHub/API."""
from copy import deepcopy
import json

from scripts.ci.pre_merge import REQUIRED
from scripts.ux_auto.coordinator import (
    Blocked, REPOSITORY, REVIEW_CHECKS, STAGES, acceptance, atomic_json, digest, stage_prompt,
)


def sha(value):
    return digest(value)[:40]


def green_ci(pr):
    snapshot = {"repository": REPOSITORY, "pr": {
        "number": pr["number"], "state": "open", "auto_merge": None,
        "base": {"ref": pr["base"], "sha": pr["base_sha"]},
        "head": {"sha": pr["head"], "ref": pr["branch"], "repo": {"full_name": REPOSITORY}},
    }, "comparison": {"status": "ahead"}, "runs": {}, "jobs": {}}
    for i, (workflow, names) in enumerate(REQUIRED.items(), 1):
        snapshot["runs"][workflow] = [{
            "id": i, "head_sha": pr["head"], "event": "pull_request",
            "path": f".github/workflows/{workflow}", "head_branch": pr["branch"],
            "pull_requests": [{"number": pr["number"]}], "run_attempt": 1,
            "status": "completed", "conclusion": "success", "html_url": f"mock://ci/{i}",
        }]
        snapshot["jobs"][str(i)] = [dict(
            name=name, head_sha=pr["head"], status="completed", conclusion="success", run_attempt=1,
            steps=[{"name": "Синтетична перевірка", "conclusion": "success"}],
        ) for name in sorted(names)]
    return snapshot


class MockBackend:
    def __init__(self, path, spec_sha, scenario="success"):
        self.path = path
        self.spec_sha = spec_sha
        self.scenario = scenario
        self.data = json.loads(path.read_text()) if path.exists() else {
            "scenario": scenario, "spec_sha": spec_sha, "master": sha("master"),
            "prs": {}, "operations": {}, "receipts": {}, "feedback": {}, "reports": {},
        }
        if self.data["scenario"] != scenario or self.data["spec_sha"] != spec_sha:
            raise Blocked("Для іншого scenario/spec потрібен окремий каталог")

    def save(self):
        atomic_json(self.path, self.data)

    def publish(self, stage, attempt, key, feedback):
        if key in self.data["operations"]:
            return deepcopy(self.data["operations"][key])
        if stage not in STAGES:
            raise Blocked("Невідомий stage")
        previous = STAGES[:STAGES.index(stage)]
        if any(s not in self.data["receipts"] for s in previous):
            raise Blocked("Попередні merge ще не підтверджено")
        pr = {"stage": stage, "repository": REPOSITORY, "base": "master",
              "base_sha": self.data["master"], "branch": f"ux-auto/{stage.lower()}",
              "number": 1000 + STAGES.index(stage), "url": f"mock://pr/{stage}",
              "head": sha([stage, attempt]), "conflicts": False,
              "spec_sha": self.spec_sha, "coder_invocation": f"mock-coder-{stage}-{attempt}",
              "attempt": attempt, "prompt_sha": digest(stage_prompt(stage))}
        self.data["prs"][stage] = pr
        self.data["operations"][key] = pr
        self.data["feedback"][key] = feedback
        self.save()
        return deepcopy(pr)

    def current(self, stage):
        return deepcopy(self.data["prs"][stage])

    def evidence(self, stage):
        pr = self.current(stage)
        evidence = {"ci": green_ci(pr), "review": {
            "head": pr["head"], "base": pr["base_sha"], "spec_sha": self.spec_sha,
            "readonly": True, "invocation": f"mock-review-{stage}-{pr['attempt']}",
            "findings": [], "checks": sorted(REVIEW_CHECKS), "evidence": ["mock://diff/tests/assertions"],
        }, "browser": {
            "head": pr["head"], "status": "success", "checks": acceptance(stage),
            "matrix": [f"{w}/{t}" for w in (1440, 390, 320) for t in ("light", "dark")],
            "screenshots": [f"mock://screenshot/{i}" for i in range(6)],
        }}
        if self.scenario == "failure" or (self.scenario == "retry" and pr["attempt"] == 1):
            evidence["review"]["findings"] = ["Синтетична помилка: відсутній rollback assertion"]
        if self.scenario == "approval":
            evidence["ci"]["runs"]["fast.yml"][0].update(status="waiting", conclusion=None)
        return evidence

    def merge(self, stage, head, base, key):
        if key in self.data["operations"]:
            return deepcopy(self.data["operations"][key])
        pr = self.current(stage)
        if self.scenario == "race":
            self.data["master"] = sha("concurrent master")
        if pr["head"] != head or self.data["master"] != base:
            raise Blocked("SHA-locked merge відхилено: head/base race")
        receipt = {"merged": True, "head": head, "pr": pr["number"], "merge_sha": sha([head, base])}
        self.data["master"] = receipt["merge_sha"]
        self.data["receipts"][stage] = receipt
        self.data["operations"][key] = receipt
        self.data["reports"][stage] = {"pr": pr, "tested_sha": head, "merge_sha": receipt["merge_sha"],
                                       "ci_reviewer_browser": "mock-success"}
        self.save()
        return deepcopy(receipt)

    def confirm(self, stage, receipt):
        return self.scenario != "unconfirmed" and self.data["receipts"].get(stage) == receipt
