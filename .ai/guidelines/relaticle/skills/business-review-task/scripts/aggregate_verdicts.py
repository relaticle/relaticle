#!/usr/bin/env python3
"""Aggregate per-case verdict.json files into verdict-final.json.

Usage:
    python3 aggregate_verdicts.py <REVIEW_DIR>
    python3 aggregate_verdicts.py --test

Reads:
    <REVIEW_DIR>/case*/verdict.json
    <REVIEW_DIR>/acceptance-criteria.json
    <REVIEW_DIR>/plan.md (for change_types frontmatter)

Writes:
    <REVIEW_DIR>/verdict-final.json

Pure stdlib. No penalty math — agent confidence is used as-is.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

FRONTMATTER_RE = re.compile(r"<!--json\s*\n(.*?)\n-->", re.DOTALL)


# ---------------------------------------------------------------------------
# Core helpers
# ---------------------------------------------------------------------------


def read_case_confidence(case_verdict: dict) -> int:
    """Validate and return confidence from a case verdict dict.

    Raises ValueError if confidence is missing, not an int, or out of 0-100 range.
    """
    if "confidence" not in case_verdict:
        raise ValueError("verdict missing 'confidence' field")
    confidence = case_verdict["confidence"]
    if not isinstance(confidence, int) or isinstance(confidence, bool):
        raise ValueError(
            f"confidence must be an int, got {type(confidence).__name__!r}: {confidence!r}"
        )
    if confidence < 0 or confidence > 100:
        raise ValueError(f"confidence out of range 0-100: {confidence}")
    return confidence


def derive_label(confidences: list[int], flaky_count: int) -> tuple[str, str]:
    """Return (label, rationale) deterministically from confidence scores."""
    if not confidences:
        return ("ai-needs-human", "No cases executed (infra-only or empty plan?)")
    if min(confidences) < 60 or flaky_count >= 3:
        return (
            "ai-rejected",
            f"min confidence {min(confidences)}, {flaky_count} flaky",
        )
    if min(confidences) < 75 or flaky_count >= 1:
        return (
            "ai-needs-human",
            f"min confidence {min(confidences)}, {flaky_count} flaky",
        )
    return (
        "ai-approved",
        f"all {len(confidences)} cases ≥75 confidence, no flaky",
    )


def extract_change_types_from_plan(plan_md_path: Path) -> list[str]:
    """Read JSON frontmatter from plan.md and return the change_types list.

    Returns an empty list if the field is absent or the frontmatter is missing.
    """
    if not plan_md_path.exists():
        return []
    text = plan_md_path.read_text(encoding="utf-8")
    match = FRONTMATTER_RE.search(text)
    if not match:
        return []
    try:
        data = json.loads(match.group(1))
    except json.JSONDecodeError:
        return []
    return data.get("change_types", [])


# ---------------------------------------------------------------------------
# Evidence / step helpers
# ---------------------------------------------------------------------------


def _tally_evidence(steps: list[dict]) -> dict[str, int]:
    """Count occurrences of each evidence_type across a list of steps."""
    counts: dict[str, int] = {}
    for step in steps:
        evidence_type = step.get("evidence_type")
        if evidence_type:
            counts[evidence_type] = counts.get(evidence_type, 0) + 1
    return counts


def _collect_health_gate_failures(case_id: str, health_gates: dict) -> list[dict]:
    """Return failure entries from a health_gates dict."""
    failures = []
    for gate_key, status in health_gates.items():
        if status != "pass":
            failures.append({"case_id": case_id, "gate": gate_key, "status": status})
    return failures


# ---------------------------------------------------------------------------
# Main aggregation
# ---------------------------------------------------------------------------


def aggregate(review_dir: Path) -> dict:
    """Read case*/verdict.json + plan.md, produce verdict-final.json shape."""
    case_files = sorted(review_dir.glob("case*/verdict.json"))
    verdicts = []
    for path in case_files:
        try:
            verdicts.append(json.loads(path.read_text(encoding="utf-8")))
        except json.JSONDecodeError as exc:
            raise ValueError(f"Invalid JSON in {path}: {exc}") from exc

    confidences: list[int] = []
    flaky_cases: list[str] = []
    case_summary_rows: list[dict] = []
    evidence_summary: dict[str, int] = {}
    iter_distribution = {"iter_1": 0, "iter_2": 0, "iter_3": 0}
    health_gate_failures: list[dict] = []

    for v in verdicts:
        case_id = v.get("case_id", "?")

        confidence = read_case_confidence(v)
        confidences.append(confidence)

        flaky = bool(v.get("flaky", False))
        if flaky:
            flaky_cases.append(case_id)

        iter_count = int(v.get("iter_count", 1))
        case_summary_rows.append(
            {
                "case_id": case_id,
                "confidence": confidence,
                "flaky": flaky,
                "iter_count": iter_count,
            }
        )

        # Evidence tally
        for etype, count in _tally_evidence(v.get("steps", [])).items():
            evidence_summary[etype] = evidence_summary.get(etype, 0) + count

        # Iter distribution (cap at iter_3)
        iter_key = f"iter_{min(iter_count, 3)}"
        iter_distribution[iter_key] = iter_distribution.get(iter_key, 0) + 1

        # Health gate failures
        health_gates = v.get("health_gates", {})
        if health_gates:
            health_gate_failures.extend(
                _collect_health_gate_failures(case_id, health_gates)
            )

    avg_confidence = (
        round(sum(confidences) / len(confidences), 1) if confidences else 0.0
    )

    change_types = extract_change_types_from_plan(review_dir / "plan.md")
    label, rationale = derive_label(confidences, len(flaky_cases))

    return {
        "label": label,
        "avg_confidence": avg_confidence,
        "case_summary": case_summary_rows,
        "change_types": change_types,
        "evidence_summary": evidence_summary,
        "iter_distribution": iter_distribution,
        "health_gate_failures": health_gate_failures,
        "flaky_cases": flaky_cases,
        "rationale": rationale,
    }


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------


def run_tests() -> int:
    import tempfile

    failures: list[str] = []

    def ok(name: str) -> None:
        print(f"  {name} ... PASS")

    def fail(name: str, detail: str) -> None:
        print(f"  {name} ... FAIL: {detail}")
        failures.append(name)

    def check(name: str, condition: bool, detail: str = "") -> None:
        if condition:
            ok(name)
        else:
            fail(name, detail or "assertion failed")

    print("Running aggregate_verdicts tests...\n")

    # T-read-confidence-ok
    try:
        result = read_case_confidence({"confidence": 87})
        check("T-read-confidence-ok", result == 87, f"got {result}")
    except Exception as exc:
        fail("T-read-confidence-ok", str(exc))

    # T-read-confidence-out-of-range
    try:
        read_case_confidence({"confidence": 110})
        fail("T-read-confidence-out-of-range", "expected ValueError, none raised")
    except ValueError:
        ok("T-read-confidence-out-of-range")
    except Exception as exc:
        fail("T-read-confidence-out-of-range", f"wrong exception: {exc}")

    # T-read-confidence-non-int
    try:
        read_case_confidence({"confidence": "high"})
        fail("T-read-confidence-non-int", "expected ValueError, none raised")
    except ValueError:
        ok("T-read-confidence-non-int")
    except Exception as exc:
        fail("T-read-confidence-non-int", f"wrong exception: {exc}")

    # T-label-all-approved
    try:
        label, _ = derive_label([80, 90, 95], 0)
        check("T-label-all-approved", label == "ai-approved", f"got {label!r}")
    except Exception as exc:
        fail("T-label-all-approved", str(exc))

    # T-label-needs-human-low-conf
    try:
        label, _ = derive_label([80, 70, 95], 0)
        check(
            "T-label-needs-human-low-conf",
            label == "ai-needs-human",
            f"got {label!r}",
        )
    except Exception as exc:
        fail("T-label-needs-human-low-conf", str(exc))

    # T-label-needs-human-one-flaky
    try:
        label, _ = derive_label([85, 90, 80], 1)
        check(
            "T-label-needs-human-one-flaky",
            label == "ai-needs-human",
            f"got {label!r}",
        )
    except Exception as exc:
        fail("T-label-needs-human-one-flaky", str(exc))

    # T-label-rejected-low-conf
    try:
        label, _ = derive_label([80, 55, 95], 0)
        check(
            "T-label-rejected-low-conf", label == "ai-rejected", f"got {label!r}"
        )
    except Exception as exc:
        fail("T-label-rejected-low-conf", str(exc))

    # T-label-rejected-many-flaky
    try:
        label, _ = derive_label([90, 85, 80], 3)
        check(
            "T-label-rejected-many-flaky", label == "ai-rejected", f"got {label!r}"
        )
    except Exception as exc:
        fail("T-label-rejected-many-flaky", str(exc))

    # T-label-empty-cases
    try:
        label, rationale = derive_label([], 0)
        check(
            "T-label-empty-cases",
            label == "ai-needs-human" and "no cases" in rationale.lower(),
            f"got label={label!r} rationale={rationale!r}",
        )
    except Exception as exc:
        fail("T-label-empty-cases", str(exc))

    # T-extract-change-types
    with tempfile.TemporaryDirectory() as tmp:
        plan = Path(tmp) / "plan.md"
        plan.write_text(
            '<!--json\n{"change_types": ["modal", "form"]}\n-->\nbody',
            encoding="utf-8",
        )
        try:
            result = extract_change_types_from_plan(plan)
            check(
                "T-extract-change-types",
                result == ["modal", "form"],
                f"got {result!r}",
            )
        except Exception as exc:
            fail("T-extract-change-types", str(exc))

    # T-extract-change-types-missing
    with tempfile.TemporaryDirectory() as tmp:
        plan = Path(tmp) / "plan.md"
        plan.write_text('<!--json\n{"pr": 1}\n-->\nbody', encoding="utf-8")
        try:
            result = extract_change_types_from_plan(plan)
            check(
                "T-extract-change-types-missing",
                result == [],
                f"got {result!r}",
            )
        except Exception as exc:
            fail("T-extract-change-types-missing", str(exc))

    # T-aggregate-end-to-end
    with tempfile.TemporaryDirectory() as tmp:
        review_dir = Path(tmp)
        (review_dir / "plan.md").write_text(
            '<!--json\n{"pr": 1, "change_types": ["modal"]}\n-->\nbody',
            encoding="utf-8",
        )
        (review_dir / "acceptance-criteria.json").write_text(
            json.dumps({"criteria": [{"id": 1, "text": "x"}, {"id": 2, "text": "y"}]})
        )
        case1 = review_dir / "case1"
        case1.mkdir()
        (case1 / "verdict.json").write_text(
            json.dumps({
                "case_id": "1.1",
                "case_name": "Modal open",
                "acs": [1],
                "mode": "browser",
                "iter_count": 1,
                "steps": [
                    {"id": "step-1.1.1", "result": "STEP_PASS", "evidence_type": "snapshot_diff"},
                ],
                "confidence": 85,
                "flaky": False,
                "rationale": "Passed cleanly.",
                "health_gates": {},
            })
        )
        case2 = review_dir / "case2"
        case2.mkdir()
        (case2 / "verdict.json").write_text(
            json.dumps({
                "case_id": "2.1",
                "case_name": "Modal close",
                "acs": [2],
                "mode": "browser",
                "iter_count": 3,
                "steps": [
                    {"id": "step-2.1.1", "result": "STEP_PASS", "evidence_type": "screenshot_judgment"},
                ],
                "confidence": 76,
                "flaky": True,
                "rationale": "Passed at iter 3.",
                "health_gates": {},
            })
        )
        try:
            result = aggregate(review_dir)
            check(
                "T-aggregate-end-to-end",
                result["label"] == "ai-needs-human"
                and result["evidence_summary"].get("snapshot_diff") == 1
                and result["evidence_summary"].get("screenshot_judgment") == 1
                and result["iter_distribution"]["iter_1"] == 1
                and result["iter_distribution"]["iter_3"] == 1
                and result["change_types"] == ["modal"]
                and result["flaky_cases"] == ["2.1"],
                f"label={result['label']!r} evidence={result['evidence_summary']!r} "
                f"iter={result['iter_distribution']!r} flaky={result['flaky_cases']!r}",
            )
        except Exception as exc:
            fail("T-aggregate-end-to-end", str(exc))

    # T-aggregate-evidence-summary
    with tempfile.TemporaryDirectory() as tmp:
        review_dir = Path(tmp)
        (review_dir / "plan.md").write_text('<!--json\n{"pr": 1}\n-->\nbody')
        case1 = review_dir / "case1"
        case1.mkdir()
        (case1 / "verdict.json").write_text(
            json.dumps({
                "case_id": "1.1",
                "iter_count": 1,
                "steps": [
                    {"id": "s1", "result": "STEP_PASS", "evidence_type": "a11y_ref"},
                    {"id": "s2", "result": "STEP_PASS", "evidence_type": "a11y_ref"},
                    {"id": "s3", "result": "STEP_PASS", "evidence_type": "deterministic"},
                ],
                "confidence": 90,
                "flaky": False,
                "health_gates": {},
            })
        )
        try:
            result = aggregate(review_dir)
            check(
                "T-aggregate-evidence-summary",
                result["evidence_summary"] == {"a11y_ref": 2, "deterministic": 1},
                f"got {result['evidence_summary']!r}",
            )
        except Exception as exc:
            fail("T-aggregate-evidence-summary", str(exc))

    # T-aggregate-iter-distribution
    with tempfile.TemporaryDirectory() as tmp:
        review_dir = Path(tmp)
        (review_dir / "plan.md").write_text('<!--json\n{"pr": 1}\n-->\nbody')
        for i, iter_count in enumerate([1, 2, 3], start=1):
            case_dir = review_dir / f"case{i}"
            case_dir.mkdir()
            (case_dir / "verdict.json").write_text(
                json.dumps({
                    "case_id": f"{i}.1",
                    "iter_count": iter_count,
                    "steps": [],
                    "confidence": 80,
                    "flaky": False,
                    "health_gates": {},
                })
            )
        try:
            result = aggregate(review_dir)
            dist = result["iter_distribution"]
            check(
                "T-aggregate-iter-distribution",
                dist == {"iter_1": 1, "iter_2": 1, "iter_3": 1},
                f"got {dist!r}",
            )
        except Exception as exc:
            fail("T-aggregate-iter-distribution", str(exc))

    # T-aggregate-health-gate-failures
    with tempfile.TemporaryDirectory() as tmp:
        review_dir = Path(tmp)
        (review_dir / "plan.md").write_text('<!--json\n{"pr": 1}\n-->\nbody')
        case1 = review_dir / "case1"
        case1.mkdir()
        (case1 / "verdict.json").write_text(
            json.dumps({
                "case_id": "1.1",
                "iter_count": 1,
                "steps": [],
                "confidence": 70,
                "flaky": False,
                "health_gates": {
                    "after_step_1.1.1": "pass",
                    "after_step_1.1.2": "fail",
                },
            })
        )
        try:
            result = aggregate(review_dir)
            check(
                "T-aggregate-health-gate-failures",
                len(result["health_gate_failures"]) == 1
                and result["health_gate_failures"][0]["case_id"] == "1.1"
                and result["health_gate_failures"][0]["gate"] == "after_step_1.1.2",
                f"got {result['health_gate_failures']!r}",
            )
        except Exception as exc:
            fail("T-aggregate-health-gate-failures", str(exc))

    print()
    if failures:
        print(f"FAILED: {len(failures)} test(s): {', '.join(failures)}")
        return 1

    print(f"All {15} tests passed.")
    return 0


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------


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

    out_path = review_dir / "verdict-final.json"
    out_path.write_text(json.dumps(result, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"Aggregated → {out_path}: label={result['label']} ({result['rationale']})")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
