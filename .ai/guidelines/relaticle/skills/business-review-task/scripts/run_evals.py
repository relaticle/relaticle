#!/usr/bin/env python3
"""Run the eval harness across all fixtures.

Usage:
    python3 run_evals.py             # run all fixtures
    python3 run_evals.py --test      # run embedded self-tests

For each fixture:
  1. Copy inputs/ to a temp $REVIEW_DIR_TEST
  2. Invoke aggregate_verdicts.py (the smallest gate-able phase) on it
  3. Run grade_snapshot.py against expected.json
  4. Report pass/fail

The full skill --eval-mode is invoked manually for now — this harness
exercises the deterministic Python pipeline only. Skill-prose grading is
the LLM drift check (run_drift_check.py).

Pure stdlib.
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

SKILL_DIR = Path(__file__).resolve().parent.parent
FIXTURES_DIR = SKILL_DIR / "evals" / "fixtures"
AGGREGATE = SKILL_DIR / "scripts" / "aggregate_verdicts.py"
GRADE = SKILL_DIR / "scripts" / "grade_snapshot.py"


def setup_test_dir(fixture: Path) -> Path:
    """Copy fixture/inputs/ into a temp dir; return the temp path."""
    tmp = Path(tempfile.mkdtemp(prefix="eval-"))
    inputs = fixture / "inputs"
    for item in inputs.iterdir():
        if item.is_dir():
            shutil.copytree(item, tmp / item.name)
        else:
            shutil.copy2(item, tmp / item.name)
    return tmp


def run_fixture(fixture: Path) -> tuple[bool, str]:
    """Run a single fixture; return (passed, message)."""
    expected_path = fixture / "expected.json"
    if not expected_path.exists():
        return (False, "missing expected.json")

    test_dir = setup_test_dir(fixture)
    try:
        if (test_dir / "plan.md").exists() and (test_dir / "acceptance-criteria.json").exists():
            r = subprocess.run(
                ["python3", str(AGGREGATE), str(test_dir)],
                capture_output=True, text=True,
            )
            if r.returncode != 0:
                return (False, f"aggregate_verdicts failed: {r.stderr.strip()}")

        r = subprocess.run(
            ["python3", str(GRADE), str(test_dir), str(expected_path)],
            capture_output=True, text=True,
        )
        passed = r.returncode == 0
        return (passed, r.stdout.strip() or r.stderr.strip())
    finally:
        shutil.rmtree(test_dir, ignore_errors=True)


def run_tests() -> int:
    print("test_setup_test_dir_copies_inputs ...", end=" ")
    with tempfile.TemporaryDirectory() as tmp:
        fixture = Path(tmp) / "fixture"
        (fixture / "inputs").mkdir(parents=True)
        (fixture / "inputs" / "file.txt").write_text("hello")
        (fixture / "inputs" / "subdir").mkdir()
        (fixture / "inputs" / "subdir" / "nested.txt").write_text("world")
        td = setup_test_dir(fixture)
        try:
            assert (td / "file.txt").read_text() == "hello"
            assert (td / "subdir" / "nested.txt").read_text() == "world"
        finally:
            shutil.rmtree(td)
    print("PASS")

    print("\nAll tests passed.")
    return 0


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] == "--test":
        return run_tests()

    if not FIXTURES_DIR.exists():
        print(f"No fixtures dir at {FIXTURES_DIR}", file=sys.stderr)
        return 0

    fixtures = sorted(
        p for p in FIXTURES_DIR.iterdir()
        if p.is_dir() and not p.name.startswith(".")
    )
    if not fixtures:
        print("No fixtures to run.")
        return 0

    passed = 0
    failed = 0
    for fixture in fixtures:
        ok, msg = run_fixture(fixture)
        status = "PASS" if ok else "FAIL"
        print(f"[{status}] {fixture.name}")
        if not ok:
            for line in msg.splitlines():
                print(f"    {line}")
            failed += 1
        else:
            passed += 1

    print(f"\n{passed} passed, {failed} failed")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
