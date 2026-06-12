#!/usr/bin/env python3
"""Validate a business-review-task plan.md's journey-based JSON frontmatter.

Usage:
    python3 validate_plan.py <path-to-plan.md>
    python3 validate_plan.py --test

Exits: 0 valid, 1 validation/parse error, 2 bad usage.

Substance gates enforced (not just JSON shape):
  - tier present and in 0..3; channel in {healthy, degraded}
  - journeys non-empty, OR journeys empty WITH a top-level no_surface_reason
  - every journey: id, personas (non-empty), happy_path (non-empty), sad_paths (list), acs (list)
  - tier >= 2  =>  every journey has >=1 sad_path   (forbids happy-path-only on broad/critical PRs)
  - AC ids in journeys resolve against acceptance-criteria.json
  - journey ids resolve against references/journeys.json IF that manifest exists (else a warning),
    UNLESS the journey is marked "synthesized": true — a journey derived for this diff (Move A),
    which earns its place by substance, not catalog membership
  - changed_surfaces (opt-in): every user-reachable changed surface is covered by some journey
    (Move A), and every gated changed surface has an attested precondition that activates its
    gate (Move B). A plan without changed_surfaces is unaffected (back-compat).

Companion files looked up next to plan.md:
  - acceptance-criteria.json (required)
The journeys manifest is looked up at <skill>/references/journeys.json relative to this script.

Pure stdlib.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

FRONTMATTER_RE = re.compile(r"<!--json\s*\n(.*?)\n-->", re.DOTALL)

REQUIRED_TOP_LEVEL = ("pr_number", "sha", "tier", "channel", "journeys")
REQUIRED_JOURNEY_FIELDS = ("id", "personas", "happy_path", "sad_paths", "acs")
ALLOWED_CHANNELS = {"healthy", "degraded"}


def extract_frontmatter(plan_md: str) -> dict:
    m = FRONTMATTER_RE.search(plan_md)
    if not m:
        raise ValueError("No <!--json ... --> frontmatter block found in plan.md")
    try:
        return json.loads(m.group(1))
    except json.JSONDecodeError as e:
        raise ValueError(f"Frontmatter JSON is invalid: {e}") from e


def validate_schema(plan: dict, ac_ids: set[int], known_journeys: set[str] | None,
                    change_types: set[str] | None = None) -> tuple[list[str], list[str]]:
    """Return (errors, warnings). known_journeys=None means the manifest is absent.
    change_types=None means the diff classification was not supplied (the authoring
    cross-check is skipped — back-compat)."""
    errors: list[str] = []
    warnings: list[str] = []

    for field in REQUIRED_TOP_LEVEL:
        if field not in plan:
            errors.append(f"plan missing required field '{field}'")

    tier = plan.get("tier")
    if "tier" in plan:
        if not isinstance(tier, int) or isinstance(tier, bool) or tier < 0 or tier > 3:
            errors.append(f"plan.tier must be an integer 0-3; got {tier!r}")

    if "channel" in plan and plan["channel"] not in ALLOWED_CHANNELS:
        errors.append(f"plan.channel '{plan['channel']}' invalid; must be one of {sorted(ALLOWED_CHANNELS)}")

    journeys = plan.get("journeys")
    if journeys is None:
        return errors, warnings  # already flagged as missing
    if not isinstance(journeys, list):
        errors.append("plan.journeys must be a list")
        return errors, warnings

    if len(journeys) == 0:
        if not plan.get("no_surface_reason"):
            errors.append("plan.journeys is empty but no top-level 'no_surface_reason' is given")
        return errors, warnings

    tier_for_gate = tier if isinstance(tier, int) and not isinstance(tier, bool) else 0

    ids_seen: set[str] = set()
    for i, j in enumerate(journeys):
        if not isinstance(j, dict):
            errors.append(f"plan.journeys[{i}] is not an object: {j!r}")
            continue
        jid = j.get("id", f"<missing id at index {i}>")

        for field in REQUIRED_JOURNEY_FIELDS:
            if field not in j:
                errors.append(f"journey {jid} missing required field '{field}'")

        is_synth = bool(j.get("synthesized"))
        if "id" in j:
            if jid in ids_seen:
                errors.append(f"duplicate journey id '{jid}'")
            ids_seen.add(jid)
            if is_synth:
                # Synthesized journeys are derived from THIS diff (Move A) — they are
                # intentionally not in the catalog manifest. They earn their place by
                # the same substance bar as catalog journeys (checked above), not by
                # membership. A synthesized journey with no human-readable name is a
                # smell worth surfacing, but not a hard error.
                if not j.get("name"):
                    warnings.append(f"synthesized journey '{jid}' has no 'name' label")
            elif known_journeys is not None and jid not in known_journeys:
                errors.append(
                    f"journey id '{jid}' not found in references/journeys.json "
                    f"(add it to the catalog, or mark the journey \"synthesized\": true)"
                )
            elif known_journeys is None:
                warnings.append(f"journey id '{jid}' not verified — references/journeys.json absent")

        personas = j.get("personas")
        if "personas" in j and (not isinstance(personas, list) or len(personas) == 0):
            errors.append(f"journey {jid} personas must be a non-empty list")

        happy = j.get("happy_path")
        if "happy_path" in j and (not isinstance(happy, list) or len(happy) == 0):
            errors.append(f"journey {jid} happy_path must be a non-empty list")

        sad = j.get("sad_paths")
        if "sad_paths" in j:
            if not isinstance(sad, list):
                errors.append(f"journey {jid} sad_paths must be a list")
            elif tier_for_gate >= 2 and len(sad) == 0:
                errors.append(
                    f"journey {jid} has no sad_paths but tier is {tier_for_gate} "
                    f"(tier >= 2 requires >=1 sad path per journey)"
                )

        acs = j.get("acs")
        if "acs" in j:
            if not isinstance(acs, list):
                errors.append(f"journey {jid} acs must be a list")
            else:
                for ac in acs:
                    if ac == "implicit":
                        continue
                    if not isinstance(ac, int):
                        errors.append(f"journey {jid} acs entry {ac!r} must be an int or \"implicit\"")
                    elif ac not in ac_ids:
                        errors.append(f"journey {jid} references AC {ac} not in acceptance-criteria.json")

    # Move A + B — changed-surface coverage and precondition gates.
    # Both are opt-in on the presence of `changed_surfaces`: a plan that does not
    # declare it is unaffected (back-compat with legacy/test plans). When declared,
    # every user-reachable changed surface MUST be covered by some journey, and every
    # gated changed surface MUST have an attested precondition that activates its gate.
    surfaces = plan.get("changed_surfaces")
    if isinstance(surfaces, list):
        covered_surface_ids: set[str] = set()
        for j in journeys:
            if isinstance(j, dict):
                covered_surface_ids.update(
                    s for s in j.get("covers_surfaces", []) if isinstance(s, str)
                )

        activated_gates: set[str] = set()
        for p in plan.get("preconditions_activated", []) or []:
            if isinstance(p, dict) and p.get("attested") is True and p.get("gate"):
                activated_gates.add(p["gate"])

        has_reachable_authoring = False
        for s in surfaces:
            if not isinstance(s, dict):
                errors.append(f"changed_surfaces entry is not an object: {s!r}")
                continue
            sid = s.get("id")
            if not sid:
                errors.append("changed_surfaces entry missing 'id'")
                continue
            is_authoring = s.get("kind") == "authoring"
            reachable = s.get("reachable", True) is not False
            if is_authoring and reachable:
                has_reachable_authoring = True
            # An authoring/configuration surface (create + save the thing the diff adds)
            # cannot be waived as non-reachable — that is the dodge that lets an authoring
            # bug ship green. It MUST be covered by an author->persist->consume journey.
            if is_authoring and not reachable:
                errors.append(
                    f"authoring surface '{sid}' is marked reachable:false — an authoring/"
                    f"configuration surface cannot be waived; it must be covered by an "
                    f"author->persist->consume journey (only consumption-side or internal "
                    f"surfaces may be waived non-reachable)"
                )
                continue
            if not reachable:
                continue  # consumption/internal surface, explicitly non-reachable — waived
            covered = sid in covered_surface_ids or any(
                c in ids_seen for c in s.get("covered_by", []) if isinstance(c, str)
            )
            if not covered:
                errors.append(
                    f"changed surface '{sid}' is user-reachable but no journey covers it "
                    f"(add a journey whose covers_surfaces includes it — synthesize one if "
                    f"the catalog has none — or mark the surface \"reachable\": false with a reason)"
                )
            gate = s.get("gated_by")
            if gate and gate not in activated_gates:
                errors.append(
                    f"changed surface '{sid}' is gated by '{gate}' but no attested precondition "
                    f"activates it (add a preconditions_activated entry "
                    f"{{\"gate\": \"{gate}\", \"attested\": true}} — a feature you did not "
                    f"activate is a feature you did not review)"
                )

        # Anti-dodge cross-check (Move A hardening): if the diff changes a builder /
        # configuration capability (classifier change_types include `form` — field types,
        # FormBuilder, Filament Forms schemas — or `feature_flag`), the plan MUST declare
        # at least one REACHABLE authoring surface. This catches the omission dodge — not
        # listing the authoring surface at all — which the coverage gate alone can't see.
        AUTHORING_TRIGGERS = {"form", "feature_flag"}
        if change_types and (AUTHORING_TRIGGERS & change_types) and not has_reachable_authoring:
            triggers = ", ".join(sorted(AUTHORING_TRIGGERS & change_types))
            errors.append(
                f"diff changes a builder/configuration capability (change_types: {triggers}) "
                f"but the plan declares no reachable authoring surface "
                f"(kind:\"authoring\", reachable:true) — the author->persist->consume arc is "
                f"unwaivable when the diff adds something a creator configures"
            )

    return errors, warnings


def run_tests() -> int:
    failures: list[str] = []

    def check(name: str, condition: bool, detail: str = "") -> None:
        if condition:
            print(f"  {name} ... PASS")
        else:
            print(f"  {name} ... FAIL: {detail}")
            failures.append(name)

    def base_plan(overrides: dict | None = None) -> dict:
        plan = {
            "pr_number": 196,
            "sha": "abc123def0",
            "tier": 2,
            "tier_rationale": "broad",
            "channel": "healthy",
            "journeys": [
                {
                    "id": "J3",
                    "personas": ["creator-pro"],
                    "happy_path": ["generate", "publish"],
                    "sad_paths": ["discard before publish"],
                    "acs": [6],
                }
            ],
        }
        if overrides:
            plan.update(overrides)
        return plan

    ac_ids = {6}
    known = {"J1", "J3"}

    # T-ok
    e, w = validate_schema(base_plan(), ac_ids, known)
    check("T-ok", e == [], str(e))

    # T-missing-tier
    p = base_plan(); del p["tier"]
    e, _ = validate_schema(p, ac_ids, known)
    check("T-missing-tier", any("tier" in x for x in e), str(e))

    # T-tier-out-of-range
    p = base_plan({"tier": 5})
    e, _ = validate_schema(p, ac_ids, known)
    check("T-tier-out-of-range", any("tier" in x for x in e), str(e))

    # T-bad-channel
    p = base_plan({"channel": "weird"})
    e, _ = validate_schema(p, ac_ids, known)
    check("T-bad-channel", any("channel" in x for x in e), str(e))

    # T-tier2-empty-sadpaths-fails (THE substance gate)
    p = base_plan()
    p["journeys"][0]["sad_paths"] = []
    e, _ = validate_schema(p, ac_ids, known)
    check("T-tier2-empty-sadpaths-fails", any("sad_path" in x for x in e), str(e))

    # T-tier1-empty-sadpaths-ok
    p = base_plan({"tier": 1})
    p["journeys"][0]["sad_paths"] = []
    e, _ = validate_schema(p, ac_ids, known)
    check("T-tier1-empty-sadpaths-ok", e == [], str(e))

    # T-journey-missing-personas
    p = base_plan()
    del p["journeys"][0]["personas"]
    e, _ = validate_schema(p, ac_ids, known)
    check("T-journey-missing-personas", any("personas" in x for x in e), str(e))

    # T-journey-empty-personas
    p = base_plan()
    p["journeys"][0]["personas"] = []
    e, _ = validate_schema(p, ac_ids, known)
    check("T-journey-empty-personas", any("personas" in x for x in e), str(e))

    # T-unknown-ac
    p = base_plan()
    p["journeys"][0]["acs"] = [999]
    e, _ = validate_schema(p, ac_ids, known)
    check("T-unknown-ac", any("999" in x for x in e), str(e))

    # T-empty-journeys-no-reason-fails
    p = base_plan({"journeys": []})
    e, _ = validate_schema(p, ac_ids, known)
    check("T-empty-journeys-no-reason-fails", any("no_surface_reason" in x for x in e), str(e))

    # T-empty-journeys-with-reason-ok
    p = base_plan({"journeys": [], "no_surface_reason": "internal refactor, no UI"})
    e, _ = validate_schema(p, ac_ids, known)
    check("T-empty-journeys-with-reason-ok", e == [], str(e))

    # T-unknown-journey-id-with-manifest-fails
    p = base_plan()
    p["journeys"][0]["id"] = "J99"
    e, _ = validate_schema(p, ac_ids, known)
    check("T-unknown-journey-id-with-manifest-fails", any("J99" in x for x in e), str(e))

    # T-journey-id-no-manifest-warns
    p = base_plan()
    p["journeys"][0]["id"] = "J99"
    e, w = validate_schema(p, ac_ids, None)
    check("T-journey-id-no-manifest-warns", e == [] and any("J99" in x for x in w), f"e={e} w={w}")

    # ---- Move A: synthesized journeys (not in the catalog manifest) ----

    def synth_plan(overrides: dict | None = None) -> dict:
        plan = {
            "pr_number": 4104, "sha": "svcmode0001", "tier": 2, "channel": "healthy",
            "journeys": [
                {"id": "S1", "synthesized": True, "name": "Author a booking field and save",
                 "personas": ["creator-pro"], "happy_path": ["add field", "save"],
                 "sad_paths": ["save with no manual edits"], "acs": [3],
                 "covers_surfaces": ["builder:create-booking-field"]}
            ],
        }
        if overrides:
            plan.update(overrides)
        return plan

    # T-synthesized-journey-ok — a synthesized id is accepted even WITH a manifest present
    e, _ = validate_schema(synth_plan(), {3}, {"J1", "J3"})
    check("T-synthesized-journey-ok", e == [], str(e))

    # T-synthesized-no-name-warns
    p = synth_plan()
    del p["journeys"][0]["name"]
    e, w = validate_schema(p, {3}, {"J1", "J3"})
    check("T-synthesized-no-name-warns", e == [] and any("no 'name'" in x for x in w), f"e={e} w={w}")

    # ---- Move A: changed-surface coverage gate ----

    # T-changed-surface-uncovered-fails
    p = synth_plan({"changed_surfaces": [{"id": "builder:create-booking-field", "reachable": True}]})
    p["journeys"][0]["covers_surfaces"] = []  # nothing covers it now
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-changed-surface-uncovered-fails", any("no journey covers it" in x for x in e), str(e))

    # T-changed-surface-covered-ok (via covers_surfaces)
    p = synth_plan({"changed_surfaces": [{"id": "builder:create-booking-field", "reachable": True}]})
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-changed-surface-covered-ok", e == [], str(e))

    # T-changed-surface-covered-by-id-ok (via surface.covered_by referencing a present journey)
    p = synth_plan({"changed_surfaces": [{"id": "x", "reachable": True, "covered_by": ["S1"]}]})
    p["journeys"][0]["covers_surfaces"] = []
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-changed-surface-covered-by-id-ok", e == [], str(e))

    # T-nonreachable-surface-waived
    p = synth_plan({"changed_surfaces": [{"id": "internal:refactor", "reachable": False}]})
    p["journeys"][0]["covers_surfaces"] = []
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-nonreachable-surface-waived", e == [], str(e))

    # ---- Move B: precondition / gate-activation gate ----

    # T-gated-surface-no-precondition-fails
    p = synth_plan({"changed_surfaces": [
        {"id": "builder:create-booking-field", "reachable": True, "gated_by": "pennant:services"}]})
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-gated-surface-no-precondition-fails", any("gated by 'pennant:services'" in x for x in e), str(e))

    # T-gated-surface-with-precondition-ok
    p = synth_plan({
        "changed_surfaces": [
            {"id": "builder:create-booking-field", "reachable": True, "gated_by": "pennant:services"}],
        "preconditions_activated": [{"gate": "pennant:services", "attested": True}],
    })
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-gated-surface-with-precondition-ok", e == [], str(e))

    # T-gated-surface-precondition-not-attested-fails
    p = synth_plan({
        "changed_surfaces": [
            {"id": "builder:create-booking-field", "reachable": True, "gated_by": "pennant:services"}],
        "preconditions_activated": [{"gate": "pennant:services", "attested": False}],
    })
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-gated-precondition-not-attested-fails", any("gated by 'pennant:services'" in x for x in e), str(e))

    # ---- Move A hardening: an authoring surface cannot be waived reachable:false ----

    # T-authoring-surface-reachable-false-fails
    p = synth_plan({"changed_surfaces": [
        {"id": "builder:create-booking-field", "kind": "authoring", "reachable": False}]})
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-authoring-reachable-false-fails", any("cannot be waived" in x for x in e), str(e))

    # T-authoring-surface-reachable-true-ok (covered by the synthesized journey)
    p = synth_plan({"changed_surfaces": [
        {"id": "builder:create-booking-field", "kind": "authoring", "reachable": True}]})
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-authoring-reachable-true-ok", e == [], str(e))

    # T-consumption-surface-may-be-waived (kind consumption + reachable false is fine)
    p = synth_plan({"changed_surfaces": [
        {"id": "x:downstream", "kind": "consumption", "reachable": False}]})
    p["journeys"][0]["covers_surfaces"] = []
    e, _ = validate_schema(p, {3}, {"J1", "J3"})
    check("T-consumption-waivable-ok", e == [], str(e))

    # ---- Move A hardening: authoring-class diff must declare a reachable authoring surface ----

    # T-crosscheck-form-requires-authoring — change_types includes 'form', no authoring surface => fail
    p = synth_plan({"changed_surfaces": [
        {"id": "x:downstream", "kind": "consumption", "reachable": True, "covered_by": ["S1"]}]})
    p["journeys"][0]["covers_surfaces"] = ["x:downstream"]
    e, _ = validate_schema(p, {3}, {"J1", "J3"}, change_types={"form"})
    check("T-crosscheck-form-requires-authoring", any("no reachable authoring surface" in x for x in e), str(e))

    # T-crosscheck-satisfied — same change_types but a reachable authoring surface present
    p = synth_plan({"changed_surfaces": [
        {"id": "builder:create-booking-field", "kind": "authoring", "reachable": True}]})
    e, _ = validate_schema(p, {3}, {"J1", "J3"}, change_types={"form", "feature_flag"})
    check("T-crosscheck-satisfied", e == [], str(e))

    # T-crosscheck-skipped-when-no-classification — change_types=None => no authoring requirement
    p = synth_plan({"changed_surfaces": [
        {"id": "x:downstream", "kind": "consumption", "reachable": True, "covered_by": ["S1"]}]})
    p["journeys"][0]["covers_surfaces"] = ["x:downstream"]
    e, _ = validate_schema(p, {3}, {"J1", "J3"}, change_types=None)
    check("T-crosscheck-skipped-no-classification", e == [], str(e))

    # T-crosscheck-non-authoring-changetype-ok — 'table' is not an authoring trigger
    e, _ = validate_schema(p, {3}, {"J1", "J3"}, change_types={"table"})
    check("T-crosscheck-non-authoring-changetype-ok", e == [], str(e))

    # T-no-changed-surfaces-backcompat — legacy plan without changed_surfaces unaffected
    e, _ = validate_schema(base_plan(), ac_ids, known)
    check("T-no-changed-surfaces-backcompat", e == [], str(e))

    # T-extract-frontmatter-ok
    md = '<!--json\n{"tier": 2}\n-->\nbody'
    check("T-extract-frontmatter-ok", extract_frontmatter(md) == {"tier": 2})

    # T-main-bad-usage
    check("T-main-bad-usage", main(["v.py"]) == 2)

    # T-main-missing-file
    check("T-main-missing-file", main(["v.py", "/tmp/nope-bvqx-vp2.md"]) == 1)

    # Journey-specific: Tier-3 plan for J5 with NO sad_path attestation must fail
    # (sad_paths is empty, tier >= 2 requires >=1 sad path per journey)
    j5_no_sad = {
        "pr_number": 1,
        "sha": "abc123def0",
        "tier": 3,
        "tier_rationale": "critical: transactions + grants",
        "channel": "healthy",
        "journeys": [
            {
                "id": "J5",
                "personas": ["finance-clerk"],
                "happy_path": ["record HAP payment", "submit"],
                "sad_paths": [],
                "acs": [1],
            }
        ],
    }
    e, _ = validate_schema(j5_no_sad, {1}, {"J5", "J2"})
    check("T-journey-J5-tier3-no-sad-path-fails",
          any("sad_path" in x for x in e),
          str(e))

    # Journey-specific: Tier-3 plan for J5 WITH sad_path_attestation per AC must pass
    j5_with_sad = {
        "pr_number": 1,
        "sha": "abc123def0",
        "tier": 3,
        "tier_rationale": "critical: transactions + grants",
        "channel": "healthy",
        "journeys": [
            {
                "id": "J5",
                "personas": ["finance-clerk"],
                "happy_path": ["record HAP payment", "submit"],
                "sad_paths": ["payment with no covering grant"],
                "acs": [1],
            }
        ],
    }
    e, _ = validate_schema(j5_with_sad, {1}, {"J5", "J2"})
    check("T-journey-J5-tier3-with-sad-path-passes", e == [], str(e))

    print()
    if failures:
        print(f"FAILED: {len(failures)} test(s): {', '.join(failures)}")
        return 1
    print("All tests passed.")
    return 0


def _load_known_journeys() -> set[str] | None:
    manifest = Path(__file__).resolve().parent.parent / "references" / "journeys.json"
    if not manifest.exists():
        return None
    try:
        data = json.loads(manifest.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return None
    return {j["id"] for j in data.get("journeys", []) if isinstance(j, dict) and "id" in j}


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] == "--test":
        return run_tests()
    if len(argv) != 2:
        print(f"Usage: {argv[0]} <path-to-plan.md>  OR  --test", file=sys.stderr)
        return 2

    plan_path = Path(argv[1])
    try:
        plan_md = plan_path.read_text(encoding="utf-8")
    except FileNotFoundError:
        print(f"FAIL: plan file not found: {plan_path}", file=sys.stderr)
        return 1
    try:
        plan = extract_frontmatter(plan_md)
    except ValueError as e:
        print(f"FAIL: {e}", file=sys.stderr)
        return 1

    ac_path = plan_path.parent / "acceptance-criteria.json"
    if not ac_path.exists():
        print(f"FAIL: {ac_path} not found", file=sys.stderr)
        return 1
    try:
        ac_data = json.loads(ac_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        print(f"FAIL: {ac_path} is not valid JSON: {e}", file=sys.stderr)
        return 1
    ac_ids = {c["id"] for c in ac_data.get("criteria", [])
              if isinstance(c, dict) and isinstance(c.get("id"), int)}

    # Optional: the diff classification, written next to plan.md by classify_diff.py.
    # Present → the authoring cross-check runs; absent → it is skipped (back-compat).
    change_types: set[str] | None = None
    cls_path = plan_path.parent / "diff-classification.json"
    if cls_path.exists():
        try:
            cls_data = json.loads(cls_path.read_text(encoding="utf-8"))
            change_types = {t for t in cls_data.get("change_types", []) if isinstance(t, str)}
        except json.JSONDecodeError:
            change_types = None

    errors, warnings = validate_schema(plan, ac_ids, _load_known_journeys(), change_types)

    for w in warnings:
        print(f"  ! {w}")
    if errors:
        print("Plan validation failed:", file=sys.stderr)
        for err in errors:
            print(f"  - {err}", file=sys.stderr)
        return 1
    print(f"Plan OK: tier {plan.get('tier')}, {len(plan.get('journeys', []))} journey(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
