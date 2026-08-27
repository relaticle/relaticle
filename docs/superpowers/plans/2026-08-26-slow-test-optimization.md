# Slow Test Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve shard balance and TIA precision by splitting three large test files and trimming redundant scale volume.

**Architecture:** Static fixture classes replace file-global builders. Domain-focused test files become independent scheduling and TIA units.

**Tech Stack:** PHP 8.5, Laravel 13, Pest 5.1.1, PostgreSQL, existing factories and test helpers.

**Spec:** `docs/superpowers/specs/2026-08-26-slow-test-optimization-design.md`

**Prerequisite:** Complete `2026-08-26-test-organization-factories-and-rector.md` first.

## Global constraints

- Change test files only.
- Preserve all 226 test descriptions.
- Preserve every assertion's intent.
- Keep production-shaped database behavior.
- Keep one 1,000-row import case.
- Keep two cases above the 500-row chunk boundary.
- Do not add global helper functions.
- Do not change application code for speed.
- Run targeted development tests with `--no-tia`.
- Do not merge, push, or tag.

## Task 1: Capture reproducible baselines

**Files:**

- Create local evidence only under `.context`

- [ ] Record each original test list.

```bash
vendor/bin/pest tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php --list-tests --no-tia > .context/pr4-import-tests-before.txt
vendor/bin/pest tests/Feature/Chat/ProposalCardComponentTest.php --list-tests --no-tia > .context/pr4-proposal-tests-before.txt
vendor/bin/pest tests/Feature/Onboarding/CreateTeamOnboardingTest.php --list-tests --no-tia > .context/pr4-onboarding-tests-before.txt
```

Expected counts: 105 import, 59 proposal, and 62 onboarding tests.

- [ ] Profile each original file on the same machine.

```bash
/usr/bin/time -p vendor/bin/pest tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php --compact --profile --no-tia
/usr/bin/time -p vendor/bin/pest tests/Feature/Chat/ProposalCardComponentTest.php --compact --profile --no-tia
/usr/bin/time -p vendor/bin/pest tests/Feature/Onboarding/CreateTeamOnboardingTest.php --compact --profile --no-tia
```

Save terminal output in `.context/pr4-baseline-2026-08-26.txt`.

Compare against the earlier 44.948s, 21.717s, and 29.552s results.

- [ ] Stop if a file is now below five seconds.

That would invalidate the measured optimization target. Document the new cause before continuing.

## Task 2: Extract proposal-card fixtures

**Files:**

- Create: `tests/Helpers/ProposalCardFixture.php`
- Modify temporarily: `tests/Feature/Chat/ProposalCardComponentTest.php`

**Interface:**

```php
final class ProposalCardFixture
{
    public static function proposal(User $user, array $action, array $display): PendingAction;
    public static function batchCompany(User $user, array $names): PendingAction;
    public static function task(User $user, array $actionData): PendingAction;
    public static function batchTask(User $user, array $records): PendingAction;
    public static function seededTaskChoice(mixed $team): array;
    public static function taskFieldWithVisibilityCondition(mixed $team): CustomField;
    public static function customField(User $user, string $entityType, string $name, string $type): PendingAction;
}
```

Add precise list and array-shape PHPDoc to every array parameter and return.

- [ ] Move the seven existing global builders into the class unchanged.

Rename methods only as shown above. Keep database attributes and expectation messages unchanged.

- [ ] Move any plan-step fixture block into a final typed class method.

Name it `planSteps`. Derive its exact parameters and return shape from current callers.

- [ ] Replace old helper calls with static class calls.

- [ ] Remove all seven global functions from the original file.

- [ ] Run the unchanged proposal suite.

```bash
php artisan test --compact tests/Feature/Chat/ProposalCardComponentTest.php --no-tia
```

Expected: 59 tests and 264 assertions pass.

- [ ] Run the global-helper guard.

```bash
php artisan test --compact tests/Arch/GlobalTestHelperIntegrityTest.php --no-tia
```

Expected: no duplicate or guarded global helper appears.

## Task 3: Split proposal-card coverage

**Files:**

- Create: `tests/Feature/Chat/ProposalCardLifecycleTest.php`
- Create: `tests/Feature/Chat/ProposalCardEditingTest.php`
- Create: `tests/Feature/Chat/ProposalCardResolutionFailureTest.php`
- Create: `tests/Feature/Chat/ProposalPlanCardTest.php`
- Delete: `tests/Feature/Chat/ProposalCardComponentTest.php`

- [ ] Copy shared `beforeEach` setup into each new file.

Keep feature setup, user setup, authentication, and Filament tenant setup identical.

- [ ] Move tests from rendering through the non-batch stepper into `ProposalCardLifecycleTest.php`.

The last test is `renders a single (non-batch) proposal without a stepper`.

- [ ] Move custom-field and core editing through pagination shrink into `ProposalCardEditingTest.php`.

The first test builds the real custom-field component. The last test shrinks pagination after skips.

- [ ] Move batch failures, delete cases, stale assignees, and custom-field failures into `ProposalCardResolutionFailureTest.php`.

Include database-error redaction in this file.

- [ ] Move the complete `plan card` describe block into `ProposalPlanCardTest.php`.

Also move the three final client-controlled tenant and editing security tests.

- [ ] Declare precise `mutates` entries in each file.

Keep `ProposalCard::class` everywhere it is directly exercised. Add only directly covered collaborating classes.

- [ ] Delete the original file after every test has a destination.

- [ ] Compare proposal test inventories.

```bash
vendor/bin/pest tests/Feature/Chat/ProposalCardLifecycleTest.php \
    tests/Feature/Chat/ProposalCardEditingTest.php \
    tests/Feature/Chat/ProposalCardResolutionFailureTest.php \
    tests/Feature/Chat/ProposalPlanCardTest.php \
    --list-tests --no-tia > .context/pr4-proposal-tests-after.txt
```

Expected: 59 descriptions match the baseline exactly.

- [ ] Run the replacement group.

```bash
php artisan test --compact tests/Feature/Chat/ProposalCardLifecycleTest.php \
    tests/Feature/Chat/ProposalCardEditingTest.php \
    tests/Feature/Chat/ProposalCardResolutionFailureTest.php \
    tests/Feature/Chat/ProposalPlanCardTest.php --no-tia
```

Expected: 59 tests and 264 assertions pass.

- [ ] Commit the proposal split.

```bash
git add tests/Helpers/ProposalCardFixture.php tests/Feature/Chat
git commit -m "test: split proposal card workflows"
```

## Task 4: Split team onboarding coverage

**Files:**

- Create: `tests/Feature/Onboarding/CreateTeamWizardTest.php`
- Create: `tests/Feature/Onboarding/CreateTeamSeedTest.php`
- Create: `tests/Feature/Onboarding/CreateTeamInvitationTest.php`
- Create: `tests/Feature/Onboarding/CreateTeamPrecreationTest.php`
- Create: `tests/Feature/Onboarding/CreateTeamLimitTest.php`
- Delete: `tests/Feature/Onboarding/CreateTeamOnboardingTest.php`

- [ ] Copy the feature-enabling `beforeEach` into every replacement file.

```php
beforeEach(function (): void {
    Feature::define(OnboardSeed::class, true);
});
```

- [ ] Move rendering, normal creation, trial, slug, name, personal-team, and redirect tests into `CreateTeamWizardTest.php`.

This is the original block through `redirects subsequent teams to dashboard`.

- [ ] Move use-case seed, custom-field, relationship, board-position, field-value, and fixture-matrix tests into `CreateTeamSeedTest.php`.

Also include owner assignment and fallback handle behavior.

- [ ] Move referral, onboarding context, invitation roles, deliverability, mail failure, skip, and confirmation tests into `CreateTeamInvitationTest.php`.

- [ ] Move invite-link precreation, button labels, reconciliation, and workspace-created analytics flags into `CreateTeamPrecreationTest.php`.

- [ ] Move all four workspace-cap tests into `CreateTeamLimitTest.php`.

- [ ] Minimize imports and declare precise `mutates` entries per file.

Do not move setup into a new global function.

- [ ] Delete the original file after every test has a destination.

- [ ] Compare onboarding inventories.

```bash
vendor/bin/pest tests/Feature/Onboarding/CreateTeamWizardTest.php \
    tests/Feature/Onboarding/CreateTeamSeedTest.php \
    tests/Feature/Onboarding/CreateTeamInvitationTest.php \
    tests/Feature/Onboarding/CreateTeamPrecreationTest.php \
    tests/Feature/Onboarding/CreateTeamLimitTest.php \
    --list-tests --no-tia > .context/pr4-onboarding-tests-after.txt
```

Expected: 62 expanded descriptions match the baseline exactly.

- [ ] Run the replacement group.

```bash
php artisan test --compact tests/Feature/Onboarding --no-tia
```

Expected: all onboarding tests pass. The replacement files contribute 62 tests and 336 assertions.

- [ ] Commit the onboarding split.

```bash
git add tests/Feature/Onboarding
git commit -m "test: split workspace onboarding workflows"
```

## Task 5: Extract import execution fixtures

**Files:**

- Create: `tests/Helpers/ImportExecutionFixture.php`
- Modify temporarily: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`

**Interface:**

```php
final class ImportExecutionFixture
{
    public static function readyStore(
        object $context,
        array $headers,
        array $rows,
        array $mappings,
        ImportEntityType $entityType = ImportEntityType::People,
    ): array;

    public static function run(object $context): void;
    public static function row(int $number, array $raw, array $overrides = []): array;
    public static function customField(
        object $context,
        string $code,
        string $type,
        string $entityType = 'people',
        array $options = [],
    ): CustomField;
    public static function customFieldValue(
        object $context,
        string $entityId,
        string $customFieldId,
    ): ?CustomFieldValue;
}
```

- [ ] Move the five current helper functions into the class.

The current row helper is `makeExecuteImportRow` after PR2.

- [ ] Preserve every side effect on the Pest context object.

The ready-store method must still assign `import` and `store` to the context.

- [ ] Add exact array-shape PHPDoc to row and tuple results.

- [ ] Replace calls with static class calls. Remove the global functions.

- [ ] Run the unchanged import suite.

```bash
php artisan test --compact tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php --no-tia
```

Expected: 105 tests pass before any case moves or row-count changes.

## Task 6: Split import execution coverage

**Files:**

- Create: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobCoreTest.php`
- Create: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobScaleTest.php`
- Create: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobCustomFieldsTest.php`
- Create: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobFieldTypeTest.php`
- Create: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobEntityTest.php`
- Create: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobDeduplicationTest.php`
- Create: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobRegressionTest.php`
- Delete: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobTest.php`

- [ ] Copy the existing `beforeEach` and `afterEach` into each replacement file.

Keep event fakes, user setup, authentication, Filament tenant setup, store destruction, and import deletion.

- [ ] Move the opening block before the custom-field marker into `ExecuteImportJobCoreTest.php`.

The source boundary is the marker formerly at line 999.

- [ ] Move the three scale cases from the opening block into `ExecuteImportJobScaleTest.php` when the core profile exceeds 15 seconds.

- [ ] Move normal custom-field cases into `ExecuteImportJobCustomFieldsTest.php`.

The source range starts at the custom-field marker and ends before the missing-field-type marker.

- [ ] Move missing and field-type behavior into `ExecuteImportJobFieldTypeTest.php`.

The source range starts at the marker formerly at line 1584.

- [ ] Move company, opportunity, task, and note importer cases into `ExecuteImportJobEntityTest.php`.

The source range starts at the marker formerly at line 1798.

- [ ] Move deduplication and multi-choice merge cases into `ExecuteImportJobDeduplicationTest.php`.

Combine the sections formerly starting at lines 2036 and 2123.

- [ ] Move tag-option, soft-deleted-record, and remaining issue regressions into `ExecuteImportJobRegressionTest.php`.

The source begins at the marker formerly at line 2401.

- [ ] Minimize imports and declare precise `mutates` entries per file.

Keep `ExecuteImportJob::class` where the job runs. Keep `EntityLinkResolver::class` only in relevant entity-link coverage.

- [ ] Delete the original file after every test has a destination.

- [ ] Compare import inventories before changing scale sizes.

```bash
vendor/bin/pest tests/Feature/ImportWizard/Jobs/ExecuteImportJobCoreTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobScaleTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobCustomFieldsTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobFieldTypeTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobEntityTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobDeduplicationTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobRegressionTest.php \
    --list-tests --no-tia > .context/pr4-import-tests-after-split.txt
```

Expected: all 105 descriptions match the baseline.

## Task 7: Trim redundant import scale volume

**Files:**

- Modify the replacement file containing the mixed 1,000-row case
- Modify the replacement file containing the entity-link 1,000-row case

- [ ] Keep the pure-create scalability test at 1,000 rows.

- [ ] Change the mixed case to 501 rows.

Use this distribution:

```text
50 updates
25 skips
426 creates
```

Update count assertions to those exact values. Keep row-content assertions unchanged.

- [ ] Change the entity-link case to 501 rows.

Keep 20 repeated companies. Update only total and derived count assertions.

- [ ] Confirm both changed cases still cross the chunk boundary.

The production chunk size is 500. Do not lower either case to 500.

- [ ] Run the three scalability tests by exact filter.

```bash
php artisan test --compact tests/Feature/ImportWizard/Jobs/ExecuteImportJobScaleTest.php \
    --filter='1000|1,000|large|chunk' --no-tia
```

Expected: the 1,000-row case and both 501-row cases pass.

- [ ] Run the complete replacement group.

```bash
php artisan test --compact tests/Feature/ImportWizard/Jobs/ExecuteImportJobCoreTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobScaleTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobCustomFieldsTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobFieldTypeTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobEntityTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobDeduplicationTest.php \
    tests/Feature/ImportWizard/Jobs/ExecuteImportJobRegressionTest.php --no-tia
```

Expected: 105 tests and at least 301 assertions pass.

- [ ] Commit the import split and scale adjustment.

```bash
git add tests/Helpers/ImportExecutionFixture.php tests/Feature/ImportWizard/Jobs
git commit -m "test: split and streamline import execution coverage"
```

## Task 8: Measure acceptance targets

**Files:**

- No expected source changes

- [ ] Profile every replacement file separately.

Use `/usr/bin/time -p vendor/bin/pest <file> --compact --profile --no-tia`.

Save all output in `.context/pr4-final-profile.txt`.

- [ ] Verify the per-file target.

Expected: no replacement file exceeds 15 seconds on this machine.

- [ ] Sum each replacement group.

Expected: no group exceeds its new baseline by more than five percent.

Expected import aggregate: below 42 seconds.

- [ ] If a target fails, profile the offending file before changing it.

Move coherent test blocks again if scheduling granularity caused the failure.

Do not weaken assertions, add sleeps, or change production code.

- [ ] Re-run combined inventories.

Expected totals: 105 import, 59 proposal, 62 onboarding, and 226 combined.

## Task 9: Refresh shard timing data

**Files:**

- Modify: `tests/.pest/shards.json`

- [ ] Run the shard update after final file paths and timings settle.

```bash
composer test:update-shards
```

Expected: old three paths disappear. All replacement paths receive timings.

- [ ] Inspect the shard map for omissions.

```bash
rg -n 'ExecuteImportJobTest|ProposalCardComponentTest|CreateTeamOnboardingTest' tests/.pest/shards.json
```

Expected: no matches.

- [ ] Commit timing data.

```bash
git add tests/.pest/shards.json
git commit -m "test: refresh shard timing data"
```

## Task 10: Final verification

- [ ] Run the helper architecture guard.

```bash
composer test:arch
```

Expected: all architecture tests pass.

- [ ] Run checks in project order.

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

Expected: no findings. Type coverage remains 100 percent.

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

- [ ] Confirm PR4 production files are untouched.

```bash
git diff --name-only 3f66ea580...ac2d0069c | rg -v '^(tests/|docs/)'
```

Expected: no output. Later commits only correct shared test diagnostics and their exact-message tests.

The full branch also contains the approved Boost and test-runner configuration changes from PR1.

- [ ] Run `git diff --check` and inspect the complete pull request diff.

- [ ] Prepare the pull request draft with before-and-after timings. Do not publish it without explicit approval.
