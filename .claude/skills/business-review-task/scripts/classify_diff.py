#!/usr/bin/env python3
"""Classify a unified diff into change types for business-review planning.

Usage:
    python3 classify_diff.py <path-to-pr-diff.patch> [--profile <project-profile.json>]
    python3 classify_diff.py --test

Reads a unified diff patch, classifies changes by path/extension families, and
writes diff-classification.json to stdout.

Output schema (JSON, sorted keys):
{
  "change_types":       ["template", "component", "route", "controller", ...],
  "touched_files":      {"template": ["src/views/Login.vue"], ...},
  "surfaces":           ["src/views/Login.vue"],
  "infra_only":         false,
  "critical_signals":   ["auth", "payment"],
  "subsystems":         [...],        // = change_types (compat alias for compute_tier.py)
  "critical_subsystems":[...],        // = critical_signals u project_subsystems (compat alias)
  "project_subsystems": ["grants"]    // ONLY present when --profile given
}

compute_tier.py reads `critical_subsystems` and `subsystems` -- we emit both aliases
so that script is usable unchanged.

Pure stdlib. No hardcoded domain subsystems.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


# ---------------------------------------------------------------------------
# Generic change-type families (stack-agnostic path/extension rules)
# ---------------------------------------------------------------------------

# Template extensions / path patterns: files that render HTML/UI
_TEMPLATE_EXTS = {".blade.php", ".vue", ".jsx", ".tsx", ".svelte", ".erb", ".twig", ".html"}
_TEMPLATE_PATH_RE = re.compile(
    r"(resources/views/|templates/|views/|pages/)", re.IGNORECASE
)

# Component patterns: UI component files
_COMPONENT_PATH_RE = re.compile(
    r"(/components/|/Livewire/|/livewire/)", re.IGNORECASE
)
_COMPONENT_EXTS = {".jsx", ".tsx", ".vue", ".svelte"}

# Route patterns: routing and API definition files
_ROUTE_PATH_RE = re.compile(
    r"(^routes/|/routes/|/router\b|/api/)", re.IGNORECASE
)

# Controller / handler patterns: request handlers
_CONTROLLER_PATH_RE = re.compile(r"(Controller|Handler)", re.IGNORECASE)

# Validation patterns: rule classes, request validators
_VALIDATION_PATH_RE = re.compile(r"(Rule|Request|validat)", re.IGNORECASE)

# Config: configuration files
_CONFIG_PATH_RE = re.compile(
    r"(^config/|/config/|\.config\.(js|ts|php|rb|py)$|webpack\.|vite\.|tailwind\.)", re.IGNORECASE
)

# Migration patterns
_MIGRATION_PATH_RE = re.compile(r"/migrations?/", re.IGNORECASE)

# Infra: jobs, console, tests, CI
_INFRA_PATH_RE = re.compile(
    r"(^tests?/|/tests?/|Jobs?/|Console/|\.github/|/commands/|app/Jobs/|app/Console/)",
    re.IGNORECASE,
)

# ---------------------------------------------------------------------------
# Generic critical signal patterns (content OR path)
# ---------------------------------------------------------------------------

_CRITICAL_SIGNALS: dict[str, re.Pattern] = {
    "auth":        re.compile(r"auth|login|password|session", re.IGNORECASE),
    "payment":     re.compile(r"payment|billing|charge|invoice|subscription", re.IGNORECASE),
    "destructive": re.compile(r"delete|destroy|drop|truncate|purge", re.IGNORECASE),
    "permission":  re.compile(r"permission|authoriz|policy|acl", re.IGNORECASE),
    "migration":   re.compile(r"migration", re.IGNORECASE),
    "security":    re.compile(r"security|secret|token|crypto", re.IGNORECASE),
}


# ---------------------------------------------------------------------------
# Classifiers
# ---------------------------------------------------------------------------

def _path_ext(path: str) -> str:
    """Return dotted extension, e.g. '.php', or '' if none."""
    if "." not in path.rsplit("/", 1)[-1]:
        return ""
    return "." + path.rsplit(".", 1)[-1]


def _classify_change_type(path: str, hunk_text: str) -> list[str]:
    """Return sorted list of change_types this file contributes. Markdown files skipped."""
    if path.endswith(".md"):
        return []

    types: set[str] = set()
    ext = _path_ext(path)

    # template
    if ext in _TEMPLATE_EXTS or _TEMPLATE_PATH_RE.search(path):
        types.add("template")

    # component
    if _COMPONENT_PATH_RE.search(path) or ext in _COMPONENT_EXTS:
        types.add("component")

    # route
    if _ROUTE_PATH_RE.search(path):
        types.add("route")

    # controller
    if _CONTROLLER_PATH_RE.search(path):
        types.add("controller")

    # validation
    if _VALIDATION_PATH_RE.search(path):
        types.add("validation")

    # config
    if _CONFIG_PATH_RE.search(path):
        types.add("config")

    # migration
    if _MIGRATION_PATH_RE.search(path):
        types.add("migration")

    # infra (jobs, console, tests, ci)
    if _INFRA_PATH_RE.search(path):
        types.add("infra")

    return sorted(types)


def _is_surface_path(path: str) -> bool:
    """Return True if this file is user-facing (renders UI or handles a request)."""
    if path.endswith(".md"):
        return False
    ext = _path_ext(path)
    if ext in _TEMPLATE_EXTS:
        return True
    if _TEMPLATE_PATH_RE.search(path):
        return True
    if _COMPONENT_PATH_RE.search(path) or ext in _COMPONENT_EXTS:
        return True
    if _ROUTE_PATH_RE.search(path):
        return True
    if _CONTROLLER_PATH_RE.search(path):
        return True
    return False


def _is_infra_only_path(path: str) -> bool:
    """Return True if the file is purely infrastructure (no UI surface)."""
    if _MIGRATION_PATH_RE.search(path):
        return True
    if _INFRA_PATH_RE.search(path):
        return True
    if _CONFIG_PATH_RE.search(path):
        return True
    return False


def _detect_critical_signals(path: str, hunk_text: str) -> set[str]:
    """Return set of critical signal names matched by path or added-line content.

    Markdown files are documentation — their path and content are never scanned
    for critical signals (documentation may mention sensitive terms in prose).
    """
    if path.endswith(".md"):
        return set()
    matched: set[str] = set()
    combined = path + "\n" + hunk_text
    for signal_name, pattern in _CRITICAL_SIGNALS.items():
        if pattern.search(combined):
            matched.add(signal_name)
    return matched


def _compile_profile_patterns(profile: dict) -> list[tuple[str, re.Pattern]]:
    """Build (area_name, compiled_pattern) list from profile.sensitive_areas[].

    Each area string is matched as a case-insensitive substring. To cover both
    plural spellings ("grants") and singular roots ("grant", "Grant"), we also
    match the singular form (area with trailing 's' stripped when present).
    """
    patterns: list[tuple[str, re.Pattern]] = []
    for area in profile.get("sensitive_areas", []):
        if not isinstance(area, str) or not area:
            continue
        # Build alternation: match full area OR singular root (strip trailing 's')
        singular = area.rstrip("s") if area.endswith("s") and len(area) > 1 else area
        if singular != area:
            pat = re.compile(
                r"(?:" + re.escape(area) + r"|" + re.escape(singular) + r")",
                re.IGNORECASE,
            )
        else:
            pat = re.compile(re.escape(area), re.IGNORECASE)
        patterns.append((area, pat))
    return patterns


def _detect_project_subsystems(
    path: str,
    hunk_text: str,
    profile_patterns: list[tuple[str, re.Pattern]],
) -> set[str]:
    """Return project-specific subsystem names matched from profile sensitive_areas."""
    matched: set[str] = set()
    combined = path + "\n" + hunk_text
    for area_name, pattern in profile_patterns:
        if pattern.search(combined):
            matched.add(area_name)
    return matched


# ---------------------------------------------------------------------------
# Diff parser
# ---------------------------------------------------------------------------

def parse_diff(diff_text: str) -> list[tuple[str, str]]:
    """Return list of (file_path, combined_added_lines_text) tuples."""
    files: list[tuple[str, str]] = []
    current_file: str | None = None
    current_hunks: list[str] = []

    for line in diff_text.splitlines():
        if line.startswith("diff --git"):
            if current_file is not None:
                files.append((current_file, "\n".join(current_hunks)))
            m = re.match(r"^diff --git a/\S+ b/(\S+)$", line)
            current_file = m.group(1) if m else None
            current_hunks = []
        elif line.startswith("+") and not line.startswith("+++"):
            current_hunks.append(line[1:])

    if current_file is not None:
        files.append((current_file, "\n".join(current_hunks)))

    return files


# ---------------------------------------------------------------------------
# Top-level classifier
# ---------------------------------------------------------------------------

def classify_diff(diff_text: str, profile: dict | None = None) -> dict:
    """Parse diff, classify each file, aggregate. profile may be None."""
    files = parse_diff(diff_text)

    profile_patterns = _compile_profile_patterns(profile) if profile else []

    touched: dict[str, list[str]] = {}
    surfaces: list[str] = []
    all_file_infra = True if files else False
    critical_signals_all: set[str] = set()
    project_subsystems_all: set[str] = set()

    for path, hunk in files:
        is_infra = _is_infra_only_path(path)
        if not is_infra:
            all_file_infra = False

        if _is_surface_path(path):
            surfaces.append(path)

        types = _classify_change_type(path, hunk)
        for t in types:
            touched.setdefault(t, []).append(path)

        critical_signals_all.update(_detect_critical_signals(path, hunk))

        if profile_patterns:
            project_subsystems_all.update(
                _detect_project_subsystems(path, hunk, profile_patterns)
            )

    change_types = sorted(touched.keys())

    # infra_only: there are files AND none are user-facing surfaces AND all are infra paths
    infra_only = bool(files) and len(surfaces) == 0 and all_file_infra

    # Compatibility aliases for compute_tier.py (which reads these keys unchanged):
    #   subsystems          = change_types  (deduped surface-family list)
    #   critical_subsystems = critical_signals u project_subsystems
    critical_subsystems = sorted(critical_signals_all | project_subsystems_all)

    result: dict = {
        "change_types": change_types,
        "touched_files": touched,
        "surfaces": sorted(set(surfaces)),
        "infra_only": infra_only,
        "critical_signals": sorted(critical_signals_all),
        "subsystems": change_types,
        "critical_subsystems": critical_subsystems,
    }

    if profile is not None:
        result["project_subsystems"] = sorted(project_subsystems_all)

    return result


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

def _run_tests() -> int:
    failures: list[str] = []

    def assert_eq(label: str, actual, expected) -> None:
        if actual == expected:
            print(f"  PASS: {label}")
        else:
            print(f"  FAIL: {label}")
            print(f"    expected: {expected!r}")
            print(f"    actual:   {actual!r}")
            failures.append(label)

    def assert_in(label: str, item, container) -> None:
        if item in container:
            print(f"  PASS: {label}")
        else:
            print(f"  FAIL: {label}")
            print(f"    {item!r} not in {container!r}")
            failures.append(label)

    # T1: Vue template change -> surface + change_types includes template
    diff = (
        "diff --git a/src/views/LoginPage.vue b/src/views/LoginPage.vue\n"
        "index abc..def 100644\n"
        "--- a/src/views/LoginPage.vue\n"
        "+++ b/src/views/LoginPage.vue\n"
        "@@ -1,3 +1,4 @@\n"
        "+<template><div>Login</div></template>\n"
    )
    r = classify_diff(diff)
    assert_in("T1 vue: template in change_types", "template", r["change_types"])
    assert_eq("T1 vue: infra_only false", r["infra_only"], False)
    assert_in("T1 vue: file in surfaces", "src/views/LoginPage.vue", r["surfaces"])

    # T2: TSX component file -> component + template
    diff = (
        "diff --git a/src/components/PaymentForm.tsx b/src/components/PaymentForm.tsx\n"
        "index a..b 100644\n"
        "--- a/src/components/PaymentForm.tsx\n"
        "+++ b/src/components/PaymentForm.tsx\n"
        "@@ -1,2 +1,3 @@\n"
        "+export function PaymentForm() { return <form />; }\n"
    )
    r = classify_diff(diff)
    assert_in("T2 tsx: component in change_types", "component", r["change_types"])
    assert_in("T2 tsx: template in change_types (tsx ext)", "template", r["change_types"])
    assert_eq("T2 tsx: infra_only false", r["infra_only"], False)

    # T3: auth/login path -> critical_signals includes auth
    diff = (
        "diff --git a/app/Http/Controllers/Auth/LoginController.php b/app/Http/Controllers/Auth/LoginController.php\n"
        "index a..b 100644\n"
        "--- a/app/Http/Controllers/Auth/LoginController.php\n"
        "+++ b/app/Http/Controllers/Auth/LoginController.php\n"
        "@@ -1,2 +1,3 @@\n"
        "+// updated login logic\n"
    )
    r = classify_diff(diff)
    assert_in("T3 auth path: auth signal", "auth", r["critical_signals"])
    assert_in("T3 auth path: auth in critical_subsystems (compat)", "auth", r["critical_subsystems"])

    # T4: PaymentController with charge method -> critical payment signal
    diff = (
        "diff --git a/app/Http/Controllers/PaymentController.php b/app/Http/Controllers/PaymentController.php\n"
        "index a..b 100644\n"
        "--- a/app/Http/Controllers/PaymentController.php\n"
        "+++ b/app/Http/Controllers/PaymentController.php\n"
        "@@ -10,3 +10,7 @@\n"
        "+    public function charge(Request $r)\n"
        "+    {\n"
        "+        return Payment::create($r->all());\n"
        "+    }\n"
    )
    r = classify_diff(diff)
    assert_in("T4 payment: payment signal", "payment", r["critical_signals"])
    assert_in("T4 payment: controller change_type", "controller", r["change_types"])

    # T5: Migration-only -> infra_only true
    diff = (
        "diff --git a/database/migrations/2026_01_01_add_col.php b/database/migrations/2026_01_01_add_col.php\n"
        "index a..b 100644\n"
        "--- a/database/migrations/2026_01_01_add_col.php\n"
        "+++ b/database/migrations/2026_01_01_add_col.php\n"
        "@@ -1,2 +1,3 @@\n"
        "+Schema::table('x', fn($t) => $t->string('y'));\n"
    )
    r = classify_diff(diff)
    assert_eq("T5 migration: infra_only true", r["infra_only"], True)
    assert_eq("T5 migration: surfaces empty", r["surfaces"], [])
    assert_in("T5 migration: migration signal", "migration", r["critical_signals"])

    # T6: --profile with sensitive_areas:["grants"] + path containing "grant"
    diff = (
        "diff --git a/app/Services/SupportingGrantResolver.php b/app/Services/SupportingGrantResolver.php\n"
        "index a..b 100644\n"
        "--- a/app/Services/SupportingGrantResolver.php\n"
        "+++ b/app/Services/SupportingGrantResolver.php\n"
        "@@ -1,2 +1,3 @@\n"
        "+// new resolution logic\n"
    )
    profile = {"sensitive_areas": ["grants", "auth"]}
    r = classify_diff(diff, profile=profile)
    assert_in("T6 grants: project_subsystems contains grants", "grants", r.get("project_subsystems", []))
    assert_in("T6 grants: lands in critical_subsystems", "grants", r["critical_subsystems"])
    assert_eq("T6 project_subsystems key present", "project_subsystems" in r, True)

    # T7: No profile -> project_subsystems key absent
    diff = (
        "diff --git a/app/Services/GrantService.php b/app/Services/GrantService.php\n"
        "index a..b 100644\n--- a/x\n+++ b/x\n@@ -1 +1,2 @@\n+// changed\n"
    )
    r = classify_diff(diff)
    assert_eq("T7 no profile: project_subsystems absent", "project_subsystems" in r, False)

    # T8: Empty diff
    r = classify_diff("")
    assert_eq("T8 empty: change_types", r["change_types"], [])
    assert_eq("T8 empty: infra_only false (no files)", r["infra_only"], False)
    assert_eq("T8 empty: surfaces empty", r["surfaces"], [])

    # T9: Markdown files never produce change_types or signals
    diff = (
        "diff --git a/docs/auth-design.md b/docs/auth-design.md\n"
        "index a..b 100644\n"
        "--- a/docs/auth-design.md\n"
        "+++ b/docs/auth-design.md\n"
        "@@ -1,2 +1,3 @@\n"
        "+Mentions login, password, payment, delete in documentation.\n"
    )
    r = classify_diff(diff)
    assert_eq("T9 md: no change_types", r["change_types"], [])
    assert_eq("T9 md: no critical_signals", r["critical_signals"], [])
    assert_eq("T9 md: surfaces empty", r["surfaces"], [])

    # T10: compute_tier.py compatibility - both aliases present
    diff = (
        "diff --git a/app/Http/Controllers/Auth/LoginController.php b/app/Http/Controllers/Auth/LoginController.php\n"
        "index a..b 100644\n--- a/x\n+++ b/x\n@@ -1 +1,2 @@\n+// update\n"
    )
    r = classify_diff(diff)
    assert_eq("T10 compat: subsystems key exists", "subsystems" in r, True)
    assert_eq("T10 compat: critical_subsystems key exists", "critical_subsystems" in r, True)
    assert_eq("T10 compat: subsystems == change_types", r["subsystems"], r["change_types"])

    import tempfile
    import subprocess
    with tempfile.TemporaryDirectory() as tmp:
        d = Path(tmp)
        (d / "diff-classification.json").write_text(json.dumps(r), encoding="utf-8")
        (d / "acceptance-criteria.json").write_text(
            json.dumps({"criteria": [{"id": 1}]}), encoding="utf-8"
        )
        here = Path(__file__).resolve().parent
        proc = subprocess.run(
            [sys.executable, str(here / "compute_tier.py"), str(d)],
            capture_output=True, text=True,
        )
        assert_eq("T10 compute_tier integration: exit 0", proc.returncode, 0)
        if proc.returncode == 0:
            tier_data = json.loads((d / "tier.json").read_text())
            assert_eq("T10 compute_tier: tier key present", "tier" in tier_data, True)

    # T11: Blade template (Laravel) — checkout summary view
    diff = (
        "diff --git a/resources/views/checkout/summary.blade.php b/resources/views/checkout/summary.blade.php\n"
        "index a..b 100644\n--- a/x\n+++ b/x\n@@ -1,2 +1,3 @@\n"
        "+<div class=\"checkout-total\">Total: {{ $total }}</div>\n"
    )
    r = classify_diff(diff)
    assert_in("T11 blade: template in change_types", "template", r["change_types"])
    assert_in("T11 blade: file in surfaces", "resources/views/checkout/summary.blade.php", r["surfaces"])
    # checkout/summary.blade.php path contains no payment/billing/charge keywords — no payment signal
    assert_eq("T11 blade: no payment signal (no payment keyword in path or content)", "payment" in r["critical_signals"], False)

    # T11b: Blade view with billing keyword in added content -> payment signal
    diff_billing = (
        "diff --git a/resources/views/billing/invoice.blade.php b/resources/views/billing/invoice.blade.php\n"
        "index a..b 100644\n--- a/x\n+++ b/x\n@@ -1,2 +1,3 @@\n"
        "+<h1>Your invoice</h1>\n"
    )
    r_billing = classify_diff(diff_billing)
    assert_in("T11b billing: payment signal from path", "payment", r_billing["critical_signals"])

    # T12: Livewire/SignupForm.php -> component
    diff = (
        "diff --git a/app/Livewire/SignupForm.php b/app/Livewire/SignupForm.php\n"
        "index a..b 100644\n--- a/x\n+++ b/x\n@@ -1,2 +1,3 @@\n+public function save() {}\n"
    )
    r = classify_diff(diff)
    assert_in("T12 livewire: component in change_types", "component", r["change_types"])
    assert_in("T12 livewire: file in surfaces", "app/Livewire/SignupForm.php", r["surfaces"])

    # T13: purge keyword -> destructive signal
    diff = (
        "diff --git a/app/Services/AccountService.php b/app/Services/AccountService.php\n"
        "index a..b 100644\n--- a/x\n+++ b/x\n@@ -1,2 +1,3 @@\n"
        "+public function purgeUserData(int $userId): void {}\n"
    )
    r = classify_diff(diff)
    assert_in("T13 destructive: purge signal", "destructive", r["critical_signals"])

    # T14: empty profile -> project_subsystems = []
    diff = (
        "diff --git a/app/Http/Controllers/HomeController.php b/app/Http/Controllers/HomeController.php\n"
        "index a..b 100644\n--- a/x\n+++ b/x\n@@ -1 +1,2 @@\n+// homepage\n"
    )
    r = classify_diff(diff, profile={})
    assert_eq("T14 empty profile: project_subsystems empty list", r.get("project_subsystems"), [])

    print()
    if failures:
        print(f"FAILED: {len(failures)} test(s): {', '.join(failures)}")
        return 1
    print("All tests passed.")
    return 0


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main() -> int:
    argv = sys.argv
    if len(argv) < 2:
        print("Usage: classify_diff.py <patch> [--profile <profile.json>] | --test",
              file=sys.stderr)
        return 2

    if argv[1] == "--test":
        return _run_tests()

    patch_path = Path(argv[1])
    if not patch_path.exists():
        print(f"Patch not found: {patch_path}", file=sys.stderr)
        return 2

    profile: dict | None = None
    if "--profile" in argv:
        idx = argv.index("--profile")
        if idx + 1 >= len(argv):
            print("--profile requires a path argument", file=sys.stderr)
            return 2
        profile_path = Path(argv[idx + 1])
        if profile_path.exists():
            try:
                profile = json.loads(profile_path.read_text(encoding="utf-8"))
            except json.JSONDecodeError as exc:
                print(f"Profile JSON is invalid: {exc}", file=sys.stderr)
                return 1
        else:
            print(f"Warning: profile not found at {profile_path}, ignoring", file=sys.stderr)

    diff_text = patch_path.read_text(encoding="utf-8", errors="replace")
    result = classify_diff(diff_text, profile=profile)
    json.dump(result, sys.stdout, indent=2, sort_keys=True)
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
