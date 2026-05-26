#!/usr/bin/env python3
"""Validate plan.md's JSON frontmatter against the new per-case schema.

Usage:
    python3 validate_plan.py <path-to-plan.md>
    python3 validate_plan.py --test

Exits:
    0 = valid (warnings may be printed)
    1 = validation errors or file/parse error
    2 = bad usage (wrong number of args)

The companion acceptance-criteria.json must be in the same directory as plan.md.

Pure stdlib.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

FRONTMATTER_RE = re.compile(r"<!--json\s*\n(.*?)\n-->", re.DOTALL)

REQUIRED_TOP_LEVEL = ("pr_number", "sha", "generated_at", "change_types", "total_cases", "cases")
REQUIRED_CASE_FIELDS = ("id", "name", "acs", "mode", "setup", "verification_steps")
REQUIRED_STEP_FIELDS = ("id", "action", "expected", "evidence_type")

ALLOWED_MODES = {"browser", "pest-only"}
ALLOWED_EVIDENCE_TYPES = {"deterministic", "a11y_ref", "snapshot_diff", "screenshot_judgment"}
SCREENSHOT_FIELDS = {"selector", "callout_target", "callout_label", "evidence"}

SOFT_CAP_CASES = 12


def extract_frontmatter(plan_md: str) -> dict:
    m = FRONTMATTER_RE.search(plan_md)
    if not m:
        raise ValueError("No <!--json ... --> frontmatter block found in plan.md")
    try:
        return json.loads(m.group(1))
    except json.JSONDecodeError as e:
        raise ValueError(f"Frontmatter JSON is invalid: {e}") from e


def validate_schema(plan: dict, ac_ids: set[int]) -> tuple[list[str], list[str]]:
    """Return (errors, warnings). Errors cause exit 1; warnings are reported only."""
    errors: list[str] = []
    warnings: list[str] = []

    # --- top-level required fields ---
    for field in REQUIRED_TOP_LEVEL:
        if field not in plan:
            errors.append(f"plan missing required field '{field}'")

    if "change_types" in plan and not isinstance(plan["change_types"], list):
        errors.append(
            f"plan.change_types must be a list of strings; got {type(plan['change_types']).__name__}"
        )
    elif "change_types" in plan:
        for i, ct in enumerate(plan["change_types"]):
            if not isinstance(ct, str):
                errors.append(f"plan.change_types[{i}] must be a string; got {ct!r}")

    if "cases" not in plan:
        # already reported above; bail to avoid cascading errors
        return errors, warnings

    cases = plan["cases"]
    if not isinstance(cases, list):
        errors.append("plan.cases must be a list")
        return errors, warnings

    if "total_cases" in plan:
        expected_total = plan["total_cases"]
        if not isinstance(expected_total, int):
            errors.append(
                f"plan.total_cases must be an integer; got {type(expected_total).__name__}"
            )
        elif expected_total != len(cases):
            errors.append(
                f"plan.total_cases is {expected_total} but len(cases) is {len(cases)}; they must match"
            )

    if len(cases) > SOFT_CAP_CASES:
        warnings.append(
            f"plan has {len(cases)} cases; soft cap is {SOFT_CAP_CASES} "
            "(review may time out or produce an overwhelming artifact bundle)"
        )

    # --- per-case validation ---
    ids_seen: dict[str, int] = {}
    for i, case in enumerate(cases):
        if not isinstance(case, dict):
            errors.append(f"plan.cases[{i}] is not an object: {case!r}")
            continue

        cid = case.get("id", f"<missing id at index {i}>")

        for field in REQUIRED_CASE_FIELDS:
            if field not in case:
                errors.append(f"case {cid} missing required field '{field}'")

        # duplicate id check
        if "id" in case:
            if cid in ids_seen:
                errors.append(
                    f"duplicate case id '{cid}' at cases[{i}] and cases[{ids_seen[cid]}]"
                )
            else:
                ids_seen[cid] = i

        # mode check
        if "mode" in case:
            if case["mode"] not in ALLOWED_MODES:
                errors.append(
                    f"case {cid} mode '{case['mode']}' is not valid; must be one of {sorted(ALLOWED_MODES)}"
                )

        # acs check
        if "acs" in case:
            acs_val = case["acs"]
            if not isinstance(acs_val, list):
                errors.append(f"case {cid} acs must be a list; got {type(acs_val).__name__}")
            else:
                for ac in acs_val:
                    if ac == "implicit":
                        continue
                    if not isinstance(ac, int):
                        errors.append(
                            f"case {cid} acs entry {ac!r} must be an integer or \"implicit\""
                        )
                    elif ac not in ac_ids:
                        errors.append(
                            f"case {cid} references AC {ac} which does not exist in acceptance-criteria.json"
                        )

        # verification_steps check
        if "verification_steps" in case:
            steps = case["verification_steps"]
            if not isinstance(steps, list):
                errors.append(
                    f"case {cid} verification_steps must be a list; got {type(steps).__name__}"
                )
            elif len(steps) == 0:
                errors.append(
                    f"case {cid} verification_steps must not be empty"
                )
            else:
                for j, step in enumerate(steps):
                    if not isinstance(step, dict):
                        errors.append(
                            f"case {cid} verification_steps[{j}] is not an object: {step!r}"
                        )
                        continue
                    sid = step.get("id", f"<missing id at step index {j}>")
                    for field in REQUIRED_STEP_FIELDS:
                        if field not in step:
                            errors.append(
                                f"case {cid} step {sid} missing required field '{field}'"
                            )
                    if "evidence_type" in step and step["evidence_type"] not in ALLOWED_EVIDENCE_TYPES:
                        errors.append(
                            f"case {cid} step {sid} evidence_type '{step['evidence_type']}' is not valid; "
                            f"must be one of {sorted(ALLOWED_EVIDENCE_TYPES)}"
                        )

        # screenshot check (only browser mode)
        mode = case.get("mode")
        if mode == "browser":
            if "screenshot" not in case:
                errors.append(
                    f"case {cid} is mode=browser and is missing required 'screenshot' field"
                )
            else:
                shot = case["screenshot"]
                if not isinstance(shot, dict):
                    errors.append(
                        f"case {cid} screenshot must be an object; got {type(shot).__name__}"
                    )
                elif "none" in shot:
                    extra = SCREENSHOT_FIELDS & shot.keys()
                    if extra:
                        errors.append(
                            f"case {cid} screenshot has 'none' AND populated {sorted(extra)}; use one or the other"
                        )
                    elif not shot["none"]:
                        errors.append(
                            f"case {cid} screenshot.none is empty; provide a reason string"
                        )
                else:
                    missing = SCREENSHOT_FIELDS - shot.keys()
                    if missing:
                        errors.append(
                            f"case {cid} screenshot is missing required fields: {sorted(missing)}"
                        )

    return errors, warnings


def run_tests() -> int:
    """Self-tests. Returns 0 if all pass, 1 if any fail."""
    results: list[tuple[str, bool, str]] = []

    def check(name: str, passed: bool, detail: str = "") -> None:
        results.append((name, passed, detail))
        status = "PASS" if passed else "FAIL"
        suffix = f"  ({detail})" if detail and not passed else ""
        print(f"  {name} ... {status}{suffix}")

    # Minimal valid plan + 1 case + 1 step
    def _minimal_plan(overrides: dict | None = None) -> dict:
        plan = {
            "pr_number": 87,
            "sha": "abc123def0",
            "generated_at": "2026-05-23T14:00:00Z",
            "change_types": ["form"],
            "total_cases": 1,
            "cases": [
                {
                    "id": "1.1",
                    "name": "Happy path",
                    "acs": [1],
                    "mode": "browser",
                    "setup": ["login as test@example.com"],
                    "verification_steps": [
                        {
                            "id": "step-1.1.1",
                            "action": "submit form",
                            "expected": "success toast",
                            "evidence_type": "deterministic",
                        }
                    ],
                    "screenshot": {
                        "selector": ".fi-section",
                        "callout_target": ".fi-section-header",
                        "callout_label": "Success state",
                        "evidence": "Saved",
                    },
                }
            ],
        }
        if overrides:
            plan.update(overrides)
        return plan

    ac_ids: set[int] = {1, 2}

    # T-fm-ok
    name = "T-fm-ok"
    plan = _minimal_plan()
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, errors == [], str(errors))

    # T-fm-missing-pr_number
    name = "T-fm-missing-pr_number"
    plan = _minimal_plan()
    del plan["pr_number"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("pr_number" in e for e in errors), str(errors))

    # T-fm-missing-change_types
    name = "T-fm-missing-change_types"
    plan = _minimal_plan()
    del plan["change_types"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("change_types" in e for e in errors), str(errors))

    # T-fm-change_types-not-list
    name = "T-fm-change_types-not-list"
    plan = _minimal_plan({"change_types": "form"})
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("change_types" in e for e in errors), str(errors))

    # T-fm-total-mismatch
    name = "T-fm-total-mismatch"
    plan = _minimal_plan({"total_cases": 3})  # but len(cases) == 1
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("total_cases" in e or "len(cases)" in e for e in errors), str(errors))

    # T-case-missing-acs
    name = "T-case-missing-acs"
    plan = _minimal_plan()
    del plan["cases"][0]["acs"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'acs'" in e for e in errors), str(errors))

    # T-case-missing-mode
    name = "T-case-missing-mode"
    plan = _minimal_plan()
    del plan["cases"][0]["mode"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'mode'" in e for e in errors), str(errors))

    # T-case-bad-mode
    name = "T-case-bad-mode"
    plan = _minimal_plan()
    plan["cases"][0]["mode"] = "foo"
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("foo" in e for e in errors), str(errors))

    # T-case-duplicate-id
    name = "T-case-duplicate-id"
    plan = _minimal_plan({"total_cases": 2})
    import copy
    plan["cases"].append(copy.deepcopy(plan["cases"][0]))
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("duplicate case id" in e for e in errors), str(errors))

    # T-case-unknown-ac
    name = "T-case-unknown-ac"
    plan = _minimal_plan()
    plan["cases"][0]["acs"] = [999]
    errors, warnings = validate_schema(plan, {1, 2})
    check(name, any("AC 999" in e for e in errors), str(errors))

    # T-case-implicit-ac-allowed
    name = "T-case-implicit-ac-allowed"
    plan = _minimal_plan()
    plan["cases"][0]["acs"] = ["implicit"]
    errors, warnings = validate_schema(plan, set())  # empty ac_ids
    check(name, errors == [], str(errors))

    # T-case-browser-missing-screenshot
    name = "T-case-browser-missing-screenshot"
    plan = _minimal_plan()
    del plan["cases"][0]["screenshot"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("screenshot" in e for e in errors), str(errors))

    # T-case-pest-only-no-screenshot-ok
    name = "T-case-pest-only-no-screenshot-ok"
    plan = _minimal_plan()
    plan["cases"][0]["mode"] = "pest-only"
    del plan["cases"][0]["screenshot"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, errors == [], str(errors))

    # T-screenshot-none-with-reason
    name = "T-screenshot-none-with-reason"
    plan = _minimal_plan()
    plan["cases"][0]["screenshot"] = {"none": "state-only check, no visual diff needed"}
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, errors == [], str(errors))

    # T-screenshot-none-with-extra
    name = "T-screenshot-none-with-extra"
    plan = _minimal_plan()
    plan["cases"][0]["screenshot"] = {"none": "x", "selector": ".y"}
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("none" in e and ("AND" in e or "one or the other" in e) for e in errors), str(errors))

    # T-step-missing-evidence_type
    name = "T-step-missing-evidence_type"
    plan = _minimal_plan()
    del plan["cases"][0]["verification_steps"][0]["evidence_type"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("evidence_type" in e for e in errors), str(errors))

    # T-step-bad-evidence_type
    name = "T-step-bad-evidence_type"
    plan = _minimal_plan()
    plan["cases"][0]["verification_steps"][0]["evidence_type"] = "bogus"
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("bogus" in e for e in errors), str(errors))

    # T-step-missing-action
    name = "T-step-missing-action"
    plan = _minimal_plan()
    del plan["cases"][0]["verification_steps"][0]["action"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'action'" in e for e in errors), str(errors))

    # T-soft-cap-warns
    name = "T-soft-cap-warns"
    many_cases = []
    for n in range(1, SOFT_CAP_CASES + 2):  # 13 cases
        many_cases.append({
            "id": f"{n}.1",
            "name": f"Case {n}",
            "acs": [1],
            "mode": "pest-only",
            "setup": ["login"],
            "verification_steps": [
                {
                    "id": f"step-{n}.1.1",
                    "action": "do thing",
                    "expected": "thing done",
                    "evidence_type": "deterministic",
                }
            ],
        })
    plan = {
        "pr_number": 87,
        "sha": "abc123def0",
        "generated_at": "2026-05-23T14:00:00Z",
        "change_types": ["form"],
        "total_cases": len(many_cases),
        "cases": many_cases,
    }
    errors, warnings = validate_schema(plan, {1})
    check(
        name,
        errors == [] and any("soft cap" in w or str(SOFT_CAP_CASES) in w for w in warnings),
        f"errors={errors}, warnings={warnings}",
    )

    # T-empty-steps
    name = "T-empty-steps"
    plan = _minimal_plan()
    plan["cases"][0]["verification_steps"] = []
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("must not be empty" in e or "empty" in e for e in errors), str(errors))

    # --- extra tests (beyond the 20 required) ---

    # T-extract-frontmatter-ok
    name = "T-extract-frontmatter-ok"
    md = '<!--json\n{"pr_number": 42}\n-->\n# body'
    result = extract_frontmatter(md)
    check(name, result == {"pr_number": 42}, str(result))

    # T-extract-frontmatter-missing
    name = "T-extract-frontmatter-missing"
    try:
        extract_frontmatter("# just a body, no frontmatter")
        check(name, False, "should have raised ValueError")
    except ValueError as e:
        check(name, "No <!--json" in str(e), str(e))

    # T-extract-frontmatter-bad-json
    name = "T-extract-frontmatter-bad-json"
    try:
        extract_frontmatter("<!--json\n{not valid json}\n-->")
        check(name, False, "should have raised ValueError")
    except ValueError as e:
        check(name, "invalid" in str(e).lower(), str(e))

    # T-case-missing-setup
    name = "T-case-missing-setup"
    plan = _minimal_plan()
    del plan["cases"][0]["setup"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'setup'" in e for e in errors), str(errors))

    # T-case-missing-verification_steps
    name = "T-case-missing-verification_steps"
    plan = _minimal_plan()
    del plan["cases"][0]["verification_steps"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'verification_steps'" in e for e in errors), str(errors))

    # T-case-acs-not-list
    name = "T-case-acs-not-list"
    plan = _minimal_plan()
    plan["cases"][0]["acs"] = 1  # should be a list
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("acs must be a list" in e for e in errors), str(errors))

    # T-step-missing-expected
    name = "T-step-missing-expected"
    plan = _minimal_plan()
    del plan["cases"][0]["verification_steps"][0]["expected"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'expected'" in e for e in errors), str(errors))

    # T-step-missing-id
    name = "T-step-missing-id"
    plan = _minimal_plan()
    del plan["cases"][0]["verification_steps"][0]["id"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'id'" in e for e in errors), str(errors))

    # T-screenshot-missing-one-field
    name = "T-screenshot-missing-one-field"
    plan = _minimal_plan()
    del plan["cases"][0]["screenshot"]["evidence"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("screenshot" in e and "evidence" in e for e in errors), str(errors))

    # T-fm-missing-sha
    name = "T-fm-missing-sha"
    plan = _minimal_plan()
    del plan["sha"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'sha'" in e for e in errors), str(errors))

    # T-fm-missing-generated_at
    name = "T-fm-missing-generated_at"
    plan = _minimal_plan()
    del plan["generated_at"]
    errors, warnings = validate_schema(plan, ac_ids)
    check(name, any("'generated_at'" in e for e in errors), str(errors))

    # T-main-bad-usage-exits-2
    name = "T-main-bad-usage-exits-2"
    check(name, main(["validate_plan.py"]) == 2 and main(["validate_plan.py", "a", "b"]) == 2)

    # T-main-missing-plan-exits-1
    name = "T-main-missing-plan-exits-1"
    check(name, main(["validate_plan.py", "/tmp/does-not-exist-bvqx-validate-plan.md"]) == 1)

    failed = [n for n, passed, _ in results if not passed]
    total = len(results)
    passed_count = total - len(failed)
    print(f"\n{passed_count}/{total} tests passed.")
    if failed:
        print(f"FAILED: {', '.join(failed)}")
        return 1
    return 0


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
    if not isinstance(ac_data, dict):
        print(f"FAIL: {ac_path} must be an object with 'criteria' key", file=sys.stderr)
        return 1

    criteria = ac_data.get("criteria", [])
    ac_ids: set[int] = {
        c["id"] for c in criteria if isinstance(c, dict) and isinstance(c.get("id"), int)
    }

    errors, warnings = validate_schema(plan, ac_ids)

    if warnings:
        print("Warnings:")
        for w in warnings:
            print(f"  ! {w}")

    if errors:
        print("Plan validation failed:", file=sys.stderr)
        for err in errors:
            print(f"  - {err}", file=sys.stderr)
        return 1

    case_count = len(plan.get("cases", []))
    print(f"Plan OK: {case_count} case(s), pr_number={plan.get('pr_number')}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
