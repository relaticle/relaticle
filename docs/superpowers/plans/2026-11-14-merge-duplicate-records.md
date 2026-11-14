# Merge Duplicate Records (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `sdd-lean` (this user's replacement for
> superpowers:subagent-driven-development) or superpowers:executing-plans to implement this plan
> task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A member merges two duplicate companies, or two duplicate people, from the record page's ⋯ menu. The kept record keeps its ID, takes the other's values and links, and the other is soft-deleted.

**Architecture:** `MergeCompanies` and `MergePeople` in `app/Actions/Merge/` each compose shared steps (`CombineRecordValues`, `MoveRecordLinks`, `MoveRecordHistory`) and write their own entity links. Core dispatches `App\Events\RecordMerged` inside the merge transaction, and `packages/EmailIntegration` moves its emails, meetings, participants, attendees and counters in a listener. One Filament action, `MergeRecordAction`, sits in the record page's ⋯ menu and picks the right merge action.

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
- Run tests with: `DB_USERNAME=postgres DB_PORT=5432 php -d memory_limit=1G vendor/bin/pest --compact <files>` (see the local environment memory if the port refuses).
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
| `app/Actions/Merge/MergeCompanies.php` (create) | Company merge: guards, transaction, company links |
| `app/Actions/Merge/MergePeople.php` (create) | Person merge: guards, transaction, person links |
| `app/Actions/Merge/MoveRecordLinks.php` (create) | Notes, tasks, attachments, record-type references |
| `app/Actions/Merge/MoveRecordHistory.php` (create) | Activity rows and the `record_merged` event |
| `app/Events/RecordMerged.php` (create) | Tells packages a merge is happening |
| `packages/EmailIntegration/src/Listeners/MoveMergedRecordCommunicationListener.php` (create) | Emails, meetings, participants, attendees, counters |
| `packages/EmailIntegration/src/EmailIntegrationServiceProvider.php` (modify) | Registers the listener |
| `app/Filament/Actions/MergeRecordAction.php` (create) | The ⋯ menu item and the dialog |
| `resources/views/filament/app/merge-record.blade.php` (create) | The three-card dialog body |
| `app/Filament/Concerns/HasRecordPageLayout.php:308` (modify) | Adds the action to the menu |
| `app/ActivityLog/AppEventPalette.php` (modify) | `RecordMerged` case |
| `lang/{en,fr}/filament/record-page.php`, `lang/{en,fr}/activity-log.php` (modify) | Copy |
| `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php` (create) | Company merge through `ViewCompany` |
| `tests/Feature/Filament/App/Resources/MergePeopleTest.php` (create) | Person merge through `ViewPeople` |
| `tests/Feature/EmailIntegration/MergedRecordCommunicationTest.php` (create) | Email rows and counters |
| `tests/Browser/CRM/MergeRecordDialogTest.php` (create) | The dialog in a browser |

---

### Task 1: Merge two companies' values from the ⋯ menu

**Files:**
- Create: `app/Data/MergedValues.php`, `app/Actions/Merge/CombineRecordValues.php`, `app/Actions/Merge/MergeCompanies.php`, `app/Filament/Actions/MergeRecordAction.php`
- Modify: `app/Filament/Concerns/HasRecordPageLayout.php:320-330`, `lang/en/filament/record-page.php`, `lang/fr/filament/record-page.php`
- Test: `tests/Feature/Filament/App/Resources/MergeCompaniesTest.php`

**Interfaces:**
- Produces:
  - `final readonly class MergedValues { public function __construct(public array $attributes, public array $customFields, public array $overLimit) }`. `$attributes`: `array<string, mixed>` native columns. `$customFields`: `array<string, mixed>` keyed by field code. `$overLimit`: `array<string, int>`, code to that field's limit.
  - `CombineRecordValues::execute(Company|People $kept, Company|People $duplicate): MergedValues`
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

- [ ] **Step 4: Implement `MergeCompanies`**

Order:
1. `abort_unless($user->can('update', $kept) && $user->can('delete', $duplicate), 403)`.
2. Refuse a duplicate equal to the kept record, or in another workspace: `abort(404)`.
3. `$values = CombineRecordValues->execute(...)`. When `overLimit` is not empty, throw `ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.over_limit', ['field' => $label, 'max' => $limit])])` before anything is written.
4. `DB::transaction`: lock both rows with `Company::query()->whereKey([...])->lockForUpdate()->get()`, and `abort(404)` when either is missing (trashed meanwhile). Then `$duplicate->delete()`, and write the values through the injected `UpdateCompany::execute($user, $kept, [...$values->attributes, 'custom_fields' => $values->customFields])`.
5. Return `$kept->refresh()`.

Later tasks insert their steps before `$duplicate->delete()`.

- [ ] **Step 5: Implement `MergeRecordAction` and add it to the menu**

Mirror `MoveCardAction`'s class shape. Visible only when the record is `Company|People` and the user can `update` and `delete` it. The form has a searchable `Select` named `duplicate_id` and a `Hidden` field named `keep` (default `'current'`). Search inline in `getSearchResultsUsing`: the same model, excluding the current key, name `ilike`, limit 25. The workspace scope already bounds it. The action resolves `kept` and `duplicate` from `keep`, calls `MergeCompanies` (or `MergePeople` once Task 6 lands), sends a success notification, and redirects to the kept record's `view` URL. In `recordActions()`, add `MergeRecordAction::make()` to the inner group after `copyRecordId`, so it renders above `DeleteAction`.

Lang (`filament/record-page.php`): `actions.merge_record` "Merge record", and a `merge` group: `heading` "Merge", `notice` "The record on the right wins where both records hold a value.", `search` "Search", `swap` "Swap records", `submit` "Merge records", `merged` "Records merged", `over_limit` ":field can hold :max values. Together these records have more. Remove some first." Add matching `lang/fr` entries.

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
- Modify: `app/Actions/Merge/MergeCompanies.php`, `app/Actions/Merge/CombineRecordValues.php`
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

In the transaction, before the delete: when `$kept->creation_source === CreationSource::SAMPLE` and the duplicate's is not, set the kept record's `creation_source` to the duplicate's. The owner rule lives in `CombineRecordValues` (Task 1, Step 3).

- [ ] **Step 4: Run them and see them pass**

- [ ] **Step 5: Commit** `fix(merge): guard list limits, sample data and stale duplicates`

### Task 3: Move notes, tasks, attachments and record references

**Files:**
- Create: `app/Actions/Merge/MoveRecordLinks.php`
- Modify: `app/Actions/Merge/MergeCompanies.php`
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
- For `noteables` and `taskables`: delete the duplicate's rows whose item is already linked to the kept record, then update `*_id` on the rest.
- Media: `media` rows with `model_type` = morph, `model_id` = duplicate and `collection_name` = `attachments` get `model_id` = kept, scoped on `workspace_id`.
- Record-type values: `custom_field_values` joined to `custom_fields` where `type = 'record'`, `lookup_type` = the morph, `tenant_id` = workspace, and `json_value::text like '%<duplicate id>%'`. Decode, replace the id, `array_values(array_unique(...))`, write back.

Call it in `MergeCompanies` before `$duplicate->delete()`, and before the values are written, so `UploadClaims` sees the media as owned.

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
- Create: `app/Actions/Merge/MoveRecordHistory.php`, `app/Events/RecordMerged.php`, `packages/EmailIntegration/src/Listeners/MoveMergedRecordCommunicationListener.php`
- Modify: `app/Actions/Merge/MergeCompanies.php`, `app/ActivityLog/AppEventPalette.php`, `lang/{en,fr}/activity-log.php`, `packages/EmailIntegration/src/EmailIntegrationServiceProvider.php:106`
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
    livewire(ViewCompany::class, ['record' => $kept->getKey()])->assertSee('Brightline Health Inc. merged into this record');
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
- `AppEventPalette` gets the `RecordMerged` case. The timeline line reads `__('activity-log.events.record_merged.description', ['name' => ...])`. en: label "Merged", description ":name merged into this record". Find where `app-event.blade.php` takes `$entry->description`, and give this event its description from `duplicate_name`.
- The listener (registered with `Event::listen` beside line 106) owns the email rows:
  - `emailables` and `meetingables`: same dedupe as Task 3.
  - `email_participants` and `meeting_attendees`: `company_id` for a company, `contact_id` for a person.
  - Counters: sum the two records. Subtract the shared emails that `EmailVisibilityService::countsTowardCommunicationIntelligence` counts, by `direction`, and the shared meetings that `meetingCountsTowardCommunicationIntelligence` counts. Take `GREATEST` of the three `last_*_at` columns.
- `MergeCompanies` calls `MoveRecordHistory`, then `RecordMerged::dispatch($kept, $duplicate)`, both before the delete. The shared links must be read before the listener drops them.

- [ ] **Step 4: Run them and see them pass**

Run: `... vendor/bin/pest --compact tests/Feature/Filament/App/Resources/MergeCompaniesTest.php tests/Feature/EmailIntegration/MergedRecordCommunicationTest.php`

- [ ] **Step 5: Commit** `feat(merge): carry history, emails and meetings to the kept record`

### Task 6: Merge two people

**Files:**
- Create: `app/Actions/Merge/MergePeople.php`
- Modify: `app/Filament/Actions/MergeRecordAction.php`
- Test: `tests/Feature/Filament/App/Resources/MergePeopleTest.php`

**Interfaces:**
- Consumes: `CombineRecordValues`, `MoveRecordLinks`, `MoveRecordHistory`, `RecordMerged` from Tasks 1, 3 and 5. `UpdatePeople::execute($user, $person, $data)`.
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

- [ ] **Step 3: Implement `MergePeople`** with the same order as `MergeCompanies`. Its own link is `opportunities.contact_id`, and it writes values through `UpdatePeople`. If the two actions share more than the composed steps, extract the shared part into a step action, not a base class. `MergeRecordAction` picks `MergePeople` for a `People` record.

- [ ] **Step 4: Run them and see them pass**

- [ ] **Step 5: Commit** `feat(merge): merge two people`

### Task 7: The merge dialog

**Files:**
- Create: `resources/views/filament/app/merge-record.blade.php`, `tests/Browser/CRM/MergeRecordDialogTest.php`
- Modify: `app/Filament/Actions/MergeRecordAction.php`

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

- [ ] **Step 3: Build the dialog** as `modalContent(view('filament.app.merge-record', ...))`, laid out like the spec's sketch:
  - three cards: picked, swap button, current (priority), `=`, result
  - the picked slot hosts the searchable select behind a **+**
  - each card shows name, domains or emails, and counts of notes, tasks and (companies) people
  - the result card renders `CombineRecordValues` for the current pair and updates live when `duplicate_id` or `keep` changes
  - the submit is disabled until a duplicate is picked and while `overLimit` is not empty
  - menu tokens from `.ai/guidelines/relaticle/ui` (`h-menu-row`, `size-menu-icon`, `rounded-menu`) for the search list. Colours come from theme tokens only.

- [ ] **Step 4: Run it and see it pass**, then in WebKit: `... vendor/bin/pest --compact tests/Browser/CRM/MergeRecordDialogTest.php --browser safari`. If WebKit fails where Chromium passes, add `pest()->group('webkit')` to the file.

- [ ] **Step 5: Screenshot the dialog** in light, dark and a 390px phone width, empty (nothing picked) and filled, and check it against the spec sketch.

- [ ] **Step 6: Commit** `feat(merge): the merge dialog with search, swap and a live preview`

### Task 8: Pre-push checks

- [ ] **Step 1:** `vendor/bin/rector --dry-run`. Apply with `vendor/bin/rector` when it suggests changes.
- [ ] **Step 2:** `php -d memory_limit=2G vendor/bin/phpstan analyse --memory-limit=2G`. Expected: no new errors and no new ignores.
- [ ] **Step 3:** `composer test:lint` and `composer test:arch`. Expected: PASS. The arch checks cover action naming, the single `execute()` and core never using the package.
- [ ] **Step 4:** `composer test:affected`. Expected: PASS.
- [ ] **Step 5:** Commit any fixes, push, open the PR against `main` with `Refs #885` (Phase 1), and watch the `Tests` workflow in the background.
