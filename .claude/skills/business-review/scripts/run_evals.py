#!/usr/bin/env python3
"""Run business-review-v2 fixtures: aggregate each fixture's inputs and assert
its expected.json. Exits 0 if all fixtures pass. Pure stdlib.

Usage:
    python3 run_evals.py [<fixtures-dir>]

If <fixtures-dir> is omitted, defaults to ../evals/fixtures relative to this script.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
AGG = HERE / "aggregate_verdicts.py"


def run_fixture(fixture_dir: Path) -> list[str]:
    """Return a list of failure strings (empty = pass)."""
    failures: list[str] = []
    expected = json.loads((fixture_dir / "expected.json").read_text())
    with tempfile.TemporaryDirectory() as tmp:
        work = Path(tmp)
        # copy inputs/* into the work dir
        inputs = fixture_dir / "inputs"
        for item in inputs.rglob("*"):
            if item.is_file():
                dest = work / item.relative_to(inputs)
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy(item, dest)
        rc = subprocess.run([sys.executable, str(AGG), str(work)],
                            capture_output=True, text=True)
        verdict_path = work / "verdict-final.json"
        if not verdict_path.exists():
            return [f"{fixture_dir.name}: aggregator wrote no verdict-final.json "
                    f"(rc={rc.returncode}, stderr={rc.stderr.strip()})"]
        verdict = json.loads(verdict_path.read_text())

        # Support both "label" (Journey fixtures) and "verdict_label" (maxforms compat)
        if "label" in expected and verdict.get("label") != expected["label"]:
            failures.append(f"{fixture_dir.name}: label {verdict.get('label')!r} "
                            f"!= expected {expected['label']!r}")
        if "verdict_label" in expected and verdict.get("label") != expected["verdict_label"]:
            failures.append(f"{fixture_dir.name}: label {verdict.get('label')!r} "
                            f"!= expected {expected['verdict_label']!r}")

        # channel assertion
        if "channel" in expected and verdict.get("channel") != expected["channel"]:
            failures.append(f"{fixture_dir.name}: channel {verdict.get('channel')!r} "
                            f"!= expected {expected['channel']!r}")

        # frontier_flagged: asserts verdict.frontier_suspicious == expected value
        if "frontier_flagged" in expected:
            got = verdict.get("frontier_suspicious", False)
            want = expected["frontier_flagged"]
            if got != want:
                failures.append(f"{fixture_dir.name}: frontier_suspicious {got!r} "
                                 f"!= expected {want!r}")

        if "confirmed_blocker_ids" in expected:
            got = sorted(b.get("id") for b in verdict.get("confirmed_blockers", []))
            want = sorted(expected["confirmed_blocker_ids"])
            if got != want:
                failures.append(f"{fixture_dir.name}: confirmed_blockers {got} != {want}")
        if "unconfirmed_finding_ids" in expected:
            got = sorted(b.get("id") for b in verdict.get("unconfirmed_findings", []))
            want = sorted(expected["unconfirmed_finding_ids"])
            if got != want:
                failures.append(f"{fixture_dir.name}: unconfirmed {got} != {want}")
        if "must_not_contain" in expected:
            blob = json.dumps(verdict)
            for forbidden in expected["must_not_contain"]:
                if forbidden in blob:
                    failures.append(f"{fixture_dir.name}: verdict contains forbidden {forbidden!r}")
        if expected.get("decision_needed_present"):
            if not (isinstance(verdict.get("decision_needed"), str) and verdict["decision_needed"]):
                failures.append(f"{fixture_dir.name}: decision_needed missing/empty")
        if expected.get("frontier_items_have_how_to_close"):
            fr = verdict.get("frontier", [])
            if not fr or not all(x.get("how_to_close") for x in fr):
                failures.append(f"{fixture_dir.name}: frontier items missing how_to_close ({fr!r})")

    return failures


def main(argv: list[str] | None = None) -> int:
    if argv is None:
        argv = sys.argv
    if len(argv) > 2:
        print(f"Usage: {argv[0]} [<fixtures-dir>]", file=sys.stderr)
        return 2
    if len(argv) == 2:
        fixtures_dir = Path(argv[1])
    else:
        fixtures_dir = HERE.parent / "evals" / "fixtures"

    if not fixtures_dir.exists():
        print(f"FAIL: fixtures directory not found: {fixtures_dir}", file=sys.stderr)
        return 1

    fixtures = sorted(p for p in fixtures_dir.iterdir() if p.is_dir())
    if not fixtures:
        print(f"FAIL: no fixture directories found under {fixtures_dir}", file=sys.stderr)
        return 1

    all_failures: list[str] = []
    for fx in fixtures:
        fails = run_fixture(fx)
        status = "PASS" if not fails else "FAIL"
        print(f"  {fx.name} ... {status}")
        all_failures.extend(fails)
    print()
    if all_failures:
        for f in all_failures:
            print(f"  - {f}")
        print(f"\nFAILED: {len(all_failures)} assertion(s)")
        return 1
    print(f"All {len(fixtures)} fixtures passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
