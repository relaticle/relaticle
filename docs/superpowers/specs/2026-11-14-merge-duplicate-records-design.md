# Merge duplicate companies and people

**Date:** 2026-11-14
**Status:** Phase 1 built (PR #1193), Phase 2 built
**Issue:** #885

## Summary

A member merges two duplicate companies, or two duplicate people, into one record. They open
**Merge record** from the record page's ⋯ menu, pick the duplicate, and confirm. The record they
started on survives with its own ID and URL. The duplicate's values fill the gaps, its emails or
domains join the kept record's, everything linked to it moves across, and it is soft-deleted.

## Why

Duplicates already exist. After domain normalization, 259 domains are shared by 717 companies in 5
workspaces, about three records per domain. Mailbox sync creates companies by domain and people by
email (`AutoCreateCompanyAction`, `AutoCreatePersonAction`), so a duplicate that loses its domains or
emails in a merge comes straight back on the next sync.

## Decisions (2026-11-14)

| # | Question | Choice |
|---|---|---|
| 1 | Merge rule | Whole-record priority. The priority record wins where both hold a value. No per-field picking |
| 2 | Records per merge | Two. Three duplicates take two merges |
| 3 | Where it starts | **Merge record** is the first item in the record page's ⋯ menu. Companies and people only |
| 4 | Dialog | The current record fills the priority slot, the other slot starts empty with a **+** that opens a search, a ⇄ button swaps the two, and a third card previews the result |
| 5 | Which record survives | The priority record keeps its ID and URL. A new ID would break every saved link, API reference and Rela mention to both records, and double the links to move |
| 6 | Duplicate prompt | Phase 2, a separate PR: a record-page section listing the duplicates, each with a Merge that opens the dialog with it picked. A duplicates review page is out of scope |
| 7 | Rela, API, MCP | Out of scope. Panel only |
| 8 | Build shape | Approach A below |
| 9 | More than 5 domains or emails | Block the merge. The dialog names the list over the limit (`getMaxValues()`, 5) so the member trims it first. Dropping extras would let mailbox sync recreate the duplicate |
| 10 | Sample data | When the kept record is `CreationSource::SAMPLE` and the duplicate is not, the kept record takes the duplicate's source, so `RemoveSampleData` never deletes what was merged into it |

## Phases

1. **Merge two records** (built, below): the action, the dialog and the merge.
2. **Duplicate prompt**: on a company or person page, a query in `app/Queries` finds other live records
   in the workspace sharing any domain or email, with mailbox sync's normalization. A collapsed section
   counts them, and expanding it lists each one with the value it shares and its own **Merge**, which
   opens the Phase 1 dialog with that record picked. The section is hidden from members who cannot merge,
   and gone once no duplicate is left.
   Built as `App\Queries\Crm\DuplicateRecordQuery::get()`. It reads each of the record's domains or
   emails through `EntitiesByFieldValueQuery` with `CanonicalValue::spellings()`, the match the upsert API
   and the restore conflict already use: case-insensitive, with `www.` and a scheme folded into one domain.
   It returns up to 10 records, grouped by the value they share and oldest first within each value.
   The section is a native `Section` in the record rail under the quick actions. Its rows are a small
   view, `filament.app.duplicate-prompt`, because an action inside a `RepeatableEntry` item cannot be
   mounted. Each row's **Merge** is a `MergeRecordAction` named `mergeDuplicate`. The row passes its
   record id, and `duplicateId()` accepts only an id from the list, falling back to the first duplicate.
   Both merge entry points read `MergeRecordAction::canMerge()`. The header's dismiss writes a session
   key for the record, so the section stays hidden for that record until the session ends.

## Approaches

**A. One action per entity, sharing the common steps (chosen first, then folded).** The thin
`MergeCompanies` and `MergePeople` wrappers were built, then folded into `MergeRecords` in review:
the two closures each wrapper passed were its only content, so `MergeRecords` now owns the whole
merge and dispatches the per-entity differences through exhaustive `match` expressions that PHPStan
fails the moment a third entity joins the `Company|People` union. `MergeRecords` owns the
authorization checks, the transaction, the lock on both rows, the limit check, the sample-data rule,
the link and history moves, the `RecordMerged` event and the soft delete. It composes
`CombineRecordValues`, `MoveRecordLinks` and `MoveRecordHistory`, and the pivot-moving steps share
`MoveMorphPivot`. A company owns people and opportunities. A person is only a point of contact.
Per-entity facts the dialog needs (the identifying list, the count chips) come from `CrmEntity` and
`RecordIdentity`, not from a branch on the record type.

**B. One generic `MergeRecords` driven by data on `CrmEntity`.** Less code, but the difference
between the entities becomes data the reader has to follow. Adding opportunities later would be one
enum case, which is the only thing it wins on.

**C. Discover links from the database schema.** Dropped. It cannot see links stored inside JSON,
such as record-type custom attributes, and it would move whatever a future table adds without
anyone deciding it should.

## The dialog

```
Merge  [Brightline Health]                                              ✕
ⓘ The record on the right wins where both records hold a value.

┌───────────────┐       ┌───────────────┐       ┌───────────────┐
│       +       │  ⇄    │ Brightline    │   =   │ Brightline    │
│ (search the   │       │ Health        │       │ Health        │
│  other record)│       │ domains, ...  │       │ preview       │
└───────────────┘       └───────────────┘       └───────────────┘
                                            [Cancel] [Merge records]
```

- The dialog body is one form field, `MergeRecordPicker`, which `MergeRecordAction` puts in its
  schema as `picked_id` next to a hidden `keep_picked` boolean (false until ⇄). The field renders
  the cards from `resources/views/filament/app/merge-record.blade.php` and its partials (card,
  heading, mark, picker).
- The record the member opened from sits on the right: the priority slot.
- The left slot's **+** opens a search list of live records of the same entity in the workspace,
  excluding the current one. It matches the name, and for companies any domain, for people any email.
  Picking a record sets `picked_id` and resets `keep_picked` to false.
- Keyboard: the arrow keys move through the list, Enter picks the highlighted record, and Esc closes
  the list without closing the dialog. When focus is not in a field, Enter merges once a record is
  picked. After a pick, focus moves to the clear button on the picked card.
- The list is positioned by hand, not with `x-float`, because `x-float` caps the list to the dialog's
  height and the list must float past the dialog's edge. On a phone it waits for the sheet to finish
  rising before it measures where to sit.
- ⇄ swaps the two. Whichever record is on the right is the one kept.
- Each source card shows the name, the identifying list (domains or emails) and counts of linked
  records. The result card shows the combined values and counts, and updates live as the pick or the
  swap changes.
- **Merge records** stays disabled until a second record is picked, and while a combined list is over
  its limit. An alert names the list over the limit.
- After the merge, the member lands on the kept record's page with a "Records merged" notification.
  If the kept record is the other one (after ⇄), they are redirected to it.

## The merge rule

`kept` is the priority record, `duplicate` the other one.

**Single values** (name, account owner, a person's company, every single-value custom attribute):
`kept`'s value when it holds one, otherwise `duplicate`'s. "Holds one" means not null, not an empty
string and not an empty list.

**Lists that identify the record** (company `domains`, people `emails`): `kept`'s values first, then
`duplicate`'s, with duplicates removed after the field's own normalization. Order matters:
`FetchFaviconForCompany` takes the first domain, so `kept`'s logo source stays the same.

**Other multi-value custom attributes** (multi-select, checkbox list, tags, and any field whose
`allow_multiple` setting is on): combined the same way, `kept`'s first. A field that holds a list but
allows one value, such as a person's phone, follows the single-value rule. The limit is the field's
own `max_values` (5 for domains and emails), and record-type fields have none. (Refined while
planning.)

The preview and the merge read this rule from one place, so the card cannot promise what the merge
does not do.

## What moves

All of it runs in one database transaction, with both rows locked (`lockForUpdate`) so two members
merging the same records cannot interleave.

**Both entities**
- Note, task, email and meeting links (`noteables`, `taskables`, `emailables`, `meetingables`).
  A link the kept record already has is dropped instead of moved, because each table is unique on
  (item, type, id).
- Activity history: `activity_log` rows whose subject is the duplicate move to the kept record, so its
  timeline shows the duplicate's past. Then a new `record_merged` event on the kept record names the
  duplicate and who merged it, so the timeline reads "<member> merged <duplicate> into this record".
- `attachments` media the merged rich-text values actually reference, so a value copied from the
  duplicate keeps its images. `UploadClaims` rejects a rich-text value that references media owned
  by another record, so the media moves before the values are written. An image whose text lost the
  field stays on the soft-deleted duplicate, because moving it would orphan it on the kept record
  (builder's call, made in review).
- Record-type custom attributes on other records that point at the duplicate are rewritten to point
  at the kept record, without listing the kept record twice.
- Email counters (`email_count`, `inbound_email_count`, `outbound_email_count`, `meeting_count`) are
  summed, minus the emails and meetings both records were linked to, so a shared email counts once.
  `last_email_at`, `last_interaction_at` and `last_meeting_at` take the later value. (Builder's call,
  made in review: `RecordCommunicationMetrics` only increments, and no recount exists.) The
  duplicate's counters are zeroed in the same update, so restoring it cannot bring back numbers its
  moved links no longer back. A manual link that collides with the kept record's auto link promotes
  the kept link to manual before the dedup drops the duplicate's row.
- Repointed `people` and `opportunities` rows take `updated_at = now()`, so an API consumer syncing
  by `updated_at` sees the move. The kept record's own `record_merged` entry stays the audit trail;
  no per-row relation-change entries are written (builder's call, made in review).
- A record-type attribute on the kept record that pointed at the duplicate now points at the kept
  record itself, so a company can end up as its own parent. Kept on purpose: dropping the link would
  silently lose that the relationship existed (decided in review of `76aa81c75`).

**Company only**
- `people.company_id` and `opportunities.company_id`.
- `email_participants.company_id` and `meeting_attendees.company_id`.
- The logo stays the kept record's. When the kept record has none, the duplicate's moves across.

**Person only**
- `opportunities.contact_id`.
- `email_participants.contact_id` and `meeting_attendees.contact_id`.

Emails, meetings, their participants and attendees, and the communication counters belong to
`packages/EmailIntegration`. Core dispatches `App\Events\RecordMerged` inside the transaction. The
package's listener, `MoveMergedRecordCommunicationListener`, makes one call to the package action
`MoveMergedRecordCommunication`, which moves its own rows and sums the counters through
`RecordCommunicationMetrics`. (Refined while planning: core never names the package.)

**Then** the duplicate is soft-deleted through the model, so its own `deleted` activity row is written.
The duplicate's own custom attribute values are left on it.

## Authorization

The member needs `update` on the kept record and `delete` on the duplicate (`CompanyPolicy`,
`PeoplePolicy`). Both records must be in the same workspace, different from each other, and live. The
action checks all of this itself, as every action does. The menu item is hidden when the member lacks
either capability in the workspace.

## Known effects

- **Restoring the duplicate** brings back an empty shell: its links already moved. Its emails or
  domains now belong to the kept record, so restore hits the existing `RestoreConflictMessage`, which
  is the right outcome.
- **Force-deleting the duplicate later** is safe. `people.company_id` and `opportunities.company_id`
  are set null on a hard delete (`ON DELETE SET NULL`), and nothing points at the duplicate any
  more anyway.
- **Old Rela conversations and chat proposals** that mention the duplicate keep its ID. They show it
  as deleted, as they would after any delete.
- **Email relink.** Changing the kept record's emails or domains already queues
  `RelinkRecordHistoryJob`. It only links participants that are unlinked, which is why the merge moves
  the linked ones itself.
- **Saved views and filters** that name the duplicate by ID (for example "Company is any of ...")
  stop matching it. They are not rewritten. (Call made in review: rewriting `saved_views` and
  `table_preferences` JSON is its own change.)
- **Favicon.** `CompanyObserver::saved` refetches the logo only when the first domain changed. With
  the kept record's domains first, it changes only when the kept record had none.

## Tests

Feature tests through the record page (Livewire `ViewCompany` and `ViewPeople`), for each entity:
- values: kept wins, gaps filled from the duplicate, lists combined in order, and an empty value on
  the duplicate never overwrites a filled one on the kept record
- every link listed above moves, and a link both records share ends up once
- history moves and a `record_merged` event is written, and the timeline names who merged
- record-type attributes on a third record are repointed
- authorization: a member without `update` on the kept record or `delete` on the duplicate is
  refused, and a record from another workspace cannot be picked
- the duplicate is soft-deleted and the kept record keeps its ID
- the search finds a record by name, domain or email and leaves out the current, trashed and
  other-workspace records

Communication tests (`MergedRecordCommunicationTest`) cover emails, meetings, participants and
attendees, the summed counters with a shared email or meeting counted once, and the merge with the
email feature off.

Rollback and race tests, per entity:
- a failed final write rolls back every moved link and the history
- both records are locked with one select in id order, pinned with `DB::listen`, whichever one is kept
- a duplicate or kept record that vanishes at the start of the transaction gives the "no longer
  available" error and changes nothing

`MergeDuplicatePromptTest` covers the duplicate prompt: a shared domain and a shared email in another
case, every record that matches any value listed and counted, the dialog opened with the row's record
picked, an id outside the list falling back to the first duplicate, no prompt for an unshared value, a
trashed record, another workspace or a member who cannot merge, the prompt keeping the duplicate left
after a merge, and a dismiss that hides it for that record only.

`MergeRecordDialogStateTest` covers the dialog's "no longer available" message: absent before a pick, shown when the picked record is deleted while the dialog is open.

Three browser test files, run in Chromium and in WebKit (`--browser safari`):
- `MergeRecordDialogTest`: the main flows. Open, search, pick, swap, merge, land on the kept record,
  the over-limit block, and the merge opened from the duplicate prompt
- `MergeRecordDialogKeyboardTest`: Esc, arrows, Enter, focus after a pick and tabbing out of the list
- `MergeRecordDialogLayoutTest`: the row layout on laptop and desktop, the stacked phone layout, and
  the popover's placement and height cap, including while the phone sheet is still rising

The picker joins the `MenusTest` dataset, so its rows, icons and height cap follow the menu tokens.

## Non-goals

- Merging more than two records at once
- A duplicates review page (the record-page prompt is Phase 2)
- Merging two duplicate opportunities, tasks or notes into one. The notes, tasks and opportunities
  linked to a merged company or person do move, as listed under What moves
- Merging from Rela, the REST API or the MCP server
- Undoing a merge
- Per-field choice of which value wins

## Left to the builder

- How the dialog is built in Filament. Built as a `MergeRecordPicker` form field inside the action's
  schema, laid out as above
- Names and placement of the shared steps inside `app/Actions/Merge/`. Built as `MergeRecords`,
  `CombineRecordValues`, `MoveRecordLinks`, `MoveRecordHistory` and `MoveMorphPivot`
- How the search ranks results. Built as name order, 25 results
- The `record_merged` icon and copy, following `AppEventPalette`. Built as `lucide-merge`, with the
  title and description in `AppEventPalette::title()` and `description()`
