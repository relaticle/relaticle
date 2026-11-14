# Merge Duplicate Records (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`)
> syntax for tracking.

**Goal:** A member merges two duplicate companies, or two duplicate people, from the record page's ⋯ menu. The kept record keeps its ID, takes the other's values and links, and the other is soft-deleted.

**Architecture:** `MergeCompanies` and `MergePeople` in `app/Actions/Merge/` are thin. Each calls the shared `MergeRecords` step with two closures, one that moves its own entity links and one that writes the combined values. `MergeRecords` owns the checks, the transaction, the lock and the shared steps (`CombineRecordValues`, `MoveRecordLinks`, `MoveRecordHistory`). Core dispatches `App\Events\RecordMerged` inside the merge transaction, and `packages/EmailIntegration` moves its emails, meetings, participants, attendees and counters from a listener that calls one package action. One Filament action, `MergeRecordAction`, sits first in the record page's ⋯ menu and picks the right merge action. Its dialog body is the `MergeRecordPicker` form field.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Pest 5 (+ browser plugin), PostgreSQL, relaticle/custom-fields, spatie/laravel-activitylog.

**Spec:** `docs/superpowers/specs/2026-11-14-merge-duplicate-records-design.md`

## Global Constraints

- Actions are `final readonly`, one public `execute()`, no `Action` suffix, and check authorization and tenancy themselves (`tests/Arch/ConventionsTest.php`).
- Core (`app/`) never names `Relaticle\EmailIntegration` from an action. Email rows move only in the package listener.
- Never call `Media::move()`. Ownership changes are attribute writes on the existing `media` row (`.ai/rules/file-uploads.md`).
- Writing `custom_fields` saves every field and nulls the missing ones. Always pass the full combined map (`CustomFieldMerger` explains why).
- Every user-facing string goes through `__()`, in `lang/en` and `lang/fr`. Copy says "custom attribute", never "custom field". No em-dashes anywhere.
- Datetimes are written from PHP `now()`, never the database clock.
- Tests live in `tests/Feature` or `tests/Browser`, go through the record page (no unit tests of actions), declare `mutates(...)`, and carry no comments.
- Methods stay under 60 lines (`MethodLengthRule`).
- Run tests with: `DB_USERNAME=postgres DB_PORT=5432 php -d memory_limit=1G vendor/bin/pest --compact <files>`.
- Commits use Conventional Commits with no `Co-Authored-By` trailer, and end with `Refs #885` in the body.

## Review Focus

1. **A note, task, email or meeting linked to both records.** The merge must keep one link and not hit the `(item, type, id)` unique index. Test in Task 3 and Task 5.
2. **A rich-text attribute filled only on the duplicate.** Copying it to the kept record must not trip `UploadClaims`' "image another record owns" check, and its images must still render. Test in Task 3.
3. **The duplicate is deleted, or merged elsewhere, between opening the dialog and confirming.** The merge refuses with a clear error and changes nothing. Test in Task 2.
4. **The duplicate's account owner has left the workspace.** The merge still succeeds, and the kept company's owner stays empty instead of failing `TenantFkValidator`. Test in Task 2.
5. **A forged `duplicate_id`:** the current record itself, a trashed record, or a record in another workspace. The merge refuses all three. Test in Task 1.

---

## File map

| File | Responsibility |
|---|---|
| `app/Data/MergedValues.php` (create) | The combined values and any list over its limit |
| `app/Actions/Merge/CombineRecordValues.php` (create) | The merge rule. Read by the merge and the dialog preview |
| `app/Actions/Merge/MergeRecords.php` (create) | The shared merge step: authorization, lock, limits, sample data, the shared moves, the event and the delete |
| `app/Actions/Merge/MergeCompanies.php` (create) | Company merge: its own links and its write, through `MergeRecords` |
| `app/Actions/Merge/MergePeople.php` (create) | Person merge: its own links and its write, through `MergeRecords` |
| `app/Actions/Merge/MoveRecordLinks.php` (create) | Notes, tasks, attachments, record-type references |
| `app/Actions/Merge/MoveMorphPivot.php` (create) | Moves one morph pivot's rows and drops the ones the kept record already has. Shared by core and the email package |
| `app/Actions/Merge/MoveRecordHistory.php` (create) | Activity rows and the `record_merged` event |
| `app/Events/RecordMerged.php` (create) | Tells packages a merge is happening |
| `app/Enums/RecordIdentity.php` (create) | The identifying list of an entity (domains or emails): field code, icon, link, empty label, search placeholder |
| `app/Enums/CrmEntity.php` (modify) | `identity()` and `mergeCountRelations()` |
| `app/Models/People.php` (modify) | `contactOpportunities()` relation, counted on the person card |
| `packages/EmailIntegration/src/Actions/MoveMergedRecordCommunication.php` (create) | Emails, meetings, participants, attendees, counters |
| `packages/EmailIntegration/src/Listeners/MoveMergedRecordCommunicationListener.php` (create) | Listens for `RecordMerged` and makes one call to the action above |
| `packages/EmailIntegration/src/Services/RecordCommunicationMetrics.php` (modify) | `combineMergedRecords()` sums the counters and takes the later dates |
| `packages/EmailIntegration/src/EmailIntegrationServiceProvider.php` (modify) | Registers the listener |
| `app/Filament/Actions/MergeRecordAction.php` (create) | The ⋯ menu item, the dialog heading and the submit |
| `app/Filament/Components/Forms/MergeRecordPicker.php` (create) | The dialog's form field: search, pairing, cards and over-limit messages |
| `resources/views/filament/app/merge-record.blade.php` (create) | The three-card dialog body |
| `resources/views/filament/app/merge-record/{card,heading,mark,picker,alert}.blade.php` (create) | The card, the dialog heading, the logo or icon mark, the search list and the alert |
| `app/Filament/Concerns/HasRecordPageLayout.php` (modify) | Adds the action first in the ⋯ menu |
| `app/ActivityLog/AppEventPalette.php` (modify) | `RecordMerged` case with `title()` and `description()` |
| `app/Support/ActivityLog/MergedActivityRenderer.php` (modify) | Takes `AppEventRenderer` in its constructor and renders app events through it |
| `resources/views/activity-log/entries/app-event.blade.php` (modify) | Shows the entry's title and description |
| `lang/{en,fr}/filament/record-page.php`, `lang/{en,fr}/activity-log.php` (modify) | Copy |
| `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php` (create) | Company merge through `ViewCompany` |
| `tests/Feature/Filament/App/Resources/MergePeopleTest.php` (create) | Person merge through `ViewPeople` |
| `tests/Feature/Filament/App/Resources/MergeRecordDialogStateTest.php` (create) | The dialog's "no longer available" message |
| `tests/Feature/EmailIntegration/MergedRecordCommunicationTest.php` (create) | Email rows and counters |
| `tests/Browser/CRM/MergeRecordDialogTest.php` (create) | The main flows in a browser |
| `tests/Browser/CRM/MergeRecordDialogKeyboardTest.php` (create) | Esc, arrows, Enter and focus |
| `tests/Browser/CRM/MergeRecordDialogLayoutTest.php` (create) | Row and phone layout, popover placement |
| `tests/Browser/Panel/MenusTest.php` (modify) | The picker joins the menu datasets |

---

### Task 1: Merge two companies' values from the ⋯ menu

**Files:**
- Create: `app/Data/MergedValues.php`, `app/Actions/Merge/CombineRecordValues.php`, `app/Actions/Merge/MergeRecords.php`, `app/Actions/Merge/MergeCompanies.php`, `app/Filament/Actions/MergeRecordAction.php`
- Modify: `app/Filament/Concerns/HasRecordPageLayout.php:320-330`, `lang/en/filament/record-page.php`, `lang/fr/filament/record-page.php`
- Test: `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`

**Interfaces:**
- Produces:
  - `final readonly class MergedValues { public function __construct(public array $attributes, public array $customFields, public array $overLimit) }`. `$attributes`: `array<string, mixed>` native columns. `$customFields`: `array<string, mixed>` keyed by field code. `$overLimit`: `array<string, array{label: string, limit: int}>`, field code to that field's label and limit.
  - `CombineRecordValues::execute(Company|People $kept, Company|People $duplicate): MergedValues`
  - `MergeRecords::execute(User $user, Company|People $kept, Company|People $duplicate, Closure $moveOwnLinks, Closure $writeValues): Company|People`. `$moveOwnLinks(kept, duplicate)` moves the entity's own links. `$writeValues(kept, MergedValues)` writes the combined values and returns the record
  - `MergeCompanies::execute(User $user, Company $kept, Company $duplicate): Company`
  - `MergeRecordAction` named `mergeRecord`, form data `['duplicate_id' => string, 'keep' => 'current'|'other']`

- [ ] **Step 1: Write the failing tests**

Set custom attribute values the way `tests/Feature/Mcp/EmailToolsTest.php:195-200` does (`saveCustomFieldValue($field, $value, $workspace)`). Call the action as `TestAction::make('mergeRecord')->schemaComponent('recordActions', schema: 'infolist')` on `livewire(ViewCompany::class, ['record' => $kept->getKey()])`.

```php
it('keeps the current company and fills its empty values from the duplicate', function (): void {
    // kept: name 'Brightline Health', icp empty, domains ['brightlinehealth.com']
    // duplicate: name 'Brightline Health Inc.', icp true, domains ['brightline.health', 'brightlinehealth.com']
    // callAction(mergeRecord, data: ['duplicate_id' => $duplicate->id, 'keep' => 'current'])
    expect($kept->refresh()->name)->toBe('Brightline Health')
        ->and(customValue($kept, 'icp'))->toBeTrue()
        ->and(customValue($kept, 'domains'))->toBe(['brightlinehealth.com', 'brightline.health'])
        ->and(Company::withTrashed()->find($duplicate->id)->trashed())->toBeTrue()
        ->and(Company::find($kept->id))->not->toBeNull();
});

it('keeps the other company when the member swaps the two', function (): void {
    // 'keep' => 'other': the duplicate's id survives, the current record is trashed, redirect to the survivor's view URL
});

it('never overwrites a filled value with an empty one', function (): void {
    // kept linkedin filled, duplicate linkedin null: kept's value unchanged
});

it('combines multi-select values with the kept record first', function (): void {
    // a workspace multi-select field: kept [A], duplicate [B, A] => [A, B]
});

it('hides merge from a member who cannot update and delete the record', function (): void {
    // member role without records.delete: assertActionHidden(mergeRecord)
});

it('refuses the current record, a trashed record and another workspace\'s record', function (string $case): void {
    // each forged duplicate_id: action errors, nothing trashed, values unchanged
})->with(['itself', 'trashed', 'other workspace']);
```

`customValue(Model $record, string $code): mixed` is a file-local helper reading `$record->customFieldValues` by field code.

- [ ] **Step 2: Run the tests and see them fail**

Run: `DB_USERNAME=postgres DB_PORT=5432 php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`
Expected: FAIL, action `mergeRecord` does not exist.

- [ ] **Step 3: Implement `CombineRecordValues` and `MergedValues`**

Native columns: `['name', 'account_owner_id']` for `Company`, `['name', 'company_id']` for `People`. Read custom values as `CustomFieldMerger::merge` does (`customFieldValues.customField`, `Collection` to array). Walk every active field of the entity in the workspace:

```
isList  = type in [MULTI_SELECT, CHECKBOX_LIST, TAGS_INPUT] or field.settings.allow_multiple
limit   = field.settings.allow_multiple and type != RECORD ? field.settings.max_values : null
filled  = value not null, not '', not []

if isList and both values are arrays:
    value = array_values(array_unique([...kept, ...duplicate]))
    if limit !== null and count(value) > limit: overLimit[code] = limit
else:
    value = filled(kept) ? kept : duplicate
```

Native columns follow the single-value rule. `account_owner_id` taken from the duplicate counts only when that user is still a member of the workspace (Review Focus 4, tested in Task 2).

- [ ] **Step 4: Implement `MergeRecords` and `MergeCompanies`**

`MergeRecords::execute` runs, in order:
1. `abort_unless($user->can('update', $kept) && $user->can('delete', $duplicate), 403)`.
2. Refuse a duplicate equal to the kept record, or in another workspace: `ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.unavailable')])`.
3. `DB::transaction`: lock both rows with one select ordered by id (`lockForUpdate`), so two opposite merges of the same pair wait instead of deadlocking. Throw the same `merge.unavailable` error when either row is missing (trashed meanwhile).
4. `$values = CombineRecordValues->execute(...)`. When `overLimit` is not empty, throw `ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.over_limit', ['field' => $label, 'max' => $limit])])` before anything is written.
5. Move the links, call `$moveOwnLinks`, move the history, dispatch `RecordMerged`, then `$duplicate->delete()`, then return `$writeValues($kept, $values)`.

`MergeCompanies::execute` calls it with a closure that moves the company's own links and a closure that writes the values through the injected `UpdateCompany::execute($user, $kept, [...$values->attributes, 'custom_fields' => $values->customFields])`. Later tasks add their steps to `MergeRecords` before the delete, or to the entity's own closure.

- [ ] **Step 5: Implement `MergeRecordAction` and add it to the menu**

Mirror `MoveCardAction`'s class shape. Visible only when the record is `Company|People` and the user can `update` and `delete` it. The schema holds a `MergeRecordPicker` field named `duplicate_id` (a form field, not a searchable `Select` and not a `modalContent` view) and a `Hidden` field named `keep` (default `'current'`). The picker's `searchRecords()` is `#[Exposed]` and `#[Renderless]`: the same model, excluding the current key, name `ilike` or any domain or email, limit 25, ordered by name. The workspace scope already bounds it. The action resolves `kept` and `duplicate` from `keep`, calls `MergeCompanies` (or `MergePeople` once Task 6 lands), shows the success notification and redirects to the kept record's `view` URL. A `ValidationException` from the merge is rethrown on the picker's state path so the dialog shows it. In `recordActions()`, add `MergeRecordAction::make()` as the first item of the inner group, before `copyPageUrl`, so Merge record is the first item in the ⋯ menu.

Lang (`filament/record-page.php`): `actions.merge_record` "Merge record", `notifications.records_merged` "Records merged", and a `merge` group: `heading` "Merge", `notice` "The record on the right wins where both records hold a value.", `search` "Search", `swap` "Swap records", `submit` "Merge records", `unavailable` "That record is no longer available to merge.", `over_limit` ":field can hold :max values. Together these records have more. Remove some first.", plus the picker's own strings (`pick`, `clear`, `search_placeholder`, `no_results`, `no_domains`, `no_emails`, `record`, `counts`). Add matching `lang/fr` entries.

- [ ] **Step 6: Run the tests and see them pass**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/MergedValues.php app/Actions/Merge app/Filament/Actions/MergeRecordAction.php app/Filament/Concerns/HasRecordPageLayout.php lang tests/Feature/Filament/App/Resources/MergeCompaniesTest.php
git commit -m "feat(merge): merge two companies from the record menu" -m "Refs #885"
```

### Task 2: Guards: list limits, sample data, stale duplicates, departed owners

**Files:**
- Modify: `app/Actions/Merge/MergeRecords.php`, `app/Actions/Merge/CombineRecordValues.php`
- Test: `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`

**Interfaces:**
- Consumes: `MergeCompanies::execute`, `MergedValues::$overLimit` from Task 1.

- [ ] **Step 1: Write the failing tests**

```php
it('blocks a merge that would give a company more than five domains', function (): void {
    // kept 3 domains, duplicate 3 different domains
    // ->assertHasActionErrors(['duplicate_id']); duplicate not trashed; kept domains unchanged (3)
});

it('makes the kept company real data when the duplicate was real', function (): void {
    // kept creation_source SAMPLE, duplicate WEB => kept creation_source WEB
    // then resolve(RemoveSampleData::class)->execute(...) leaves kept alive
});

it('refuses a duplicate deleted after the dialog opened', function (): void {
    // mount the page, soft-delete the duplicate, then callAction => error, kept values unchanged
});

it('leaves the owner empty when the duplicate\'s owner has left the workspace', function (): void {
    // kept account_owner_id null; duplicate's owner removed from workspace => merge succeeds, kept owner null
});
```

- [ ] **Step 2: Run them and see them fail**

Run: `... vendor/bin/pest --compact tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`
Expected: the sample and owner cases FAIL. The limit and stale cases may already pass from Task 1. If so, keep them as guards.

- [ ] **Step 3: Implement**

In `MergeRecords`, inside the transaction and before the delete: when `$kept->creation_source === CreationSource::SAMPLE` and the duplicate's is not, set the kept record's `creation_source` to the duplicate's. The owner rule lives in `CombineRecordValues` (Task 1, Step 3).

- [ ] **Step 4: Run them and see them pass**

- [ ] **Step 5: Commit** `fix(merge): guard list limits, sample data and stale duplicates`

### Task 3: Move notes, tasks, attachments and record references

**Files:**
- Create: `app/Actions/Merge/MoveRecordLinks.php`, `app/Actions/Merge/MoveMorphPivot.php`
- Modify: `app/Actions/Merge/MergeRecords.php`
- Test: `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`

**Interfaces:**
- Produces: `MoveRecordLinks::execute(Company|People $kept, Company|People $duplicate): void`

- [ ] **Step 1: Write the failing tests**

```php
it('moves the duplicate\'s notes and tasks and keeps a shared one once', function (): void {
    // noteA on duplicate, noteShared on both, taskB on duplicate
    expect($kept->notes()->pluck('notes.id')->sort()->values()->all())->toBe(collect([$noteA->id, $noteShared->id])->sort()->values()->all())
        ->and(DB::table('noteables')->where('note_id', $noteShared->id)->count())->toBe(1)
        ->and($kept->tasks()->whereKey($taskB->id)->exists())->toBeTrue();
});

it('copies a rich-text value with its image from the duplicate', function (): void {
    // a rich-editor field filled only on duplicate, embedding an attachment media row owned by duplicate
    // after merge: kept holds the HTML, the media row's model_id is kept->id, the rendered URL resolves
});

it('repoints record-type attributes on other records to the kept company', function (): void {
    // a record field on Opportunity (lookup company) holding [$duplicate->id, $kept->id] on opp1 and [$duplicate->id] on opp2
    // after merge: opp1 => [$kept->id], opp2 => [$kept->id]
});
```

- [ ] **Step 2: Run them and see them fail**

- [ ] **Step 3: Implement `MoveRecordLinks`**

Use the query builder, not models. Raw writes skip `LogsLinkChanges`, so no link-change rows are written on the notes and tasks (spec: moved, not re-linked).
- For `noteables` and `taskables`: `MoveMorphPivot` deletes the duplicate's rows whose item is already linked to the kept record, then updates `*_id` on the rest. The email package reuses it for `emailables` and `meetingables`.
- Media: `media` rows with `model_type` = morph, `model_id` = duplicate and `collection_name` = `attachments` get `model_id` = kept, scoped on `workspace_id`.
- Record-type values: `custom_field_values` joined to `custom_fields` where `type = 'record'`, `lookup_type` = the morph, `tenant_id` = workspace, and `json_value::text like '%<duplicate id>%'`. Swap the id inside the list and drop repeats in one set-based `update`, keeping the order.

Call it in `MergeRecords` before `$duplicate->delete()`, and before the values are written, so `UploadClaims` sees the media as owned.

- [ ] **Step 4: Run them and see them pass**

- [ ] **Step 5: Commit** `feat(merge): move notes, tasks, attachments and record references`

### Task 4: Move a company's people, opportunities and logo

**Files:**
- Modify: `app/Actions/Merge/MergeCompanies.php`
- Test: `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`

- [ ] **Step 1: Write the failing tests**

```php
it('moves the duplicate\'s people and opportunities to the kept company', function (): void {
    expect($person->refresh()->company_id)->toBe($kept->id)
        ->and($opportunity->refresh()->company_id)->toBe($kept->id);
});

it('takes the duplicate\'s logo only when the kept company has none', function (bool $keptHasLogo): void {
    // logo media on duplicate (and on kept when $keptHasLogo); after merge kept's logo is its own, or the duplicate's
})->with([true, false]);
```

- [ ] **Step 2: Run them and see them fail**

- [ ] **Step 3: Implement** `people.company_id` and `opportunities.company_id` updates with the query builder. Move the logo by updating the `logo` media row's `model_id` when the kept company has no logo media. Both go before `$duplicate->delete()`, because `people.company_id` cascades on a hard delete.

- [ ] **Step 4: Run them and see them pass**

- [ ] **Step 5: Commit** `feat(merge): move a company's people, opportunities and logo`

### Task 5: History, the merged event, and email rows

**Files:**
- Create: `app/Actions/Merge/MoveRecordHistory.php`, `app/Events/RecordMerged.php`, `packages/EmailIntegration/src/Actions/MoveMergedRecordCommunication.php`, `packages/EmailIntegration/src/Listeners/MoveMergedRecordCommunicationListener.php`
- Modify: `app/Actions/Merge/MergeRecords.php`, `app/ActivityLog/AppEventPalette.php`, `app/Support/ActivityLog/MergedActivityRenderer.php`, `resources/views/activity-log/entries/app-event.blade.php`, `lang/{en,fr}/activity-log.php`, `packages/EmailIntegration/src/Services/RecordCommunicationMetrics.php`, `packages/EmailIntegration/src/EmailIntegrationServiceProvider.php`
- Test: `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`, `tests/Feature/EmailIntegration/MergedRecordCommunicationTest.php`

**Interfaces:**
- Produces:
  - `MoveRecordHistory::execute(User $user, Company|People $kept, Company|People $duplicate): void`
  - `final readonly class RecordMerged { use Dispatchable; public function __construct(public Company|People $kept, public Company|People $duplicate) {} }`, shaped like `App\Events\WorkspaceDeleted`
  - `AppEventPalette::RecordMerged = 'record_merged'`, icon `lucide-merge`

- [ ] **Step 1: Write the failing tests**

```php
it('shows the duplicate\'s history and a merged entry on the kept company', function (): void {
    // duplicate has an 'updated' activity row; after merge:
    expect(Activity::query()->where('subject_id', $kept->id)->where('event', 'record_merged')->first()?->properties['duplicate_name'])->toBe('Brightline Health Inc.')
        ->and(Activity::query()->whereKey($duplicateUpdate->id)->value('subject_id'))->toBe($kept->id);
    livewire(ViewCompany::class, ['record' => $kept->getKey()])->assertSee("{$this->user->name} merged Brightline Health Inc. into this record");
});
```

In `MergedRecordCommunicationTest.php`, for companies and for people (dataset):

```php
it('moves emails, meetings, participants and attendees, keeping a shared email once', function (): void { /* emailables, meetingables, email_participants.company_id|contact_id, meeting_attendees.* */ });

it('adds up the counters without counting a shared email twice', function (): void {
    // kept email_count 3, duplicate 2, one shared counted email => 4; inbound/outbound split by the shared email's direction; last_email_at is the later one
});
```

- [ ] **Step 2: Run them and see them fail**

- [ ] **Step 3: Implement**
- `MoveRecordHistory` updates `activity_log.subject_id` from the duplicate to the kept record for the morph type. It then logs `activity()->performedOn($kept)->causedBy($user)->event('record_merged')->withProperties(['duplicate_name' => $duplicate->name])->log('record_merged')`, using the log name `LabelledChangeLog::write` uses.
- `AppEventPalette` gets the `RecordMerged` case, and `title()` and `description(TimelineEntry)` methods. The description names who merged: `activity-log.events.record_merged.description_by` ":causer merged :name into this record", with `description` ":name merged into this record" when the entry has no causer. en label: "Merged". `app-event.blade.php` takes its title and description from the palette first. `MergedActivityRenderer` takes `AppEventRenderer` in its constructor and hands app events to it.
- The listener is registered with `Event::listen` in `EmailIntegrationServiceProvider` and makes one call to `MoveMergedRecordCommunication`, which owns the email rows:
  - `emailables` and `meetingables`: same dedupe as Task 3.
  - `email_participants` and `meeting_attendees`: `company_id` for a company, `contact_id` for a person.
  - Counters: `RecordCommunicationMetrics::combineMergedRecords()` sums the two records. Subtract the shared emails that `EmailVisibilityService::countsTowardCommunicationIntelligence` counts, by `direction`, and the shared meetings that `meetingCountsTowardCommunicationIntelligence` counts. Take the later of each `last_*_at` value.
- `MergeRecords` calls `MoveRecordHistory`, then `event(new RecordMerged($kept, $duplicate))`, both before the delete. The shared links must be read before the listener drops them.

- [ ] **Step 4: Run them and see them pass**

Run: `... vendor/bin/pest --compact tests/Feature/Filament/App/Resources/MergeCompaniesTest.php tests/Feature/EmailIntegration/MergedRecordCommunicationTest.php`

- [ ] **Step 5: Commit** `feat(merge): carry history, emails and meetings to the kept record`

### Task 6: Merge two people

**Files:**
- Create: `app/Actions/Merge/MergePeople.php`
- Modify: `app/Filament/Actions/MergeRecordAction.php`
- Test: `tests/Feature/Filament/App/Resources/MergePeopleTest.php`

**Interfaces:**
- Consumes: `MergeRecords` (with `CombineRecordValues`, `MoveRecordLinks`, `MoveRecordHistory` and `RecordMerged` behind it) from Tasks 1, 3 and 5. `UpdatePeople::execute($user, $person, $data)`.
- Produces: `MergePeople::execute(User $user, People $kept, People $duplicate): People`

- [ ] **Step 1: Write the failing tests** through `livewire(ViewPeople::class, ...)`:
  - emails combined in order, with the limit of 5 blocking
  - phone kept from the kept person when both hold one
  - `company_id` filled from the duplicate when the kept person has none
  - the duplicate's notes and tasks move, and a shared one stays once
  - `opportunities.contact_id` moves to the kept person
  - history moves and `record_merged` is written
  - sample data becomes real
  - the action is hidden without `update` and `delete`
  - the forged-id dataset from Task 1

- [ ] **Step 2: Run them and see them fail**

- [ ] **Step 3: Implement `MergePeople`** as a thin action over `MergeRecords`, like `MergeCompanies`. Its own-links closure moves `opportunities.contact_id`, and its write closure goes through `UpdatePeople`. `MergeRecords` is the shared step both actions call, so there is no base class. `MergeRecordAction` picks `MergePeople` for a `People` record.

- [ ] **Step 4: Run them and see them pass**

- [ ] **Step 5: Commit** `feat(merge): merge two people`

### Task 7: The merge dialog

**Files:**
- Create: `app/Filament/Components/Forms/MergeRecordPicker.php`, `app/Enums/RecordIdentity.php`, `resources/views/filament/app/merge-record.blade.php`, `resources/views/filament/app/merge-record/{card,heading,mark,picker,alert}.blade.php`, `tests/Browser/CRM/MergeRecordDialogTest.php`, `tests/Browser/CRM/MergeRecordDialogKeyboardTest.php`, `tests/Browser/CRM/MergeRecordDialogLayoutTest.php`, `tests/Feature/Filament/App/Resources/MergeRecordDialogStateTest.php`
- Modify: `app/Filament/Actions/MergeRecordAction.php`, `app/Enums/CrmEntity.php`, `tests/Browser/Panel/MenusTest.php`

**Interfaces:**
- Consumes: `CombineRecordValues::execute` for the preview card, `MergedValues::$overLimit` for the disabled state.

- [ ] **Step 1: Write the failing browser test** (`visitAs($user, '/app/...', 1440, 900)`):

```php
it('merges a duplicate picked from the dialog and lands on the kept company', function (): void {
    // open ⋯, click "Merge record", the right card shows the current company, the left slot shows "+"
    // search the duplicate's name, pick it, the result card lists both domains
    // "Merge records" becomes enabled, click it, the URL is the kept company's, "Records merged" notification
});

it('swaps the two records and keeps the other one', function (): void { /* click swap, merge, land on the other record's URL */ });

it('disables merging and names the list over its limit', function (): void { /* 3 + 3 domains: message visible, submit disabled */ });
```

- [ ] **Step 2: Run it and see it fail**

Run: `... vendor/bin/pest --compact tests/Browser/CRM/MergeRecordDialogTest.php`

- [ ] **Step 3: Build the dialog** as the `MergeRecordPicker` form field in the action's schema (Task 1, Step 5), rendered by `merge-record.blade.php` and laid out like the spec's sketch:
  - three cards: picked, swap button, current (priority), `=`, result
  - the picked slot hosts the search list behind a **+**. The list is positioned by hand and never with `x-float`, which caps it to the dialog height. It waits for the phone sheet to finish opening before it measures
  - search by name, and by domain (companies) or email (people), through the field's `searchRecords()`
  - Esc closes the list and not the dialog. Arrows move, Enter picks, and Enter merges when focus is not in a field. After a pick, focus moves to the clear button
  - each card shows name, domains or emails, and counts from `CrmEntity::mergeCountRelations()`
  - the result card renders `CombineRecordValues` for the current pair and updates live when `duplicate_id` or `keep` changes
  - the submit is disabled until a duplicate is picked and while `overLimit` is not empty, and an alert names the list over its limit
  - menu tokens from `.ai/guidelines/relaticle/ui` (`h-menu-row`, `size-menu-icon`, `rounded-menu`) for the search list. Colours come from theme tokens only
  - add the picker to the `MenusTest` datasets
  - split the browser tests into three files: main flows, keyboard, and layout (laptop, desktop, phone, popover placement)

- [ ] **Step 4: Run it and see it pass**, then in WebKit: `... vendor/bin/pest --compact tests/Browser/CRM/MergeRecordDialogTest.php --browser safari`. If WebKit fails where Chromium passes, add `pest()->group('webkit')` to the file.

- [ ] **Step 5: Screenshot the dialog** in light, dark and a 390px phone width, empty (nothing picked) and filled, and check it against the spec sketch.

- [ ] **Step 6: Commit** `feat(merge): the merge dialog with search, swap and a live preview`

### Task 8: Pre-push checks

- [ ] **Step 1:** `vendor/bin/rector --dry-run`. Apply with `vendor/bin/rector` when it suggests changes.
- [ ] **Step 2:** `php -d memory_limit=2G vendor/bin/phpstan analyse --memory-limit=2G`. Expected: no new errors and no new ignores.
- [ ] **Step 3:** `composer test:lint` and `composer test:arch`. Expected: PASS. The arch checks cover action naming, the single `execute()` and core never using the package.
- [ ] **Step 4:** `composer test:affected`. Expected: PASS.
- [ ] **Step 5:** Commit any fixes, push, open the PR against `main` with `Refs #885` (Phase 1), and watch the `Tests` workflow in the background.
