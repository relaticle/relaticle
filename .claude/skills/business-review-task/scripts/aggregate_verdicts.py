#!/usr/bin/env python3
"""Aggregate persona findings + verifier confirmations into verdict-final.json.

Usage:
    python3 aggregate_verdicts.py <REVIEW_DIR>
    python3 aggregate_verdicts.py --test

Reads:
    <REVIEW_DIR>/plan.md                       (frontmatter: tier, channel, journeys, changed_surfaces)
    <REVIEW_DIR>/persona-*/findings.json       (per-persona finding sets)
    <REVIEW_DIR>/verifier/confirmations.json   (bug_id -> {confirmed, ...}), optional
    <REVIEW_DIR>/coverage-critic.json          (unreached_changed_surfaces, ...), optional

Writes:
    <REVIEW_DIR>/verdict-final.json

Label precedence: degraded->blocked, confirmed-blocker->rejected,
no-journeys->needs-human, unreached-PRIMARY-surface->blocked (healthy channel; the
feature under review was never exercised), unreached-secondary-surface->needs-human,
all-delivered+sad-paths-ok->approved, else needs-human.
No Pest. Pure stdlib.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

FRONTMATTER_RE = re.compile(r"<!--json\s*\n(.*?)\n-->", re.DOTALL)

_VERDICT_RANK = {"delivered": 0, "partial": 1, "failed": 2}

def _structure_frontier(items: list) -> list[dict]:
    out = []
    for it in items:
        if isinstance(it, dict):
            out.append({
                "item": it.get("item", ""),
                "why_unreached": it.get("why_unreached"),
                "how_to_close": it.get("how_to_close"),
            })
        else:
            out.append({"item": str(it), "why_unreached": None, "how_to_close": None})
    return out


def _derive_decision_needed(label: str, journey_verdicts: dict, frontier: list,
                            confirmed_blockers: list) -> str | None:
    if label not in ("ai-needs-human", "ai-rejected"):
        return None
    if label == "ai-rejected" and confirmed_blockers:
        return f"Decide how to handle {len(confirmed_blockers)} confirmed blocker(s) before this can ship."
    partials = sorted(j for j, v in journey_verdicts.items() if v in ("partial", "failed"))
    if partials:
        return f"Verify or close the unfinished journey(s): {', '.join(partials)}."
    if frontier:
        return frontier[0].get("item") or "Resolve the top coverage-frontier item."
    return "A human should confirm coverage before approving."




def worst_verdict(verdicts: list[str]) -> str:
    """Return the worst (most severe) journey verdict from a list."""
    if not verdicts:
        return "partial"
    return max(verdicts, key=lambda v: _VERDICT_RANK.get(v, 1))


def derive_label(channel: str, tier: int, journey_verdicts: dict[str, str],
                 confirmed_blockers: list, sad_path_satisfied: bool,
                 has_journeys: bool, unreached_surfaces: list | None = None,
                 unreached_primary: list | None = None) -> tuple[str, str]:
    """Return (label, rationale) by the documented precedence."""
    unreached_surfaces = unreached_surfaces or []
    unreached_primary = unreached_primary or []
    if channel == "degraded":
        return "blocked", "browser channel degraded — no trustworthy verdict possible"
    if confirmed_blockers:
        ids = ", ".join(str(b.get("id")) for b in confirmed_blockers)
        return "ai-rejected", f"{len(confirmed_blockers)} confirmed blocker(s): {ids}"
    # Reached only when channel is healthy AND there are no confirmed blockers
    # (both checked above). So "no journeys" here means a genuinely surface-less
    # diff, never a confirmed-bug-without-journeys case.
    if not has_journeys:
        return "ai-needs-human", "no user-facing surface — deferred to CI + code-review"
    # Move C (max-strict) — if the PRIMARY changed surface (the point of the diff) went
    # unreached, the review never exercised the feature it exists to verify. That is not
    # a "needs a human's eye" call; it is the absence of a review, same class as a dead
    # channel — so it is `blocked` even on a healthy channel (channel stays "healthy" in
    # the output, which discriminates this from a degraded-channel block).
    if unreached_primary:
        joined = ", ".join(str(s) for s in unreached_primary)
        return "blocked", f"primary changed surface(s) never exercised — no review of the feature under review: {joined}"
    # A non-primary changed surface left unreached still bars approval, but a human can
    # make the call (the main feature WAS exercised). "A feature you did not reach is a
    # feature you did not review."
    if unreached_surfaces:
        joined = ", ".join(str(s) for s in unreached_surfaces)
        return "ai-needs-human", f"changed surface(s) unreached/unverified: {joined}"
    all_delivered = all(v == "delivered" for v in journey_verdicts.values())
    if all_delivered and sad_path_satisfied:
        return "ai-approved", f"all {len(journey_verdicts)} journeys delivered; sad-path attestation satisfied"
    if not sad_path_satisfied:
        return "ai-needs-human", "sad-path attestation not satisfied for tier >= 2"
    return "ai-needs-human", "one or more journeys not fully delivered (partial/failed, unconfirmed)"


def extract_plan_frontmatter(plan_path: Path) -> dict:
    if not plan_path.exists():
        return {}
    m = FRONTMATTER_RE.search(plan_path.read_text(encoding="utf-8"))
    if not m:
        return {}
    try:
        return json.loads(m.group(1))
    except json.JSONDecodeError:
        return {}


def aggregate(review_dir: Path) -> dict:
    plan = extract_plan_frontmatter(review_dir / "plan.md")
    tier = plan.get("tier", 0) if isinstance(plan.get("tier"), int) else 0
    channel = plan.get("channel", "healthy")
    plan_journeys = [j for j in plan.get("journeys", []) if isinstance(j, dict)]
    planned_journeys = [j.get("id") for j in plan_journeys]

    critic = {}
    critic_path = review_dir / "coverage-critic.json"
    if critic_path.exists():
        try:
            critic = json.loads(critic_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            raise ValueError(f"Invalid JSON in {critic_path}: {exc}") from exc

    confirmations = {}
    conf_path = review_dir / "verifier" / "confirmations.json"
    if conf_path.exists():
        try:
            confirmations = json.loads(conf_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            raise ValueError(f"Invalid JSON in {conf_path}: {exc}") from exc

    findings = []
    for path in sorted(review_dir.glob("persona-*/findings.json")):
        try:
            findings.append(json.loads(path.read_text(encoding="utf-8")))
        except json.JSONDecodeError as exc:
            raise ValueError(f"Invalid JSON in {path}: {exc}") from exc

    # Aggregate journey verdicts (worst across personas)
    per_journey: dict[str, list[str]] = {}
    sad_walked: dict[str, int] = {}
    all_bugs: list[dict] = []
    ux_friction_count = 0
    frontier_raw: list = []

    for f in findings:
        for j in f.get("journeys", []):
            jid = j.get("id")
            if jid is None:
                continue
            per_journey.setdefault(jid, []).append(j.get("value_verdict", "partial"))
            sad_walked[jid] = sad_walked.get(jid, 0) + len(j.get("sad_paths_walked", []))
        all_bugs.extend(f.get("bugs", []))
        ux_friction_count += len(f.get("ux_friction", []))
        frontier_raw.extend(f.get("coverage", {}).get("frontier_not_reached", []))

    frontier_raw.extend(critic.get("frontier_not_reached", []) or [])
    frontier_structured = _structure_frontier(frontier_raw)
    coverage_frontier = sorted({fs["item"] for fs in frontier_structured})
    journey_verdicts = {jid: worst_verdict(vs) for jid, vs in per_journey.items()}

    # Move C — which changed user-reachable surfaces did the run fail to reach?
    # Two sources, unioned: (1) the coverage-critic's explicit attestation, and
    # (2) a derived backstop — a reachable changed surface is "reached" only if at
    # least one journey that covers it was actually delivered. A surface covered on
    # paper but whose covering journey ended partial/failed/unwalked stays unreached.
    unreached_surfaces: set[str] = set(
        s for s in critic.get("unreached_changed_surfaces", []) if isinstance(s, str)
    )
    primary_surface_ids: set[str] = set()
    for surface in plan.get("changed_surfaces", []):
        if not isinstance(surface, dict):
            continue
        sid = surface.get("id")
        if sid and surface.get("primary") is True:
            primary_surface_ids.add(sid)
        if surface.get("reachable", True) is False or not sid:
            continue
        covering = {j.get("id") for j in plan_journeys if sid in j.get("covers_surfaces", [])}
        covering |= {c for c in surface.get("covered_by", []) if isinstance(c, str)}
        if not any(journey_verdicts.get(jid) == "delivered" for jid in covering):
            unreached_surfaces.add(sid)
    unreached_sorted = sorted(unreached_surfaces)
    # The primary surface(s) of the diff that went unreached — these escalate to `blocked`.
    unreached_primary = sorted(unreached_surfaces & primary_surface_ids)

    confirmed_blockers = []
    unconfirmed = []
    for bug in all_bugs:
        bug_id = bug.get("id")
        entry = confirmations.get(bug_id, {})
        slim = {"id": bug_id, "journey": bug.get("journey"), "actual": bug.get("actual")}
        if entry.get("confirmed"):
            confirmed_blockers.append(slim)
        else:
            unconfirmed.append(slim)

    # Sad-path attestation: tier>=2 => every planned journey walked >=1 sad path
    if tier >= 2 and planned_journeys:
        missing = [jid for jid in planned_journeys if sad_walked.get(jid, 0) == 0]
        sad_satisfied = len(missing) == 0
        sad_detail = ("tier %d: all planned journeys walked >=1 sad path" % tier) if sad_satisfied \
            else ("tier %d: journeys missing a walked sad path: %s" % (tier, ", ".join(missing)))
    else:
        sad_satisfied = True
        sad_detail = "tier < 2: sad paths encouraged, not required"

    label, rationale = derive_label(
        channel, tier, journey_verdicts, confirmed_blockers, sad_satisfied,
        has_journeys=bool(planned_journeys), unreached_surfaces=unreached_sorted,
        unreached_primary=unreached_primary,
    )

    # Discriminate the two causes of `blocked` for downstream messaging/escalation:
    # a dead browser channel vs a healthy channel that never reached the primary surface.
    blocked_reason = None
    if label == "blocked":
        blocked_reason = "degraded-channel" if channel == "degraded" else "unreached-primary-surface"

    # A non-trivial diff (tier>=2) with an empty coverage_frontier is suspicious:
    # every journey has a frontier; claiming none were missed on a broad/critical diff
    # is a faked-breadth signal (SPEC §14 faked-breadth-trap).
    frontier_suspicious = tier >= 2 and bool(planned_journeys) and len(frontier_structured) == 0

    return {
        "label": label,
        "tier": tier,
        "channel": channel,
        "journey_verdicts": journey_verdicts,
        "confirmed_blockers": confirmed_blockers,
        "unconfirmed_findings": unconfirmed,
        "sad_path_attestation": {"satisfied": sad_satisfied, "detail": sad_detail},
        "coverage_frontier": coverage_frontier,
        "frontier": frontier_structured,
        "decision_needed": _derive_decision_needed(label, journey_verdicts, frontier_structured, confirmed_blockers),
        "frontier_suspicious": frontier_suspicious,
        "unreached_changed_surfaces": unreached_sorted,
        "unreached_primary_surfaces": unreached_primary,
        "blocked_reason": blocked_reason,
        "ux_friction_count": ux_friction_count,
        "rationale": rationale,
    }


def run_tests() -> int:
    import tempfile
    failures: list[str] = []

    def check(name: str, condition: bool, detail: str = "") -> None:
        if condition:
            print(f"  {name} ... PASS")
        else:
            print(f"  {name} ... FAIL: {detail}")
            failures.append(name)

    # NEW: frontier[] structured + decision_needed emitted (integration)
    with tempfile.TemporaryDirectory() as td:
        work = Path(td)
        (work / "plan.md").write_text(
            '<!--json\n{"pr_number":1,"sha":"x","tier":3,"channel":"healthy",'
            '"journeys":[{"id":"S1","synthesized":true,"name":"n","personas":["p"],'
            '"happy_path":["h"],"sad_paths":["s"],"acs":[1]}]}\n-->\n', encoding="utf-8")
        pdir = work / "persona-p"
        pdir.mkdir()
        (pdir / "findings.json").write_text(json.dumps({
            "persona": "p",
            "journeys": [{"id": "S1", "value_verdict": "partial", "sad_paths_walked": ["s"]}],
            "bugs": [], "ux_friction": [],
            "coverage": {"frontier_not_reached": [
                {"item": "paid-unlock", "why_unreached": "no paid acct", "how_to_close": "seed a paid account"},
                "a bare-string frontier item",
            ]},
        }), encoding="utf-8")
        v = aggregate(work)
        check("T-frontier-structured", isinstance(v.get("frontier"), list)
              and all(set(x) >= {"item", "why_unreached", "how_to_close"} for x in v["frontier"]),
              f"got {v.get('frontier')!r}")
        check("T-frontier-backcompat-string", any(
            x["item"] == "a bare-string frontier item" and x["why_unreached"] is None
            for x in v["frontier"]))
        check("T-coverage-frontier-kept", isinstance(v.get("coverage_frontier"), list)
              and "paid-unlock" in v["coverage_frontier"])
        check("T-decision-needed-present",
              isinstance(v.get("decision_needed"), str) and bool(v["decision_needed"]),
              f"got {v.get('decision_needed')!r}")

    # worst_verdict
    check("T-worst-failed", worst_verdict(["delivered", "failed", "partial"]) == "failed")
    check("T-worst-partial", worst_verdict(["delivered", "partial"]) == "partial")
    check("T-worst-delivered", worst_verdict(["delivered", "delivered"]) == "delivered")

    # derive_label precedence
    lbl, _ = derive_label("degraded", 2, {"J3": "delivered"}, [], True, True)
    check("T-label-degraded-blocked", lbl == "blocked", f"got {lbl}")

    lbl, _ = derive_label("healthy", 2, {"J3": "failed"}, [{"id": "BUG-1"}], True, True)
    check("T-label-confirmed-rejected", lbl == "ai-rejected", f"got {lbl}")

    lbl, _ = derive_label("healthy", 1, {}, [], True, False)
    check("T-label-no-journeys-needs-human", lbl == "ai-needs-human", f"got {lbl}")

    lbl, _ = derive_label("healthy", 2, {"J1": "delivered", "J3": "delivered"}, [], True, True)
    check("T-label-all-delivered-approved", lbl == "ai-approved", f"got {lbl}")

    lbl, _ = derive_label("healthy", 2, {"J3": "delivered"}, [], False, True)
    check("T-label-sadpath-unsatisfied-needs-human", lbl == "ai-needs-human", f"got {lbl}")

    lbl, _ = derive_label("healthy", 2, {"J3": "partial"}, [], True, True)
    check("T-label-partial-needs-human", lbl == "ai-needs-human", f"got {lbl}")

    # Move C — unreached changed surface caps an otherwise-clean run at needs-human
    lbl, rat = derive_label("healthy", 2, {"J11": "delivered", "J12": "delivered"}, [], True, True,
                            unreached_surfaces=["builder:create-booking-field"])
    check("T-label-unreached-surface-needs-human",
          lbl == "ai-needs-human" and "unreached" in rat, f"got {lbl}: {rat}")

    # confirmed blocker still outranks an unreached surface (rejected, not needs-human)
    lbl, _ = derive_label("healthy", 2, {"J11": "delivered"}, [{"id": "BUG-2"}], True, True,
                          unreached_surfaces=["builder:create-booking-field"])
    check("T-label-blocker-outranks-unreached", lbl == "ai-rejected", f"got {lbl}")

    # degraded still outranks an unreached surface (blocked)
    lbl, _ = derive_label("degraded", 2, {"J11": "delivered"}, [], True, True,
                          unreached_surfaces=["x"])
    check("T-label-degraded-outranks-unreached", lbl == "blocked", f"got {lbl}")

    # Move C (max-strict) — an unreached PRIMARY surface escalates to blocked on a healthy channel
    lbl, rat = derive_label("healthy", 3, {"J11": "delivered"}, [], True, True,
                            unreached_surfaces=["builder:create-booking-field"],
                            unreached_primary=["builder:create-booking-field"])
    check("T-label-unreached-primary-blocked",
          lbl == "blocked" and "primary" in rat, f"got {lbl}: {rat}")

    # a non-primary unreached surface stays needs-human (primary list empty)
    lbl, _ = derive_label("healthy", 3, {"J11": "delivered"}, [], True, True,
                          unreached_surfaces=["secondary:thing"], unreached_primary=[])
    check("T-label-unreached-secondary-needs-human", lbl == "ai-needs-human", f"got {lbl}")

    # confirmed blocker still outranks an unreached PRIMARY surface (rejected, not blocked)
    lbl, _ = derive_label("healthy", 3, {"J11": "delivered"}, [{"id": "BUG-3"}], True, True,
                          unreached_surfaces=["p"], unreached_primary=["p"])
    check("T-label-blocker-outranks-unreached-primary", lbl == "ai-rejected", f"got {lbl}")

    # degraded overrides even confirmed blockers
    lbl, _ = derive_label("degraded", 3, {"J3": "failed"}, [{"id": "BUG-1"}], True, True)
    check("T-label-degraded-overrides-blocker", lbl == "blocked", f"got {lbl}")

    # end-to-end: confirmed blocker -> rejected
    with tempfile.TemporaryDirectory() as tmp:
        d = Path(tmp)
        (d / "plan.md").write_text(
            '<!--json\n{"tier": 2, "channel": "healthy", '
            '"journeys": [{"id": "J3", "personas": ["creator-pro"], '
            '"happy_path": ["x"], "sad_paths": ["discard"], "acs": [6]}]}\n-->\nbody')
        pdir = d / "persona-creator-pro"
        pdir.mkdir()
        (pdir / "findings.json").write_text(json.dumps({
            "persona": "creator-pro",
            "journeys": [{"id": "J3", "value_verdict": "failed", "happy_path": "pass",
                          "sad_paths_walked": [{"path": "discard before publish",
                                                "result": "500", "bug_ref": "BUG-1"}]}],
            "bugs": [{"id": "BUG-1", "journey": "J3", "actual": "HTTP 500"}],
            "ux_friction": [{"journey": "J3", "note": "x", "severity": "medium"}],
            "coverage": {"seams_hit": [], "frontier_not_reached": ["regen over edit"]},
        }))
        vdir = d / "verifier"
        vdir.mkdir()
        (vdir / "confirmations.json").write_text(json.dumps({"BUG-1": {"confirmed": True}}))
        res = aggregate(d)
        check("T-e2e-rejected",
              res["label"] == "ai-rejected"
              and res["journey_verdicts"] == {"J3": "failed"}
              and len(res["confirmed_blockers"]) == 1
              and res["sad_path_attestation"]["satisfied"] is True
              and res["coverage_frontier"] == ["regen over edit"]
              and res["ux_friction_count"] == 1,
              f"got {res}")

    # end-to-end: unconfirmed bug does NOT reject (no false reject)
    with tempfile.TemporaryDirectory() as tmp:
        d = Path(tmp)
        (d / "plan.md").write_text(
            '<!--json\n{"tier": 1, "channel": "healthy", '
            '"journeys": [{"id": "J1", "personas": ["creator-hurry"], '
            '"happy_path": ["x"], "sad_paths": [], "acs": [1]}]}\n-->\nbody')
        pdir = d / "persona-creator-hurry"
        pdir.mkdir()
        (pdir / "findings.json").write_text(json.dumps({
            "persona": "creator-hurry",
            "journeys": [{"id": "J1", "value_verdict": "failed", "happy_path": "pass",
                          "sad_paths_walked": []}],
            "bugs": [{"id": "BUG-9", "journey": "J1", "actual": "weird glitch"}],
            "ux_friction": [],
            "coverage": {"frontier_not_reached": []},
        }))
        # no verifier dir at all -> bug unconfirmed
        res = aggregate(d)
        check("T-e2e-unconfirmed-not-rejected",
              res["label"] == "ai-needs-human"
              and len(res["confirmed_blockers"]) == 0
              and len(res["unconfirmed_findings"]) == 1,
              f"got {res}")

    # T-main-bad-usage / missing dir
    check("T-main-bad-usage", main(["a.py"]) == 2)
    check("T-main-missing-dir", main(["a.py", "/tmp/nope-bvqx-agg2"]) == 1)

    # Journey-specific: channel:degraded + confirmed blocker -> label must be "blocked" (NOT ai-rejected)
    # SPEC §11: degraded overrides EVERYTHING including a confirmed blocker
    with tempfile.TemporaryDirectory() as tmp:
        d = Path(tmp)
        (d / "plan.md").write_text(
            '<!--json\n{"tier": 2, "channel": "degraded", '
            '"journeys": [{"id": "J2", "personas": ["caseworker"], '
            '"happy_path": ["move-in wizard"], "sad_paths": ["no supporting grant 500"], "acs": [1]}]}\n-->\n'
            'Degraded channel with a confirmed blocker — blocked must win.')
        pdir = d / "persona-caseworker"
        pdir.mkdir()
        (pdir / "findings.json").write_text(json.dumps({
            "persona": "caseworker",
            "journeys": [{"id": "J2", "value_verdict": "failed", "happy_path": "pass",
                          "sad_paths_walked": [{"path": "no supporting grant", "result": "500", "bug_ref": "BUG-1"}]}],
            "bugs": [{"id": "BUG-1", "journey": "J2", "actual": "HTTP 500 GrantAssignmentException",
                      "artifact": "case-J2/no-grant-500.png"}],
            "ux_friction": [],
            "coverage": {"seams_hit": [], "frontier_not_reached": ["grant-override fallback"]},
        }))
        vdir = d / "verifier"
        vdir.mkdir()
        (vdir / "confirmations.json").write_text(json.dumps({"BUG-1": {"confirmed": True}}))
        res = aggregate(d)
        check("T-journey-degraded-plus-blocker-is-blocked",
              res["label"] == "blocked",
              f"got label={res['label']} (expected blocked, not ai-rejected)")

    # Journey-specific: channel:healthy, all journeys delivered, sad-path attestation satisfied,
    # zero confirmed blockers -> label must be "ai-approved"
    with tempfile.TemporaryDirectory() as tmp:
        d = Path(tmp)
        (d / "plan.md").write_text(
            '<!--json\n{"tier": 2, "channel": "healthy", '
            '"journeys": [{"id": "J5", "personas": ["finance-clerk"], '
            '"happy_path": ["record HAP payment", "submit"], '
            '"sad_paths": ["payment with no covering grant"], "acs": [1]}]}\n-->\n'
            'Healthy channel, full delivery, sad path walked.')
        pdir = d / "persona-finance-clerk"
        pdir.mkdir()
        (pdir / "findings.json").write_text(json.dumps({
            "persona": "finance-clerk",
            "journeys": [{"id": "J5", "value_verdict": "delivered", "happy_path": "pass",
                          "sad_paths_walked": [{"path": "payment with no covering grant",
                                                "result": "pre-flight validation block", "bug_ref": None}]}],
            "bugs": [],
            "ux_friction": [],
            "coverage": {"seams_hit": ["expense-type -> supporting-grant resolution"],
                         "frontier_not_reached": ["tenancy grant-override fallback"]},
        }))
        res = aggregate(d)
        check("T-journey-healthy-all-delivered-approved",
              res["label"] == "ai-approved",
              f"got label={res['label']} (expected ai-approved)")

    print()
    if failures:
        print(f"FAILED: {len(failures)} test(s): {', '.join(failures)}")
        return 1
    print("All tests passed.")
    return 0


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] == "--test":
        return run_tests()
    if len(argv) != 2:
        print(f"Usage: {argv[0]} <REVIEW_DIR>  OR  --test", file=sys.stderr)
        return 2
    review_dir = Path(argv[1])
    if not review_dir.exists():
        print(f"FAIL: {review_dir} does not exist", file=sys.stderr)
        return 1
    try:
        result = aggregate(review_dir)
    except Exception as exc:
        print(f"FAIL: aggregation error: {exc}", file=sys.stderr)
        return 1
    out = review_dir / "verdict-final.json"
    out.write_text(json.dumps(result, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"Aggregated → {out}: label={result['label']} ({result['rationale']})")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
