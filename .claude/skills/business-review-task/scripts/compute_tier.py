#!/usr/bin/env python3
"""Compute the review tier (0-3) for a business-review-v2 run from blast radius.

Usage:
    python3 compute_tier.py <REVIEW_DIR>
    python3 compute_tier.py --test

Reads:
    <REVIEW_DIR>/diff-classification.json   (output of classify_diff.py)
    <REVIEW_DIR>/acceptance-criteria.json   (output of extract_ac.py)

Writes:
    <REVIEW_DIR>/tier.json

Tier precedence (highest wins):
    3 Critical : any critical subsystem touched, OR >= 3 subsystems (wide diff)
    2 Broad    : >= 3 change_types, OR >= 3 acceptance criteria, OR exactly 2 subsystems
    1 Narrow   : >= 1 change_type OR >= 1 acceptance criterion
    0 Trivial  : nothing user-facing detected

Exit codes: 0 ok, 1 error, 2 bad usage. Pure stdlib.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path


def compute_tier(classification: dict, ac_count: int) -> tuple[int, str]:
    """Return (tier, human-readable rationale)."""
    change_types = classification.get("change_types", [])
    subsystems = classification.get("subsystems", [])
    critical = classification.get("critical_subsystems", [])

    ct_count = len(change_types)
    sub_count = len(subsystems)

    if critical:
        return 3, f"critical: subsystem(s) {', '.join(critical)} touched"
    if sub_count >= 3:
        return 3, f"critical: wide multi-subsystem diff ({', '.join(subsystems)})"

    if ct_count >= 3 or ac_count >= 3 or sub_count == 2:
        reasons: list[str] = []
        if ct_count >= 3:
            reasons.append(f"{ct_count} change types")
        if ac_count >= 3:
            reasons.append(f"{ac_count} acceptance criteria")
        if sub_count == 2:
            reasons.append(f"2 subsystems ({', '.join(subsystems)})")
        return 2, "broad: " + "; ".join(reasons)

    if ct_count >= 1 or ac_count >= 1:
        return 1, f"narrow: {ct_count} change type(s), {ac_count} AC, {sub_count} subsystem(s)"

    return 0, "trivial: no user-facing change detected"


def _load_ac_count(review_dir: Path) -> int:
    ac_path = review_dir / "acceptance-criteria.json"
    if not ac_path.exists():
        return 0
    data = json.loads(ac_path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        return 0
    return len(data.get("criteria", []))


def run_tests() -> int:
    failures: list[str] = []

    def check(name: str, condition: bool, detail: str = "") -> None:
        if condition:
            print(f"  {name} ... PASS")
        else:
            print(f"  {name} ... FAIL: {detail}")
            failures.append(name)

    # T-critical-transactions (Journey: billing -> transactions)
    tier, why = compute_tier({"change_types": ["form"], "subsystems": ["transactions", "households"],
                              "critical_subsystems": ["transactions"]}, 1)
    check("T-critical-transactions", tier == 3, f"got {tier} ({why})")

    # T-critical-overrides-low-counts: critical wins even with 1 change_type, 0 AC (Journey: auth -> grants)
    tier, _ = compute_tier({"change_types": ["form"], "subsystems": ["grants"],
                            "critical_subsystems": ["grants"]}, 0)
    check("T-critical-overrides-low-counts", tier == 3, f"got {tier}")

    # T-wide-three-subsystems (Journey: forms/ai/notifications -> households/inspections/documents)
    tier, _ = compute_tier({"change_types": ["form"], "subsystems": ["households", "inspections", "documents"],
                            "critical_subsystems": []}, 1)
    check("T-wide-three-subsystems", tier == 3, f"got {tier}")

    # T-broad-three-acs
    tier, _ = compute_tier({"change_types": ["form"], "subsystems": ["households"],
                            "critical_subsystems": []}, 3)
    check("T-broad-three-acs", tier == 2, f"got {tier}")

    # T-broad-two-subsystems (Journey: forms/ai -> households/inspections)
    tier, _ = compute_tier({"change_types": ["form"], "subsystems": ["households", "inspections"],
                            "critical_subsystems": []}, 1)
    check("T-broad-two-subsystems", tier == 2, f"got {tier}")

    # T-broad-three-change-types
    tier, _ = compute_tier({"change_types": ["modal", "form", "validation"], "subsystems": ["households"],
                            "critical_subsystems": []}, 1)
    check("T-broad-three-change-types", tier == 2, f"got {tier}")

    # T-narrow-one-change-type
    tier, _ = compute_tier({"change_types": ["blade"], "subsystems": [],
                            "critical_subsystems": []}, 0)
    check("T-narrow-one-change-type", tier == 1, f"got {tier}")

    # T-narrow-one-ac-no-change-type
    tier, _ = compute_tier({"change_types": [], "subsystems": [],
                            "critical_subsystems": []}, 1)
    check("T-narrow-one-ac-no-change-type", tier == 1, f"got {tier}")

    # T-trivial-empty
    tier, _ = compute_tier({"change_types": [], "subsystems": [],
                            "critical_subsystems": []}, 0)
    check("T-trivial-empty", tier == 0, f"got {tier}")

    # T-rationale-nonempty (Journey: billing -> transactions)
    _, why = compute_tier({"change_types": ["form"], "subsystems": ["transactions"],
                           "critical_subsystems": ["transactions"]}, 1)
    check("T-rationale-nonempty", isinstance(why, str) and len(why) > 0, f"got {why!r}")

    # T-main-bad-usage
    check("T-main-bad-usage", main(["compute_tier.py"]) == 2 and main(["compute_tier.py", "a", "b"]) == 2)

    # T-main-missing-dir
    check("T-main-missing-dir", main(["compute_tier.py", "/tmp/nope-bvqx-compute-tier"]) == 1)

    # T-main-end-to-end
    import tempfile
    with tempfile.TemporaryDirectory() as tmp:
        d = Path(tmp)
        (d / "diff-classification.json").write_text(json.dumps({
            "change_types": ["form", "validation"], "subsystems": ["households", "inspections"],
            "critical_subsystems": [],
        }))
        (d / "acceptance-criteria.json").write_text(json.dumps({
            "criteria": [{"id": 1}, {"id": 2}, {"id": 3}, {"id": 4}]
        }))
        rc = main(["compute_tier.py", str(d)])
        out = json.loads((d / "tier.json").read_text())
        check("T-main-end-to-end",
              rc == 0 and out["tier"] == 2 and out["ac_count"] == 4 and out["subsystems"] == ["households", "inspections"],
              f"rc={rc} out={out}")

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
    class_path = review_dir / "diff-classification.json"
    if not class_path.exists():
        print(f"FAIL: {class_path} not found (run classify_diff.py first)", file=sys.stderr)
        return 1
    try:
        classification = json.loads(class_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        print(f"FAIL: {class_path} is not valid JSON: {exc}", file=sys.stderr)
        return 1

    ac_count = _load_ac_count(review_dir)
    tier, rationale = compute_tier(classification, ac_count)

    out = {
        "tier": tier,
        "rationale": rationale,
        "change_types": classification.get("change_types", []),
        "subsystems": classification.get("subsystems", []),
        "critical_subsystems": classification.get("critical_subsystems", []),
        "ac_count": ac_count,
    }
    (review_dir / "tier.json").write_text(json.dumps(out, indent=2), encoding="utf-8")
    print(f"Tier {tier}: {rationale}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
