#!/usr/bin/env python3
"""Regression-ledger matcher + plan gate for business-review-task (Relaticle v3).

Usage:
    python3 check_regressions.py <REVIEW_DIR>            # match ledger vs diff -> regression-checks.json
    python3 check_regressions.py <REVIEW_DIR> --plan     # gate: every match scheduled in plan.md
    python3 check_regressions.py --test                  # self-tests (incl. the REG-001 missed-regression lock)

Reads:
    <skill>/regressions.json                 (the ledger, next to this script's parent dir)
    <REVIEW_DIR>/pr-files.txt                (touched files, one per line)
    <REVIEW_DIR>/pr-diff.patch               (unified diff; added-line patterns)
    <REVIEW_DIR>/diff-classification.json    (optional; change_types)
    <REVIEW_DIR>/plan.md                     (--plan mode; JSON frontmatter regression_checks[])

Writes:
    <REVIEW_DIR>/regression-checks.json      ({matched: [{id, class, severity, check, repro}]})

Exit codes: 0 ok / gate passed, 1 gate failed or error, 2 bad usage. Pure stdlib.
"""
from __future__ import annotations

import fnmatch
import json
import re
import sys
import tempfile
from pathlib import Path

FRONTMATTER_RE = re.compile(r"<!--json\s*\n(.*?)\n-->", re.DOTALL)


def load_ledger(skill_dir: Path) -> list[dict]:
    ledger_path = skill_dir / "regressions.json"
    if not ledger_path.exists():
        return []
    data = json.loads(ledger_path.read_text())
    return data.get("entries", [])


def path_matches(patterns: list[str], files: list[str]) -> bool:
    for pat in patterns:
        for f in files:
            if "*" in pat or "?" in pat:
                if fnmatch.fnmatch(f, pat) or fnmatch.fnmatch(f, pat.rstrip("/") + "/*"):
                    return True
            elif f.startswith(pat) or pat in f:
                return True
    return False


def added_lines(patch_text: str) -> list[str]:
    return [l for l in patch_text.splitlines() if l.startswith("+") and not l.startswith("+++")]


def entry_matches(entry: dict, files: list[str], patch_text: str, change_types: list[str]) -> bool:
    trig = entry.get("trigger", {})
    paths = trig.get("paths", [])
    types = trig.get("change_types", [])
    pattern = trig.get("added_line_pattern")

    base_hit = False
    if paths and path_matches(paths, files):
        base_hit = True
    if not base_hit and types and any(t in change_types for t in types):
        base_hit = True
    if not paths and not types:
        base_hit = True  # pattern-only entry
    if not base_hit:
        return False

    if pattern:
        rx = re.compile(pattern, re.MULTILINE)
        return any(rx.search(l) for l in added_lines(patch_text))
    return True


def run_match(review_dir: Path, skill_dir: Path) -> dict:
    files_path = review_dir / "pr-files.txt"
    patch_path = review_dir / "pr-diff.patch"
    files = files_path.read_text().split() if files_path.exists() else []
    patch_text = patch_path.read_text() if patch_path.exists() else ""
    change_types: list[str] = []
    cls_path = review_dir / "diff-classification.json"
    if cls_path.exists():
        try:
            change_types = json.loads(cls_path.read_text()).get("change_types", [])
        except (json.JSONDecodeError, AttributeError):
            change_types = []

    matched = [
        {
            "id": e["id"],
            "class": e.get("class", ""),
            "severity": e.get("severity", "high"),
            "check": e.get("check", ""),
            "repro": e.get("repro", []),
        }
        for e in load_ledger(skill_dir)
        if entry_matches(e, files, patch_text, change_types)
    ]
    out = {"matched": matched}
    (review_dir / "regression-checks.json").write_text(json.dumps(out, indent=2))
    return out


def gate_plan(review_dir: Path) -> list[str]:
    """Return failure strings; empty = gate passed."""
    failures: list[str] = []
    checks_path = review_dir / "regression-checks.json"
    if not checks_path.exists():
        return ["regression-checks.json missing — run check_regressions.py <REVIEW_DIR> first"]
    matched = json.loads(checks_path.read_text()).get("matched", [])
    if not matched:
        return []

    plan_path = review_dir / "plan.md"
    if not plan_path.exists():
        return [f"plan.md missing but {len(matched)} ledger entr(y/ies) matched"]
    m = FRONTMATTER_RE.search(plan_path.read_text())
    if not m:
        return ["plan.md has no JSON frontmatter (<!--json ... -->)"]
    try:
        plan = json.loads(m.group(1))
    except json.JSONDecodeError as exc:
        return [f"plan.md frontmatter is not valid JSON: {exc}"]

    scheduled = {c.get("id"): c for c in plan.get("regression_checks", [])}
    for entry in matched:
        rid = entry["id"]
        sched = scheduled.get(rid)
        if sched is None:
            failures.append(f"{rid} matched the diff but is not scheduled in plan.regression_checks")
            continue
        status = sched.get("status")
        if status == "planned":
            if not sched.get("journey"):
                failures.append(f"{rid} is planned but names no covering journey")
        elif status == "not-applicable":
            if not (sched.get("reason") or "").strip():
                failures.append(f"{rid} is not-applicable but gives no reason")
        else:
            failures.append(f"{rid} has invalid status {status!r} (planned|not-applicable)")
    return failures


# ---------------------------------------------------------------- self-tests
def _selftest() -> int:
    fails: list[str] = []

    def check(name: str, cond: bool) -> None:
        if not cond:
            fails.append(name)

    with tempfile.TemporaryDirectory() as tmp:
        work = Path(tmp)
        review = work / "review"
        review.mkdir()
        skill = work / "skill"
        skill.mkdir()
        (skill / "regressions.json").write_text(json.dumps({"entries": [
            {"id": "REG-001", "class": "enum-case-added", "severity": "critical",
             "trigger": {"paths": ["app/Enums/", "packages/*/src/Enums/"],
                          "added_line_pattern": r"^\+\s*case\s+\w+"},
             "check": "open enum consumers", "repro": ["step"]},
            {"id": "REG-002", "class": "double-submit",
             "trigger": {"change_types": ["form"]}, "check": "double-click", "repro": []},
        ]}))

        # T1: the missed-regression lock — PR-326-shaped diff MUST match REG-001
        (review / "pr-files.txt").write_text("packages/Chat/src/Enums/AiCreditType.php\n")
        (review / "pr-diff.patch").write_text(
            "--- a/packages/Chat/src/Enums/AiCreditType.php\n"
            "+++ b/packages/Chat/src/Enums/AiCreditType.php\n"
            "+    case Reservation = 'reservation';\n")
        out = run_match(review, skill)
        check("T1 REG-001 matches PR-326-shaped diff",
              any(e["id"] == "REG-001" for e in out["matched"]))

        # T2: plan gate FAILS when the matched entry is not scheduled
        (review / "plan.md").write_text('<!--json\n{"tier": 2, "journeys": [], "regression_checks": []}\n-->\n')
        check("T2 unscheduled match fails the gate", len(gate_plan(review)) > 0)

        # T3: plan gate passes when scheduled with a journey
        (review / "plan.md").write_text(
            '<!--json\n{"tier": 2, "journeys": [],'
            ' "regression_checks": [{"id": "REG-001", "status": "planned", "journey": "S1"}]}\n-->\n')
        check("T3 scheduled match passes the gate", gate_plan(review) == [])

        # T4: not-applicable requires a reason
        (review / "plan.md").write_text(
            '<!--json\n{"tier": 2, "journeys": [],'
            ' "regression_checks": [{"id": "REG-001", "status": "not-applicable", "reason": ""}]}\n-->\n')
        check("T4 empty reason fails", len(gate_plan(review)) > 0)
        (review / "plan.md").write_text(
            '<!--json\n{"tier": 2, "journeys": [],'
            ' "regression_checks": [{"id": "REG-001", "status": "not-applicable",'
            ' "reason": "enum is internal-only, no UI consumer (greped)"}]}\n-->\n')
        check("T4b written reason passes", gate_plan(review) == [])

        # T5: change_types trigger
        (review / "pr-files.txt").write_text("app/Livewire/SomeForm.php\n")
        (review / "pr-diff.patch").write_text("+    public function store()\n")
        (review / "diff-classification.json").write_text('{"change_types": ["form"]}')
        out = run_match(review, skill)
        check("T5 change_types trigger matches", any(e["id"] == "REG-002" for e in out["matched"]))

        # T6: no false match on unrelated diff
        (review / "pr-files.txt").write_text("README.md\n")
        (review / "pr-diff.patch").write_text("+ docs only\n")
        (review / "diff-classification.json").write_text('{"change_types": []}')
        out = run_match(review, skill)
        check("T6 unrelated diff matches nothing", out["matched"] == [])

    if fails:
        for f in fails:
            print(f"  FAIL: {f}")
        return 1
    print("check_regressions.py: all self-tests passed.")
    return 0


def main(argv: list[str]) -> int:
    if len(argv) >= 2 and argv[1] == "--test":
        return _selftest()
    if len(argv) < 2:
        print(__doc__, file=sys.stderr)
        return 2
    review_dir = Path(argv[1])
    if not review_dir.is_dir():
        print(f"error: REVIEW_DIR not found: {review_dir}", file=sys.stderr)
        return 1
    skill_dir = Path(__file__).resolve().parent.parent

    if "--plan" in argv[2:]:
        failures = gate_plan(review_dir)
        if failures:
            print("REGRESSION GATE FAILED:")
            for f in failures:
                print(f"  - {f}")
            return 1
        print("Regression gate passed.")
        return 0

    out = run_match(review_dir, skill_dir)
    print(f"{len(out['matched'])} ledger entr(y/ies) matched -> {review_dir / 'regression-checks.json'}")
    for e in out["matched"]:
        print(f"  {e['id']} [{e['severity']}] {e['class']}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
