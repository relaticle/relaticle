# Test Helper and Assertion Hygiene Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect global Pest helper collisions and remove three confirmed meaningless negative assertions.

**Architecture:** An Arch test parses test files with PHP Parser. It reports duplicate global functions and all `function_exists` guards.

**Tech Stack:** PHP 8.5, Pest 5.1.1, `nikic/php-parser` 5.8.0, Laravel 13.

**Spec:** `docs/superpowers/specs/2026-08-26-test-helper-and-assertion-hygiene-design.md`

**Prerequisite:** Complete `2026-08-26-safe-pest-tia-and-boost-2-6.md` first.

## Global constraints

- Do not assert against PHP source text.
- Do not add a Composer dependency.
- Keep valid runtime regression assertions.
- Do not change application files.
- Keep all tests inside existing suite directories.
- Run tests with `--no-tia` during targeted development.
- Do not merge, push, or tag.

## Task 1: Add the global-helper architecture test

**Files:**

- Create: `tests/Arch/GlobalTestHelperIntegrityTest.php`

- [ ] Confirm PHP Parser is installed.

```bash
composer show nikic/php-parser
```

Expected: version 5.8.0 or a compatible installed 5.x release.

- [ ] Create one recursive PHP-file provider inside the test file.

Use a local closure. Do not declare another global helper function.

- [ ] Parse each PHP file under `tests` with `ParserFactory::createForNewestSupportedVersion()`.

Use `NodeFinder` to find `PhpParser\Node\Stmt\Function_` nodes.

- [ ] Build a map with lowercase function names.

Store each relative file path and start line. PHP function names are case-insensitive.

- [ ] Add the duplicate declaration assertion.

Format each collision like this:

```text
makeRow: tests/.../ExecuteImportJobTest.php:123, tests/.../PreviewStepTest.php:45
```

The assertion message must list every collision in one run.

- [ ] Use `NodeFinder` to find `Expr\FuncCall` nodes named `function_exists`.

Collect each relative path and line. Reject every occurrence under `tests`.

- [ ] Run the new test and confirm the intended failure.

```bash
php artisan test --compact tests/Arch/GlobalTestHelperIntegrityTest.php --no-tia
```

Expected: failure names both `makeRow` declarations and both `function_exists` guards.

If another collision appears, inspect it. Do not expand scope without documenting the finding.

## Task 2: Rename the ImportWizard helpers

**Files:**

- Modify: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`
- Modify: `tests/Feature/ImportWizard/Livewire/PreviewStepTest.php`
- Modify: `tests/Helpers/PestTiaRuntime.php`

- [ ] In `ExecuteImportJobTest.php`, rename `makeRow` to `makeExecuteImportRow`.

Update every call in that file. Remove its surrounding `function_exists` guard.

- [ ] In `PreviewStepTest.php`, rename `makeRow` to `makePreviewRow`.

Update every call in that file. Remove its surrounding `function_exists` guard.

- [ ] Remove the redundant `xdebug_info` guard from `PestTiaRuntime`.

Keep the `extension_loaded('xdebug')` check. PHP 8.5 requires Xdebug 3.5 or newer, which provides `xdebug_info`.

- [ ] Verify the old helper and guard are absent.

```bash
rg -n 'function makeRow|function_exists\(' tests --glob '!GlobalTestHelperIntegrityTest.php'
```

Expected: no matches.

- [ ] Run both affected test files together.

```bash
php artisan test --compact \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php \
    tests/Feature/ImportWizard/Livewire/PreviewStepTest.php \
    --no-tia
```

Expected: both files pass in the same process.

- [ ] Run the new architecture test.

```bash
php artisan test --compact tests/Arch/GlobalTestHelperIntegrityTest.php --no-tia
```

Expected: duplicate and guard checks pass.

- [ ] Run the focused TIA runtime test.

```bash
php artisan test --compact tests/Arch/PestTiaRuntimeTest.php --no-tia
```

Expected: driver detection and all runtime modes pass.

- [ ] Commit helper enforcement and fixes together.

```bash
git add tests/Arch/GlobalTestHelperIntegrityTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php \
    tests/Feature/ImportWizard/Livewire/PreviewStepTest.php \
    tests/Helpers/PestTiaRuntime.php
git commit -m "test: enforce unique global helpers"
```

## Task 3: Remove confirmed obsolete negative assertions

**Files:**

- Modify: `tests/Feature/Public/PublicPagesTest.php`

- [ ] Run the current file as a baseline.

```bash
php artisan test --compact tests/Feature/Public/PublicPagesTest.php --no-tia
```

Expected: all tests pass.

- [ ] Find and remove only assertions for these literals.

```text
word usage
Basic" plan
registered mail
```

Do not remove their containing tests. Keep all positive assertions and other negative guards.

- [ ] Recount reviewed absent-literal guards.

Use the existing audit output in `.context/negative-assertions-all.txt` as a checklist.

Expected: the three obsolete entries are gone. The other 44 reviewed entries remain.

- [ ] Run the public pages test.

```bash
php artisan test --compact tests/Feature/Public/PublicPagesTest.php --no-tia
```

Expected: every test passes with unchanged behavior coverage.

- [ ] Commit the assertion cleanup separately.

```bash
git add tests/Feature/Public/PublicPagesTest.php
git commit -m "test: remove obsolete legal copy guards"
```

## Task 4: Final verification

- [ ] Run the full Arch suite.

```bash
composer test:arch
```

Expected: all architecture tests pass.

- [ ] Run targeted behavior coverage.

```bash
php artisan test --compact \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php \
    tests/Feature/ImportWizard/Livewire/PreviewStepTest.php \
    tests/Feature/Public/PublicPagesTest.php \
    --no-tia
```

Expected: all tests pass.

- [ ] Run checks in project order.

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

Expected: no Rector or PHPStan findings. Type coverage remains 100 percent.

- [ ] Run the complete Default suite.

```bash
composer test:pest:full
```

Expected: all tests pass.

- [ ] Run repository-wide lint.

```bash
composer test:lint
```

Expected: no style errors.

- [ ] Inspect the final pull request diff.

```bash
git diff --check origin/main...HEAD
git status --short
```

Expected: only the architecture test and three reviewed test files changed.

- [ ] Prepare the pull request draft. Do not publish it without explicit approval.
