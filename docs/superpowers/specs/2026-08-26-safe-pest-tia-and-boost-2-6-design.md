# Safe Pest TIA and Laravel Boost 2.6

Date: 2026-08-26
Status: approved
Sequence: PR1 of 4

## 1. Goal

Make test impact analysis the normal local test path without weakening full-suite verification.

Upgrade Laravel Boost to 2.6.0 in the same infrastructure pull request.

## 2. Current problems

The current TIA command excludes suites. Pest treats exclusions as a partial selection.

A partial selection cannot represent the complete default test result.

Pest's default storage also assumes `.git` is a directory. Linked worktrees use a `.git` file.

Conductor worktrees can therefore miss shared state or overwrite shared parallel worker files.

Pest loads `tests/Pest.php` before restarting for PCOV or Xdebug. A naive child lock would deadlock.

Pest TIA rejects native PHPUnit test classes, including PHPStan `RuleTestCase` subclasses.

Boost 2.5.5 also predates the project-aware testing skill and PostgreSQL read-only query enforcement.

## 3. Invariants

- Keep the existing Testing Trophy suites.
- Do not add `tests/Unit`.
- Keep Browser tests explicit.
- Keep CI full and sharded.
- Keep targeted worktree runs concurrent.
- Do not change application behavior.
- Do not change unrelated Composer packages.
- Preserve committed Relaticle Boost rules.

## 4. Default suite

Both PHPUnit files will declare `defaultTestSuite="Default"`.

The `Default` suite contains these directories:

1. `tests/Arch`
2. `tests/PHPStan`
3. `tests/Smoke`
4. `tests/Feature`

Existing named suites remain available. Browser remains a separate named suite.

`TestSuiteIntegrityTest` will support suites with several directories. It will preserve orphan and parity checks.

It will also assert the exact Default membership in both configuration files.

The four PHPStan rule tests will use Pest-generated classes backed by abstract `RuleTestCase` bases.

This keeps PHPStan's rule harness while satisfying Pest TIA's Pest-test requirement.

## 5. Execution modes

| Invocation | TIA | Storage | Shared lock |
| --- | --- | --- | --- |
| Bare local Pest | On | Git common directory | Yes |
| `composer test:pest` | On | Git common directory | Yes |
| Targeted path, filter, group, or suite | Selection only | Worktree-local | No |
| `--no-tia` | Off | Worktree-local | No |
| `--ci` | Off | Worktree-local | No |
| Full local command | Off | Worktree-local | No |
| Browser, Arch, or type coverage | Off | Worktree-local | No |

The classifier will mirror Pest 5.1.1 partial-selection flags.

Unknown arguments will choose worktree-local storage. This protects the shared graph from accidental partial snapshots.

The shared TIA directory will sit under Git's common directory.

The worktree-local directory will sit under `storage/framework/testing`.

Both modes will call `pest()->tia()->directory(...)->locally()`.

## 6. Runtime design

`Tests\Helpers\PestTiaRuntime` owns argument classification, repository path resolution, storage selection, and driver checks.

It exposes these public entry points:

```php
PestTiaRuntime::configure(string $projectRoot, array $arguments): void;
PestTiaRuntime::usesSharedState(array $arguments): bool;
```

The class will parse normal Git directories and linked-worktree metadata.

It will not shell out to Git during Pest bootstrap.

Shared recording requires PCOV or Xdebug coverage mode. Missing coverage will fail with a direct setup message.

Targeted and TIA-disabled runs do not require a coverage driver.

## 7. Lock design

`Tests\Helpers\TiaRunLock` owns one exclusive operating-system lock.

It exposes this entry point:

```php
TiaRunLock::acquire(string $lockPath, string $workspace, int $timeoutSeconds = 900): void;
```

The lock file lives beside the shared TIA directory. A `--fresh` run cannot delete it.

The process writes JSON metadata containing a unique token, PID, workspace, and acquisition time.

The owner exports the token through `RELATICLE_PEST_TIA_LOCK_TOKEN`.

A restarted child skips acquisition only when its token matches the active metadata.

The outer process keeps the file handle alive while the child runs.

ParaTest workers skip lock acquisition because the outer process already owns the lock.

The lock polls without blocking the user interface. The timeout is 15 minutes.

A timeout reports the recorded PID, workspace, and lock path.

Process exit releases the operating-system lock. No stale-lock cleanup command is needed.

## 8. Composer commands

`composer test:pest` becomes the normal local TIA command.

It enables coverage mode and runs Pest in parallel. Composer's script timeout remains disabled.

`composer test:pest:full` runs the complete Default suite with `--no-tia`.

`composer test` keeps its quality checks before calling `test:pest`.

The redundant `test:pest:tia` command is removed.

Architecture, type coverage, Browser, CI, shard, and shard-refresh commands receive `--no-tia`.

CI commands also receive `--ci`. Existing suite exclusions are removed.

## 9. Boost update

The root `^2.0` constraint already permits Boost 2.6.0.

Run a narrow update for `laravel/boost`. Stop if Composer changes unrelated packages.

Run `php artisan boost:update --no-discover --no-interaction` after the package update.

Restore `.ai/rules/boost` and `.ai/rules/index.md` from the branch after generation.

Replace `pest-testing` with `testing-best-practices` in `boost.json` and generated agent skills.

Pest 5.1.1 still advertises its older `pest-testing` skill during Boost updates.
Exclude that name in `config/boost.php` so regeneration does not restore the duplicate.

Copy regenerated `AGENTS.md` to `GEMINI.md`. Boost does not generate Gemini skill output.

Review every generated diff before keeping it.

## 10. Tests

Infrastructure tests will cover:

- Shared versus local argument classification.
- Normal repository and linked-worktree path resolution.
- Missing coverage-driver failure guidance.
- Exclusive subprocess contention.
- One-second timeout diagnostics.
- Matching restart-token bypass.
- ParaTest worker bypass.
- Default suite membership and configuration parity.
- PHPStan rule analysis through Pest-generated test classes.

Subprocess tests will use inline PHP commands or helpers under `tests/Helpers`.

No new top-level test directory will be added.

## 11. Acceptance criteria

- A first bare run records a complete Default result.
- A second bare run can replay unchanged tests.
- Two shared runs queue behind one lock.
- A targeted run never touches shared TIA state.
- A full local command executes every Default test without TIA.
- CI executes every assigned shard without TIA.
- Browser remains excluded from bare Pest runs.
- Boost reports version 2.6.0.
- Composer reports no security advisories.
- Relaticle Boost rules remain byte-for-byte unchanged.
- Generated guidance is synchronized across supported agents.

## 12. Non-goals

- No test helper cleanup.
- No test file moves.
- No factory migration.
- No slow-test optimization.
- No production or UI changes.
