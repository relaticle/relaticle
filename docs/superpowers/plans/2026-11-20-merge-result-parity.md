# Merge Result Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`)
> syntax for tracking.

**Goal:** The merge already creates a new record from two originals. This plan makes the result
continue both: the new record keeps the priority record's creation date and both originals'
history, each original becomes a merged record whose old URL says where it went, a merged record can
never be restored or edited, a deleted record's page shows a notice, a merge raises a field's value
limit instead of blocking, and the REST API, the MCP server and Rela can merge.

**Spec:** `docs/superpowers/specs/2026-11-14-merge-duplicate-records-design.md`, Phase 3 under
Phases, and decisions 7, 9 and 11 to 15 as changed on 2026-11-20.

**Architecture:** Four phases on top of PR #1193, which ships with them (decided 2026-11-20).

1. Result: `MergeRecords` copies the origin's creation date, and a restored `MoveRecordHistory` moves
   both originals' activity rows onto the new record and writes one merge entry. A merge over a
   field's limit raises the limit.
2. Merged records: a nullable `merged_into_id` with no foreign key on `companies` and `people`. The
   resources' list query, the policies and a `restoring` listener keep a merged record out of every
   list, search, restore and edit. Its view page renders a notice in place of the record, and so does
   a deleted record's.
3. REST: `POST /api/v1/companies/merge` and `POST /api/v1/people/merge` call `MergeRecords`.
4. Agents: an MCP tool and a Rela write tool per entity call `MergeRecords`. Rela proposes through a
   new `PendingActionOperation::Merge`.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Pest 5 (+ browser plugin), PostgreSQL,
relaticle/custom-fields, spatie/laravel-activitylog, Scribe.

## Global Constraints

- Everything in `2026-11-14-merge-duplicate-records.md` Global Constraints still holds, except the
  test command: use `composer test:affected`, or `php artisan test --compact <paths>` when it refuses.
- Read `.ai/rules/models.md`, `panel-links.md`, `agent-surfaces.md`, `boost/http-routes.md`,
  `boost/tests.md`, `email-integration.md` and `chat.md` before touching their paths.
- Copy is our own. No artifact (code, docs, lang, commits, PR) names another CRM product.
- Every new string ships in `lang/en`, `lang/de`, `lang/es` and `lang/fr`. Every new or changed help
  or docs page ships in English under `content/help` or `content/docs` and in `content/{de,es,fr}`,
  or `DocsTranslationParityTest` fails.
- Migrations have `up()` only, write no data, and are rehearsed on anonymized production data
  (`.ai/rules/core.md`, Database), never tested.
- The merge stays one transaction. Nothing in this plan adds a queued step to it.
- New merge cases go in a new `tests/Feature/Filament/App/Resources/MergeRecordHistoryTest.php` that
  loops over both entities inside each test. `MergeCompaniesTest` already takes 89s of the 130s class
  budget.

## Review Focus

1. **A restore or edit path that skips the guard.** Filament's bulk restore reads the table query
   (`HasBulkActions.php:361`), so the list query must never return a merged record. The system admin
   panel's `EditCompany` and `EditPeople` read trashed records through `ResolvesTrashedRecords` and
   use their own policy. Test in Task 6.
2. **A moved activity row that misreads on the new record.** Only the origin's `created` row moves.
   The non-origin's `created` row stays behind, matched by its id, never by a `batch_uuid` that can be
   null. Its creation-time custom attribute rows (`CustomFieldValueObserver::created`) move and read
   as a change at its creation time, as the reference showed. Test in Task 2.
3. **Two originals' rows folding together.** Both originals can hold rows from one import batch.
   Their `batch_uuid` is rehashed per original on the move. Test in Task 2.
4. **The pointer actually saved.** A soft delete writes only `deleted_at` and `updated_at`
   (`SoftDeletes::runSoftDelete`). The pointer is a query update of its own. Test in Task 5.
5. **The API caller's error shape.** `MergeRecords` keys its validation errors on `records`, and the
   Filament action still shows them under the picker. Test in Tasks 10 and 11.
6. **A Member's merge changes a workspace setting.** The raise writes `max_values` on the workspace's
   field without the fields-manage capability, by decision 9. It must raise, never lower, and stop at
   the ceiling. Test in Task 3.
7. **An agent merge with the wrong pair or a stale id.** Rela resolves ids at approval time and a
   vanished record fails that pair only. Test in Task 13.

---

## File map

| File | Change |
|---|---|
| `app/Actions/Merge/MergeRecords.php` | origin's creation date, pointer, muted deletes, history step, `records` error key |
| `app/Actions/Merge/MoveRecordHistory.php` | restored from `57bbbd1de^`, adapted |
| `app/Actions/Merge/LogRecordMerge.php` | removed |
| `app/Actions/Merge/CombineRecordValues.php`, `app/Data/MergedValues.php`, `app/Actions/Merge/RaiseFieldLimits.php` (new) | the raise replaces the over-limit block |
| `app/Support/CustomFieldSettingsSchema.php` | the 20 ceiling becomes a public constant |
| `app/Filament/Components/Forms/MergeRecordPicker.php`, `resources/views/filament/app/merge-record.blade.php` | the raise notice replaces the over-limit alert |
| `app/ActivityLog/AppEventPalette.php`, `lang/*/activity-log.php` | one `record_merged` text |
| `app/Filament/Pages/Workspace/ActivityLog.php` | `record_merged` in `eventOptions()`, translated |
| `tests/Helpers/functions/merge-records.php` | reads the new record from `merged_names` |
| `database/migrations/<date>_add_merged_into_id_to_companies_and_people.php` | new |
| `app/Models/Company.php`, `app/Models/People.php` | `mergedInto()`, `#[Scope] notMerged()`, `restoring` listener, `merged_into_id` in `logExcept` |
| `app/Policies/CompanyPolicy.php`, `app/Policies/PeoplePolicy.php` | refuse `update`, `delete`, `restore`, `forceDelete` on a merged record |
| `packages/SystemAdmin/src/Policies/CompanyPolicy.php`, `PeoplePolicy.php` (or their edit pages) | refuse editing a merged record |
| `app/Filament/Resources/CompanyResource.php`, `PeopleResource.php` | list query drops merged rows, route binding keeps them |
| `app/Filament/Concerns/HasRecordPageLayout.php`, `resources/views/filament/app/record-unavailable.blade.php`, `lang/*/filament/record-page.php` | the merged and deleted notices |
| `app/Filament/Resources/CompanyResource.php`, `PeopleResource.php`, `OpportunityResource.php` | global search leaves deleted records out |
| `packages/Chat/src/Http/Controllers/RecordRedirectController.php` | a merged record redirects a member to its notice |
| `routes/api.php`, `app/Http/Requests/Api/V1/MergeRecordsRequest.php`, `app/Http/Controllers/Api/V1/CompaniesMergeController.php`, `PeopleMergeController.php` | new endpoints |
| `app/Mcp/Tools/Company/MergeCompaniesTool.php`, `app/Mcp/Tools/People/MergePeopleTool.php`, `RelaticleServer`, `tests/Feature/Mcp/ToolAnnotationsTest.php` | MCP tools |
| `packages/Chat/src/Tools/Company/MergeCompaniesTool.php`, `packages/Chat/src/Tools/People/MergePeopleTool.php`, `PendingActionOperation`, `ProposalEntity`, `PendingActionService`, `CrmAssistant`, `chat-interface.blade.php`, the proposal card partials, `lang/*` proposal keys | Rela tools |
| `packages/Documentation/resources/content/help/records/merge-duplicate-records.md` and `content/{de,es,fr}/help/records/...` | new help page |
| `.../help/records/restore-deleted-records.md`, `company-records.md`, `people-records.md`, `docs/guides/rest-api.md`, `docs/guides/mcp.md`, in all four locales | updated |

---

## Phase 1: the result continues both originals

### Task 1: The new record keeps the origin's creation date

**Files:** `app/Actions/Merge/MergeRecords.php`, `MergeRecordHistoryTest.php` (new)

- [ ] Test first, both entities in one test: travel to two dates, create the two originals, merge
      with the older on the right, then with the newer on the right. The new record's `created_at`
      is the right-hand record's each time. A sample-data priority with a real other record gives
      the other record's date, creator and source.
- [ ] `createMergedRecord()` already picks `$origin`. Pass `'created_at' => $origin->created_at` to
      `forceCreate()`. `updateTimestamps()` leaves a dirty `created_at` alone (`HasTimestamps.php:99`).
- [ ] Run the new file and both existing merge files.

### Task 2: History moves onto the new record

**Files:** `app/Actions/Merge/MoveRecordHistory.php` (restore), `app/Actions/Merge/LogRecordMerge.php`
(delete), `app/Actions/Merge/MergeRecords.php`, `app/ActivityLog/AppEventPalette.php`,
`lang/{en,de,es,fr}/activity-log.php`, `app/Filament/Pages/Workspace/ActivityLog.php`,
`tests/Helpers/functions/merge-records.php`, `MergeRecordHistoryTest.php`, the history cases in both
existing merge files

- [ ] Rewrite the history cases first, in the new file, both entities:
      - the new record's timeline shows both originals' later updates and relation changes, and
        exactly one `created` entry, the origin's
      - the non-origin's creation-time custom attribute values show as one "changed" entry at its
        creation time, and its `created` row does not move
      - a merge of two originals created in one import batch keeps their rows as separate entries
      - a chain of merges shows only the latest merge entry
      - the entry reads "<member> merged 2 records into this record" and stores both names
      - the originals show no merge entry and no `deleted` row from the merge
      - the workspace Activity page lists the merge with its translated label and filters by it
      Delete the old cases they replace from `MergeCompaniesTest` and `MergePeopleTest` (the ones on
      each original's entry, the carried entries and their rollback). Run them and watch them fail.
- [ ] Restore `MoveRecordHistory` with `git show 57bbbd1de^:app/Actions/Merge/MoveRecordHistory.php`.
      Keep its per-original `batch_uuid` rehash. Its exclusion becomes: every `deleted`, `restored`
      and `record_merged` row, plus, on the non-origin only, its `created` row.
- [ ] Its entry stores `merged_names` (priority first) in a batch of its own.
      `AppEventPalette::description()` renders ":causer merged 2 records into this record" ("2 records
      were merged into this record" with no causer). Remove the `merged_name` and `merged_into_name`
      branches and lang keys: PR #1193 is unmerged, so no stored row uses them.
- [ ] `tests/Helpers/functions/merge-records.php:91` finds the new record through `merged_name`. Point
      it at `merged_names`.
- [ ] `ActivityLog::eventOptions()` gains `record_merged` with the palette's translated label.
- [ ] In `MergeRecords`, soft-delete both originals inside `activity()->withoutLogging()`, and call
      `MoveRecordHistory` after the values are written, where `LogRecordMerge` ran. Delete
      `LogRecordMerge`.
- [ ] Run the new file, both merge files, `tests/Feature/ActivityLog`, and the three merge browser
      files.

### Task 3: A merge over a field's limit raises the limit

**Files:** `app/Actions/Merge/CombineRecordValues.php`, `app/Data/MergedValues.php`,
`app/Actions/Merge/RaiseFieldLimits.php` (new), `app/Actions/Merge/MergeRecords.php`,
`app/Support/CustomFieldSettingsSchema.php`, `app/Filament/Components/Forms/MergeRecordPicker.php`,
`resources/views/filament/app/merge-record.blade.php`, `lang/{en,de,es,fr}/filament/record-page.php`,
`MergeRecordHistoryTest.php`, the over-limit cases in both merge files, `MergeRecordDialogTest`

- [ ] Test first, both entities: two records with 4 domains or emails each merge into one holding all
      8, the workspace field's `max_values` becomes 8, a Member without fields-manage can do it, a
      merge under the limit leaves `max_values` alone, a limit already above the count is never
      lowered, two merges in one process where the second needs fewer values than the first leave
      the first's raise in place, the field's activity shows the raise, and a combined count above
      20 refuses with 422 on `records` and changes nothing. The
      dialog shows the raise notice and keeps Merge enabled; above 20 it shows the old alert and
      disables Merge. Replace the "blocks a merge over five" cases in both files and the browser
      over-limit flow.
- [ ] `CustomFieldSettingsSchema::MAX_MAX_VALUES` becomes public.
- [ ] `CombineRecordValues` returns `raises` (field code to new count) for counts between the limit
      and the ceiling, and keeps `overLimit` only above the ceiling. `MergedValues` gains the property.
- [ ] `RaiseFieldLimits` (one `execute(MergedValues)`), inside the merge transaction and before the
      values are written: re-read each field row with `lockForUpdate()`, scoped to the workspace's
      `tenant_id`, skip it when the current value already meets the count, and otherwise write
      `max(current, count)` through
      `$field->update(['settings' => CustomFieldSettingsSchema::apply($field, ['max_values' => $n])])`,
      as `UpdateCustomField.php:39-43` does. The model save clears the per-request field cache
      (`AppServiceProvider.php:735-746`), so a second merge in the same request (Rela's approve-all)
      reads the raised value, and `CustomField` logs the `settings` change, so the raise shows who
      made it. It is a step of the merge, so it checks no fields-manage capability: `MergeRecords`
      already checked who may merge.
- [ ] The over-ceiling message passes 20 as `:max`, not the field's own limit.
- [ ] Copy (en): "Companies can now hold :count domains" and "People can now hold :count emails",
      built from the field and entity names so a custom multi-value field reads right too.

## Phase 2: merged records

### Task 4: The pointer

**Files:** a new migration, `app/Models/Company.php`, `app/Models/People.php`

- [ ] `php artisan make:migration add_merged_into_id_to_companies_and_people --no-interaction`.
      `up()` only: a nullable `ulid('merged_into_id')` on both tables. No foreign key: a force delete
      of the result must not null the marker and put the originals back into the trash.
- [ ] Rehearse it on anonymized production data (core.md, Database): schema dump, the `migrations`
      table, `companies` and `people` with names replaced. Confirm only this migration runs, row
      counts match, and a second `migrate` is a no-op.
- [ ] Models: `mergedInto(): BelongsTo` (`withTrashed()`) and `#[Scope] notMerged()`
      (`whereNull('merged_into_id')`). Add `merged_into_id` to `logExcept`, and leave it out of
      `relationChangeLabels()` on purpose: `models.md` asks for both for a relation, but this pointer
      is only ever written with logging off and is never shown as a change.
- [ ] A `restoring` listener on both models returns `false` for a merged record.

### Task 5: The merge writes the pointer

**Files:** `app/Actions/Merge/MergeRecords.php`, `MergeRecordHistoryTest.php`

- [ ] Test first: after a merge, both originals reloaded from the database hold the new record's id.
      A chained merge leaves the first original pointing at the middle record, and the middle at the
      last.
- [ ] Before the muted soft deletes, one query update sets `merged_into_id` on both originals:
      `$priority->newQueryWithoutRelationships()->whereKey([...])->update([...])`. A `forceFill()`
      before `delete()` would be dropped, because the soft delete saves only its own columns.

### Task 6: Merged records stay out of every list, search, restore and edit

**Files:** both app policies, both resources, the SystemAdmin company and people policies or edit
pages, a new `tests/Feature/Filament/App/Resources/MergedRecordTest.php`, the SystemAdmin resource
tests

- [ ] Test first, both entities:
      - through the list page with the trashed filter on "with" and "only", a merged record is
        absent and a deleted record is present
      - global search by name finds no merged record (global search reads names only)
      - `RestoreBulkAction` on the "only" list cannot reach a merged record
      - restoring a merged record through the model returns `false` and leaves it deleted
      - the app policies refuse update, delete, restore and force delete on it
      - the system admin edit page refuses it with 403 on mount
- [ ] App policies: `update`, `delete`, `restore` and `forceDelete` return `false` when
      `merged_into_id` is set.
- [ ] Resources: `getEloquentQuery()` adds `notMerged()`. `getRecordRouteBindingEloquentQuery()`
      returns the query without it, so the old URL still resolves (`HasRoutes.php:41-62`, the same
      split SystemAdmin already uses).
- [ ] SystemAdmin is outside PHPStan. Read every company and people page and action there. It has no
      restore action, but `EditCompany` and `EditPeople` reach trashed records through
      `ResolvesTrashedRecords`: refuse a merged one on mount with 403. Their policy's `update()` takes no
      record (`packages/SystemAdmin/src/Policies/CompanyPolicy.php:26`), so the page is the place.
- [ ] Sweep: `grep -rn "withTrashed\|onlyTrashed\|SoftDeletingScope\|TrashedFilter\|->restore()" app
      packages`. List every reader in the PR body with what it does for a merged record. Known:
      `RecordRedirectController` (Task 8); both `ListActivityTool`s, `LogicFilter.php:80`,
      `FilterRules.php:239`, `FilterRuleDisplay.php:235`, `Workspace/ActivityLog.php:574` and
      `SetCustomFieldOptions` read rows or names and change nothing.

### Task 7: The merged notice and the deleted notice

**Files:** `app/Filament/Concerns/HasRecordPageLayout.php`, `resources/views/filament/app/record-unavailable.blade.php`,
`CompanyResource.php`, `PeopleResource.php`, `OpportunityResource.php`,
`lang/{en,de,es,fr}/filament/record-page.php`, `MergedRecordTest.php`, a new
`tests/Browser/CRM/MergedRecordNoticeTest.php`

- [ ] Test first:
      - a merged record's URL returns 200 and shows the merged notice, with no record name in the
        heading or title, no field, tab, relation manager or header action
      - "Go to record" links to the view URL built from `merged_into_id`. It opens the next merged
        notice after a chain, the deleted notice after the result was deleted, and a 404 after it was
        force-deleted
      - a deleted company, person or opportunity that was never merged shows the deleted notice:
        "This record was deleted", a "Go back" button to the entity's list, no record data
      - global search finds no deleted company, person or opportunity by name
      - a live opportunity page is unchanged
- [ ] One view, `record-unavailable.blade.php`, renders both notices. When the record is trashed, or
      is a merged company or person, `HasRecordPageLayout` overrides `getHeading()`, `getTitle()`,
      `infolist()`, `content()` and the header actions to render it. Merged takes precedence.
- [ ] Copy (en). Merged: heading "This record was merged", body "It was merged with another record and
      no longer exists on its own.", button "Go to record", link "How merging works". Deleted: heading
      "This record was deleted", body "It was deleted and no longer exists here.", button "Go back".
- [ ] The help link is `DocUrl::helpPage('records', 'merge-duplicate-records')` on its own, as
      `RoleOptions.php:83` does. It is already absolute, so `getPublicUrl()` would double the host.
- [ ] The trait's preview branches (`HasRecordPageLayout.php:82-84`, `:110-116`, used by
      `PreviewRecordAction`) render the notice too, without the quick actions.
- [ ] Trash filter rows (decided 2026-11-20): a trashed row
      gets no row link and no preview action, so the list keeps selecting rows for Restore and no
      click lands on the notice. On all three resources, `recordUrl(fn (Model $record): ?string =>
      $record->trashed() || ! static::canView($record) ? null : static::getUrl('view', ['record' =>
      $record]))`, since a custom `recordUrl()` replaces Filament's own view check, and
      `PreviewRecordAction::make()->hidden(fn (Model $record): bool => $record->trashed())`.
- [ ] Existing tests: rewrite `MergeCompaniesTest.php:760-767` ("offers no merge and no duplicate
      prompt on a deleted company") to assert the notice and no merge action, since
      `assertSchemaComponentHidden` needs the infolist. Re-word or retire `TrashedSubjectTimelineTest`,
      whose case reads a deleted record's history from its page.
- [ ] Global search: `getGlobalSearchEloquentQuery()` on the three resources adds
      `whereNull('deleted_at')`, because `getEloquentQuery()` drops the soft-delete scope for the
      trash filter and global search reads it.
- [ ] agent-browser: both notices in light, dark and a phone viewport, then the browser test in
      WebKit (`--browser safari`). Tag the file `pest()->group('webkit')` only if WebKit differs.

### Task 8: A Rela chip that names a merged or deleted record

**Files:** `packages/Chat/src/Http/Controllers/RecordRedirectController.php`, its test

- [ ] Test first: a member opening a chip for a merged company or person, or a deleted company,
      person or opportunity, is redirected to its view URL, which shows the matching notice. A
      non-member still gets 404. A deleted task or note still gets the 410 page. Update
      `RecordRedirectTest.php:83`.
- [ ] Branch after the membership check at line 89, for `Company`, `People` and `Opportunity` only,
      before the trashed 410 branch, so the panel's notice is the one notice for those three.

### Task 9: Help

**Files:** `content/help/records/merge-duplicate-records.md` (new) and its `content/{de,es,fr}/` copies,
`restore-deleted-records.md`, `company-records.md`, `people-records.md`, in all four locales

- [ ] The new page says how to merge, which record wins, what moves, what the old URL shows, and that
      a merge cannot be undone. Front matter values carry no bare `: ` (writing.md).
- [ ] `restore-deleted-records.md` says a merged record is not in the trash and cannot be restored,
      and that a deleted record's page now shows a notice, so restoring starts from the list.
- [ ] Link the new page from both record pages' `related`, in every locale.

## Phase 3: REST

### Task 10: A neutral error key

**Files:** `app/Actions/Merge/MergeRecords.php`, `app/Filament/Actions/MergeRecordAction.php`

- [ ] Key every `ValidationException` in `MergeRecords` on `records` instead of `picked_id`. The
      Filament action already flattens the errors onto the picker. Run both merge files and
      `MergeRecordDialogStateTest`.

### Task 11: The endpoints

**Files:** `routes/api.php`, `app/Http/Requests/Api/V1/MergeRecordsRequest.php`,
`app/Http/Controllers/Api/V1/CompaniesMergeController.php`, `PeopleMergeController.php`,
`content/docs/guides/rest-api.md` and its `content/{de,es,fr}/` copies, a
new `tests/Feature/Api/V1/MergeRecordsApiTest.php`

- [ ] Test first, for each entity:
      - 200 with the new record's resource, and both originals answer 404 on `GET` afterwards
      - 422 on `secondary_record_id` when the two ids are the same
      - 422 on the field for an id that is unknown, deleted, merged or in another workspace, and on a
        repeat of the same request
      - 403 for a token missing `create`, `update` or `delete`, one case each
      - 200 with the field's `max_values` raised when the combined count passes the limit, and 422 on
        `records` above the ceiling of 20
- [ ] Routes beside the upsert routes, before `apiResource`, with
      `EnsureTokenHasAbility::class.':create,update,delete'`.
- [ ] The request validates `primary_record_id` and `secondary_record_id` as required, distinct ULIDs,
      each `Rule::exists($table, 'id')->where('workspace_id', <the bound workspace id>)
      ->whereNull('deleted_at')->whereNull('merged_into_id')`. `exists` applies no model scope, so the
      workspace filter is explicit. The primary record is the priority record.
- [ ] `tests/Feature/Documentation/OpenApiSpecTest.php` keeps passing: no 404 on these paths, a 422
      on both. The generated 403 text names `create` alone, as it does for the upsert routes;
      `ErrorResponsesGenerator` receives no route middleware, so that gap stays out of this plan.
- [ ] Scribe attributes on both controllers (`@group Companies` / `@group People`, `BodyParam`,
      `ResponseFromApiResource`, the 422 example). Add a "Merge two records" section to `rest-api.md`
      in every locale.

## Phase 4: agents

### Task 12: MCP merge tools

**Files:** `app/Mcp/Tools/Company/MergeCompaniesTool.php`, `app/Mcp/Tools/People/MergePeopleTool.php`,
`app/Mcp/Servers/RelaticleServer.php`, `tests/Feature/Mcp/ToolAnnotationsTest.php`,
`content/docs/guides/mcp.md` and its `content/{de,es,fr}/` copies, `tests/Feature/Mcp/CompanyToolsTest.php`,
`tests/Feature/Mcp/PeopleToolsTest.php` (or the file that covers people tools)

- [ ] Test first, per entity: the merge returns the new record's id and name; the same id twice, an
      unknown, deleted, merged or other-workspace id returns an error naming it; a token missing
      `create`, `update` or `delete` is refused; a Viewer is refused; a raise above the ceiling
      returns the limit error; both originals then answer "not found" on the get tool.
- [ ] Each tool is a standalone `final class ... extends Tool` built from `BaseDeleteTool::handle()`'s
      order: `denyIfTokenCannot()` for `create`, `update` and `delete`; validate `primary_id` and
      `secondary_id`; load both through the workspace-scoped query; call `MergeRecords`; catch its
      `ValidationException` and `HttpException` into `Response::error` strings, as every MCP tool
      answers. MCP writes apply at once, as an MCP delete does.
- [ ] Register both tools. In `ToolAnnotationsTest` add both to the class map with a `#[Title]`, an
      `outputSchema` and the `$deleteAnnotations` set (destructive, not idempotent), and raise its
      `toHaveCount(50)` at :172 and :194 to 52. Then add one row per tool to the Companies and
      People tables of `mcp.md`, and raise "50 tools" to 52 and "39 tools" to 41 in all four locales:
      `ConventionsTest` checks only the English count.

### Task 13: Rela merge tools

**Files:** `packages/Chat/src/Enums/PendingActionOperation.php`, `packages/Chat/src/Enums/ProposalEntity.php`
and its lang keys, `packages/Chat/src/Tools/Company/MergeCompaniesTool.php`,
`packages/Chat/src/Tools/People/MergePeopleTool.php`, `packages/Chat/src/Services/PendingActionService.php`,
`packages/Chat/src/Agents/CrmAssistant.php`, the proposal card partials,
`packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`, a new
`tests/Feature/Chat/MergeRecordsToolTest.php`

- [ ] Test first, per entity, following `BulkDeleteToolTest` and `RemoveSampleDataToolTest`:
      - the tool takes `merges: [{primary_id, secondary_id}]` and proposes one `PendingAction`, a
        `_batch` when it holds more than one pair
      - the card shows each pair as "<secondary> into <primary>" with both names
      - approving one pair runs the merge and leaves the others pending; approving all runs each
      - a pair whose record vanished or was merged before approval fails that pair with a message
        and leaves the rest
      - rejecting changes nothing
      - a member without create, update or delete gets no proposal
      - an id named in two pairs is refused at proposal time
      - a single pair is a plain proposal, not a `_batch`, like a single update
      - the card reads as a merge everywhere: the dock step verb, the card body, the transcript, the
        confirm label, and no per-field leave-out control
      - setup mode does not offer the tool, as it drops the delete tools
- [ ] `PendingActionOperation::Merge`. For Company and People, `action`, `done`, `verb` and
      `notDone` already delegate to the operation, so it needs its own arms there and nothing in
      `ProposalEntity`'s per-entity matches. `deletes()` is per operation and returns true for a
      merge. Two kinds of change follow:
      - "Does this step carry field values": add `PendingActionOperation::writesFields()` (true for
        create and update) and use it at `ProposalCard.php:847`, `PendingActionService.php:1027` and
        the dock step's field rows.
      - The words: a `merge` arm beside `delete` in `ProposalEntity::confirmLabel()` (:183), in the
        operation-name match of `_dock-step.blade.php:11-15` and `_proposal-card-body.blade.php:13`,
        and in `transcript.js:1691`, which reads the operation name each step already carries to the
        browser (confirm it in the step payload, or add it there). Add the `batch_title.merge.*`, `batch_summary.merge.*` and
      `receipt_batch.merge.*` lang keys in four locales.
- [ ] Payload: a pair is `_record_id` (the primary), `_model_class` and one new `_merge_with` marker
      (the secondary), read only through a new `ProposalPayload` accessor (chat.md: `ProposalPayload`
      is the one reader of `action_data`). Add the shape to its list (`ProposalPayload.php:13-17`) and
      to the matching "four-arm union" sentence in `.ai/rules/chat.md`.
- [ ] `PendingActionService` branches on `Merge` in both paths: `executeAction()` (:968) for a single
      pair and `executeBatchItem()` (:516-541) for a batch item, which today falls through to
      `execute($user, $record)` and would throw a TypeError PHPStan cannot see. Both call a new
      `executeMerge()` that resolves the two ids in the proposal's workspace at approval time, throws
      `ProposalNotResolvable::recordNotFound()` for a vanished or merged one, and calls `MergeRecords`.
      Add `MergeRecords` to `ALLOWED_ACTION_CLASSES`. A merge pair takes no `$ref`: it names two
      records that already exist.
- [ ] Register both tools on `CrmAssistant`, drop them in setup mode beside the delete tools, and add
      "Preparing company merge…" and "Preparing person merge…" to `toolLabels`, matching "Preparing
      company deletion…". Alias the chat tools in `CrmAssistant` like their siblings. Run
      `php artisan test tests/Browser/Chat/LoadingShimmerTest.php`.

### Task 14: Rela's prompt, evals and a real-stack walk

**Files:** `packages/Chat/src/Agents/CrmAssistant.php` (prompt), `tests/Feature/Chat/CrmAssistantInstructionsTest.php`,
`tests/Evals/Rela/WritesTest.php`

- [ ] The prompt gains one rule: when two records are the same company or person, propose a merge
      with the record to keep as `primary_id`, say the result is a new record holding both, never
      say a merge can be undone, and propose a third copy only after the first merge is approved.
      The setup-mode line (`CrmAssistant.php:487`) sends merging to the record page, since setup mode
      drops the merge tools. Pin the rule in `CrmAssistantInstructionsTest`.
- [ ] One eval case beside "proposes deleting only the company": two duplicate companies, the ask "these
      are the same company, clean it up", judged on calling the merge tool with the right pair and a
      reply that never claims the other record is kept.
- [ ] Walk it on the production-shaped stack (chat.md): Horizon running, `QUEUE_CONNECTION=redis`,
      Reverb up, a real browser. Ask Rela to merge two duplicates, see the card, approve, open the new
      record and both old URLs. Then reject one, and approve a two-pair batch one pair at a time.

## Verification (before the PR is ready again)

- [ ] `vendor/bin/pint --dirty --format agent`, `composer test:affected`, `vendor/bin/rector --dry-run`,
      `vendor/bin/phpstan analyse`, `composer test:lint`, `composer test:arch`.
- [ ] The three merge browser files, the notice test and `LoadingShimmerTest` in Chromium, and the
      merge and notice browser files in WebKit.
- [ ] agent-browser walk on a seeded workspace: merge two companies with notes, tasks and an email,
      read the new record's timeline, open both old URLs, try every trash filter state, merge the
      result again and follow the notices, delete the end of the chain, open the first URL. Merge two
      companies with 4 domains each and read the raised limit in the field settings.
- [ ] Re-read the PR body and update it: the lead, decisions 7 and 11 to 14, the migration and its
      rehearsal, the deploy note (no Horizon pause: the migration only adds a nullable column), the
      sweep list from Task 6, the MCP tool count, and the Rela walk.
