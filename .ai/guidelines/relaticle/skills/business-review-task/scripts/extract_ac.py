#!/usr/bin/env python3
"""Extract acceptance criteria from PR body, or infer from diff.

Usage:
    python3 extract_ac.py --body <path> --title <path> --diff <path>
    python3 extract_ac.py --test

Writes $REVIEW_DIR/acceptance-criteria-suggested.json with:
    {"source": "pr-body-explicit" | "inferred-from-diff",
     "criteria": [{"id": 1, "text": "...", "source_files": [...]}]}

Pure stdlib.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path

# Headings that signal an AC section in PR body
AC_HEADING_RE = re.compile(
    # Narrow whitelist: "tasks" / "todo" / "checklist" deliberately excluded
    # because PR templates use those for engineer to-do lists, not user-facing AC.
    r"^#{2,4}\s+(acceptance criteria|ac|acceptance|requirements)\b",
    re.IGNORECASE | re.MULTILINE,
)
# Item patterns under those headings
CHECKBOX_RE = re.compile(r"^\s*-\s*\[[\sx]\]\s+(.+)$", re.MULTILINE)
NUMBERED_RE = re.compile(r"^\s*\d+\.\s+(.+)$", re.MULTILINE)
BULLET_RE = re.compile(r"^\s*[-*]\s+(?!\[)(.+)$", re.MULTILINE)

# Patterns to spot user-facing changes in a diff
USER_FACING_PATHS = [
    (re.compile(r"^\+\+\+ b/(routes/api\.php)"), "REST API route"),
    (re.compile(r"^\+\+\+ b/(routes/[^\s]+)"), "route"),
    (re.compile(r"^\+\+\+ b/(app/Filament/[^\s]+Resource\.php)"), "Filament resource"),
    (re.compile(r"^\+\+\+ b/(app/Filament/Pages/[^\s]+\.php)"), "Filament page"),
    (re.compile(r"^\+\+\+ b/(app/Livewire/[^\s]+\.php)"), "Livewire component"),
    (re.compile(r"^\+\+\+ b/(app/Http/Controllers/Api/[^\s]+\.php)"), "REST API controller"),
    (re.compile(r"^\+\+\+ b/(app/Models/CustomField[^\s]*\.php)"), "custom field schema"),
    (re.compile(r"^\+\+\+ b/(packages/ImportWizard/[^\s]+\.php)"), "import wizard surface"),
    (re.compile(r"^\+\+\+ b/(packages/Chat/[^\s]+\.php)"), "AI chat surface"),
    (re.compile(r"^\+\+\+ b/(packages/SystemAdmin/[^\s]+\.php)"), "sysadmin surface"),
    (re.compile(r"^\+\+\+ b/(resources/views/[^\s]+\.blade\.php)"), "Blade view"),
]


def extract_explicit_ac(body: str) -> list[str]:
    """Return list of AC text items found under recognized headings."""
    lines = body.splitlines()
    in_ac_section = False
    section_lines: list[str] = []
    for line in lines:
        if AC_HEADING_RE.match(line):
            in_ac_section = True
            section_lines.append(line)
            continue
        if in_ac_section and line.startswith("#"):
            in_ac_section = False
            continue
        if in_ac_section:
            section_lines.append(line)

    if not section_lines:
        return []

    section = "\n".join(section_lines)
    items = CHECKBOX_RE.findall(section)
    if not items:
        items = NUMBERED_RE.findall(section)
    if not items:
        items = BULLET_RE.findall(section)
    return [item.strip() for item in items if item.strip()]


def infer_ac_from_diff(diff: str, title: str) -> list[dict]:
    """Infer candidate AC from user-facing diff changes."""
    seen: dict[str, dict] = {}
    for line in diff.splitlines():
        for pattern, label in USER_FACING_PATHS:
            m = pattern.match(line)
            if m:
                path = m.group(1)
                if path not in seen:
                    seen[path] = {
                        "path": path,
                        "label": label,
                    }
                break

    candidates: list[dict] = []
    for i, info in enumerate(list(seen.values())[:5], start=1):
        candidates.append({
            "id": i,
            "text": f"User-facing behavior in {info['label']} works as described by '{title}'.",
            "source_files": [info["path"]],
        })

    if not candidates:
        candidates.append({
            "id": 1,
            "text": f"Behavior described by '{title}' works as intended.",
            "source_files": [],
        })
    return candidates


def write_output(out_path: Path, source: str, criteria: list) -> None:
    if source == "pr-body-explicit":
        payload_criteria = [
            {"id": i, "text": item, "source_files": []}
            for i, item in enumerate(criteria, start=1)
        ]
    else:
        payload_criteria = criteria
    payload = {"source": source, "criteria": payload_criteria}
    out_path.write_text(json.dumps(payload, indent=2))


def run_tests() -> int:
    import tempfile

    print("test_explicit_ac_under_acceptance_criteria_heading ...", end=" ")
    body = """## Description
Some words.

## Acceptance Criteria
- [ ] User can pick EUR
- [x] EUR symbol renders
- [ ] USD still works

## Other section
"""
    items = extract_explicit_ac(body)
    assert items == ["User can pick EUR", "EUR symbol renders", "USD still works"], items
    print("PASS")

    print("test_explicit_ac_numbered ...", end=" ")
    body = """## Requirements
1. First thing
2. Second thing

## Another
"""
    items = extract_explicit_ac(body)
    assert items == ["First thing", "Second thing"], items
    print("PASS")

    print("test_no_ac_section_returns_empty ...", end=" ")
    body = "Just a description with no AC heading."
    items = extract_explicit_ac(body)
    assert items == []
    print("PASS")

    print("test_infer_from_diff_finds_filament_resource ...", end=" ")
    diff = """diff --git a/app/Filament/Resources/UserResource.php b/app/Filament/Resources/UserResource.php
+++ b/app/Filament/Resources/UserResource.php
@@ -0,0 +1,10 @@
+<?php
"""
    candidates = infer_ac_from_diff(diff, "Add user management")
    assert len(candidates) == 1
    assert "Filament resource" in candidates[0]["text"]
    assert candidates[0]["source_files"] == ["app/Filament/Resources/UserResource.php"]
    print("PASS")

    print("test_infer_caps_at_five_candidates ...", end=" ")
    diff = "\n".join(
        f"+++ b/routes/route{i}.php" for i in range(10)
    )
    candidates = infer_ac_from_diff(diff, "Many routes")
    assert len(candidates) == 5
    print("PASS")

    print("test_infer_fallback_when_no_user_facing_change ...", end=" ")
    diff = "+++ b/app/Services/InternalThing.php\n"
    candidates = infer_ac_from_diff(diff, "Refactor internal thing")
    assert len(candidates) == 1
    assert "Refactor internal thing" in candidates[0]["text"]
    print("PASS")

    print("test_write_output_pr_body_form ...", end=" ")
    with tempfile.TemporaryDirectory() as tmp:
        out = Path(tmp) / "out.json"
        write_output(out, "pr-body-explicit", ["AC one", "AC two"])
        data = json.loads(out.read_text())
        assert data["source"] == "pr-body-explicit"
        assert data["criteria"][0]["id"] == 1
        assert data["criteria"][0]["text"] == "AC one"
    print("PASS")

    print("test_ac_resumes_after_intermediate_heading ...", end=" ")
    body = """## Acceptance Criteria
- [ ] First AC

## Description
Some text.

## Requirements
- [ ] Second AC
"""
    items = extract_explicit_ac(body)
    assert items == ["First AC", "Second AC"], items
    print("PASS")

    print("test_tasks_heading_does_not_count_as_ac ...", end=" ")
    body = """## Tasks
- [ ] Add migration
- [ ] Write tests
"""
    items = extract_explicit_ac(body)
    assert items == [], items
    print("PASS")

    print("\nAll tests passed.")
    return 0


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] == "--test":
        return run_tests()
    parser = argparse.ArgumentParser()
    parser.add_argument("--body", required=True)
    parser.add_argument("--title", required=True)
    parser.add_argument("--diff", required=True)
    args = parser.parse_args(argv[1:])

    body = Path(args.body).read_text(encoding="utf-8")
    title = Path(args.title).read_text(encoding="utf-8").strip()
    diff = Path(args.diff).read_text(encoding="utf-8")

    review_dir = Path(os.environ.get("REVIEW_DIR", "."))
    out_path = review_dir / "acceptance-criteria-suggested.json"

    explicit = extract_explicit_ac(body)
    if explicit:
        write_output(out_path, "pr-body-explicit", explicit)
        print(f"Found {len(explicit)} explicit AC in PR body → {out_path}")
    else:
        candidates = infer_ac_from_diff(diff, title)
        write_output(out_path, "inferred-from-diff", candidates)
        print(f"Inferred {len(candidates)} AC from diff (no explicit AC found) → {out_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
