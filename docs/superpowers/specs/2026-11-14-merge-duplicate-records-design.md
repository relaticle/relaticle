# Merge duplicate companies and people

**Date:** 2026-11-14
**Status:** Phase 1 built (PR #1193), Phase 2 built. Reworked 2026-11-17: the merge creates a new record (decision 5), built
**Issue:** #885

## Summary

A member merges two duplicate companies, or two duplicate people, into one record. They open
**Merge record** from the record page's ⋯ menu, pick the duplicate, and confirm. The merge creates
a new record from the two. The priority record's values win, the other record's values fill the
gaps, both lists of emails or domains are combined, everything linked to either record moves to the
new one, and both originals are soft-deleted.

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
| 5 | Which record survives | Neither. The merge creates a new record and soft-deletes both originals (changed 2026-11-17 on the repo owner's call). The cost is accepted: every outside reference to either ID (bookmarks, API and MCP clients, Rela mentions, saved views by ID) now points at a deleted record |
| 6 | Duplicate prompt | Phase 2, a separate PR: a record-page section listing the duplicates, each with a Merge that opens the dialog with it picked. A duplicates review page is out of scope |
| 7 | Rela, API, MCP | Out of scope. Panel only |
| 8 | Build shape | Approach A below |
| 9 | More than 5 domains or emails | Block the merge. The dialog names the list over the limit (`getMaxValues()`, 5) so the member trims it first. Dropping extras would let mailbox sync recreate the duplicate |
| 10 | Sample data | The new record takes the priority record's `creation_source`. When that is `CreationSource::SAMPLE` and the other record's is not, it takes the other record's, so `RemoveSampleData` never deletes what was merged |
| 11 | The new record's creator and dates | `creator_id` and `creation_source` come from the same original: the priority record, or the other one in decision 10's sample case. A mailbox-synced record stays automated. `created_at` is the merge time |
| 12 | Timelines | History stays where it happened: each original keeps its own (changed 2026-11-18). The new record has no `created` row. The new record continues the priority record: each merge adds one entry naming the other record, "<member> merged <name> into this record", and the priority record's earlier merge entries carry over. The other record's merge entries stay on it. No value changes are logged. Each original gets a `record_merged` entry naming the record it went into |
| 13 | Old URLs of the originals | No redirect. An old URL opens the soft-deleted original, as after any delete. A `merged_into_id` pointer with a redirect on the record page was weighed and left out for now: it needs a migration |

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
   The section is `App\Livewire\App\Records\DuplicateRecordsPrompt`, deferred with `lazy: on-load` in
   the record rail under the quick actions, so the page never waits on the scan and its own requests
   never repeat it. At 100,000 companies the scan took about 240 ms a request locally. While it runs, a
   "Checking for duplicates" row shows, and with none found the slot takes no space. The section is a
   native `Section` and its rows a small view, because an action inside a `RepeatableEntry` item cannot
   be mounted. A row's **Merge** dispatches `merge-duplicate` to the page, which mounts its own
   `MergeRecordAction` named `mergeDuplicate`, since that action needs the record page. The row's id goes
   straight into the dialog: the picker and the action resolve it in the workspace like any picked record,
   so a second scan to check it would add nothing (decided in review). Both merge entry points read
   `MergeRecordAction::canMerge()`. The section has no dismiss: a dismiss kept per record hid the
   prompt on one record of a pair and not the other, which read as a bug.

## Approaches

**A. One action per entity, sharing the common steps (chosen first, then folded).** The thin
`MergeCompanies` and `MergePeople` wrappers were built, then folded into `MergeRecords` in review:
the two closures each wrapper passed were its only content, so `MergeRecords` now owns the whole
merge and dispatches the per-entity differences through exhaustive `match` expressions that PHPStan
fails the moment a third entity joins the `Company|People` union. `MergeRecords` owns the
authorization checks, the transaction, the lock on both rows, the limit check, the sample-data rule,
creating the new record, the link moves, the `RecordMerged` events, both soft deletes and the merge entries. It composes
`CombineRecordValues`, `MoveRecordLinks` and `LogRecordMerge`, and the pivot-moving steps share
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
- ⇄ swaps the two. Whichever record is on the right has priority.
- Each source card shows the name, the identifying list (domains or emails) and counts of linked
  records. The result card shows the combined values and counts, and updates live as the pick or the
  swap changes.
- **Merge records** stays disabled until a second record is picked, and while a combined list is over
  its limit. An alert names the list over the limit.
- The result card previews the new record.
- After the merge, the member is always redirected to the new record's page with a "Records merged"
  notification, because the page they opened it from is now a deleted record.

## The merge rule

`priority` is the record on the right, `other` the one on the left. Both are originals, and the
merge writes their combined values to a new record.

**Single values** (name, account owner, a person's company, every single-value custom attribute):
`priority`'s value when it holds one, otherwise `other`'s. "Holds one" means not null, not an empty
string and not an empty list.

**Lists that identify the record** (company `domains`, people `emails`): `priority`'s values first,
then `other`'s, with duplicates removed after the field's own normalization. Order matters:
`FetchFaviconForCompany` takes the first domain, so `priority`'s logo source stays the first.

**Other multi-value custom attributes** (multi-select, checkbox list, tags, and any field whose
`allow_multiple` setting is on): combined the same way, `priority`'s first. A field that holds a list
but allows one value, such as a person's phone, follows the single-value rule. The limit is the
field's own `max_values` (5 for domains and emails), and record-type fields have none.

**Record-type values that name an original** are rewritten to the new record. When one original
pointed at the other, the new record points at itself. Kept on purpose: dropping the link would
silently lose that the relationship existed.

The preview and the merge read this rule from one place, so the card cannot promise what the merge
does not do.

## The merge, in order

All of it runs in one database transaction.

1. Lock both originals with one select in id order (`lockForUpdate`), so two members merging the
   same records, in either direction, wait instead of interleaving or deadlocking.
2. Insert the new record with its name, workspace, `creator_id` and `creation_source` (decisions 10
   and 11), with model events muted. It comes before the values are combined, because a record-type
   value that names an original is rewritten to the new record's id. That writes no `created` activity row, runs no
   `CompanyObserver::created` or `PeopleObserver::created`, and queues no favicon fetch before the
   record has domains. `ScheduleAssistantArrivalTurn` would otherwise start an onboarding turn for a
   record whose source is `mcp`.
3. Combine the values and check the limits.
4. Move everything listed under What moves, from `priority` first and then from `other`. The order
   decides ties: a link both originals hold keeps `priority`'s row, and `priority`'s logo moves.
   `RecordMerged` fires once per original.
5. Soft-delete both originals through the model, so each writes its own `deleted` activity row.
   Their own custom attribute values and history stay on them.
6. Write the combined values to the new record through `UpdateCompany` or `UpdatePeople`, with
   activity logging off, so the timeline gets no `updated` or `custom_field_changes` row.
7. `LogRecordMerge` moves the priority record's own merge entries (those with `merged_name`) to the
   new record, then writes three `record_merged` entries, each in a batch of its own. The new
   record's stores the other record's name (`merged_name`) and reads "<member> merged <name> into
   this record". Each original's stores the new name
   (`merged_into_name`) and reads "<member> merged this record into <name>".

## What moves

Each step runs once per original, into the new record.

**Both entities**
- Note, task, email and meeting links (`noteables`, `taskables`, `emailables`, `meetingables`).
  A link the new record already holds from the first original is dropped instead of moved, because
  each table is unique on (item, type, id).
- Activity history does not move. A moved edit would read as the new record's own ("Name: Acme →
  Acme Corp" on a record named Acme), the two pasts would interleave, and a restored original would
  come back without its history.
- `attachments` media the combined rich-text values actually reference, so a copied value keeps its
  images. `UploadClaims` rejects a rich-text value that references media owned by another record,
  so the media moves before the values are written. An image whose text lost the field stays on its
  soft-deleted original.
- Record-type custom attributes on other records that point at an original are rewritten to point
  at the new record, without listing it twice when a record pointed at both originals.
- Email counters (`email_count`, `inbound_email_count`, `outbound_email_count`, `meeting_count`):
  the new record starts at zero and takes each original's counts in turn, minus the emails and
  meetings already linked from the first, so a shared email counts once.
  `RecordCommunicationMetrics::combineMergedRecords()` reloads the new record before it sums,
  because the first original's counts are already in its row. `last_email_at`,
  `last_interaction_at` and `last_meeting_at` take the later value. Each original's counters are
  zeroed in the same update, so restoring it cannot bring back numbers its moved links no longer
  back. A manual link that collides with an auto link already moved promotes it to manual before
  the dedup drops the second row.
- Repointed `people` and `opportunities` rows take `updated_at = now()`, so an API consumer syncing
  by `updated_at` sees the move. The `record_merged` entry is the audit trail; no per-row
  relation-change entries are written. Records whose record-type attribute is repointed keep their
  `updated_at`: the rewrite touches only `custom_field_values`.

**Company only**
- `people.company_id` and `opportunities.company_id`.
- `email_participants.company_id` and `meeting_attendees.company_id`.
- The logo: `priority`'s moves. When it has none, `other`'s moves.

**Person only**
- `opportunities.contact_id`.
- `email_participants.contact_id` and `meeting_attendees.contact_id`.

Emails, meetings, their participants and attendees, and the communication counters belong to
`packages/EmailIntegration`. Core dispatches `App\Events\RecordMerged` inside the transaction, once
per original, carrying the new record and that original. The package's listener,
`MoveMergedRecordCommunicationListener`, makes one call to the package action
`MoveMergedRecordCommunication`, which moves its own rows and sums the counters through
`RecordCommunicationMetrics`. Core never names the package.

## Authorization

The member needs `create` on the entity, `update` on the priority record and `delete` on both
originals (`CompanyPolicy`, `PeoplePolicy`). `update` is checked up front because the values are
written through `UpdateCompany` or `UpdatePeople`, which require it. Both originals must be in the same workspace, different from each other, and live.
The action checks all of this itself, as every action does. `MergeRecordAction::canMerge()` hides
the menu item and the duplicate prompt from a member who lacks `create` on the entity, or `update` or
`delete` on the current record, and on a deleted record's page, where the prompt would otherwise offer to merge
an original into the record it already went into.

## Known effects

- **Old URLs** of either original open its soft-deleted page, which no longer shows the moved links
  (decision 13). Its timeline still shows its own history and ends with "merged this record into
  <name>", so the reader can find the new record. Notification links and Rela record chips that
  name an original land there too.
- **The REST API, the MCP server and Rela** treat both original IDs as deleted. A pending Rela
  proposal that names an original fails at approval, as after any delete.
- **Saved views and filters** that name an original by ID (for example "Company is any of ...")
  stop matching it. They are not rewritten. Rewriting `saved_views` and `table_preferences` JSON is
  its own change.
- **Sort by created date.** The new record's `created_at` is the merge time, so it sorts as the
  newest record, and the duplicate prompt, which lists oldest first, lists it last.
- **Restoring an original** brings back its values and history, but none of its links: they moved. Its emails or
  domains now belong to the new record, so restore hits the existing `RestoreConflictMessage`, which
  is the right outcome.
- **Force-deleting an original later** is safe. `people.company_id` and `opportunities.company_id`
  are set null on a hard delete (`ON DELETE SET NULL`), and nothing points at it any more anyway.
- **Email relink.** Changing a record's emails or domains already queues `RelinkRecordHistoryJob`.
  It only links participants that are unlinked, which is why the merge moves the linked ones itself.
- **Favicon.** `CompanyObserver::saved` runs when the values are written. It refetches only when the
  moved logo was not fetched from the new record's first domain, which is `priority`'s first domain
  when it has one.

## Tests

Feature tests through the record page (Livewire `ViewCompany` and `ViewPeople`), for each entity:
- the merge creates a new record, both originals are soft-deleted, and the member is redirected to
  the new record
- the new record takes `priority`'s `creator_id` and `creation_source`, and `other`'s source when
  `priority` is sample data and `other` is not
- values: `priority` wins, gaps filled from `other`, lists combined in order, an empty value on
  `other` never overwrites a filled one, and a record-type value naming an original names the new
  record
- every link listed above moves from both originals, and a link both originals share ends up once
- each original keeps its own history and gets a `record_merged` entry naming the new record; the
  new record's timeline names the other record, keeps the priority record's earlier merges, and
  leaves the other record's merges on it
- no merge and no duplicate prompt on a deleted record's page
- record-type attributes on a third record that point at either original are repointed
- no onboarding turn is scheduled when `priority`'s source is `mcp`
- authorization: a member without `create` on the entity or `delete` on either original is refused,
  and a record from another workspace cannot be picked
- the search finds a record by name, domain or email and leaves out the current, trashed and
  other-workspace records

Communication tests (`MergedRecordCommunicationTest`) cover emails, meetings, participants and
attendees moving from both originals, the counters summed into the new record with a shared email or
meeting counted once, both originals' counters zeroed, and the merge with the email feature off.

Rollback and race tests, per entity:
- a failed final write rolls back the new record and every moved link
- both originals are locked with one select in id order, pinned with `DB::listen`, whichever has
  priority
- an original that vanishes at the start of the transaction gives the "no longer available" error,
  creates nothing and changes nothing

`MergeDuplicatePromptTest` covers the duplicate prompt: a shared domain and a shared email in another
case, every record that matches any value listed and counted, the dialog opened with the row's record
picked, a crafted call naming another workspace's record refused, no prompt for an unshared value, a
trashed record, another workspace or a member who cannot merge, and the prompt on the new record
listing a third duplicate left after a merge.

`MergeRecordDialogStateTest` covers the dialog's "no longer available" message: absent before a pick, shown when the picked record is deleted while the dialog is open.

Three browser test files, run in Chromium and in WebKit (`--browser safari`):
- `MergeRecordDialogTest`: the main flows. Open, search, pick, swap, merge, land on the new record,
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
- Redirecting an original's old URL to the new record (decision 13)
- Per-field choice of which value wins

## Left to the builder

- How the dialog is built in Filament. Built as a `MergeRecordPicker` form field inside the action's
  schema, laid out as above
- Names and placement of the shared steps inside `app/Actions/Merge/`. Built as `MergeRecords`,
  `CombineRecordValues`, `MoveRecordLinks`, `LogRecordMerge` and `MoveMorphPivot`
- How the search ranks results. Built as name order, 25 results
- The `record_merged` icon and copy, following `AppEventPalette`. Built as `lucide-merge`, with the
  title and description in `AppEventPalette::title()` and `description()`
- Renaming the `kept` and `duplicate` parameters to fit the new rule. Built as `priority` and `other`
  for the originals and `merged` for the new record, and `RecordMerged` carries `merged` and
  `original`. The dialog's `keep_picked` field keeps its name: true still means the picked record
  wins
- The dialog copy. The notice reads "Merging creates a new record. The record on the right wins
  where both records hold a value."
- How the preview combines values without a new record. `CombineRecordValues` takes the new record
  as an optional third argument, and the preview leaves it out
