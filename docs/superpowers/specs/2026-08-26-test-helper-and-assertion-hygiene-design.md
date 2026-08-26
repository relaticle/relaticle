# Test Helper and Assertion Hygiene

Date: 2026-08-26
Status: approved
Sequence: PR2 of 4
Prerequisite: safe Pest TIA and Boost 2.6

## 1. Goal

Prevent global Pest helper collisions and remove only confirmed meaningless negative assertions.

## 2. Current problems

Two ImportWizard test files declare a guarded global `makeRow` helper.

The `function_exists` guards hide the collision. Test load order decides which implementation wins.

The PR1 TIA runtime also guards `xdebug_info`. PHP 8.5 requires Xdebug 3.5 or newer, which always provides that function.

A broad text search also identified 47 negative string assertions whose literal text is absent from source.

That heuristic produces many false positives. Rendered output, dynamic payloads, and regression guards remain valid coverage.

## 3. Helper rule

Every global function declared under `tests` must have a unique name.

Tests must not guard helper declarations with `function_exists`.

Local closures and class methods remain allowed.

The two duplicate helpers will become:

- `makeExecuteImportRow`
- `makePreviewRow`

Their `function_exists` guards will be removed.

## 4. Enforcement design

Create `tests/Arch/GlobalTestHelperIntegrityTest.php`.

Use the installed `nikic/php-parser` package to parse every PHP file under `tests`.

Collect only `PhpParser\Node\Stmt\Function_` declarations.

Class and trait methods use different node types. They will not trigger this rule.

Fail duplicate names with each file path and source line.

Also reject every `function_exists` call under `tests`.

Remove the redundant TIA runtime guard while preserving its extension check.

The rule will inspect syntax trees. It will not assert against source text.

Do not add a new Composer dependency.

## 5. Negative assertion triage

Remove these three obsolete assertions from `tests/Feature/Public/PublicPagesTest.php`:

- `word usage`
- `Basic" plan`
- `registered mail`

Keep the remaining 44 reviewed assertions.

They cover dynamic SQL redaction, prompt protocols, pricing accuracy, UI transitions, timezone conversion, and rendered transformations.

Do not add a source-literal allowlist. There is no reliable gate to maintain.

Do not copy MaxForms' absent-literal check. It conflicts with Relaticle's runtime-testing rule.

## 6. Tests

The new architecture test must fail against the current duplicate `makeRow` declarations.

The failure must name both files and lines.

After renaming, run both affected ImportWizard files together.

Run `PublicPagesTest` after removing the three assertions.

Run the complete Arch suite to verify the enforcement rule.

## 7. Acceptance criteria

- No duplicate global function name exists under `tests`.
- No `function_exists` call exists under `tests`.
- TIA coverage-driver detection still passes its focused tests.
- Both ImportWizard test files pass together.
- The three obsolete legal-template checks are gone.
- Every other reviewed negative assertion remains unchanged.
- No source-text assertion test is introduced.

## 8. Non-goals

- No broad assertion rewrite.
- No global-helper ban.
- No test file reclassification.
- No factory migration.
- No application changes.
