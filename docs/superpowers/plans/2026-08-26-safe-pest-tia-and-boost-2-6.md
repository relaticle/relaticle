# Safe Pest TIA and Laravel Boost 2.6 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make complete local TIA safe across linked worktrees, preserve full CI coverage, and upgrade Laravel Boost to 2.6.0.

**Architecture:** Pest bootstrap selects shared or worktree-local storage. One restart-safe file lock protects complete shared runs. PHPUnit owns normal suite selection.

**Tech Stack:** PHP 8.5, Laravel 13, Pest 5.1.1, PHPUnit XML, Composer, Laravel Boost 2.6.0, PCOV or Xdebug.

**Spec:** `docs/superpowers/specs/2026-08-26-safe-pest-tia-and-boost-2-6-design.md`

## Global constraints

- Keep `tests/Arch`, `tests/PHPStan`, `tests/Smoke`, `tests/Feature`, and `tests/Browser`.
- Do not add `tests/Unit`.
- Keep CI full and sharded.
- Do not change application behavior.
- Keep targeted worktree runs independent.
- Preserve `.ai/rules/boost` and `.ai/rules/index.md` exactly.
- Stop a Composer update that changes unrelated packages.
- Run project quality checks in the documented order.
- Do not merge, push, or tag.

## Task 1: Establish the PHPUnit Default suite

**Files:**

- Modify: `phpunit.xml`
- Modify: `phpunit.ci.xml`
- Modify: `tests/Arch/TestSuiteIntegrityTest.php`

- [ ] Capture the current suite integrity baseline.

```bash
php artisan test --compact tests/Arch/TestSuiteIntegrityTest.php
```

Expected: three tests pass.

- [ ] Add failing tests for `defaultTestSuite="Default"` in both files.

- [ ] Add a failing test for exact Default directories.

Expected directories:

```php
[
    'tests/Arch',
    'tests/PHPStan',
    'tests/Smoke',
    'tests/Feature',
]
```

- [ ] Change `declaredSuites` to return `array<string, list<string>>`.

Read all `<directory>` children for each suite. Preserve suite order.

- [ ] Flatten and deduplicate every declared directory for orphan detection.

- [ ] Add `defaultTestSuite="Default"` to both root `<phpunit>` nodes.

- [ ] Add the same aggregate suite to both files.

```xml
<testsuite name="Default">
    <directory>tests/Arch</directory>
    <directory>tests/PHPStan</directory>
    <directory>tests/Smoke</directory>
    <directory>tests/Feature</directory>
</testsuite>
```

Keep all existing named suites. Keep Browser outside Default.

- [ ] Run the integrity test again.

```bash
php artisan test --compact tests/Arch/TestSuiteIntegrityTest.php
```

Expected: five tests pass. No orphan or parity failure appears.

- [ ] Commit this task.

```bash
git add phpunit.xml phpunit.ci.xml tests/Arch/TestSuiteIntegrityTest.php
git commit -m "test: define the default test suite"
```

## Task 2: Build the pure TIA runtime classifier

**Files:**

- Create: `tests/Helpers/PestTiaRuntime.php`
- Create: `tests/Arch/PestTiaRuntimeTest.php`

**Interface:**

```php
final class PestTiaRuntime
{
    /** @param list<string> $arguments */
    public static function configure(string $projectRoot, array $arguments): void;

    /** @param list<string> $arguments */
    public static function usesSharedState(array $arguments): bool;
}
```

- [ ] Write table-driven failing tests for shared runs.

Shared examples are an empty argument list, `--parallel`, `--profile`, and output formatting flags.

- [ ] Write table-driven failing tests for local runs.

Cover explicit paths, `--filter`, `--group`, `--testsuite`, `--exclude-testsuite`, `--dirty`, `--todo`, `--ci`, and `--no-tia`.

Also cover Browser paths, `--type-coverage`, `--help`, `--version`, `--list-tests`, shards, and unknown flags.

- [ ] Implement argument normalization before classification.

Handle both `--flag=value` and `--flag value`. Treat every non-option argument as a path selection.

- [ ] Keep the classifier conservative.

Only a complete, unfiltered local invocation may return `true`.

- [ ] Add pure path-resolution methods behind the public configure interface.

Resolve both repository forms:

1. A `.git` directory is the common directory.
2. A `.git` file points to a worktree Git directory.

For a linked worktree, read its `commondir` file and normalize the relative path.

- [ ] Test both Git layouts with temporary fixture directories.

Create fixtures during the test. Remove only those exact temporary directories during teardown.

- [ ] Use these storage locations.

```text
shared:    <git-common-dir>/relaticle/pest-tia
workspace: <project-root>/storage/framework/testing/pest-tia
lock:      <git-common-dir>/relaticle-pest-tia.lock
```

- [ ] Run the focused test.

```bash
php artisan test --compact tests/Arch/PestTiaRuntimeTest.php
```

Expected: classification and Git-layout tests pass.

## Task 3: Implement restart-safe locking

**Files:**

- Create: `tests/Helpers/TiaRunLock.php`
- Extend: `tests/Arch/PestTiaRuntimeTest.php`

**Interface:**

```php
final class TiaRunLock
{
    public static function acquire(
        string $lockPath,
        string $workspace,
        int $timeoutSeconds = 900,
    ): void;
}
```

- [ ] Write a failing subprocess contention test.

The first process acquires the lock and waits. The second process must remain queued until release.

- [ ] Write a failing one-second timeout test.

Assert that output includes the holder PID, holder workspace, and lock path.

- [ ] Write a failing restart-token test.

The parent owns the lock and exports a token. A child with the matching token must return immediately.

- [ ] Write a failing ParaTest worker test.

Set `PARATEST=1`. The worker must bypass acquisition.

- [ ] Implement nonblocking `flock` polling.

Store the open resource in a static property. This retains ownership for the process lifetime.

- [ ] Generate a unique token with `random_bytes`.

Write JSON metadata only after acquisition. Include token, PID, workspace, and an ISO timestamp.

- [ ] Export `RELATICLE_PEST_TIA_LOCK_TOKEN` with `putenv`.

Also update `$_ENV` and `$_SERVER`. Pest's restart process must inherit the same value.

- [ ] Permit bypass only when metadata contains the inherited matching token.

If no active lock exists, acquire normally. Never trust metadata without checking lock ownership.

- [ ] Poll at a short fixed interval until timeout.

Use a subsecond interval. Do not issue a blocking sleep above 60 seconds.

- [ ] Run focused subprocess coverage.

```bash
php artisan test --compact tests/Arch/PestTiaRuntimeTest.php
```

Expected: contention, timeout, restart, and worker tests pass.

## Task 4: Wire Pest bootstrap and coverage preflight

**Files:**

- Modify: `tests/Pest.php`
- Modify: `tests/Helpers/PestTiaRuntime.php`
- Extend: `tests/Arch/PestTiaRuntimeTest.php`

- [ ] Write a failing subprocess test for missing coverage support.

The failure must explain how to enable PCOV or `XDEBUG_MODE=coverage`.

- [ ] Add a coverage-driver predicate.

Accept enabled PCOV. Accept Xdebug only when coverage mode is active.

- [ ] Apply the driver requirement only to shared TIA runs.

Targeted and `--no-tia` runs must continue without a coverage driver.

- [ ] Configure Pest storage before the existing test-suite declarations.

Add these imports and call:

```php
use Tests\Helpers\PestTiaRuntime;

PestTiaRuntime::configure(dirname(__DIR__), array_slice($_SERVER['argv'], 1));
```

- [ ] Inside `configure`, call the configured TIA API.

```php
pest()->tia()->directory($storagePath)->locally();
```

Acquire `TiaRunLock` before selecting the shared directory.

- [ ] Verify raw modes.

```bash
vendor/bin/pest --help
vendor/bin/pest tests/Arch/PestTiaRuntimeTest.php --no-tia --compact
XDEBUG_MODE=coverage vendor/bin/pest --list-tests
```

Expected: help returns zero, targeted tests pass, and complete test listing returns zero.

- [ ] Commit runtime work.

```bash
git add tests/Pest.php tests/Helpers/PestTiaRuntime.php \
    tests/Helpers/TiaRunLock.php tests/Arch/PestTiaRuntimeTest.php
git commit -m "test: protect shared Pest TIA state"
```

## Task 5: Update Composer test commands

**Files:**

- Modify: `composer.json`

- [ ] Replace the test scripts with these behaviors.

| Script | Required behavior |
| --- | --- |
| `test:arch` | `pest tests/Arch/ --no-tia` |
| `test:type-coverage` | existing coverage command plus `--no-tia` |
| `test:pest` | timeout disabled, coverage env, `pest --parallel` |
| `test:pest:full` | `pest --parallel --no-tia` |
| `test:pest:shard` | existing shard plus `--no-tia`, no suite exclusion |
| `test:pest:ci` | existing CI configuration plus `--ci --no-tia`, no suite exclusion |
| `test:update-shards` | existing update plus `--no-tia`, no suite exclusion |
| `test:browser` | Browser path plus `--no-tia` |

Use an array for `test:pest`:

```json
[
    "Composer\\Config::disableProcessTimeout",
    "@putenv XDEBUG_MODE=coverage",
    "pest --parallel"
]
```

- [ ] Remove `test:pest:tia`.

- [ ] Keep `test` calling `test:pest`. Keep the existing quality-check order.

- [ ] Validate Composer syntax and scripts.

```bash
composer validate --strict
composer run test:arch
composer run test:pest:full -- --filter=PestTiaRuntime
```

Expected: validation passes and both focused invocations pass.

- [ ] Commit command changes.

```bash
git add composer.json
git commit -m "test: make TIA the local default"
```

## Task 5A: Make PHPStan rule tests TIA compatible

**Files:**

- Modify: `tests/PHPStan/Rules/EloquentWriteOutsideActionRuleTest.php`
- Modify: `tests/PHPStan/Rules/HardcodedStaticPropertyRuleTest.php`
- Modify: `tests/PHPStan/Rules/HardcodedUserFacingStringRuleTest.php`
- Modify: `tests/PHPStan/Rules/RelationAccessInPolicyRuleTest.php`

- [ ] Record the failing complete TIA run.

```bash
composer test:pest
```

Expected: Pest rejects the first native PHPUnit `RuleTestCase` subclass.

- [ ] Convert one rule test to a Pest-generated class.

Keep an abstract `RuleTestCase` base. Attach it to the file with `uses()`.

Keep the existing rule configuration, fixtures, expected messages, and line numbers.

- [ ] Run the converted file without TIA.

```bash
vendor/bin/pest tests/PHPStan/Rules/EloquentWriteOutsideActionRuleTest.php --compact --no-tia
```

Expected: the two existing rule behaviors pass.

- [ ] Run complete TIA again.

Expected: Pest accepts the converted file and stops at another native PHPUnit rule test.

- [ ] Convert the other three rule tests using the same structure.

- [ ] Run all four rule tests without TIA.

```bash
vendor/bin/pest tests/PHPStan/Rules --compact --no-tia
```

Expected: nine tests pass with their original analysis assertions.

- [ ] Commit the compatibility conversion.

```bash
git add tests/PHPStan/Rules
git commit -m "test: make PHPStan rule tests TIA compatible"
```

## Task 6: Upgrade Laravel Boost narrowly

**Files:**

- Modify: `composer.lock`
- Modify: `boost.json`
- Modify: `config/boost.php`
- Modify: generated agent guidance and skill files
- Preserve: `.ai/rules/boost/**`
- Preserve: `.ai/rules/index.md`

- [ ] Capture protected rule hashes.

```bash
find .ai/rules/boost -type f -print0 | sort -z | xargs -0 shasum -a 256 > .context/boost-rules-before.sha256
shasum -a 256 .ai/rules/index.md >> .context/boost-rules-before.sha256
```

- [ ] Preview the narrow dependency update.

```bash
composer update laravel/boost --dry-run
```

Expected: only `laravel/boost` changes from 2.5.5 to 2.6.0.

Stop if the plan changes another installed package.

- [ ] Apply the same narrow update.

```bash
composer update laravel/boost
```

- [ ] Confirm the installed version and advisory status.

```bash
composer show laravel/boost
composer audit
```

Expected: Boost 2.6.0 and no security advisories.

- [ ] Replace `pest-testing` with `testing-best-practices` in `boost.json`.

- [ ] Add `pest-testing` to `boost.skills.exclude` in `config/boost.php`.

Pest 5.1.1 still advertises its bundled copy. The exclusion prevents future
Boost updates from restoring the duplicate skill.

- [ ] Regenerate Boost output.

```bash
php artisan boost:update --no-discover --no-interaction
```

- [ ] Restore protected Relaticle rules from `HEAD`.

```bash
git restore --source=HEAD -- .ai/rules/boost .ai/rules/index.md
```

- [ ] Copy regenerated `AGENTS.md` to `GEMINI.md` with `apply_patch`.

Do not edit either compiled file by hand.

- [ ] Verify protected rule hashes.

```bash
find .ai/rules/boost -type f -print0 | sort -z | xargs -0 shasum -a 256 > .context/boost-rules-after.sha256
shasum -a 256 .ai/rules/index.md >> .context/boost-rules-after.sha256
diff -u .context/boost-rules-before.sha256 .context/boost-rules-after.sha256
```

Expected: no diff.

- [ ] Review generated changes.

Confirm supported agents receive `testing-best-practices`. Confirm old `pest-testing` output is removed.

Confirm `AGENTS.md`, `CLAUDE.md`, and `GEMINI.md` contain Relaticle's Testing Trophy guidance.

- [ ] Commit the Boost update.

List generated changes with `git status --short`. Stage each reviewed path explicitly.

```bash
git add composer.lock boost.json AGENTS.md CLAUDE.md GEMINI.md
git add -u .claude/skills/pest-testing
git add .claude/skills/testing-best-practices
git commit -m "chore: update Laravel Boost to 2.6"
```

Add other generated agent paths only when `boost:update` creates them. Review each path before staging.

## Task 7: Exercise shared and local TIA modes

**Files:**

- No expected source changes

- [ ] Clear only the resolved shared TIA storage directory.

Read the path from the runtime test or a diagnostic. Do not use a broad recursive target.

- [ ] Record a complete shared graph.

```bash
composer test:pest
```

Expected: every Default test executes. Browser tests do not execute.

- [ ] Run the same command again.

Expected: Pest replays unchanged tests from the complete shared result.

- [ ] Run one targeted file.

```bash
vendor/bin/pest tests/Arch/PestTiaRuntimeTest.php --compact
```

Expected: only that file runs. Shared graph modification time stays unchanged.

- [ ] Prove lock queuing with two shell sessions or the subprocess test.

Expected: the second shared run waits. It starts after the first releases the lock.

- [ ] Run the explicit full escape hatch.

```bash
composer test:pest:full
```

Expected: every Default test executes without TIA.

## Task 8: Final verification

- [ ] Run checks in project order.

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
php artisan test --compact tests/Arch/TestSuiteIntegrityTest.php tests/Arch/PestTiaRuntimeTest.php --no-tia
```

Expected: Pint is clean, Rector suggests nothing, PHPStan passes, and type coverage remains 100 percent.

- [ ] Run the complete local suite without TIA.

```bash
composer test:pest:full
```

Expected: all Default tests pass.

- [ ] Run repository-wide lint.

```bash
composer test:lint
```

Expected: Pint reports no style errors.

- [ ] Review the final diff against the prerequisite branch.

```bash
git diff --stat origin/main...HEAD
git diff --check origin/main...HEAD
git status --short
```

Expected: only PR1 files appear. No whitespace errors exist.

- [ ] Prepare the pull request draft. Do not publish it without explicit approval.
