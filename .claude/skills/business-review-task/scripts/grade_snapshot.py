#!/usr/bin/env python3
"""Grade an eval-mode skill run against a fixture's expected.json.

Usage:
    python3 grade_snapshot.py <REVIEW_DIR_TEST> <expected.json>
    python3 grade_snapshot.py --test

Exits 0 if all assertions pass, 1 otherwise. Prints per-assertion results.

Pure stdlib.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

# expected_mode was a top-level plan field in the v1 schema; it's now per-case
# in the rewrite. This check has been removed. If a fixture needs to assert
# anything about case modes, add a new check based on plan.md cases[].


def grade(review_dir: Path, expected: dict) -> tuple[bool, list[str]]:
    """Return (all_passed, messages)."""
    messages: list[str] = []
    ok = True

    verdict_path = review_dir / "verdict-final.json"
    if not verdict_path.exists():
        return (False, [f"FAIL: {verdict_path} not written by skill"])
    verdict = json.loads(verdict_path.read_text())

    expected_label = expected.get("expected_label")
    if expected_label and verdict["label"] != expected_label:
        ok = False
        messages.append(
            f"FAIL: label expected '{expected_label}', got '{verdict['label']}'"
        )
    else:
        messages.append(f"OK: label == '{verdict['label']}'")

    expected_ac_min = expected.get("expected_ac_count_min")
    if expected_ac_min is not None:
        ac_path = review_dir / "acceptance-criteria.json"
        if not ac_path.exists():
            ok = False
            messages.append("FAIL: acceptance-criteria.json not found")
        else:
            ac_data = json.loads(ac_path.read_text())
            ac_count = len(ac_data.get("criteria", []))
            if ac_count < expected_ac_min:
                ok = False
                messages.append(
                    f"FAIL: AC count {ac_count} < expected min {expected_ac_min}"
                )
            else:
                messages.append(f"OK: AC count {ac_count} >= {expected_ac_min}")

    review_path = review_dir / "REVIEW.md"
    review_text = review_path.read_text() if review_path.exists() else ""

    for needle in expected.get("must_contain_substrings", []):
        if needle in review_text:
            messages.append(f"OK: REVIEW.md contains '{needle[:40]}...'")
        else:
            ok = False
            messages.append(f"FAIL: REVIEW.md missing '{needle[:40]}...'")

    for forbidden in expected.get("must_not_contain", []):
        if forbidden in review_text:
            ok = False
            messages.append(f"FAIL: REVIEW.md contains forbidden '{forbidden}'")
        else:
            messages.append(f"OK: REVIEW.md does not contain '{forbidden}'")

    return (ok, messages)


def run_tests() -> int:
    import tempfile

    print("test_grade_all_pass ...", end=" ")
    with tempfile.TemporaryDirectory() as tmp:
        rd = Path(tmp)
        (rd / "verdict-final.json").write_text(json.dumps({"label": "ai-approved"}))
        (rd / "REVIEW.md").write_text("Hello br-sha:abc123 world")
        expected = {
            "expected_label": "ai-approved",
            "must_contain_substrings": ["br-sha:"],
            "must_not_contain": ["TBD"],
        }
        ok, msgs = grade(rd, expected)
        assert ok, msgs
    print("PASS")

    print("test_grade_label_mismatch ...", end=" ")
    with tempfile.TemporaryDirectory() as tmp:
        rd = Path(tmp)
        (rd / "verdict-final.json").write_text(json.dumps({"label": "ai-rejected"}))
        (rd / "REVIEW.md").write_text("ok")
        expected = {"expected_label": "ai-approved"}
        ok, msgs = grade(rd, expected)
        assert not ok
        assert any("FAIL: label" in m for m in msgs)
    print("PASS")

    print("test_grade_missing_substring ...", end=" ")
    with tempfile.TemporaryDirectory() as tmp:
        rd = Path(tmp)
        (rd / "verdict-final.json").write_text(json.dumps({"label": "ai-approved"}))
        (rd / "REVIEW.md").write_text("no marker here")
        expected = {
            "expected_label": "ai-approved",
            "must_contain_substrings": ["br-sha:"],
        }
        ok, msgs = grade(rd, expected)
        assert not ok
    print("PASS")

    print("test_grade_forbidden_substring_present ...", end=" ")
    with tempfile.TemporaryDirectory() as tmp:
        rd = Path(tmp)
        (rd / "verdict-final.json").write_text(json.dumps({"label": "ai-approved"}))
        (rd / "REVIEW.md").write_text("Has TBD in it")
        expected = {
            "expected_label": "ai-approved",
            "must_not_contain": ["TBD"],
        }
        ok, _ = grade(rd, expected)
        assert not ok
    print("PASS")

    print("\nAll tests passed.")
    return 0


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] == "--test":
        return run_tests()
    if len(argv) != 3:
        print(
            f"Usage: {argv[0]} <REVIEW_DIR_TEST> <expected.json>  OR  --test",
            file=sys.stderr,
        )
        return 2

    review_dir = Path(argv[1])
    expected = json.loads(Path(argv[2]).read_text())
    ok, messages = grade(review_dir, expected)
    for m in messages:
        print(m)
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
