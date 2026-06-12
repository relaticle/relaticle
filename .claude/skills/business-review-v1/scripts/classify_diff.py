#!/usr/bin/env python3
"""Classify a unified diff into change_types for business review planning.

Usage:
    python3 classify_diff.py <path-to-pr-diff.patch>
    python3 classify_diff.py --test

Reads a unified diff patch, identifies which kinds of changes it contains
based on file paths and content patterns, and writes diff-classification.json
to stdout. Output schema:

{
  "change_types": ["modal", "form"],
  "touched_files": {
    "modal": ["app/Filament/.../EditFormModal.php"],
    "form":  ["packages/forms/.../FormBuilder.php"]
  },
  "infra_only": false,
  "blade_touched": true
}

Pure stdlib.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


# Patterns from the rewrite spec classifier table.
# Order matters: more-specific patterns first so they win over general ones.

def classify_file(path: str, hunk_text: str) -> list[str]:
    """Return list of change_types this file+hunk contributes."""
    # Markdown files are documentation, never production code surface.
    # Skip even if their content mentions keywords like `Feature::active` or `wire:`.
    if path.endswith(".md"):
        return []

    types: list[str] = []

    # Modal: path contains "Modal" or hunk references modal view/component
    if (
        re.search(r"Modal\.php$", path)
        or re.search(r"-modal\.blade\.php$", path)
        or "<x-filament::modal" in hunk_text
        or ".fi-modal-" in hunk_text
    ):
        types.append("modal")

    # Form: FormBuilder, Form components, schema field types
    if (
        re.search(r"Form(Builder|Schema)?\.php$", path)
        or re.search(r"Filament/.*/Forms/", path)
        or "->schema(" in hunk_text
        or "TextInput::make" in hunk_text
    ):
        types.append("form")

    # Table
    if (
        re.search(r"Table\.php$", path)
        or re.search(r"-table\.blade\.php$", path)
        or ".fi-table-" in hunk_text
    ):
        types.append("table")

    # Validation: Rule classes, FormRequest@rules method, validation array
    if (
        re.search(r"/Rules/.+\.php$", path)
        or re.search(r"FormRequest\.php$", path)
        or "function rules()" in hunk_text
        or "->rules(" in hunk_text
    ):
        types.append("validation")

    # Feature flag (Pennant)
    if (
        path == "config/pennant.php"
        or "Feature::define" in hunk_text
        or "Feature::active" in hunk_text
        or "@feature(" in hunk_text
    ):
        types.append("feature_flag")

    # Mutation: controller store/update/destroy
    if (
        re.search(r"Controller\.php$", path)
        and re.search(r"public function (store|update|destroy)\(", hunk_text)
    ):
        types.append("mutation")

    # Blade
    if path.endswith(".blade.php"):
        types.append("blade")

    # Volt: single-file component
    if (
        re.search(r"resources/views/livewire/.+\.blade\.php$", path)
        and ("volt::" in hunk_text.lower() or "<?php" in hunk_text)
    ):
        types.append("volt")

    # Livewire (broader than volt — any wire: directive or Livewire class)
    if (
        re.search(r"Livewire/.+\.php$", path)
        or "wire:" in hunk_text
    ):
        types.append("livewire")

    # Route
    if re.match(r"routes/.+\.php$", path):
        types.append("route")

    # REST API
    if (
        path == "routes/api.php"
        or path.startswith("app/Http/Controllers/Api/")
        or path.startswith("app/Http/Resources/")
    ):
        types.append("api")

    # Custom fields
    if (
        re.search(r"app/Models/CustomField.*\.php$", path)
        or "UsesCustomFields" in hunk_text
        or "saveCustomFieldValue" in hunk_text
    ):
        types.append("custom_fields")

    # Multi-tenant
    if (
        re.search(r"app/Models/(Team|Membership|TeamInvitation)\.php$", path)
        or re.search(r"app/Http/Middleware/.*Tenant.*\.php$", path)
        or "TenantContextService" in hunk_text
        or "BelongsToTenant" in hunk_text
    ):
        types.append("tenant")

    # Relaticle packages
    if path.startswith("packages/ImportWizard/"):
        types.append("import_wizard")
    if path.startswith("packages/Chat/"):
        types.append("ai_chat")
    if path.startswith("packages/SystemAdmin/"):
        types.append("sysadmin")

    return types


def is_infra_only_path(path: str) -> bool:
    """Return True if the file is purely infrastructure (no UI surface)."""
    if path.startswith("app/Console/"):
        return True
    if path.startswith("app/Jobs/"):
        return True
    if path.startswith("tests/Unit/"):
        return True
    if path.startswith("database/migrations/"):
        return True
    if path.startswith("config/") and path != "config/pennant.php":
        return True
    return False


def parse_diff(diff_text: str) -> list[tuple[str, str]]:
    """Return list of (file_path, combined_hunk_text) tuples."""
    files: list[tuple[str, str]] = []
    current_file: str | None = None
    current_hunks: list[str] = []

    for line in diff_text.splitlines():
        if line.startswith("diff --git"):
            if current_file is not None:
                files.append((current_file, "\n".join(current_hunks)))
            # Extract b-path from "diff --git a/foo b/bar"
            m = re.match(r"^diff --git a/\S+ b/(\S+)$", line)
            current_file = m.group(1) if m else None
            current_hunks = []
        elif line.startswith("+") and not line.startswith("+++"):
            # Only consider added lines for content patterns
            current_hunks.append(line[1:])

    if current_file is not None:
        files.append((current_file, "\n".join(current_hunks)))

    return files


def classify_diff(diff_text: str) -> dict:
    """Top-level: parse diff, classify each file, aggregate."""
    files = parse_diff(diff_text)

    touched: dict[str, list[str]] = {}
    all_infra = True if files else False
    blade_touched = False

    for path, hunk in files:
        if not is_infra_only_path(path):
            all_infra = False
        if path.endswith(".blade.php"):
            blade_touched = True

        types = classify_file(path, hunk)
        for t in types:
            touched.setdefault(t, []).append(path)

    return {
        "change_types": sorted(touched.keys()),
        "touched_files": touched,
        "infra_only": all_infra and not touched,
        "blade_touched": blade_touched,
    }


# ---------- tests ----------

def _run_tests() -> int:
    failures = 0

    def assert_eq(actual, expected, label):
        nonlocal failures
        if actual != expected:
            failures += 1
            print(f"FAIL: {label}")
            print(f"  expected: {expected!r}")
            print(f"  actual:   {actual!r}")
        else:
            print(f"PASS: {label}")

    # T1: Modal detection by filename
    diff = """diff --git a/app/Filament/EditFormModal.php b/app/Filament/EditFormModal.php
index abc..def 100644
--- a/app/Filament/EditFormModal.php
+++ b/app/Filament/EditFormModal.php
@@ -1,3 +1,4 @@
+public function form() { return []; }
"""
    r = classify_diff(diff)
    assert_eq(r["change_types"], ["modal"], "T1 modal by filename")
    assert_eq(r["infra_only"], False, "T1 not infra_only")

    # T2: Form + validation combined
    diff = """diff --git a/packages/forms/src/FormBuilder.php b/packages/forms/src/FormBuilder.php
index a..b 100644
--- a/packages/forms/src/FormBuilder.php
+++ b/packages/forms/src/FormBuilder.php
@@ -1,3 +1,4 @@
+public function rules() { return ['name' => 'required']; }
"""
    r = classify_diff(diff)
    assert_eq(sorted(r["change_types"]), ["form", "validation"], "T2 form+validation")

    # T3: Feature flag via Pennant config
    diff = """diff --git a/config/pennant.php b/config/pennant.php
index a..b 100644
--- a/config/pennant.php
+++ b/config/pennant.php
@@ -1,3 +1,4 @@
+'Billing' => fn($u) => true,
"""
    r = classify_diff(diff)
    assert_eq(r["change_types"], ["feature_flag"], "T3 feature_flag via pennant.php")

    # T4: Blade view triggers blade + livewire if wire: directive used
    diff = """diff --git a/resources/views/livewire/dashboard.blade.php b/resources/views/livewire/dashboard.blade.php
index a..b 100644
--- a/resources/views/livewire/dashboard.blade.php
+++ b/resources/views/livewire/dashboard.blade.php
@@ -1,3 +1,4 @@
+<div wire:click="refresh">Refresh</div>
"""
    r = classify_diff(diff)
    assert_eq("blade" in r["change_types"], True, "T4 blade detected")
    assert_eq("livewire" in r["change_types"], True, "T4 livewire detected")
    assert_eq(r["blade_touched"], True, "T4 blade_touched flag")

    # T5: Infra only — migration only
    diff = """diff --git a/database/migrations/2026_01_01_x.php b/database/migrations/2026_01_01_x.php
index a..b 100644
--- a/database/migrations/2026_01_01_x.php
+++ b/database/migrations/2026_01_01_x.php
@@ -1,3 +1,4 @@
+Schema::table('users', fn($t) => $t->string('foo'));
"""
    r = classify_diff(diff)
    assert_eq(r["change_types"], [], "T5 no change_types for pure migration")
    assert_eq(r["infra_only"], True, "T5 infra_only=true for pure migration")

    # T6: Mutation via controller method
    diff = """diff --git a/app/Http/Controllers/FormController.php b/app/Http/Controllers/FormController.php
index a..b 100644
--- a/app/Http/Controllers/FormController.php
+++ b/app/Http/Controllers/FormController.php
@@ -10,3 +10,7 @@
+    public function store(Request $r)
+    {
+        return Form::create($r->all());
+    }
"""
    r = classify_diff(diff)
    assert_eq("mutation" in r["change_types"], True, "T6 mutation via controller store")

    # T7: Empty diff
    r = classify_diff("")
    assert_eq(r["change_types"], [], "T7 empty diff has no change_types")
    assert_eq(r["infra_only"], False, "T7 empty diff is not infra_only (no files at all)")

    # T8a: Relaticle ImportWizard package
    diff = """diff --git a/packages/ImportWizard/src/Importers/CompanyImporter.php b/packages/ImportWizard/src/Importers/CompanyImporter.php
index a..b 100644
--- a/packages/ImportWizard/src/Importers/CompanyImporter.php
+++ b/packages/ImportWizard/src/Importers/CompanyImporter.php
@@ -1,3 +1,4 @@
+public function import() { return true; }
"""
    r = classify_diff(diff)
    assert_eq("import_wizard" in r["change_types"], True, "T8a ImportWizard package detected")

    # T8b: Relaticle Chat package
    diff = """diff --git a/packages/Chat/src/Agents/Agent.php b/packages/Chat/src/Agents/Agent.php
index a..b 100644
--- a/packages/Chat/src/Agents/Agent.php
+++ b/packages/Chat/src/Agents/Agent.php
@@ -1,3 +1,4 @@
+protected $model = 'claude-opus-4-7';
"""
    r = classify_diff(diff)
    assert_eq("ai_chat" in r["change_types"], True, "T8b Chat package detected")

    # T8c: Custom fields
    diff = """diff --git a/app/Models/CustomField.php b/app/Models/CustomField.php
index a..b 100644
--- a/app/Models/CustomField.php
+++ b/app/Models/CustomField.php
@@ -1,3 +1,4 @@
+public function options() { return $this->hasMany(CustomFieldOption::class); }
"""
    r = classify_diff(diff)
    assert_eq("custom_fields" in r["change_types"], True, "T8c CustomField model detected")

    # T8d: Tenant
    diff = """diff --git a/app/Models/Team.php b/app/Models/Team.php
index a..b 100644
--- a/app/Models/Team.php
+++ b/app/Models/Team.php
@@ -1,3 +1,4 @@
+public function members() { return $this->belongsToMany(User::class); }
"""
    r = classify_diff(diff)
    assert_eq("tenant" in r["change_types"], True, "T8d Team model detected as tenant")

    # T8e: REST API
    diff = """diff --git a/app/Http/Controllers/Api/CompanyController.php b/app/Http/Controllers/Api/CompanyController.php
index a..b 100644
--- a/app/Http/Controllers/Api/CompanyController.php
+++ b/app/Http/Controllers/Api/CompanyController.php
@@ -1,3 +1,4 @@
+public function index() { return Company::all(); }
"""
    r = classify_diff(diff)
    assert_eq("api" in r["change_types"], True, "T8e API controller detected")

    # T9: Markdown files never produce change_types (documentation can mention keywords)
    diff = """diff --git a/.claude/skills/business-review-v1/references/qa-matrix.md b/.claude/skills/business-review-v1/references/qa-matrix.md
index a..b 100644
--- a/.claude/skills/business-review-v1/references/qa-matrix.md
+++ b/.claude/skills/business-review-v1/references/qa-matrix.md
@@ -1,3 +1,4 @@
+Mentions wire:click, Feature::active, and Modal in documentation.
"""
    r = classify_diff(diff)
    assert_eq(r["change_types"], [], "T9 .md files never produce change_types")

    if failures:
        print(f"\n{failures} test(s) FAILED")
        return 1
    print("\nAll tests passed.")
    return 0


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: classify_diff.py <patch> | --test", file=sys.stderr)
        return 2
    if sys.argv[1] == "--test":
        return _run_tests()
    patch_path = Path(sys.argv[1])
    if not patch_path.exists():
        print(f"Patch not found: {patch_path}", file=sys.stderr)
        return 2
    diff_text = patch_path.read_text(encoding="utf-8", errors="replace")
    result = classify_diff(diff_text)
    json.dump(result, sys.stdout, indent=2, sort_keys=True)
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
