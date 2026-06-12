#!/usr/bin/env python3
"""Dispatch LLM-graded drift check across fixtures.

This script does NOT call any LLM directly. It prepares a per-fixture
prompt file at evals/drift-prompts/<fixture>.md combining the fixture's
generated REVIEW.md + the rubric from evals/grader-rubric.json + the
grader subagent prompt from agents/grader.md.

Operator workflow:
  1. python3 run_drift_check.py prepare
     → writes evals/drift-prompts/*.md
  2. In a Claude Code session, paste each prompt into the grader subagent
     (or use the Agent tool with subagent_type=general-purpose)
  3. Save the grader's response to evals/drift-responses/<fixture>.md
  4. python3 run_drift_check.py collect
     → writes evals/drift-report.md summarizing all responses

Usage:
    python3 run_drift_check.py prepare
    python3 run_drift_check.py collect
    python3 run_drift_check.py --test

Pure stdlib.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

SKILL_DIR = Path(__file__).resolve().parent.parent
EVALS_DIR = SKILL_DIR / "evals"
FIXTURES_DIR = EVALS_DIR / "fixtures"
PROMPTS_DIR = EVALS_DIR / "drift-prompts"
RESPONSES_DIR = EVALS_DIR / "drift-responses"
GRADER_PROMPT = SKILL_DIR / "agents" / "grader.md"
RUBRIC_PATH = EVALS_DIR / "grader-rubric.json"


def prepare() -> int:
    PROMPTS_DIR.mkdir(exist_ok=True)
    rubric = json.loads(RUBRIC_PATH.read_text()) if RUBRIC_PATH.exists() else {}
    grader_prompt = GRADER_PROMPT.read_text() if GRADER_PROMPT.exists() else ""

    count = 0
    for fixture in sorted(FIXTURES_DIR.iterdir()):
        if not fixture.is_dir() or fixture.name.startswith("."):
            continue
        review_path = fixture / "inputs" / "REVIEW.md"
        if not review_path.exists():
            continue
        rubric_entry = rubric.get(fixture.name, {})
        criteria_list = rubric_entry.get("criteria", [])
        criteria_block = "\n".join(f"- {c}" for c in criteria_list)

        prompt = (
            f"{grader_prompt}\n\n"
            f"## Fixture: {fixture.name}\n\n"
            f"## Rubric criteria\n\n{criteria_block}\n\n"
            "## REVIEW.md to grade\n\n"
            f"```markdown\n{review_path.read_text()}\n```\n"
        )
        (PROMPTS_DIR / f"{fixture.name}.md").write_text(prompt)
        count += 1
    print(f"Wrote {count} drift-check prompt(s) to {PROMPTS_DIR}/")
    print("Next: paste each into the grader subagent, save responses to")
    print(f"      {RESPONSES_DIR}/<fixture>.md, then run 'collect'.")
    return 0


def collect() -> int:
    if not RESPONSES_DIR.exists():
        print(f"No responses directory at {RESPONSES_DIR}", file=sys.stderr)
        return 1
    responses = sorted(RESPONSES_DIR.glob("*.md"))
    if not responses:
        print(f"No responses found in {RESPONSES_DIR}", file=sys.stderr)
        return 1

    report = ["# Drift Report\n"]
    for r in responses:
        report.append(f"## {r.stem}\n\n{r.read_text()}\n")
    (EVALS_DIR / "drift-report.md").write_text("\n".join(report))
    print(f"Wrote {EVALS_DIR / 'drift-report.md'}")
    return 0


def run_tests() -> int:
    print("test_prepare_creates_dir ...", end=" ")
    PROMPTS_DIR.mkdir(exist_ok=True)
    assert PROMPTS_DIR.exists()
    print("PASS")
    print("\nAll tests passed.")
    return 0


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] == "--test":
        return run_tests()
    if len(argv) != 2 or argv[1] not in {"prepare", "collect"}:
        print(
            f"Usage: {argv[0]} prepare | collect  OR  --test",
            file=sys.stderr,
        )
        return 2
    return prepare() if argv[1] == "prepare" else collect()


if __name__ == "__main__":
    sys.exit(main(sys.argv))
