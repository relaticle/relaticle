# Merge Into a New Record Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`)
> syntax for tracking.

**Goal:** Merging two companies, or two people, creates a new record from both and soft-deletes the
two originals. Today the priority record keeps its ID and absorbs the other.

**Spec:** `docs/superpowers/specs/2026-11-14-merge-duplicate-records-design.md`, decisions 5 and 10 to
13, The merge in order, What moves, Authorization, Known effects and Tests.

**Architecture:** The existing steps already move one record into another. `MergeRecords` inserts the
new record, then runs each step twice with the new record as the target: `priority` first, then
`other`. No migration. No redirect from old URLs.

## Global Constraints

- Everything in `docs/superpowers/plans/2026-11-14-merge-duplicate-records.md` Global Constraints
  still holds.
- Rebase on `main` first: the branch is 30 commits behind.
- No new column, no migration, no new action class unless a step needs a second caller.

## Task 1: `MergeRecords` creates the new record

**Files:** `app/Actions/Merge/MergeRecords.php`, `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`,
`tests/Feature/Filament/App/Resources/MergePeopleTest.php`, `tests/Helpers/functions/merge-records.php`

- [x] Flip the existing assertions first: the merge returns a new record, both originals are
      soft-deleted, links from both land on the new record. Run them and watch them fail.
- [x] Authorization: `create` on the entity, `delete` on both originals.
- [x] After the lock and the limit check, insert the new record inside `Model::withoutEvents()`
      with name, `workspace_id`, `creator_id` and `creation_source` from `priority` (`other`'s source
      when `priority` is sample data and `other` is not). Drop the old in-place sample-data rule.
- [x] Run `MoveRecordLinks`, `moveEntityLinks`, the logo move and the history move for `priority`,
      then for `other`, with the new record as the target. Fire `RecordMerged` once per original.
- [x] Soft-delete both originals through the model.
- [x] Write the combined values through `UpdateCompany` or `UpdatePeople` inside
      `activity()->withoutLogging()`, then return the new record.
- [x] Rewrite record-type values in `MergedValues` that name either original to the new record's id.
- [x] Add tests: creator and source inherited, sample-data source, no onboarding turn for an `mcp`
      source, self-reference when one original pointed at the other.
- [x] Rollback and race tests: a failed final write leaves no new record; the lock stays one select
      in id order; a vanished original creates nothing.

## Task 2: One `record_merged` entry naming both originals

**Files:** `app/Actions/Merge/MoveRecordHistory.php`, `app/ActivityLog/AppEventPalette.php`,
`app/Support/ActivityLog/MergedActivityRenderer.php`, `lang/en/activity-log.php`,
`lang/fr/activity-log.php`, the history cases in the two merge tests

- [x] Split moving history (per original) from writing the entry (once, after the values are
      written). Store both names in the entry's properties. Phase 1 is not on `main`, so no stored
      `duplicate_name` entry needs to keep rendering.
- [x] Copy: "<member> merged <priority> and <other> into this record", in en and fr.
- [x] Test: the new record's timeline has both pasts, no `created` or `updated` row from the merge,
      and one `record_merged` entry.

## Task 3: Email package, two originals into one record

**Files:** `app/Events/RecordMerged.php`,
`packages/EmailIntegration/src/Actions/MoveMergedRecordCommunication.php`,
`tests/Feature/EmailIntegration/MergedRecordCommunicationTest.php`

- [x] Confirm the action is correct when called twice into a record that starts at zero: the second
      call subtracts the emails and meetings the first already linked. Change it only if a test fails.
- [x] Flip the tests to the new record: counters summed from both, shared email counted once, both
      originals zeroed, email feature off.

## Task 4: The dialog and the menu item

**Files:** `app/Filament/Actions/MergeRecordAction.php`, `lang/en/filament/record-page.php`,
`lang/fr/filament/record-page.php`, `resources/views/filament/app/merge-record.blade.php` and partials,
`tests/Feature/Filament/App/Resources/MergeDuplicatePromptTest.php`

- [x] `canMerge()`: `create` on the entity and `delete` on the current record.
- [x] Always redirect to the new record after the merge.
- [x] The result card's copy says it previews the new record.
- [x] Prompt test: after a merge, the new record's prompt lists the third duplicate.

## Task 5: Browser tests

**Files:** `tests/Browser/CRM/MergeRecordDialogTest.php`, `tests/Helpers/functions/browser-merge-dialog.php`

- [x] Land on the new record's URL after a merge, plain and after ⇄.
- [x] Run the three merge browser files in Chromium, then with `--browser safari`.
- [ ] Click through the merge with agent-browser in light and dark, including the duplicate prompt.

## Task 6: Spec and checks

- [x] Set the spec status to built, and fold any builder's call made here into the spec.
- [x] `vendor/bin/pint --dirty --format agent`, `composer test:affected`, then before pushing
      `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:lint`,
      `composer test:arch`.
