# Email Conversations, Part E: Linking (PR 9) and Sharing and Access (PR 13) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use the `sdd-lean` skill to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A seller can attach a conversation to the right company, person or opportunity, remove a wrong link that never comes back, and see an opportunity's mail from before it existed. A teammate can ask for a whole record's mail in one request, and nobody's past mail becomes more visible because an admin changed a default.

**Architecture:** `LinkEmailToRecord` and `UnlinkEmailFromRecord` act on every copy of a conversation the actor may see. Unlinking writes `email_link_suppressions`, which `LinkEmailAction` and `RelinkRecordHistoryJob` read before any automatic attach. `Email::onRecord()` owns which mail belongs on a record, the opportunity window included. Record tabs and the record-wide approval both read it. PR 13 adds the sharing step after every connect, stops the default from widening past mail, adds record requests on the existing `emailable_*` columns, and logs requests and manual shares to `EMAIL_ACCESS_LOG`.

**Tech stack:** Laravel 12 on PHP 8.5, Filament v5, Livewire v4, Alpine, Pest 5, PostgreSQL 17, laravel/ai in `packages/Chat`, laravel/mcp in `app/Mcp`.

**Spec:** `docs/superpowers/specs/2026-10-31-email-conversations-design.md`, sections "Visibility rules", "Record Emails tabs", "The drawer (D3)", "Sharing and access (D23, D24, D27)", "Linking (D22)", "Agents (Rela and MCP)", "Migrations and deploy", "Testing", "Phases and pull requests" (rows 9 and 13).

**Master plan:** `docs/superpowers/plans/2026-10-31-email-conversations.md`. Its global constraints, loop per task, and cross-PR contracts apply unchanged. This file repeats only what it adds. Reasoning lives in `docs/email/decisions/d-linking-privacy-reach.md` (D22 to D25, D27).

**Depends on:** PR 3 (linked-only teammate rule in `VisibleEmailScope`), PR 5 (`ConversationsQuery`, `ConversationRow`, record tabs on the new row), PR 6 (`<x-email-integration::reader-drawer>`). PR 4 supplies `emails.automated_reason`, which PR 13 Task 5 reads.

---

## Plan notes: conflicts and gaps found while reading

Each note names the conflict and what this plan does. The spec wins where it speaks. A note that changes a cross-PR contract updates the master table in the same PR.

1. **Suppression key.** D22 keys by `(workspace_id, rfc_message_id, record)` (`d-linking-privacy-reach.md:173-175`). The spec (`2026-10-31-email-conversations-design.md:212-226`) and contract (`2026-10-31-email-conversations.md:126`) key by `email_id`. Kept the contract. One row per visible copy is written. Reads match across the conversation: same `conversation_key`, else `rfc_message_id`, else `email_id`. Later replies and other copies stay unlinked.
2. **Who creates the table.** The spec groups it with PR 2's tables (`design.md:683`). The contract gives it to PR 9 (`2026-10-31-email-conversations.md:126`). PR 9 creates it. If PR 2 already did, PR 9 Task 1 keeps only the model.
3. **`copies()` is missing from the contract.** The contract lists `paginate`, `counts`, `messages` (`2026-10-31-email-conversations.md:146`). `messages()` returns one copy per message, but unlink must detach every visible copy. PR 9 adds `ConversationsQuery::copies(User, string): Collection<int, Email>`.
4. **No owner for "mail on a record".** PR 9 adds `Email::onRecord()` as a `#[Scope]`. Record tabs and PR 13's record approval both call it. New contract row.
5. **Opportunity links at sync.** D25 says stop them (`d-linking-privacy-reach.md:549-550`). The spec is silent (`design.md:455-458`), and `LinkEmailAction.php:188-197` still attaches them. This plan keeps sync linking, so `LinkEmailActionTest.php:1410` and the counters stay as they are. The window then applies to every non-manual link, and only pins (`link_source = manual`) bypass it. The 135 existing auto links do not all read as pins, as D25 wanted.
6. **No "closed" fact.** D25 ends the window 30 days after close (`d-linking-privacy-reach.md:551-552`). The stage options are editable (`app/Enums/CustomFields/OpportunityField.php:32-33`), and the only date is `close_date` (`OpportunityField.php:14,43`). The window ends at `close_date + 30 days` only when `close_date` is past, else it stays open. A stored `closed_at` is a follow-up.
7. **Widening fix listed in two PRs.** The phase table gives "raising the default never widens past mail" to PR 3 (`design.md:744`). The Sharing section and this brief give it to PR 13 (`design.md:582-585`). PR 13 Task 4 checks `main` first and does only what PR 3 left.
8. **Lowering can widen today.** `executeForWorkspace` rewrites every row whose tier differs (`ApplyDefaultSharingTierToExistingEmailsAction.php:25-27`). Take a default of Private, raised to Subject, then lowered to Participants only: the lowering would widen the Private past mail. Task 4 makes that path narrow only.
9. **No note column.** The spec wants an optional note (`design.md:587-588`). `email_access_requests` has none (`2026_03_21_054912_create_email_access_requests_table.php:13-34`). PR 13 adds `note string(500)` nullable. New contract row.
10. **Record requests skip the pending index.** `email_access_requests_one_pending_per_requester` covers `(email_id, requester_id)` (`2026_10_22_000001_add_lifecycle_columns_to_email_access_requests_table.php:49`), and null `email_id` values are distinct. PR 13 adds a partial unique index for record requests.
11. **"Past and future mail".** D24's approval names future mail (`d-linking-privacy-reach.md:463-464`). The spec does not (`design.md:589-592`), and future mail needs a standing grant in `VisibleEmailScope` and `PrivacyService`. PR 13 shares the owner's current mail on the record. Future mail is a follow-up.
12. **`sharing_confirmed_at` is missing from the contracts.** D27 requires it (`d-linking-privacy-reach.md:764-768`). PR 13 adds it on `connected_accounts`, rehearsed. New contract row.
13. **`SurfaceParityTest` is CRM-only.** The spec relies on it for email MCP parity (`design.md:663-664`), but it iterates `CrmEntity` (`tests/Feature/CRM/SurfaceParityTest.php:314`). PR 9 adds one email case.
14. **Request tool input.** The spec says `email_ids[]` and `note` (`design.md:660-662`). The chat rules and `BaseWriteCreateTool` require `records[]`. This plan uses `records[]` items of `{email_id, tier_requested, note}`.
15. **Source line in the reader.** The spec puts "Visible through Ben's mailbox" in the drawer (`design.md:473`), which is PR 6. The brief gives it to PR 13. PR 13 Task 8 adds it if PR 6 did not, and pins it with a test either way.
16. **Connect card overlaps PR 1.** PR 1 moves the card (`design.md:742`). PR 13 adds the card's states and does not move it.
17. **Routing to every holder.** D24 wants it (`d-linking-privacy-reach.md:445-446`). The spec does not include it. Requests still go to the copy's owner (`RequestEmailAccessAction.php:52-63`). Follow-up.
18. **Public-domain senders.** D22 hides them in Unlinked (`d-linking-privacy-reach.md:196-197`). The spec does not (`design.md:343,601-602`). Kept visible, sorted by mail the viewer sent them.
19. **Stale citation.** D24 cites `DecideEmailAccessRequest.php:116-127`. The logging is at `:55-66`.

## Global constraints (additions to the master list)

- New actions: `LinkEmailToRecord`, `UnlinkEmailFromRecord`, `LinkAssistantEmail`, `UnlinkAssistantEmail`, `AddEmailAddressToPerson`, `RequestRecordEmailAccess`, `RequestAssistantEmailAccess`. Each is `final readonly`, with one public `execute()` and no `Action` suffix. `tests/Arch/ConventionsTest.php` enforces both (suffix list near line 285, single-execute list near line 223). Do not add to either grandfather list.
- The record-picker options query is always filtered by the bound workspace id, as `MeetingDetailInfolist::linkTargetOptions()` does (`packages/EmailIntegration/src/Filament/Infolists/MeetingDetailInfolist.php:309-319`). Unlike that method, which plucks every row, the email picker searches with `getSearchResultsUsing` and a limit of 20.
- Every new chat tool gets a `ProposalEntity` case. Every `match` in `packages/Chat/src/Enums/ProposalEntity.php` lists each case with no `default`. The tool also needs a `toolLabels` entry in `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php` (near line 120) and its adapter action in `PendingActionService::ALLOWED_ACTION_CLASSES` (`packages/Chat/src/Services/PendingActionService.php:79`).
- Every new MCP tool is registered in `app/Mcp/Servers/RelaticleServer.php` (tools array, near line 127). The tool count in `packages/Documentation/resources/content/docs/guides/mcp.md` rises to match, and `ConventionsTest` ("keeps the documented MCP tool count true", line 801) fails otherwise.

## Read first

- `packages/EmailIntegration/src/Actions/LinkEmailAction.php`: the auto-link path, its row lock (70-96), the opportunity attach (188-197), and `autoAttach()` (272-281), which every suppression check goes through.
- `packages/EmailIntegration/src/Actions/LinkMeetingToRecordAction.php` and `UnlinkMeetingFromRecordAction.php`: the pair the new email actions clone (authorize, same workspace, manual pivot, metrics).
- `packages/EmailIntegration/src/Jobs/RelinkRecordHistoryJob.php`: the history fill (62-67) and its null-only participant matcher (92-115).
- `packages/EmailIntegration/src/Actions/ApplyDefaultSharingTierToExistingEmailsAction.php` and `SaveWorkspaceEmailSharingDefaultAction.php`: the silent widening (15-28, 21-32).
- `packages/EmailIntegration/src/Actions/RequestEmailAccessAction.php`, `ApproveEmailAccessRequestAction.php`, `DecideEmailAccessRequest.php`: the request lifecycle that record requests extend.

---

# PR 9: Linking

**Branch:** `feat/email-linking`, from `feat/email-drawer` (PR 6) until it merges, then rebased on `main`.

**Goal:** Link and unlink chips in the drawer, a manual link that survives everything, an unlink that no sync, relink, or history import undoes, an Unlinked senders view that creates or extends a person, an opportunity tab that shows the mail of its company and point of contact inside the opportunity's window, and the same link and unlink for Rela and the MCP server.

## File map

| File | Change | Owns |
|---|---|---|
| `database/migrations/2026_11_xx_000000_create_email_link_suppressions_table.php` | create | the table, shape per contract |
| `packages/EmailIntegration/src/Models/EmailLinkSuppression.php` | create | model, `#[Fillable]`, `#[Scope] matchingConversationOf(Email $email)` |
| `packages/EmailIntegration/src/Models/Email.php` | modify | `#[Scope] onRecord(Company\|People\|Opportunity $record)`, `linkSuppressions()` relation |
| `packages/EmailIntegration/src/Actions/LinkEmailToRecord.php` | create | manual link over every visible copy |
| `packages/EmailIntegration/src/Actions/UnlinkEmailFromRecord.php` | create | unlink plus suppression over every visible copy |
| `packages/EmailIntegration/src/Actions/LinkEmailAction.php` | modify | `autoAttach()` skips suppressed pairs |
| `packages/EmailIntegration/src/Services/RecordCommunicationMetrics.php` | modify | `recomputeEmailMetrics()` |
| `packages/EmailIntegration/src/Jobs/RecomputeRecordEmailMetricsJob.php` | create | queued recount after unlink |
| `packages/EmailIntegration/src/Queries/ConversationsQuery.php` | modify | `copies()`, record filter through `Email::onRecord()` |
| `packages/EmailIntegration/src/Queries/UnlinkedSendersQuery.php` | create | sender groups for the Unlinked view |
| `packages/EmailIntegration/src/Data/UnlinkedSender.php` | create | plain `final readonly` row |
| `app/Actions/People/AddEmailAddressToPerson.php` | create | appends an address through `UpdatePeople` |
| `packages/EmailIntegration/src/Filament/Concerns/HasEmailReaderActions.php` | modify | `linkRecordAction()`, `unlinkRecordAction()` |
| `packages/EmailIntegration/resources/views/components/reader-drawer.blade.php` | modify | chips with menu, "+ Link record" |
| `packages/EmailIntegration/resources/views/components/unlinked-senders.blade.php` | create | grouped Unlinked view |
| `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php` | modify | Unlinked view renders sender groups, Create person and Link to existing |
| `packages/EmailIntegration/src/Actions/LinkAssistantEmail.php`, `UnlinkAssistantEmail.php` | create | chat adapters, `execute(User, array): Email` |
| `packages/Chat/src/Tools/Email/LinkEmailsTool.php`, `UnlinkEmailsTool.php` | create | chat write tools, `records[]` |
| `packages/Chat/src/Enums/ProposalEntity.php` | modify | `EmailLink = 'email_links'`, `EmailUnlink = 'email_unlinks'` |
| `packages/Chat/src/Services/PendingActionService.php` | modify | allow the two adapters |
| `packages/Chat/src/Agents/CrmAssistant.php` | modify | register the two tools |
| `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php` | modify | `link_emails`, `unlink_emails` labels |
| `app/Mcp/Tools/Email/LinkEmailsTool.php`, `UnlinkEmailsTool.php` | create | MCP twins |
| `app/Mcp/Servers/RelaticleServer.php` | modify | register both |
| `packages/Documentation/resources/content/docs/guides/mcp.md` | modify | tool count and two reference rows |
| `docs/superpowers/plans/2026-10-31-email-conversations.md` | modify | contract rows for `copies()`, `Email::onRecord()` |
| tests (under `tests/`) | modify or create | `Feature/EmailIntegration/LinkEmailActionTest.php`, `Feature/EmailIntegration/RelinkRecordHistoryJobTest.php`, `Feature/EmailIntegration/EmailLinkingTest.php`, `Feature/EmailIntegration/RecordEmailsConversationsTest.php`, `Feature/EmailIntegration/EmailsRelationManagerReadingTest.php`, `Feature/Chat/EmailToolsTest.php`, `Feature/Mcp/EmailToolsTest.php`, `Feature/CRM/SurfaceParityTest.php`, `Feature/EmailIntegration/ConversationsPageTest.php` (PR 5), `Browser/EmailIntegration/EmailLinkingBrowserTest.php` |

## Tasks

### Task 1: Suppression table and model

**Files:** the migration, `EmailLinkSuppression.php`, `Email.php`.

- [ ] Create the migration with `php artisan make:migration create_email_link_suppressions_table --no-interaction`. The `up()` builds: `ulid('id')->primary()`, `foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete()`, `foreignUlid('email_id')->constrained('emails')->cascadeOnDelete()`, `ulidMorphs('emailable')` (matches `emailables`, `2026_03_21_054333_create_emailables.php:16`), `foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete()`, `timestamp('created_at')`. Unique `(email_id, emailable_type, emailable_id)`, index `(workspace_id, emailable_type, emailable_id)`. No `down()`. `user_id` is nullable so deleting a user keeps the unlink. Note that in the master row.
- [ ] Create `EmailLinkSuppression` with `#[Fillable([...])]`, `HasUlids`, `const UPDATED_AT = null`, and `email()`, `emailable()` relations.
- [ ] Add `#[Scope] matchingConversationOf(Builder $query, Email $email)`: `whereIn('email_id', ...)` over emails in `$email->workspace_id` with the same `conversation_key`, or the same `rfc_message_id`, or the same `id`. Skip a branch whose value is null. No table alias.
- [ ] Add `Email::linkSuppressions(): HasMany`.

**Check:** `php artisan migrate` on the dev database, then `php artisan test --compact tests/Arch`.

### Task 2: `Email::onRecord()` and `ConversationsQuery::copies()`

**Files:** `Email.php`, `ConversationsQuery.php`.

- [ ] Add `#[Scope] protected function onRecord(Builder $query, Company|People|Opportunity $record): Builder`. For a company or person it is `whereHas` on `companies()` or `people()` with `whereKey($record->getKey())`, plus `whereDoesntHave('linkSuppressions', ...)` for that record. The opportunity branch arrives in Task 6. Until then it reads stored links exactly as today, so the tab does not change in this task.
- [ ] In `ConversationsQuery`, route the `record_type` + `record_id` filter through `Email::onRecord()`. Do not keep the PR 5 filter alongside it. The record is loaded with `withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $viewer->current_workspace_id)`, the `ListsEntity` pattern, so a foreign id matches nothing.
- [ ] Add `copies(User $viewer, string $conversationKey): Collection<int, Email>`. It returns `Email::query()->withGlobalScope('visible', new VisibleEmailScope($viewer))->where('workspace_id', $viewer->current_workspace_id)->where('conversation_key', $conversationKey)->get()`, with no preferred-copy reduction. It must honour PR 2's null-key shim the same way `messages()` does.

**Check:** the PR 5 record-tab tests still pass: `php artisan test --compact tests/Feature/EmailIntegration/RecordEmailsConversationsTest.php`.

### Task 3: Regression tests for suppressions, then honour them in auto-link and relink

Write the tests first. They fail until `autoAttach()` changes.

**Files:** `LinkEmailActionTest.php`, `RelinkRecordHistoryJobTest.php`, `LinkEmailAction.php`.

- [ ] Add to `tests/Feature/EmailIntegration/LinkEmailActionTest.php` (it already uses `makeLinkEmail()` and `makeAcmeCompanyWithDomain()`, lines 1458-1471). Add `UnlinkEmailFromRecord::class` to its `mutates(...)`.

```php
it('does not link a company again after a user unlinked it, on sync or history import', function (): void {
    $company = makeAcmeCompanyWithDomain() ?? $this->markTestSkipped('No domains custom field seeded for this workspace.');
    $email = makeLinkEmail();
    EmailParticipant::factory()->from()->create(['email_id' => $email->getKey(), 'email_address' => 'ceo@acme.com']);
    resolve(LinkEmailAction::class)->execute($email);

    resolve(UnlinkEmailFromRecord::class)->execute($this->user, $email, $company);
    resolve(LinkEmailAction::class)->reapply($email->fresh());

    expect($email->companies()->whereKey($company->getKey())->exists())->toBeFalse()
        ->and(EmailLinkSuppression::query()->where('email_id', $email->getKey())->count())->toBe(1);
});
```

Add a second test beside it: "does not link a later reply in the same conversation to the record a user unlinked". It stores a first email and a reply with the same `conversation_key`, unlinks the first, runs `execute()` on the reply, and expects no company link. To give both the same key, extend `makeLinkEmail()` with an `array $attributes = []` parameter spread into the factory call.

- [ ] Add to `tests/Feature/EmailIntegration/RelinkRecordHistoryJobTest.php`. This case makes the relink job attach the company through the new person (`LinkEmailAction.php:179-186`). A vacuous test that relies on null participant ids would pass without the fix. Add `UnlinkEmailFromRecord::class` to `mutates(...)`.

```php
it('keeps an unlinked company off the mail when a new person of that company relinks it', function (): void {
    $email = ($this->linkedEmailFrom)('ana@airbnb.com');
    $company = resolve(CreateCompany::class)->execute($this->user, ['name' => 'Airbnb']);
    resolve(LinkEmailToRecord::class)->execute($this->user, $email, $company);
    resolve(UnlinkEmailFromRecord::class)->execute($this->user, $email, $company);

    resolve(CreatePeople::class)->execute($this->user, [
        'name' => 'Ana Ruiz',
        'company_id' => $company->getKey(),
        'custom_fields' => ['emails' => ['ana@airbnb.com']],
    ]);

    expect($email->people()->count())->toBe(1)
        ->and($email->companies()->whereKey($company->getKey())->exists())->toBeFalse();
});
```

- [ ] Run both files and see the new tests fail on the missing classes.
- [ ] In `LinkEmailAction::link()` (`LinkEmailAction.php:98-206`), load the suppressed pairs once per call: `EmailLinkSuppression::query()->matchingConversationOf($email)->get(['emailable_type', 'emailable_id'])`, keyed as `"{type}:{id}"`. Pass the set to `autoAttach()`, which returns false before `attach()` when the pair is present. Read the morph type from `$relation->getRelated()->getMorphClass()`. Extract a private method named for what it returns (`suppressedPairs()`) so `link()` stays under 60 lines (`MethodLengthRule`). The participant `contact_id` and `company_id` updates stay as they are. They record identity, not links.
- [ ] `RelinkRecordHistoryJob` needs no change. It goes through `reapply()` (`RelinkRecordHistoryJob.php:67`). The test above proves it.

**Check:** both files pass after Task 4 lands the actions. Order the commit so Tasks 3 and 4 land together.

### Task 4: `LinkEmailToRecord` and `UnlinkEmailFromRecord`

**Files:** the two actions, `RecordCommunicationMetrics.php`, `RecomputeRecordEmailMetricsJob.php`, `tests/Feature/EmailIntegration/EmailLinkingTest.php`.

Shape, cloned from the meeting pair (`LinkMeetingToRecordAction.php:20-48`, `UnlinkMeetingFromRecordAction.php:17-33`):

- `LinkEmailToRecord::execute(User $user, Email $email, Model $record): void`
  1. `abort_unless($user->can('view', $email) && $user->can('update', $record), 403)`.
  2. `abort_unless((string) $record->getAttribute('workspace_id') === (string) $email->workspace_id && $user->belongsToWorkspaceId($email->workspace_id), 403)`. Use 403 rather than the meeting pair's `InvalidArgumentException`, so every transport answers a foreign record the same way.
  3. Resolve the relation by `match` over `People`, `Company`, `Opportunity` (as the meeting pair does). Anything else throws `InvalidArgumentException`.
  4. Inside `DB::transaction`: for each copy in `ConversationsQuery::copies($user, $email->conversation_key)`, `syncWithoutDetaching([$id => ['link_source' => 'manual']])`. An existing `auto` row is upgraded to `manual` by `updateExistingPivot`, so a pin survives the opportunity window. Delete the suppressions for this record on those copies.
  5. Collect the copies that were not linked before, and call `RecordCommunicationMetrics::incrementEmailMetrics($record, $copy)` for each, in lock order (`incrementEmailMetricsInLockOrder`, `RecordCommunicationMetrics.php:67-72`).
- `UnlinkEmailFromRecord::execute(User $user, Email $email, Model $record): void`
  1. Same two guards.
  2. Inside `DB::transaction`: for each visible copy, detach the record and `insertOrIgnore` a suppression (`workspace_id`, `email_id`, morph, `user_id`, `created_at => now()`).
  3. After commit, dispatch `RecomputeRecordEmailMetricsJob($record)`. The job is unique per record (`#[UniqueFor(600)]`, `ShouldBeUniqueUntilProcessing`), on `emails-sync`, and runs inside `CurrentWorkspace::within($record->workspace_id, ...)`. D22 asks for a recount, not a decrement, and a decrement would drift on the 156,632-email company.
- `RecordCommunicationMetrics::recomputeEmailMetrics(People|Company|Opportunity $record): void` walks `$record->emails()` with `lazyById(500)`, counts only copies where `countsTowardCommunicationIntelligence()` is true (`EmailVisibilityService.php:288-299`, the same predicate the increment uses), and writes `email_count`, `inbound_email_count`, `outbound_email_count`, `last_email_at` and `last_interaction_at = GREATEST(last_meeting_at, new last_email_at)` in one `update`. Timestamps come from PHP `now()`.

Tests in a new `tests/Feature/EmailIntegration/EmailLinkingTest.php`, `mutates(LinkEmailToRecord::class, UnlinkEmailFromRecord::class, RecordCommunicationMetrics::class)`:

```php
it('refuses an unlink by a teammate who cannot see the email and keeps the link', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $teammate = User::factory()->create(['current_workspace_id' => $workspace->getKey()]);
    $workspace->users()->attach($teammate, ['role' => 'member']);
    $company = Company::factory()->for($workspace)->create();
    $email = linkingEmail($owner, ['privacy_tier' => EmailPrivacyTier::PRIVATE, 'privacy_tier_customized' => true]);
    $email->companies()->attach($company->getKey(), ['link_source' => 'auto']);

    expect(fn () => resolve(UnlinkEmailFromRecord::class)->execute($teammate, $email, $company))
        ->toThrow(HttpException::class);

    expect($email->companies()->whereKey($company->getKey())->exists())->toBeTrue()
        ->and(EmailLinkSuppression::query()->count())->toBe(0);
});

it('refuses to link an email to a record from another workspace', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $foreign = Company::factory()->for(Workspace::factory())->create();
    $email = linkingEmail($owner);

    expect(fn () => resolve(LinkEmailToRecord::class)->execute($owner, $email, $foreign))
        ->toThrow(HttpException::class);

    expect(DB::table('emailables')->where('email_id', $email->getKey())->count())->toBe(0);
});

it('links every copy of the conversation the user can see and pins it', function (): void { /* two copies, same conversation_key, owner + linked teammate copy */ });
it('unlinks every visible copy, so the conversation leaves the record for the user', function (): void { /* assert ConversationsQuery with record filter returns nothing */ });
it('links a record again after an unlink and clears the suppression', function (): void { /* ... */ });
it('counts a newly linked email once and recounts the record after an unlink', function (): void { /* email_count 1 -> 0 after the job runs; Queue::fake not used, the job runs sync in tests */ });
```

`linkingEmail(User $owner, array $attributes = [])` is a file-local helper. It builds a `ConnectedAccount::withoutEvents(...)` mailbox and an `Email::factory()` row with a fixed `conversation_key` and `rfc_message_id`, in the style of `sharingEmail()` (`ApplyDefaultSharingTierToExistingEmailsActionTest.php:44-54`). Fill in each placeholder body before committing. None ships empty.

**Check:** `php artisan test --compact tests/Feature/EmailIntegration --parallel --processes=4 --filter='(EmailLinkingTest|LinkEmailActionTest|RelinkRecordHistoryJobTest)::'`.

### Task 5: Link and unlink chips in the drawer

**Files:** `HasEmailReaderActions.php`, `reader-drawer.blade.php`, `lang/en/filament/pages/email-inbox.php`, `tests/Feature/EmailIntegration/EmailLinkingTest.php`.

- [ ] Add `linkRecordAction(): Action` to `HasEmailReaderActions`, next to `requestAccessAction()` (line 218). Label "Link record", icon `ri-link` (line icon, `ui.md`), `modalWidth(Width::Medium)`. The schema has a `target_type` select (Company, Person, Opportunity, labelled from `CrmEntity`) and a `target_id` select, `searchable()` with `getSearchResultsUsing()` limited to 20, filtered by `workspace_id` and by `CrmEntity::titleColumn()`. The modal description reads "Teammates will see this conversation on the record at your sharing level." That is the linked-only rule from PR 3, and the user should know it. The action resolves the email through `resolveWorkspaceEmail($arguments['emailId'], 'view')` (line 313) and the record through a workspace-filtered `findOrFail`, then calls `LinkEmailToRecord`. It notifies "Linked to :name".
- [ ] Add `unlinkRecordAction(): Action` with `requiresConfirmation()`. Heading: "Remove this conversation from :name?". Description: "It stays in your mail and will not be linked to :name again automatically." It calls `UnlinkEmailFromRecord` and notifies with an Undo button that calls `linkRecordAction` with the same arguments. PR 7's undo toast is the shared pattern. Reuse it if PR 7 merged, otherwise use a Filament notification action.
- [ ] In `reader-drawer.blade.php` header (spec item 1, `design.md:470-472`), render each visible linked record as a chip with a small menu: Open record, Unlink. Then render "+ Link record". Chips read the records the drawer already loads for the row (`ConversationRow::records`), with no extra query per chip. Keyboard `l` (PR 7's `EmailShortcut`) mounts `linkRecordAction` when PR 7 is present.
- [ ] Livewire tests in `EmailLinkingTest.php`, through the Emails page host: mount `linkRecordAction` with `emailId`, fill the form, assert the pivot row is `manual`. Then a forged `target_id` from another workspace: assert `findOrFail` gives a 404 and nothing is written.

**Check:** the file passes, then the UI verification below.

### Task 6: Opportunity window and pins

**Files:** `Email.php` (`onRecord` opportunity branch), `RecordEmailsConversationsTest.php`, `EmailsRelationManagerReadingTest.php`.

- [ ] Add the failing tests first to `tests/Feature/EmailIntegration/RecordEmailsConversationsTest.php`, which PR 5 creates for record tabs on the new row (part C plan). Put the badge assertion in `EmailsRelationManagerReadingTest.php`, next to its badge tests (lines 694-730).

```php
it('leaves company mail older than 30 days before an opportunity was created off its tab', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $company = Company::factory()->for($this->workspace)->create();
    $old = recordTabEmail($this->user, ['sent_at' => now()->subDays(45)]);
    $recent = recordTabEmail($this->user, ['sent_at' => now()->subDays(10)]);
    $old->companies()->attach($company->getKey(), ['link_source' => 'auto']);
    $recent->companies()->attach($company->getKey(), ['link_source' => 'auto']);

    $opportunity = Opportunity::factory()->for($this->workspace)->create(['company_id' => $company->getKey()]);

    livewire(OpportunityEmailsRelationManager::class, ['ownerRecord' => $opportunity, 'pageClass' => ViewOpportunity::class])
        ->assertSeeHtml('data-conversation="'.$recent->conversation_key.'"')
        ->assertDontSeeHtml('data-conversation="'.$old->conversation_key.'"');
});

it('keeps a pinned conversation on the opportunity whatever its date', function (): void { /* manual link at -400 days shows */ });
it('ignores an automatic opportunity link outside the window', function (): void { /* auto opp link at -400 days does not show: guards Plan note 5 */ });
it('ends the window 30 days after a past close date', function (): void { /* close_date -60 days, mail at -20 days hidden, mail at -40 days shown */ });
it('drops a derived conversation after a user removes it from the opportunity', function (): void { /* UnlinkEmailFromRecord on the opportunity writes a suppression; the company link stays */ });
```

Use the real relation manager class names (`grep -rl BaseEmailsRelationManager app/Filament`). `recordTabEmail()` follows the file's existing helpers. If the file has none, add one in the `sharingEmail()` style.

- [ ] Implement the opportunity branch of `onRecord()`. Window start is `$opportunity->created_at->subDays(30)`. Window end is `close_date + 30 days` when `close_date` is in the past, else none (Plan note 6). Read `close_date` with `getCustomFieldValue()` on the `App\Models\CustomField` row whose `code` is `OpportunityField::CLOSE_DATE->value`, as `OpportunitiesBoard.php:96` reads it. The predicate:
  - linked to the opportunity with `link_source = manual`, or
  - linked to the opportunity, its `company_id` company, or its `contact_id` person (any source), and `sent_at` inside the window,
  - and in both cases no suppression for the opportunity in the email's conversation (`whereDoesntHave('linkSuppressions', ...)` on the morph pair. Use `whereNotExists` over `email_link_suppressions` joined on `conversation_key` for the conversation-wide match from Plan note 1).
- [ ] Move the opportunity tab badge to the same predicate. `EmailVisibilityService::visibleEmailCountBadge()` (`EmailVisibilityService.php:153-166`) counts through `visibleEmailCount()`. Point its opportunity branch at `Email::onRecord()` so the badge and the tab agree. Company and person counts do not change.

**Check:** `php artisan test --compact tests/Feature/EmailIntegration --parallel --processes=4 --filter='(RecordEmailsConversationsTest|EmailsRelationManagerReadingTest)::'`.

### Task 7: Unlinked senders view

**Files:** `UnlinkedSendersQuery.php`, `UnlinkedSender.php`, `AddEmailAddressToPerson.php`, `EmailInboxPage.php`, `unlinked-senders.blade.php`, Emails page test file from PR 5.

- [ ] `UnlinkedSendersQuery::paginate(User $viewer, int $perPage = 25, ?string $cursor = null): CursorPaginator<int, UnlinkedSender>`, `final readonly`, in `packages/EmailIntegration/src/Queries`. It reads the viewer's own non-automated emails with no `emailables` row and groups by `lower(from address)`. It returns address, display name (most recent), conversation count, last message at, and `sent_to_count` (viewer's outbound mail to that address). Order: `sent_to_count desc, last_message_at desc, address`. It takes the viewer, binds the workspace explicitly, and writes nothing. The `Queries` folder is already in the PHPStan write ban per the master constraints.
- [ ] `UnlinkedSender` is a plain `final readonly` class built with `new`.
- [ ] `app/Actions/People/AddEmailAddressToPerson::execute(User $user, People $person, string $address): People` canonicalizes with `App\Support\EmailAddress::canonicalize()`. It merges the address into the `emails` custom field value unless already present, and calls `UpdatePeople::execute($user, $person, ['custom_fields' => ['emails' => $merged]])`. `UpdatePeople` authorizes. Saving the field queues `RelinkRecordHistoryJob` (`QueueRecordHistoryRelink.php:26-41`), which fills history and, after Task 3, respects suppressions.
- [ ] On `EmailInboxPage`, the `unlinked` view renders `<x-email-integration::unlinked-senders>`. Each group row shows name, address, "12 conversations", "You wrote 3 times" when above zero, and two actions:
  - **Create person**: a modal prefilled with the name and address, calling `CreatePeople` with `custom_fields.emails`.
  - **Link to existing**: a person picker (same search field as Task 5) calling `AddEmailAddressToPerson`.

  Expanding a group lists its conversations with the normal row. A conversation-level "Link" goes through Task 5's action, for company and opportunity links.
- [ ] Empty state: "Every conversation is on a record." Use the same component as the other empty views.
- [ ] Tests in `tests/Feature/EmailIntegration/ConversationsPageTest.php` (created by PR 5, part C plan):
  - groups two conversations from one sender into one row;
  - leaves a teammate's unlinked mail out (PR 3 rule);
  - Create person links the group's history after the relink job runs;
  - Link to existing adds the address once and does not duplicate it on a second click.

**Check:** the Emails page test file and `RelinkRecordHistoryJobTest.php` pass.

### Task 8: Rela and MCP parity

**Files:** chat adapters and tools, `ProposalEntity.php`, `PendingActionService.php`, `CrmAssistant.php`, `chat-interface.blade.php`, MCP tools, `RelaticleServer.php`, `mcp.md`, the four test files.

- [ ] `LinkAssistantEmail::execute(User $user, array $data): Email`, where `$data` is `array{email_id: string, record_type: string, record_id: string}`. It resolves the email with `VisibleEmailsQuery::find($user, $data['email_id'])`, failing with a `ValidationException` on `email_id` when it is not found. It resolves `CrmEntity::from($data['record_type'])`, refusing task and note, then loads the record with the workspace filter, then calls `LinkEmailToRecord`. `UnlinkAssistantEmail` mirrors it. The shape copies `CancelAssistantEmail.php:21-29`.
- [ ] `LinkEmailsTool` and `UnlinkEmailsTool` extend `BaseWriteCreateTool`, with `records[]` items `{email_id, record_type, record_id}` and `ownedForeignKeys()` returning `[]`, because the target is polymorphic. `validateRecord()` checks the email is visible and the record exists in the workspace. The display rows show Subject (or "Subject hidden" below `viewSubject`) and the record name. No `$ref` support: a polymorphic key cannot be listed in `ownedForeignKeys()`, so Rela links a record it proposed in the same turn only after approval. Add the `ProposalEntity` cases with their title keys, verbs and core fields in every `match`. Neither needs its own approval, because nothing leaves the workspace.
- [ ] Register both on `CrmAssistant`. Add `'link_emails' => __('Linking emails…')` and `'unlink_emails' => __('Removing email links…')` to `toolLabels`. The ellipsis character there is the existing house form, not an em-dash.
- [ ] MCP `App\Mcp\Tools\Email\LinkEmailsTool` and `UnlinkEmailsTool`. Input is `links[]` with the same three keys. They call `LinkEmailToRecord` and `UnlinkEmailFromRecord` directly (MCP writes are not proposals, as `CancelEmailTool` shows) and return `{email_id, record_type, record_id, linked: bool}` per item. They use `HasExplicitToolAnnotations` and the same token grant as `SendEmailTool`. Register both in `RelaticleServer` after `RetryEmailTool`. Update the count and tool reference in `mcp.md`.
- [ ] Tests:
  - `tests/Feature/Chat/EmailToolsTest.php`: proposal then approval links. A teammate's private email answers not found. A foreign `record_id` is refused at validation.
  - `tests/Feature/Mcp/EmailToolsTest.php`: link and unlink. Unlink then `LinkEmailAction::reapply()` keeps it off. A token without the grant does not list the tools.
  - `tests/Feature/CRM/SurfaceParityTest.php`: one test, "accepts the same email link fields on chat and mcp", comparing the item keys of both schemas.
  - Run `php artisan test tests/Browser/Chat/LoadingShimmerTest.php`.

**Check:** the four files and the shimmer test pass. `composer test:arch` passes, which covers the MCP count and the action naming.

## Rehearsal (PR 9)

`email_link_suppressions` is a new table with a foreign key to `emails`. Adding the key takes a `SHARE ROW EXCLUSIVE` lock on `emails` for the length of the `CREATE TABLE`, which blocks sync writes. The rehearsal measures it:

1. Export read-only: `pg_dump -s` for the schema, `pg_dump -a -t migrations` so only this migration is pending, and `\copy (select id, workspace_id from emails)` so the lock is taken on a table of production size (372,714 rows). The migration reads no rows, so no other data or personal column is exported.
2. Load into a scratch database with `set session_replication_role = replica`.
3. Run `DB_DATABASE=<scratch> php artisan migrate --force` with `\timing` on and a concurrent `insert into emails ...` loop in a second session. Record the wait the loop sees.
4. Run `migrate` again to prove a no-op. Drop the scratch database and delete the export.

Put row counts, run time and the observed lock wait in the PR body. Ship only if the wait stays under one second.

## UI verification (PR 9)

Run `amp orb services ensure`, sign in as `owner@relaticle.test` at the portal URL with agent-browser, and run `agent-browser set viewport 1440 900 2`, then `390 844 2`. Do both in light and dark. Save screenshots under `.amp/in/artifacts/pr9/`.

- Drawer header with zero, one and three chips. "+ Link record" picker searching a company, a person and an opportunity. Unlink confirmation, then the Undo toast.
- A chip's menu opened by keyboard (Tab, Enter) with the focus ring visible.
- Unlinked view with groups, a group expanded, Create person modal prefilled, Link to existing picker, and the empty state (use `empty@relaticle.test`).
- Opportunity tab showing derived company mail, a pin older than the window, and the empty state of a new opportunity with no company.
- At 390px: chips collapse to one plus "+N", picker modal full width, Unlinked group actions wrap under the name.
- Then run `php artisan test --compact tests/Browser/EmailIntegration/EmailLinkingBrowserTest.php --browser safari`.

## PR checklist (PR 9)

- [ ] Every test named in Tasks 3 to 8 exists with a real body and passes.
- [ ] `vendor/bin/pint --dirty --format agent`, `composer test:affected`.
- [ ] `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse` (no new ignores), `composer test:lint`, `composer test:arch`.
- [ ] `php artisan test tests/Browser/Chat/LoadingShimmerTest.php`.
- [ ] Master plan rows updated: `copies()`, `Email::onRecord()`, `email_link_suppressions.user_id` nullable.
- [ ] Rehearsal notes, screenshots (1440 and 390, light and dark) in the PR body.
- [ ] `packages/SystemAdmin` swept for `match` over `ProposalEntity` (none expected; record the grep).
- [ ] No em-dash: `rg -n $'\u2014' <changed files>` prints nothing.
- [ ] Push, open the PR, watch `Tests` with `gh run watch`.

---

# PR 13: Sharing and access

**Branch:** `feat/email-sharing-access`, from `feat/email-drawer` (PR 6) until it merges. If PR 9 is open at the same time, rebase on whichever merges first. Task 7 reads `Email::onRecord()` from PR 9, so if PR 9 has not merged, Task 7 waits for it.

**Goal:** Every connect asks who sees the mailbox, with "Who and when" preselected and a live preview. Raising the workspace default leaves past mail where it is. A teammate asks once for a record's mail and the owner answers once. Request creation and manual shares are audited. The reader names the mailbox a teammate's copy comes from. The connect card says what to do when no provider is configured.

## File map

| File | Change | Owns |
|---|---|---|
| `database/migrations/2026_11_xx_000001_add_sharing_confirmed_at_to_connected_accounts_table.php` | create | column plus backfill |
| `database/migrations/2026_11_xx_000002_add_note_and_record_index_to_email_access_requests_table.php` | create | `note`, partial unique index |
| `packages/EmailIntegration/src/Models/ConnectedAccount.php` | modify | cast, docblock, `hasAnsweredSharing()` |
| `packages/EmailIntegration/src/Enums/EmailPrivacyTier.php` | modify | `offeredAtConnect()` |
| `app/Actions/Onboarding/SaveOnboardingSharing.php` | modify | read the offered tiers from the enum |
| `packages/EmailIntegration/src/Actions/SaveMailboxSharingTier.php` | modify | stamp `sharing_confirmed_at` |
| `packages/EmailIntegration/src/Services/PrivacyService.php` | modify | unanswered mailbox stamps at the less open tier |
| `packages/EmailIntegration/src/Filament/Pages/MailboxSharingPage.php` | create | the sharing step after a connect |
| `packages/EmailIntegration/resources/views/filament/pages/mailbox-sharing.blade.php` | create | step view with live preview |
| `packages/EmailIntegration/resources/views/components/sharing-tier-choice.blade.php`, `resources/views/filament/pages/setup-workspace/sharing.blade.php` | create, modify | the tier cards both steps render |
| `packages/EmailIntegration/src/Controllers/CallbackController.php` | modify | route to the step until answered |
| `packages/EmailIntegration/src/Enums/MailboxConnectState.php` | create | connect card states and their copy |
| `packages/EmailIntegration/resources/views/components/not-connected.blade.php` | modify | render by state |
| `packages/EmailIntegration/src/Actions/ApplyDefaultSharingTierToExistingEmailsAction.php` | modify | narrow only; skip automated mail |
| `packages/EmailIntegration/src/Actions/SaveWorkspaceEmailSharingDefaultAction.php` | modify | raise forward, lower backward, notice |
| `packages/EmailIntegration/src/Notifications/WorkspaceSharingDefaultRaisedNotification.php` | create | inbox notice to null-tier mailbox owners |
| `packages/EmailIntegration/src/Filament/Pages/EmailPrivacySettingsPage.php` | modify | "applies to mail synced from now on" and the follower list |
| `packages/EmailIntegration/src/Observers/EmailObserver.php` | modify | automated mail starts Private |
| `lang/en/filament/pages/email-privacy-settings.php` | modify | `tiers.metadata_only.label` becomes "Who and when" |
| `packages/EmailIntegration/src/Actions/RequestEmailAccessAction.php` | modify | note, activity log |
| `packages/EmailIntegration/src/Actions/RequestRecordEmailAccess.php` | create | per-record batch request |
| `packages/EmailIntegration/src/Actions/ApproveEmailAccessRequestAction.php` | modify | record branch, "share all my mail on this record" |
| `packages/EmailIntegration/src/Actions/UpdateEmailSharingAction.php` | modify | log each new share |
| `packages/EmailIntegration/src/Models/EmailAccessRequest.php` | modify | `note` fillable, `emailable()` morph, `isForRecord()` |
| `packages/EmailIntegration/src/Notifications/EmailAccessRequestedNotification.php` | modify | record requests have no email |
| `packages/EmailIntegration/src/Inbox/EmailAccessRequestSource.php` | modify | present record requests, third answer |
| `packages/EmailIntegration/src/Filament/Concerns/HasEmailReaderActions.php` | modify | labelled request with note, batch request, source line |
| `packages/EmailIntegration/src/Filament/RelationManagers/BaseEmailsRelationManager.php` | modify | batch request bar |
| `packages/EmailIntegration/src/Actions/RequestAssistantEmailAccess.php` | create | chat adapter |
| `packages/Chat/src/Tools/Email/RequestEmailAccessTool.php` | create | chat write tool |
| `packages/Chat/src/Enums/ProposalEntity.php`, `PendingActionService.php`, `CrmAssistant.php`, `chat-interface.blade.php` | modify | registration |
| `docs/superpowers/plans/2026-10-31-email-conversations.md` | modify | contract rows for the two new columns and the index |
| tests (under `tests/`) | modify or create | `Feature/EmailIntegration/ApplyDefaultSharingTierToExistingEmailsActionTest.php`, `Feature/EmailIntegration/EmailPrivacySettingsPageTest.php`, `Feature/EmailIntegration/EmailCallbackControllerTest.php`, `Feature/EmailIntegration/EmailAccountsPageTest.php`, `Feature/EmailIntegration/EmailAccessRequestTest.php`, `Feature/EmailIntegration/EmailSharedNotificationTest.php`, `Feature/EmailIntegration/StoreEmailActionTest.php`, `Feature/EmailIntegration/ConversationsPageTest.php` (PR 6 drawer tests), `Feature/Notifications/NotificationInboxTest.php`, `Feature/Onboarding/SetupWorkspaceTest.php`, `Feature/Chat/EmailToolsTest.php`, `Browser/EmailIntegration/AccessRequestOpenEmailBrowserTest.php`, `Browser/EmailIntegration/MailboxSharingStepBrowserTest.php` |

## Tasks

### Task 1: Store that the owner answered

**Files:** migration `..._add_sharing_confirmed_at_to_connected_accounts_table.php`, `ConnectedAccount.php`, `EmailPrivacyTier.php`, `SaveMailboxSharingTier.php`, `SaveOnboardingSharing.php`, `PrivacyService.php`.

- [ ] Migration: `timestamp('sharing_confirmed_at')->nullable()`. Backfill in the same migration with the query builder. Run `DB::table('connected_accounts')->whereNotNull('sharing_tier')->eachById(...)` and set `sharing_confirmed_at => now()`. A stored tier means the owner chose (`SaveMailboxSharingTier.php:25` is the only writer). Mailboxes with a null tier (13 in production) see the card once, as D27 says. No `down()`.
- [ ] `ConnectedAccount`: add the `datetime` cast, the `@property CarbonImmutable|null $sharing_confirmed_at` docblock, and `hasAnsweredSharing(): bool`. `sharing_confirmed_at` is not mass assignable. `SaveMailboxSharingTier` writes it with `forceFill`, as it writes `sharing_tier`.
- [ ] `EmailPrivacyTier::offeredAtConnect(): array` returns `[self::METADATA_ONLY, self::SUBJECT]`. `SaveOnboardingSharing::OFFERED_TIERS` (`SaveOnboardingSharing.php:17`) is deleted and its readers (`RunsMailboxSteps.php:34`, `:123-133`, `:170-179`) read the enum. One fact, one owner, and the package step can read it without reaching into `App\Actions\Onboarding`.
- [ ] `SaveMailboxSharingTier::execute()` sets `sharing_confirmed_at => now()` in the same `forceFill` (`SaveMailboxSharingTier.php:25`). Onboarding goes through it (`SaveOnboardingSharing.php:36`), so both paths stamp alike. D27 names this risk.
- [ ] `PrivacyService::tierForMailbox()` (`PrivacyService.php:68-71`): when `sharing_tier` is null and `sharing_confirmed_at` is null, return the less open of the workspace default and `METADATA_ONLY` (compare `openness()`). Mail from a mailbox nobody has answered for is never stamped wider than "Who and when".
- [ ] Tests:
  - `ApplyDefaultSharingTierToExistingEmailsActionTest.php`: "stamps new mail from an unanswered mailbox at who and when while the workspace default is subject". Create a mailbox with both columns null and a workspace default of Subject, create an email through the observer, and expect `METADATA_ONLY`.
  - `SetupWorkspaceTest.php`: "marks the mailbox answered when the owner saves sharing in setup".

**Check:** both files pass.

### Task 2: The sharing step after every connect

**Files:** `MailboxSharingPage.php`, `mailbox-sharing.blade.php`, `CallbackController.php`, `EmailCallbackControllerTest.php`, lang.

- [ ] `MailboxSharingPage`: a Filament app-panel page, slug `email/sharing`, not in navigation, with `#[Url] $mailbox` and `#[Url] $return`.
  - `mount()` loads the signed-in user's mailbox in the tenant, else 404. It redirects when `hasAnsweredSharing()`, and accepts only a same-host relative `$return` (no open redirect).
  - `$sharingTier` is preselected as onboarding does (`RunsMailboxSteps.php:170-179`): Who and when unless the effective tier is Subject.
  - `save()` validates against `offeredAtConnect()`, calls `SaveMailboxSharingTier`, and redirects to `$return`. "More sharing options" links to `EmailAccountSettingsPage`.
- [ ] Extract the onboarding cards (`resources/views/filament/pages/setup-workspace/sharing.blade.php:31-63`) into `<x-email-integration::sharing-tier-choice>`, which both steps render. Below it, "How a teammate sees your mail" previews three rows:
  - Source: `ConversationsQuery::paginate($owner, ConversationView::All, new ConversationFilters(mailbox: $mailbox->id), perPage: 20)`, keeping the first three with `records` not empty. Teammates see only linked mail (PR 3).
  - Each row is `<x-email-integration::conversation-row>` in preview mode: participants, the subject only when `$tier->showsSubject()` (else "Subject hidden"), and never a snippet.
  - It updates on `wire:model.live="sharingTier"` and polls every 5 seconds while importing (`sharing.blade.php:5`). With no linked mail: "Nothing is linked to a record yet. Teammates see only mail that is."
- [ ] `CallbackController::__invoke()` (`CallbackController.php:115-127`): after `connect()`, read the mailbox back. If it has not answered and the workspace is not in setup (`onboarding_step` not `Email` or `Sharing`, which `RunsMailboxSteps` handles), redirect to `MailboxSharingPage::getUrl(['mailbox' => $id, 'return' => $returnUrl])`. Otherwise keep today's redirect. The success toast stays. A reconnect keeps `sharing_confirmed_at`, because `ConnectAccountAction` updates by lookup and does not list the column (`ConnectAccountAction.php:78`).
- [ ] Tests in `EmailCallbackControllerTest.php`, following "returns to the page that started the connect with a success notification" (line 91):
  - "sends a first connect to the sharing step with the return page kept";
  - "returns a reconnect of an answered mailbox straight to the page that started it";
  - "leaves the setup wizard to ask sharing itself".
- [ ] Tests for the page (Livewire, in `EmailAccountsPageTest.php`):
  - "preselects who and when";
  - "refuses another member's mailbox";
  - "refuses full access as a connect answer";
  - "shows the subject in the preview only for the subject tier", asserting the rendered HTML contains the subject for Subject and "Subject hidden" for Who and when;
  - "refuses an off-site return url".

**Check:** both files pass. `php artisan test --compact tests/Feature/Onboarding/SetupWorkspaceTest.php` still passes after the component extraction.

### Task 3: Connect card states

**Files:** `MailboxConnectState.php`, `not-connected.blade.php`, `email-inbox.blade.php` (card from PR 1), `BaseEmailsRelationManager.php`, `EmailAccountsPageTest.php`, `lang/en/filament/pages/email-accounts.php`.

- [ ] `MailboxConnectState` enum, cases `NoProvider`, `NotConnected`, `SharingUnanswered`, `Connected`. Static `for(User $user, Workspace $workspace): self`:
  - `NoProvider` when no `EmailProvider` case `isConfigured()`;
  - `NotConnected` when the user has no mailbox in the workspace;
  - `SharingUnanswered` when their newest mailbox has not answered;
  - else `Connected`.

  `heading()`, `description()` and `icon()` are facts of the case and live on the enum.
- [ ] Copy says what syncs and who sees it (D27), replacing "Send emails in Relaticle" (`email-accounts.php:132-136`):
  - NotConnected: "Connect your mailbox" / "Relaticle reads your mail to put it on the right company, person and opportunity. You choose what teammates see."
  - SharingUnanswered: "Choose what teammates see" / "Your mailbox is connected. Until you choose, teammates see only who and when." Its button opens `MailboxSharingPage`.
  - NoProvider: "Email is not set up on this server" / "Ask your administrator to add a Google or Microsoft app. The self-hosting guide shows how." It links to the self-hosting guide route. It shows to everyone, because a member cannot fix it and an admin needs the link.
- [ ] `not-connected.blade.php` takes a `state` prop and renders the matching action. NoProvider has no connect button today, which leaves an empty card (`not-connected.blade.php:18-24`, `ConnectMailboxAction.php:39`). The Emails page and record tabs pass `MailboxConnectState::for(...)`. Drafts and Templates stay usable under every state, which is PR 1's layout.
- [ ] Tests in `EmailAccountsPageTest.php`, one per state:
  - with `config(['services.gmail.client_id' => null, 'services.azure.client_id' => null])` (use the keys `EmailProvider::isConfigured()` reads), the page shows the NoProvider heading and the guide link;
  - an unanswered mailbox shows "Choose what teammates see";
  - an answered mailbox shows no card.

**Check:** the file passes.

### Task 4: Raising the default never widens past mail

Start with the check from Plan note 7: `git log origin/main --oneline -- packages/EmailIntegration/src/Actions/ApplyDefaultSharingTierToExistingEmailsAction.php`. If PR 3 changed `executeForWorkspace`, skip the first two bullets of the implementation list and keep the rest.

**Files:** `ApplyDefaultSharingTierToExistingEmailsAction.php`, `SaveWorkspaceEmailSharingDefaultAction.php`, `WorkspaceSharingDefaultRaisedNotification.php`, `EmailPrivacySettingsPage.php`, the two test files.

- [ ] Flip the existing test that asserts the widening, `ApplyDefaultSharingTierToExistingEmailsActionTest.php:114-125`. Rename it and change the expectation:

```php
it('leaves the past mail of default-following mailboxes where it was when the default rises', function (): void {
    $follower = sharingEmail(sharingMailbox($this->owner, $this->workspace), EmailPrivacyTier::METADATA_ONLY);
    $chosen = sharingEmail(
        sharingMailbox($this->owner, $this->workspace, ['email_address' => 'own@northwind.test', 'sharing_tier' => EmailPrivacyTier::PRIVATE]),
        EmailPrivacyTier::PRIVATE,
    );

    resolve(SaveWorkspaceEmailSharingDefaultAction::class)->execute($this->workspace, $this->owner, EmailPrivacyTier::SUBJECT);

    expect($follower->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY)
        ->and($chosen->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE)
        ->and($this->workspace->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('narrows past mail when the default is lowered and never widens mail below the new level', function (): void {
    $this->workspace->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT]);
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $wide = sharingEmail($mailbox, EmailPrivacyTier::SUBJECT);
    $closed = sharingEmail($mailbox, EmailPrivacyTier::PRIVATE);

    resolve(SaveWorkspaceEmailSharingDefaultAction::class)->execute($this->workspace, $this->owner, EmailPrivacyTier::METADATA_ONLY);

    expect($wide->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY)
        ->and($closed->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});
```

Add a third test, "stamps mail synced after a raise at the new default". It uses an answered mailbox (`sharing_confirmed_at` set) and a `creation_source` of `SYNC` email created after the raise, and expects `SUBJECT`. That pins the "future mail" half of the rule.

Three existing tests call `executeForWorkspace(..., EmailPrivacyTier::FULL)` directly and expect past mail to widen: lines 127, 148 and 157. They test who follows the default (pivot membership, other workspaces), not the direction of the change. Keep that intent: seed their emails at `SUBJECT` and lower to `METADATA_ONLY`, keeping the same counts. Line 148 then also proves another workspace is not narrowed. Line 168 already lowers and keeps passing. Do not delete any of them.

- [ ] Add the page-level test the spec names ("Feature, settings page", `design.md:726`) to `EmailPrivacySettingsPageTest.php`. Set the tab to sharing, set `default_email_sharing_tier` to Subject, call `save`, and assert a follower mailbox's past email is still `METADATA_ONLY`. Also assert the confirmation text says "Applies to mail synced from now on".
- [ ] Implementation:
  - `SaveWorkspaceEmailSharingDefaultAction::execute()` (`SaveWorkspaceEmailSharingDefaultAction.php:21-32`) calls the retroactive path only when `$newTier->openness() < $previousTier->openness()`.
  - `executeForWorkspace()` adds `whereIn('privacy_tier', <tiers more open than $tier>)` in place of `!= $tier` (`ApplyDefaultSharingTierToExistingEmailsAction.php:26`). Derive the list from `EmailPrivacyTier::cases()` and `openness()`, with no hand-written list.
  - When the tier rises, after commit, notify each owner of a mailbox with `sharing_tier` null with `WorkspaceSharingDefaultRaisedNotification`: database channel, inbox row "Your workspace now shares :tier for new mail. Your earlier mail is unchanged." with a link to `EmailAccountSettingsPage`. Leave out the actor's own mailbox.
  - `EmailPrivacySettingsPage::saveAction()` (line 140): the confirmation description reads "Applies to mail synced from now on. Earlier mail stays as it is." when raising, and lists the members whose mailboxes follow the default. When lowering, it reads "Applies to earlier mail too". Wire it through `SharingTierChangeConfirmation::modalDescription()`.
- [ ] Notification test: "tells members who follow the default when it rises and not when it falls".

**Check:** `php artisan test --compact tests/Feature/EmailIntegration --parallel --processes=4 --filter='(ApplyDefaultSharingTierToExistingEmailsActionTest|EmailPrivacySettingsPageTest)::'`.

### Task 5: Rename the metadata tier and start automated mail Private

**Files:** `lang/en/filament/pages/email-privacy-settings.php`, `EmailObserver.php`, `ApplyDefaultSharingTierToExistingEmailsAction.php`, tests.

- [ ] `tiers.metadata_only.label` becomes "Who and when" (`email-privacy-settings.php:118-121`). The description line stays unchanged for one release (`design.md:581-582`). Grep `lang/` and `packages/*/resources` for the literal "Participants only" and change only labels of this tier. Tests that assert `getLabel()` pick it up, and tests that assert the old literal are updated to `EmailPrivacyTier::METADATA_ONLY->getLabel()`.
- [ ] `EmailObserver::creating()` (`EmailObserver.php:30-38`): after the mailbox tier is applied, if `automated_reason` is not null (PR 4), set `privacy_tier = PRIVATE` and leave `privacy_tier_customized` false. The owner keeps full access (`PrivacyService.php:28-34`).
- [ ] `executeForWorkspace()` and `executeForMailbox()` skip rows with `automated_reason` not null. Otherwise an owner re-saving their mailbox tier would widen automated mail again. Past automated mail is not re-tiered by this PR. That would be a data write over production rows, and the spec says only that automated mail "starts" Private (`design.md:586`).
- [ ] Tests:
  - `StoreEmailActionTest.php` (the store path): "stores automated mail private for teammates and full for its owner";
  - `ApplyDefaultSharingTierToExistingEmailsActionTest.php`: "keeps automated mail private when the owner raises their mailbox level".

**Check:** both files pass, and `EmailPrivacySettingsPageTest.php` passes with the new label.

### Task 6: Labelled request with a note, and request logging

**Files:** migration `..._add_note_and_record_index_to_email_access_requests_table.php`, `EmailAccessRequest.php`, `RequestEmailAccessAction.php`, `HasEmailReaderActions.php`, `EmailAccessRequestedNotification.php`, `EmailAccessRequestTest.php`.

- [ ] Migration: `string('note', 500)->nullable()`. Then `DB::statement("create unique index email_access_requests_one_pending_record_request on email_access_requests (emailable_type, emailable_id, requester_id, owner_id) where status = 'pending' and email_id is null")`. No `down()`.
- [ ] `EmailAccessRequest`: add `note` to `#[Fillable]` (`EmailAccessRequest.php:26-38`), an `emailable(): MorphTo` relation, and `isForRecord(): bool` (email null and emailable set).
- [ ] `RequestEmailAccessAction::execute()` gains `?string $note = null` and stores it trimmed, at most 500 characters. Update every caller in the same change: `HasEmailReaderActions.php:253-280` and the tests. After a request is created, log `activity(Activity::EMAIL_ACCESS_LOG)->performedOn($request)->causedBy($requester)->event('requested')->withProperties(['tier' => ..., 'has_note' => filled($note)])->log('requested')`, matching `DecideEmailAccessRequest.php:55-66`. The note text is not copied into the log.
- [ ] Reader: `requestAccessAction()` becomes a labelled button ("Request access", `->button()` in place of `->iconButton()`, `HasEmailReaderActions.php:220-226`) and gains a `Textarea::make('note')->maxLength(500)` under the tier cards. The drawer gate (PR 6) and the locked row hover (PR 5) mount the same action.
- [ ] `EmailAccessRequestedNotification` shows the note under the subject in the email and the inbox body, escaped with `MarkdownText::escape()` as the subject is (line 84).
- [ ] Tests in `EmailAccessRequestTest.php`, `describe('RequestEmailAccessAction')`:
  - "stores the note and shows it to the owner";
  - "logs a new request once and not a repeated pending one" (second call returns null, one activity row);
  - "refuses a note longer than 500 characters" through the reader action.

**Check:** the file passes.

### Task 7: Per-record batch request and "share all my mail on this record"

Depends on PR 9's `Email::onRecord()`.

**Files:** `RequestRecordEmailAccess.php`, `ApproveEmailAccessRequestAction.php`, `EmailAccessRequestSource.php`, `EmailAccessRequestedNotification.php`, `BaseEmailsRelationManager.php`, `UpdateEmailSharingAction.php`, `EmailAccessRequestTest.php`, `NotificationInboxTest.php`, `EmailSharedNotificationTest.php`.

- [ ] Write the approval test first, in `describe('ApproveEmailAccessRequestAction')`:

```php
it('shares only the owner mail on the requested record when a record request is approved', function (): void {
    [$owner, $requester, $workspace] = accessRequestPair();
    $airbnb = Company::factory()->for($workspace)->create(['name' => 'Airbnb']);
    $stripe = Company::factory()->for($workspace)->create(['name' => 'Stripe']);
    $onAirbnb = accessRequestEmail($owner, linkedTo: $airbnb);
    $alsoOnAirbnb = accessRequestEmail($owner, linkedTo: $airbnb);
    $onStripe = accessRequestEmail($owner, linkedTo: $stripe);

    $request = resolve(RequestRecordEmailAccess::class)->execute($requester, $airbnb, $owner, EmailPrivacyTier::FULL, null);
    resolve(ApproveEmailAccessRequestAction::class)->execute($request, $owner);

    expect($requester->can('viewBody', $onAirbnb->fresh()))->toBeTrue()
        ->and($requester->can('viewBody', $alsoOnAirbnb->fresh()))->toBeTrue()
        ->and($requester->can('viewBody', $onStripe->fresh()))->toBeFalse()
        ->and(EmailShare::query()->where('shared_with', $requester->getKey())->count())->toBe(2);
});

it('does not share a teammate copy of the same message the owner does not hold', function (): void { /* third member's copy linked to Airbnb stays unshared */ });
it('refuses a record request for a record in another workspace', function (): void { /* 403, no row */ });
it('keeps one pending record request per requester, owner and record', function (): void { /* second call returns null */ });
it('lets the owner answer a single email request with all their mail on its record', function (): void { /* writes emailable_type and emailable_id, shares every Airbnb copy */ });
it('logs each manual share and each record share', function (): void { /* activity rows with event shared */ });
```

`accessRequestPair()` and `accessRequestEmail()` extend the file's existing setup. Read its `beforeEach` (lines 37-58) and reuse what is there before adding helpers.

- [ ] `RequestRecordEmailAccess::execute(User $requester, Company|People|Opportunity $record, User $owner, EmailPrivacyTier $tier, ?string $note): ?EmailAccessRequest`:
  - Abort unless the requester and the owner belong to the record's workspace, the requester `can('view', $record)`, and they are different users.
  - Create the row with `email_id` null, `emailable_type` from `getMorphClass()`, `emailable_id`, `owner_id`, `tier_requested`, `note`, `status` pending, `workspace_id`, `expires_at`.
  - Catch `UniqueConstraintViolationException` and return null, as `RequestEmailAccessAction.php:52-67` does. Log `requested` and notify the owner.
- [ ] `ApproveEmailAccessRequestAction::execute()` (`ApproveEmailAccessRequestAction.php:22-60`) gains a record branch when `isForRecord()`. It loads `Email::query()->onRecord($record)->where('user_id', $owner->getKey())->where('workspace_id', $request->workspace_id)` with `lazyById` and calls `EmailSharingService::shareEmail($email, $owner, $requester, $tier)` for each, inside the existing transaction after `decide->execute()`. The record must still exist and share the request's workspace, else the request is denied as stale. Each record share logs one `shared` activity on the request with `['record_type', 'record_id', 'count']`.
- [ ] The owner's answer, "Share all my mail on :record". `EmailAccessRequestSource` offers it on a single-email request whose email is on a record, one answer per record (at most three). Answering writes `emailable_type` and `emailable_id` on the open request, then runs the record branch.
- [ ] `EmailAccessRequestedNotification` and `EmailAccessRequestSource::present()` handle a null `email_id`. Today they read `emailIncludingHidden->subject` (`EmailAccessRequestedNotification.php:64-88`). Title: "Ana asks for your 12 emails on Airbnb". Body: the note.
- [ ] Batch bar on record tabs (`BaseEmailsRelationManager`). Count, per owner, the copies `onRecord($ownerRecord)` the viewer sees below Full, in one grouped SQL query using the scope's tier predicate. When an owner has two or more, show "Request Ana's 12 emails on Airbnb" (at most two bars, then "+N more"). The bar mounts `requestRecordAccess` with tier cards and a note. While a pending request exists, it reads "Requested from Ana".
- [ ] `UpdateEmailSharingAction::execute()` (`UpdateEmailSharingAction.php:23-45`) logs one `shared` activity per newly granted user (`shared_with`, `tier`) after the transaction.
- [ ] `NotificationInboxTest.php`: "shows a record request with its count and note" and "approves a record request from the inbox".

**Check:** `php artisan test --compact tests/Feature/EmailIntegration --parallel --processes=4 --filter='(EmailAccessRequestTest|EmailSharedNotificationTest)::'`, then `php artisan test --compact tests/Feature/Notifications/NotificationInboxTest.php`.

### Task 8: Name the source of a teammate copy in the reader

**Files:** `reader-drawer.blade.php`, `PreferredEmailCopyService.php` (read only), `tests/Feature/EmailIntegration/ConversationsPageTest.php`.

- [ ] Part C already plans this line in the PR 6 drawer header (`2026-10-31-email-conversations-c-list-and-drawer.md:1103-1106`). Check with `rg -n "Visible through" packages/EmailIntegration lang`. If it shipped, add only the test below.
- [ ] When the viewer's copy is not their own (`ConversationRow::owner_user_id` not null, or the drawer's selected copy has `user_id` other than the viewer), render under the subject: "Visible through :name's mailbox", where the name comes from `Email::mailboxViaName()` (`Email.php:281-298`). Use muted text and the `ri-mail-line` icon. "Most open visible copy wins" stays (`PreferredEmailCopyService.php:40-46`). The line explains it rather than changing it.
- [ ] Test: two mailboxes hold the message, Ben's copy at Subject and the viewer has no copy. The drawer shows "Visible through Ben's mailbox". When the viewer holds their own copy, the line is absent.

**Check:** `ConversationsPageTest.php` passes.

### Task 9: Rela can request access

**Files:** `RequestAssistantEmailAccess.php`, `RequestEmailAccessTool.php`, `ProposalEntity.php`, `PendingActionService.php`, `CrmAssistant.php`, `chat-interface.blade.php`, `tests/Feature/Chat/EmailToolsTest.php`.

- [ ] `RequestAssistantEmailAccess::execute(User $user, array $data): EmailAccessRequest`, where `$data` is `array{email_id: string, tier_requested: string, note?: string|null}`. It resolves the email with `VisibleEmailsQuery::find()`, refuses with a `ValidationException` when `can('requestAccess')` is false or a request is pending, and calls `RequestEmailAccessAction`.
- [ ] `RequestEmailAccessTool` extends `BaseWriteCreateTool`, with `records[]` items `{email_id, tier_requested (subject|full, default full), note}` (Plan note 14). The display shows the owner's name, "Subject hidden" or the subject at the viewer's tier, the tier, and the note. Add `ProposalEntity::EmailAccessRequest = 'email_access_requests'` to every `match`. It is not approved on its own. Register the tool, add `'request_email_access' => __('Requesting email access…')`, and allow the adapter.
- [ ] Tests:
  - "proposes one request per locked email and sends them on approval";
  - "refuses an email the user already reads in full";
  - "never reveals a private teammate email" (not found).
  - Then run `php artisan test tests/Browser/Chat/LoadingShimmerTest.php`.

**Check:** the file and the shimmer test pass.

## Rehearsal (PR 13)

Two migrations.

**`add_sharing_confirmed_at_to_connected_accounts_table`** touches `connected_accounts` and backfills it, so it follows core.md, Database:

1. Export read-only: `pg_dump -s`, `pg_dump -a -t migrations`, and `\copy (select id, workspace_id, user_id, sharing_tier, deleted_at, created_at, updated_at from connected_accounts) to ...`. Do not copy the token, address or display-name columns.
2. Load into a scratch database with `set session_replication_role = replica`. Save the before-state: `select sharing_tier is not null as chose, count(*) from connected_accounts group by 1` (expect 19 chose and 13 null of 32, from `d-linking-privacy-reach.md:230-231`).
3. `DB_DATABASE=<scratch> php artisan migrate --force`. Confirm only this migration ran. After-state: every row with a tier has `sharing_confirmed_at` set, every null-tier row has it null, and no other column changed (diff the exported columns).
4. Migrate again, expect a no-op. Drop the scratch database and delete the export.

**`add_note_and_record_index_to_email_access_requests_table`** touches a table with 0 production rows ("0 access requests and 0 shares ever", `design.md:41`). Rehearse steps 1, 3 and 4 with a schema-only export to prove the partial index builds and the migration is a no-op on rerun. Record run time.

Neither migration drops a column queued jobs read, so no Horizon pause is needed.

## UI verification (PR 13)

Same setup as PR 9, screenshots under `.amp/in/artifacts/pr13/`, 1440 and 390, light and dark.

- Sharing step after a connect. In the orb no OAuth provider completes, so seed a mailbox for `trial@relaticle.test` with both columns null and open `MailboxSharingPage` directly. Capture both tiers selected, the preview with linked mail, the preview with none, the importing state, and "More sharing options".
- Connect card in each state. NoProvider needs the provider keys blanked in `.env`, then `amp orb service restart app`. Restore them afterwards and restart again.
- Settings page confirmation when raising and when lowering, with the follower list.
- "Who and when" label in the settings cards, the account settings cards and the onboarding step.
- Drawer gate with the labelled Request access button and note field. The source line on a teammate copy.
- Record tab batch bar ("Request Ana's 12 emails on Airbnb"), the pending state, the inbox row for a record request with its third answer.
- The 390px pass checks that the batch bar wraps without truncating the name and count, and that the step's radio cards stay full width.
- Then run `php artisan test --compact tests/Browser/EmailIntegration/MailboxSharingStepBrowserTest.php tests/Browser/EmailIntegration/AccessRequestOpenEmailBrowserTest.php --browser safari` (one file per run if the parallel form refuses two paths).

## PR checklist (PR 13)

- [ ] Every test named in Tasks 1 to 9 exists with a real body and passes. The flipped test at `ApplyDefaultSharingTierToExistingEmailsActionTest.php:114` asserts the unchanged tier.
- [ ] Loop and once-per-PR checks from the master plan, no new PHPStan ignores.
- [ ] `php artisan test tests/Browser/Chat/LoadingShimmerTest.php`.
- [ ] Master plan rows added: `connected_accounts.sharing_confirmed_at`, `email_access_requests.note`, the record-request index, `MailboxConnectState`, `RequestRecordEmailAccess`.
- [ ] `packages/SystemAdmin` swept for `match` over `ProposalEntity` and `EmailPrivacyTier` (`rg -n "EmailPrivacyTier|ProposalEntity" packages/SystemAdmin`). Record the result in the PR body.
- [ ] Rehearsal notes for both migrations, screenshots for every state above.
- [ ] No em-dash in changed files. No "contact" or "deal" used as a record label in new copy.
- [ ] Push, open the PR, watch `Tests`.

---

## Decisions made for the caller

Plan notes 1, 5, 6, 11 and 18 are decisions too. The rest:

| Decision | Reason | If overruled |
|---|---|---|
| Unlink by a teammate of a visible teammate copy is allowed | Record curation is a workspace act. The owner still sees the mail in Unlinked | Add `$email->user_id === $user->getKey() \|\| <record owner>` to the unlink guard |
| Unlink recounts by queued job | D22, no drift | Decrement inline: faster, drifts |
| Connect step offers Who and when and Subject only | Same as onboarding; Private and Full stay in mailbox settings | Offer all four through `offeredAtConnect()` |
| Existing mailboxes with a stored tier count as answered | They chose in onboarding or settings | Leave all null and show the card to every mailbox once |
| Automated mail Private from now on, past mail untouched | The spec says "starts"; no production write | Add a reporting `--force` command queued from a migration |
| No `$ref` in the link tool | Polymorphic keys cannot be listed in `ownedForeignKeys()` | Split into three tools, one per record type |

## Risks and unknowns

| Risk | Cheapest early check |
|---|---|
| The suppression lookup slows `LinkEmailAction` on history import | One query per email over an indexed join. Time `RelinkRecordHistoryJob` on the rehearsal copy of the largest mailbox before merge |
| `copies()` misses copies while `conversation_key` is null | Task 2 reuses PR 2's shim. Add one test with a null key |
| The recount job on a 156,632-email company runs past the 300 s job timeout | Measure on the rehearsal copy. If it is over, chunk by date and mark the job `#[Timeout(900)]` on `imports` |
| The preview leaks a subject the chosen tier would hide | The Task 2 test asserts rendered HTML per tier |
| `onRecord()` for opportunities is slow on a large company | It filters a single record with indexed morph columns. Check `EXPLAIN` on the rehearsal copy |
