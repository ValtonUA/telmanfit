"""Неактивний UX-AUTO: детермінований контролер; жодних мережевих операцій."""
from __future__ import annotations

import argparse
from contextlib import contextmanager
from copy import deepcopy
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile
import time

from scripts.ci.pre_merge import evaluate

STAGES = tuple(f"UX-{i}" for i in range(1, 7))
REPOSITORY = "ValtonUA/telmanfit"
STATES = {"pending", "running", "pr_open", "verifying", "fixing", "merged", "blocked"}
MAX_ATTEMPTS = 3
MAX_TICKS = 200
WALL_SECONDS = 60
BROWSER_COMMON = {"auth", "rollback", "keyboard", "touch"}
BROWSER_NAV = {"no_document_navigation", "theme", "back_forward", "direct_link"}
BROWSER_PLAN = {"synthetic_plan", "drag", "stale", "unauthorized"}
REVIEW_CHECKS = {"scope", "immutable_history", "exact_versions", "auth_csrf_mfa",
                 "access", "migration_data_risk", "tests", "ui"}


class Blocked(ValueError):
    pass


class Waiting(Exception):
    """Зовнішня перевірка ще працює; не витрачати спробу кодера."""


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True).encode()).hexdigest()


def initial(spec_sha):
    if not re.fullmatch(r"[0-9a-f]{64}", spec_sha):
        raise Blocked("Некоректний digest довіреної специфікації")
    return {"version": 1, "mode": "mock", "spec_sha": spec_sha, "paused": False,
            "stages": [{"id": s, "state": "pending", "attempt": 0,
                        "reason": None, "history": []} for s in STAGES]}


def validate(state, spec_sha):
    if (state.get("version") != 1 or state.get("mode") not in {"mock", "live"}
            or state.get("spec_sha") != spec_sha
            or [s.get("id") for s in state.get("stages", [])] != list(STAGES)):
        raise Blocked("Несумісний стан або змінена довірена специфікація")
    active = 0
    found_unmerged = False
    found_pending = False
    for s in state["stages"]:
        if s["state"] not in STATES or type(s["attempt"]) is not int or not 0 <= s["attempt"] <= MAX_ATTEMPTS:
            raise Blocked("Некоректний stage/attempt")
        if s["state"] == "merged":
            if found_unmerged or not re.fullmatch(r"[0-9a-f]{40}", s.get("merge_sha", "")):
                raise Blocked("Порушена послідовність підтверджених merge")
        else:
            found_unmerged = True
            if found_pending and s["state"] != "pending":
                raise Blocked("Активний stage не може пропустити pending попередника")
            found_pending |= s["state"] == "pending"
            active += s["state"] != "pending"
    if active > 1:
        raise Blocked("Дозволено лише один активний stage")


def atomic_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix=".ux-auto-", dir=path.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            json.dump(value, stream, ensure_ascii=False, indent=2)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
        directory = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(name):
            os.unlink(name)


@contextmanager
def locked(path):
    lock = Path(str(path) + ".lock")
    lock.parent.mkdir(parents=True, exist_ok=True)
    with lock.open("a") as stream:
        try:
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise Blocked("Інший координатор уже працює") from exc
        yield


def acceptance(stage):
    if stage == "SANDBOX":
        return ["probe_artifacts", "probe_navigation", "probe_theme"]
    checks = BROWSER_COMMON | (BROWSER_NAV if stage in STAGES[:2] else BROWSER_PLAN)
    if stage == "UX-6":
        checks |= {"copy_seven_independent_days", "publish"}
    return sorted(checks)


def check_evidence(stage, pr, evidence, spec_sha, repository=REPOSITORY):
    """Спільний gate; live backend додатково перевіряє походження evidence."""
    if (pr.get("stage") != stage or pr.get("repository") != repository
            or pr.get("base") != "master" or pr.get("branch") != f"ux-auto/{stage.lower()}"
            or pr.get("conflicts") is not False or pr.get("spec_sha") != spec_sha):
        raise Blocked("Чужий PR, неправильний base/scope/spec або конфлікт")
    sha = pr.get("head", "")
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise Blocked("Некоректний head SHA")
    snapshot = evidence["ci"]
    gate = evaluate(snapshot)
    if (not gate["eligible"] or gate["head_sha"] != sha
            or gate["repository"] != repository or gate["pr"] != pr["number"]
            or snapshot["pr"]["head"]["ref"] != pr["branch"]
            or snapshot["pr"]["base"]["sha"] != pr["base_sha"]):
        raise Blocked("CI gate: " + "; ".join(gate["errors"] or ["SHA/PR/base mismatch"]))
    review = evidence["review"]
    if not isinstance(review, dict):
        raise Blocked("Review має бути структурованим, фраза PASS недостатня")
    if (review.get("head") != sha or review.get("base") != pr["base_sha"]
            or review.get("spec_sha") != spec_sha or review.get("readonly") is not True
            or not review.get("invocation") or review["invocation"] == pr.get("coder_invocation")
            or review.get("findings") != [] or review.get("checks") != sorted(REVIEW_CHECKS)
            or not review.get("evidence")):
        raise Blocked("Незалежний review відсутній, застарілий або відхилений: "
                      + json.dumps(review.get("findings"), ensure_ascii=False)[:2000])
    browser = evidence["browser"]
    if not isinstance(browser, dict):
        raise Blocked("Browser evidence має бути структурованим")
    if (browser.get("head") != sha or browser.get("status") != "success"
            or browser.get("checks") != acceptance(stage)
            or browser.get("matrix") != [f"{w}/{t}" for w in (1440, 390, 320) for t in ("light", "dark")]
            or len(browser.get("screenshots", [])) != 6
            or not all(browser["screenshots"])):
        raise Blocked("Browser evidence відсутній, неповний або застарілий")
    return {"head": sha, "base": pr["base_sha"], "evidence_sha": digest(evidence), "ci": gate}


def block(stage, reason):
    stage["state"] = "blocked"
    stage["reason"] = reason
    return stage


def tick(state, backend, spec_sha):
    """Один перехід. Backend має ідемпотентні side effects за ключем операції."""
    validate(state, spec_sha)
    result = deepcopy(state)
    if result["paused"]:
        return result
    s = next((s for s in result["stages"] if s["state"] != "merged"), None)
    if s is None or s["state"] == "blocked":
        return result
    old = s["state"]
    try:
        if old == "pending":
            s.update(state="running", attempt=1)
        elif old in {"running", "fixing"}:
            # Ключ переживає падіння після side effect, але до запису стану.
            key = f"{spec_sha}:{s['id']}:code:{s['attempt']}"
            s["pr"] = backend.publish(s["id"], s["attempt"], key, s.get("reason"))
            s.update(state="pr_open", reason=None)
            s.pop("verified", None)
        elif old == "pr_open":
            s["state"] = "verifying"
        elif old == "verifying":
            pr = backend.current(s["id"])
            # Безпосередньо перед merge завжди свіжі evidence + CI, не кешований PASS.
            if pr != s["pr"]:
                raise Blocked("PR/base змінився після публікації; потрібна повторна перевірка")
            proof = backend.evidence(s["id"])
            try:
                verified = check_evidence(s["id"], pr, proof, spec_sha)
            except Blocked as exc:
                if s["attempt"] >= MAX_ATTEMPTS:
                    raise Blocked(f"Вичерпано {MAX_ATTEMPTS} спроб: {exc}") from exc
                s.update(state="fixing", attempt=s["attempt"] + 1, reason=str(exc))
            else:
                s["verified"] = verified
                key = f"{spec_sha}:{s['id']}:merge:{pr['head']}"
                receipt = backend.merge(s["id"], pr["head"], pr["base_sha"], key)
                if (receipt.get("merged") is not True or receipt.get("head") != pr["head"]
                        or receipt.get("pr") != pr["number"]
                        or not re.fullmatch(r"[0-9a-f]{40}", receipt.get("merge_sha", ""))
                        or not backend.confirm(s["id"], receipt)):
                    raise Blocked("Merge не підтверджено; наступний stage заборонено")
                s.update(state="merged", merge_sha=receipt["merge_sha"], reason=None)
        s["history"].append({"from": old, "to": s["state"], "attempt": s["attempt"]})
    except Waiting:
        return deepcopy(state)
    except (Blocked, KeyError, TypeError, ValueError, AttributeError) as exc:
        block(s, str(exc))
    validate(result, spec_sha)
    return result


def resume(state, spec_sha):
    validate(state, spec_sha)
    result = deepcopy(state)
    s = next((s for s in result["stages"] if s["state"] == "blocked"), None)
    if s:
        if s["attempt"] >= MAX_ATTEMPTS:
            raise Blocked("Бюджет спроб вичерпано; resume не обнуляє бюджет")
        if s.get("verified"):
            # Невизначений merge: reconcile тієї самої операції, не новий code/publish.
            s.update(state="verifying", reason=None, attempt=s["attempt"] + 1)
        else:
            s.update(state="fixing", attempt=s["attempt"] + 1)
    result["paused"] = False
    return result


def summary(state):
    return {"mode": state["mode"], "live_enabled": state.get("live_authorized", False),
            "complete": all(s["state"] == "merged" for s in state["stages"]),
            "stages": [{k: s.get(k) for k in ("id", "state", "attempt", "reason", "pr", "merge_sha", "verified")}
                       for s in state["stages"]]}


def trusted_spec_sha():
    root = Path(__file__).parent
    spec = json.loads((root / "spec.json").read_text())
    source = (root / "issue-106.md").read_bytes()
    if hashlib.sha256(source).hexdigest() != spec["source_sha256"]:
        raise Blocked("Довірений snapshot Issue #106 змінився")
    if [s["id"] for s in spec["stages"]] != list(STAGES) or spec["live_enabled"] is not False:
        raise Blocked("Некоректна довірена специфікація")
    return hashlib.sha256((root / "spec.json").read_bytes()).hexdigest()


def stage_prompt(stage):
    if stage not in STAGES:
        raise Blocked("Невідомий stage")
    trusted_spec_sha()
    root = Path(__file__).parent
    spec = json.loads((root / "spec.json").read_text())
    scope = next(s for s in spec["stages"] if s["id"] == stage)
    return (f"MOCK ONLY. Stage {stage}. Scope: {scope['scope']}. "
            f"Acceptance: {scope['acceptance']}.\n"
            + "\n".join(spec["boundaries"]) + "\n"
            + (root / "issue-106.md").read_text() + "\n"
            + (root.parents[1] / "AGENTS.md").read_text())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("simulate", "status", "pause", "resume", "live"))
    parser.add_argument("--directory", type=Path, default=Path("/tmp/telmanfit-ux-auto"))
    parser.add_argument("--scenario", choices=("success", "retry", "failure", "approval", "race", "unconfirmed"), default="success")
    parser.add_argument("--trigger", default="simulate")
    args = parser.parse_args()
    if args.command == "live":
        parser.error("LIVE_DISABLED: використайте scripts.ux_auto.live із config, sandbox receipt та окремою activation")
    if args.trigger != "simulate":
        parser.error("Неоднозначний або недовірений trigger; дозволено лише simulate")
    from scripts.ux_auto.mock import MockBackend
    spec_sha = trusted_spec_sha()
    args.directory.mkdir(parents=True, exist_ok=True)
    state_path = args.directory / "state.json"
    with locked(state_path):
        state = json.loads(state_path.read_text()) if state_path.exists() else initial(spec_sha)
        validate(state, spec_sha)
        if args.command == "pause":
            state["paused"] = True
        elif args.command == "resume":
            state = resume(state, spec_sha)
        elif args.command == "simulate":
            backend = MockBackend(args.directory / "remote.json", spec_sha, args.scenario)
            start = time.monotonic()
            for _ in range(MAX_TICKS):
                if time.monotonic() - start > WALL_SECONDS:
                    raise Blocked("Перевищено wall-clock budget симуляції")
                new = tick(state, backend, spec_sha)
                atomic_json(state_path, new)
                if new == state:
                    break
                state = new
            else:
                raise Blocked("Перевищено tick budget")
        atomic_json(state_path, state)
        report = summary(state)
        atomic_json(args.directory / "report.json", report)
        print(json.dumps(report, ensure_ascii=False, indent=2))
        return 0 if args.command != "simulate" or report["complete"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
