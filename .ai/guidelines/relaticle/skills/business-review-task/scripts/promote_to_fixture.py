#!/usr/bin/env python3
"""Copy a real review's inputs into evals/fixtures/ to seed a new eval fixture.

Usage:
    python3 promote_to_fixture.py <PR_NUM> <fixture-name>
    python3 promote_to_fixture.py --test

Reads from .context/reviews/<PR_NUM>/, writes to
.ai/guidelines/relaticle/skills/business-review-task/evals/fixtures/<NN>-<fixture-name>/.

Pure stdlib.
"""

from __future__ import annotations

import json
import shutil
import sys
from pathlib import Path

SKILL_DIR = Path(".ai/guidelines/relaticle/skills/business-review-task")
FIXTURES_DIR = SKILL_DIR / "evals" / "fixtures"


def next_fixture_id() -> str:
    """Return next two-digit fixture ID like '04'."""
    existing = sorted(p.name for p in FIXTURES_DIR.iterdir() if p.is_dir())
    nums = [int(name.split("-", 1)[0]) for name in existing if name[:2].isdigit()]
    next_num = max(nums, default=0) + 1
    return f"{next_num:02d}"


def promote(pr_num: str, fixture_name: str) -> Path:
    src = Path(f".context/reviews/{pr_num}")
    if not src.exists():
        raise FileNotFoundError(
            f"No review directory at {src}. Run a review first."
        )

    fid = next_fixture_id()
    dst = FIXTURES_DIR / f"{fid}-{fixture_name}"
    dst.mkdir(parents=True)

    inputs = dst / "inputs"
    inputs.mkdir()

    candidates = [
        ("untrusted", True),
        ("pr-diff.patch", False),
        ("pr-files.txt", False),
        ("pr-context.json", False),
    ]
    for name, is_dir in candidates:
        src_path = src / name
        if src_path.exists():
            dst_path = inputs / name
            if is_dir:
                shutil.copytree(src_path, dst_path)
            else:
                shutil.copy2(src_path, dst_path)

    verdict_path = src / "verdict-final.json"
    if verdict_path.exists():
        verdict = json.loads(verdict_path.read_text())
        expected = {
            "expected_label": verdict["label"],
            "expected_ac_count_min": verdict["ac_coverage"]["total"],
            "must_contain_substrings": ["br-sha:"],
            "must_not_contain": ["<placeholder>", "TBD"],
        }
    else:
        expected = {
            "expected_label": "ai-approved",
            "expected_ac_count_min": 1,
            "must_contain_substrings": ["br-sha:"],
            "must_not_contain": ["<placeholder>", "TBD"],
        }
    (dst / "expected.json").write_text(json.dumps(expected, indent=2))

    (dst / "description.md").write_text(
        f"# Fixture {fid}-{fixture_name}\n\n"
        f"Promoted from .context/reviews/{pr_num}/.\n\n"
        "## What this fixture tests\n\n"
        "(Fill in: what edge case does this fixture exercise? What would regress without it?)\n\n"
        "## Notes\n\n"
        "(Fill in: anything unusual about the PR — sparse description, large diff, "
        "tricky AC, etc.)\n"
    )
    return dst


def run_tests() -> int:
    import tempfile

    global FIXTURES_DIR
    original = FIXTURES_DIR

    print("test_next_fixture_id_empty ...", end=" ")
    with tempfile.TemporaryDirectory() as tmp:
        FIXTURES_DIR = Path(tmp)
        FIXTURES_DIR.mkdir(exist_ok=True)
        assert next_fixture_id() == "01"
    print("PASS")

    print("test_next_fixture_id_with_existing ...", end=" ")
    with tempfile.TemporaryDirectory() as tmp:
        FIXTURES_DIR = Path(tmp)
        (FIXTURES_DIR / "01-first").mkdir()
        (FIXTURES_DIR / "02-second").mkdir()
        (FIXTURES_DIR / "07-skipped").mkdir()
        assert next_fixture_id() == "08"
    print("PASS")

    FIXTURES_DIR = original

    print("\nAll tests passed.")
    return 0


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] == "--test":
        return run_tests()
    if len(argv) != 3:
        print(f"Usage: {argv[0]} <PR_NUM> <fixture-name>  OR  --test", file=sys.stderr)
        return 2

    try:
        dst = promote(argv[1], argv[2])
    except FileNotFoundError as e:
        print(f"FAIL: {e}", file=sys.stderr)
        return 1

    print(f"Created fixture at {dst}/")
    print(f"  - inputs/   ← copied from .context/reviews/{argv[1]}/")
    print("  - expected.json   ← scaffolded (edit to formalize)")
    print("  - description.md  ← fill in fixture intent")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
