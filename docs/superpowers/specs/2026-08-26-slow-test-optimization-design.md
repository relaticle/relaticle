# Slow Test Optimization

Date: 2026-08-26
Status: approved
Sequence: PR4 of 4
Prerequisite: test organization, factories, datasets, and Pest Rector

## 1. Goal

Reduce shard bottlenecks and improve TIA granularity without weakening behavior coverage.

## 2. Baseline

Measurements from 2026-08-26:

| File | Tests | Assertions | Local duration | Historical shard duration |
| --- | ---: | ---: | ---: | ---: |
| `ExecuteImportJobTest.php` | 105 | 301 | 40.12s | 69.097s |
| `ProposalCardComponentTest.php` | 59 | 264 | 21.24s | 42.161s |
| `CreateTeamOnboardingTest.php` | 62 | 336 | 27.01s | 40.535s |

Each file is one scheduling unit. Large files limit shard balancing and TIA selection.

## 3. Constraints

- Change tests only.
- Preserve every test name and assertion intent.
- Preserve production-shaped database and queue behavior.
- Keep one 1,000-row import scalability test.
- Preserve at least two tests that cross the 500-row production chunk boundary.
- Do not introduce a new test suite.
- Do not change production code for timing.

## 4. Import execution split

Create `tests/Helpers/ImportExecutionFixture.php`.

It owns reusable setup for ready imports, row construction, custom fields, job execution, and stored values.

Split `ExecuteImportJobTest.php` into:

- `ExecuteImportJobCoreTest.php`
- `ExecuteImportJobScaleTest.php`
- `ExecuteImportJobCustomFieldsTest.php`
- `ExecuteImportJobFieldTypeTest.php`
- `ExecuteImportJobEntityTest.php`
- `ExecuteImportJobDeduplicationTest.php`
- `ExecuteImportJobRegressionTest.php`

Use existing section boundaries. Keep every test title unchanged.

Keep the three scale cases together. This gives the remaining core cases and the scale cases separate scheduling units.

Keep the pure create scalability case at 1,000 rows.

Reduce the mixed create-update-skip case to 501 rows.

Use 50 updates, 25 skips, and 426 creates.

Reduce the entity-link case to 501 rows. Keep its 20 repeated companies.

Both cases still cross the 500-row chunk boundary.

## 5. Proposal card split

Create `tests/Helpers/ProposalCardFixture.php`.

It owns current proposal, batch, task, choice, condition, custom-field, and plan-step fixture builders.

Split `ProposalCardComponentTest.php` into:

- `ProposalCardLifecycleTest.php`
- `ProposalCardEditingTest.php`
- `ProposalCardResolutionFailureTest.php`
- `ProposalPlanCardTest.php`

Keep lifecycle and basic rendering together.

Keep editing, custom fields, and pagination together.

Keep failure, stale-reference, delete, and redaction cases together.

Keep plan rendering and client-controlled security cases together.

Each file will declare precise `mutates` coverage.

## 6. Team onboarding split

Split `CreateTeamOnboardingTest.php` into:

- `CreateTeamWizardTest.php`
- `CreateTeamSeedTest.php`
- `CreateTeamInvitationTest.php`
- `CreateTeamPrecreationTest.php`
- `CreateTeamLimitTest.php`

Use local `beforeEach` blocks in each file.

Do not add shared global helper functions.

Keep rendering and normal creation in the wizard file.

Keep use-case records and relationships in the seed file.

Keep referral and invitation behavior in the invitation file.

Keep invite-link reconciliation and analytics flags in the precreation file.

Keep workspace-cap behavior in the limit file.

## 7. Measurement protocol

Capture each original file's test list and test count before splitting.

Capture the replacement files' combined lists after splitting.

The names and counts must match exactly.

Profile each replacement file with Pest's compact profile output.

No replacement file should exceed 15 seconds on the same local environment.

Combined duration for each group must not regress by more than five percent.

The import group should complete below 42 seconds.

Refresh `tests/.pest/shards.json` after final timings.

Run the complete non-Browser suite after timing verification.

## 8. Acceptance criteria

- The three original files are removed.
- Replacement files contain the same 226 test names.
- Assertion intent remains unchanged.
- One 1,000-row scalability case remains.
- Two 501-row cases still cross the chunk boundary.
- No replacement file exceeds 15 seconds locally.
- No group regresses by more than five percent.
- Import execution finishes below 42 seconds locally.
- Shard timing data reflects the new file paths.
- No production file changes.

## 9. Non-goals

- No application performance work.
- No assertion reductions.
- No database compatibility changes.
- No Browser test restructuring.
- No arbitrary wait or retry changes.
