# Email Conversations, part C: the list and the drawer (PR 5 and PR 6)

> **For agentic workers:** REQUIRED SUB-SKILL: use the `sdd-lean` skill to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The `Emails` page lists conversations, one row per conversation, with record chips, unread
state and a Needs reply default. Record Emails tabs render the same rows. A resolver route gives
every email a stable link. PR 6 replaces the modal reader with a non-modal right drawer that shows
the whole thread, the privacy gate and prev and next, on every host.

**Spec:** `docs/superpowers/specs/2026-10-31-email-conversations-design.md`, sections "Visibility
rules", "`ConversationsQuery`", "Routes", "UI" (The Emails page, The row, Record Emails tabs, The
drawer), "Feature flag", "Testing", "Phases and pull requests".

**Master plan:** `docs/superpowers/plans/2026-10-31-email-conversations.md`. Its global constraints,
loop per task, pre-push checks and cross-PR contracts apply. This file repeats none of them.

**Reasoning:** `docs/email/decisions/a-page-and-list.md`, D1 to D9.

**Depends on:** PR 2 (`emails.conversation_key`, `email_conversations`, `EmailConversation`,
`RefreshConversationSummary`), PR 3 (the linked-only teammate rule in `VisibleEmailScope`) and PR 4
(`emails.automated_reason`, `AutomatedReason`). Their contracts are treated as merged.

## What this part adds to the global constraints

- Every new surface is behind `App\Features\EmailConversations`. With the flag off, the page, the
  record tabs and the reader behave exactly as on `main`, and their existing tests pass unchanged.
- No view runs `count(*)` per page. Lists use cursor pagination. `counts()` runs once per render.
- Row fields come only from copies the viewer may see, at the tier they may see. The summary row
  supplies ordering and view membership, never text and never a count the row displays.
- Rows are built in `ConversationsQuery`. The row component makes no policy call and runs no query.

## Plan notes: conflicts with the spec, the contracts or the code

Each note gives the conflict, the evidence and the path taken. Where the spec assumed code that does
not exist, the code wins.

1. **Pennant cannot store a per-workspace flag as configured.** Spec:703-705 wants a workspace-scoped
   Pennant feature on for two workspaces. Pennant v1.26.0 is installed, but `config/pennant.php`
   defaults to the `array` store, no migration creates `features`, and all ten `app/Features` classes
   are global config switches (`app/Features/EmailIntegration.php`, keys at `config/relaticle.php:62-73`).
   Path: `EmailConversations::resolve(Workspace $workspace): bool` is true when
   `relaticle.features.email_integration` is on and `relaticle.features.email_conversations` holds
   `*` or the workspace id (env `RELATICLE_FEATURE_EMAIL_CONVERSATIONS`, comma-separated). Callers pass
   the workspace, `Feature::for($workspace)`, because the default scope is the user.
2. **`Email` has no `WorkspaceScope` to remove** (spec:331). It carries only `ActiveAccountScope`
   (`src/Models/Email.php:73`). The workspace bound is `VisibleEmailScope::apply()` on
   `$viewer->current_workspace_id` (`src/Models/Scopes/VisibleEmailScope.php:51-54`). Path: filter
   `email_conversations.workspace_id` explicitly; only the record chips remove `WorkspaceScope` and
   keep `where('workspace_id', ...)`.
3. **Signatures.** Spec:320-322 puts the view in the filters and returns `ConversationCounts`. Master:146
   takes `ConversationView` and returns `array<string, int>`. Path: the master shape, keys
   `needs_reply`, `unlinked`, `automated`, plus an optional `?ConversationFilters` on `counts()` for
   the record tab (Task 6).
4. **Done does not exist yet.** `Done`, `is_done` and "not Done" (spec:340, 345, 361) need
   `email_conversation_states`, owned by PR 7 (master:123). Path: five cases in PR 5, no `is_done`.
   PR 5 edits master:143 and moves `Done` and `is_done` to PR 7's rows. A case that returns nothing
   would be placeholder code.
5. **Counts and times from the summary leak.** Spec:357 builds the row from the summary, and
   `message_count` counts every copy (spec:183). Under the linked-only rule a conversation can hold a
   teammate's unlinked reply. Path: `message_count`, `last_message_at` and sender names come from the
   viewer's visible copies. The summary orders the list and decides view membership (D5: a
   teammate's reply clears Needs reply).
6. **No `LATERAL` join** (spec:357). Path: one `distinct on (conversation_key)` query per page with
   the ordering of `PreferredEmailCopyService::restrictToVisiblePreferredCopies()`
   (`src/Services/PreferredEmailCopyService.php:40-46`). Same cost, stays in Eloquent.
7. **The null-key shim cannot list anything.** Spec:695 puts it in the query, spec:743 in PR 2, and
   part B hands it to PR 5 (`2026-10-31-email-conversations-b-data.md:57-58`, `:1288`). A shimmed key
   has no `email_conversations` row, so no view can list it. Path: no shim. A workspace joins the flag
   only after `email:backfill-conversations --workspace=<id>` leaves zero null keys (PR 5 checklist).
   PR 5 edits part B's two lines to point here.
8. **The order needs a tiebreak.** Spec:191 indexes `(workspace_id, last_message_at desc)`; the cursor
   orders by `last_message_at desc, id desc`. Path: Task 8 adds the covering index if PR 2 lacks `id`.
9. **`awaiting_reply_business_days` has no column** (spec:341, no contract). Path: constant
   `ConversationView::AWAITING_REPLY_BUSINESS_DAYS = 3`. A workspace setting is a follow-up.
10. **Record tab extras are later PRs.** The per-sender automated bundle (spec:452) and opportunity
    read-time matching (spec:455) need PR 9's grouping and pins. The chip bar and `?filter[...]`
    (spec:416) are PR 8. Path: PR 5 tabs show linked conversations and a counted Automated toggle;
    the page keeps its search field; `ConversationFilters` is flat until PR 8.
11. **`?email=` would open two readers.** The handler binds `#[Url(as: 'email')]` and opens on mount
    (`src/Livewire/EmailAccessNotificationHandler.php:45-53`) on every page
    (`src/EmailIntegrationServiceProvider.php:153-171`). Spec:397 wants it to yield to a host. Path: a
    locked `hostsReader` flag from the render hook's `$scopes` (Filament passes them,
    `vendor/filament/support/src/View/ViewManager.php:89`). PR 5 lists the record view pages; PR 6
    adds `EmailInboxPage`.
12. **Deep links move into PR 5.** Spec:747 gives them to PR 6, but the resolver is PR 5 (spec:746)
    and redirects to `?email=`. Path: PR 5 binds `?email=` on both hosts with the old overlay.
13. **The drawer is flagged, so the overlay stays.** Master:150 says "replaces"; spec:704 flags the
    drawer. Path: drawer with the flag on, overlay with it off. Both old pieces go at the project's
    definition of done (master:161).
14. **The gate reads the email's tier, not the viewer's** (`resources/views/components/email-view.blade.php:445`, `:454`).
    Path: the drawer gate reads `PrivacyService::effectiveTier()`.
15. **`Email::appUrl(bool $openReader)` and `appUrlFor()`** (`src/Models/Email.php:444-456`) are called at
    `src/Notifications/EmailSharedNotification.php:97,130`, `src/Notifications/EmailAccessRespondedNotification.php:75`
    and `src/Inbox/EmailAccessRequestSource.php:116`. Path: `appUrl(): string` returns the resolver,
    `appUrlFor()` goes, every caller moves in PR 5. The resolver applies the tier.
16. **Name clash.** `Relaticle\Chat\Queries\ConversationsQuery` exists (`.ai/rules/queries.md`). A file
    needing both aliases one import.
17. **Already done.** `phpstan.neon:149` already bans writes in `Relaticle\EmailIntegration\Queries`.
18. **Routes load only with `EmailIntegration` on** (`src/EmailIntegrationServiceProvider.php:103`,
    `:139`). The resolver must still work with `EmailConversations` off, because notifications carry it.
19. **Row fields the spec did not list:** `ownerName`, `senderNames`, `viewerHasPendingRequest` (the
    Requested state at `resources/views/components/list-row-wide.blade.php:177-183`). `access` is an `EmailPrivacyTier` case, the
    enum that already owns `showsSubject()` and `showsBody()`. PR 5 adds them to master:145.
20. **"Shared after the share date."** Shares are per copy (unique `(email_id, shared_with)`,
    `database/migrations/2026_03_21_050520_create_email_shares_table.php:27`), so a share reaches the
    viewer when made. Path: a copy reached the viewer when it is in their mailbox or has a direct
    `full` share to them. Subject and metadata shares never bold a row (D7: "can open").
21. **Drawer width** is "55% of the content width" (spec:462). The record tab sits deep in a scrolling
    page, so the drawer is fixed to the viewport: 55vw, 40vw to 70vw, never over the sidebar.
22. **One not-available page** (spec:393). Path: `abort(404)`, rendering
    `resources/views/errors/404.blade.php` with no subject. A non-member gets the same response.

Paths in these notes that start with `src/` or `resources/views/components/` are relative to
`packages/EmailIntegration/`. The rest are relative to the repository root.

## Read first

- `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php` and
  `resources/views/filament/pages/email-inbox.blade.php`: the page body this part replaces under the flag.
- `packages/EmailIntegration/src/Filament/RelationManagers/BaseEmailsRelationManager.php`: the record
  tab, its hand-built list (`emails()`, lines 92-123) and its reader host (`selectedEmail()`, 174-194).
- `packages/EmailIntegration/src/Models/Scopes/VisibleEmailScope.php` and
  `src/Services/PreferredEmailCopyService.php`: who sees which copy, and which copy wins.
- `packages/EmailIntegration/src/Filament/Concerns/HasEmailReaderActions.php` and
  `resources/views/components/reader-overlay.blade.php`: the reader contract every host shares.
- `packages/EmailIntegration/src/Queries/VisibleEmailsQuery.php`: the sibling query to copy the shape from.

---

# PR 5: Conversations list, record tabs and resolver

**Branch:** `feat/email-conversations-list`, from the PR 4 branch until PRs 2, 3 and 4 merge, then
rebased on `main`.

**Goal:** With the flag on, the Emails page opens on Needs reply (or All when it is empty) and lists
one row per conversation with chips, unread state and locked rows. Record tabs list the same rows for
one record. `GET /email/open/{email}` resolves any email id to the right host.

## File map

Package paths start at `src/`, `resources/` or `routes/` under `packages/EmailIntegration/`.

| File | Change | Owns |
|---|---|---|
| `app/Features/EmailConversations.php` | create | per-workspace switch |
| `config/relaticle.php` (62-73), `.env.example` (near 179) | modify | `email_conversations` key |
| `src/Enums/ConversationView.php` | create | view facts |
| `src/Data/ConversationFilters.php`, `src/Data/ConversationRow.php` | create | input and row |
| `src/Queries/ConversationsQuery.php` | create | `paginate()`, `counts()`, `messages()` |
| `src/Services/PreferredEmailCopyService.php` (54-69) | modify | reads a preloaded `viewer_has_synced_copy` |
| `src/Filament/Concerns/HasConversationsFlag.php` | create | `conversationsEnabled()` |
| `src/Filament/Pages/EmailInboxPage.php`, `resources/views/filament/pages/email-inbox.blade.php` | modify | views, cursor, counts, reader host |
| `resources/views/components/view-tab.blade.php`, `conversation-row.blade.php` | create | tab and row |
| `src/Filament/RelationManagers/BaseEmailsRelationManager.php`, `resources/views/filament/relation-managers/emails-relation-manager.blade.php` | modify | rows, `?email=`, automated toggle |
| `src/Controllers/OpenEmailController.php`, `routes/web.php` | create, modify | `email.open` |
| `src/Models/Email.php` (444-456) and its four callers (note 15) | modify | `appUrl()` |
| `src/Support/EmailForAgent.php` (near 55) | modify | `url` |
| `src/Livewire/EmailAccessNotificationHandler.php`, `src/EmailIntegrationServiceProvider.php` (153-171) | modify | `hostsReader` |
| `lang/en/filament/pages/email-inbox.php`, `lang/fr/filament/pages/email-inbox.php` | modify | `views.*`, `conversation_row.*` |
| `database/migrations/2026_11_02_000000_index_email_conversations_for_views.php` | create | indexes (Task 8) |
| `database/seeders/EmailConversationsSeeder.php`, `database/seeders/LocalSeeder.php` | create, modify | local data |
| `tests/Feature/EmailIntegration/ConversationsPageTest.php`, `RecordEmailsConversationsTest.php`, `OpenEmailRouteTest.php` | create | page, tabs, resolver |
| `tests/Feature/EmailIntegration/EmailPageTabsTest.php`, `EmailAccessNotificationHandlerTest.php`, `EmailIntegrationFeatureFlagTest.php`, `EmailSharedNotificationTest.php`, `tests/Feature/Mcp/EmailToolsTest.php`, `tests/Feature/Chat/EmailToolsTest.php` | modify | flag off, `hostsReader`, URLs |
| `docs/superpowers/plans/2026-10-31-email-conversations.md` (143, 145, 146, PR 7 rows) | modify | notes 3, 4, 19 |

No existing test covers a conversation list, so the three new files duplicate nothing.

## Shared test helpers

Every new feature test file in this part starts from this setup. Helper names carry the file's
prefix because Pest helpers are global functions.

```php
<?php

declare(strict_types=1);

use App\Features\EmailConversations;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

use function Pest\Laravel\actingAs;

mutates(EmailInboxPage::class, ConversationsQuery::class, ConversationView::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);

    $this->ownerMailbox = conversationsPageMailbox($this->owner, 'ana@acme.test');
    $this->teammateMailbox = conversationsPageMailbox($this->teammate, 'ben@acme.test');
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);

    Feature::for($this->workspace)->activate(EmailConversations::class);
    actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);
});

function conversationsPageMailbox(User $user, string $address): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'user_id' => $user->id,
        'email_address' => $address,
        'status' => 'active',
    ]));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function conversationsPageMessage(ConnectedAccount $mailbox, string $key, string $from, array $attributes = []): Email
{
    $email = Email::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->id,
        'conversation_key' => $key,
        'rfc_message_id' => '<'.Str::lower((string) Str::ulid()).'@mail.test>',
        'direction' => $from === $mailbox->email_address ? EmailDirection::OUTBOUND : EmailDirection::INBOUND,
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'is_internal' => false,
        'sent_at' => now(),
        ...$attributes,
    ]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->id,
        'email_address' => $from,
        'name' => Str::headline(Str::before($from, '@')),
    ]);

    resolve(RefreshConversationSummary::class)->execute($mailbox->workspace_id, $key);

    return $email;
}

/**
 * @return list<string>
 */
function conversationsPageKeys(string $html): array
{
    preg_match_all('/data-conversation="([^"]+)"/', $html, $matches);

    return $matches[1];
}
```

`livewire()` is the global helper in `tests/Pest.php:76`. Later tests also import `CarbonImmutable`,
`DB`, `AutomatedReason`, `EmailRead`, `EmailShare` and `EmailBody`. The helper runs PR 2's real summary action, so the summary under test is the one production
computes. If PR 2 ships an `Email` factory state for conversations, use it.

## Tasks

### Task 1: The flag and the host check

**Files:** `app/Features/EmailConversations.php`, `config/relaticle.php`, `.env.example`,
`packages/EmailIntegration/src/Filament/Concerns/HasConversationsFlag.php`,
`tests/Feature/EmailIntegration/EmailIntegrationFeatureFlagTest.php`.

- [ ] Add `'email_conversations' => array_filter(explode(',', (string) env('RELATICLE_FEATURE_EMAIL_CONVERSATIONS', '')))`
  to `config/relaticle.php` `features`, and the empty key to `.env.example` beside
  `RELATICLE_FEATURE_EMAIL_INTEGRATION`.
- [ ] Create `final readonly class EmailConversations` with `resolve(Workspace $workspace): bool`:
  false unless `relaticle.features.email_integration` is true, then true when the list contains `*`
  or `$workspace->getKey()`.
- [ ] Create trait `HasConversationsFlag` with `protected function conversationsEnabled(?Workspace $workspace): bool`,
  returning false for null and `Feature::for($workspace)->active(EmailConversations::class)` otherwise.
  Page and record tab both use it. The resolver calls `Feature::for()` directly because it is not a
  Filament class.
- [ ] Add to `EmailIntegrationFeatureFlagTest.php`:

```php
it('turns conversations on only for the listed workspaces', function (): void {
    $listed = Workspace::factory()->create();
    $other = Workspace::factory()->create();

    config()->set('relaticle.features.email_integration', true);
    config()->set('relaticle.features.email_conversations', [$listed->id]);

    expect(Feature::for($listed)->active(EmailConversations::class))->toBeTrue()
        ->and(Feature::for($other)->active(EmailConversations::class))->toBeFalse();
});

it('keeps conversations off while email integration is off', function (): void {
    $workspace = Workspace::factory()->create();

    config()->set('relaticle.features.email_integration', false);
    config()->set('relaticle.features.email_conversations', ['*']);

    expect(Feature::for($workspace)->active(EmailConversations::class))->toBeFalse();
});
```

**Check:** `php artisan test --compact tests/Feature/EmailIntegration/EmailIntegrationFeatureFlagTest.php`.
If Pennant caches across the two `config()->set` calls, add `Feature::flushCache()` after them and keep
it; do not move the config read out of `resolve()`.

### Task 2: A thin All view: enum, data, query, page

The first end-to-end slice: the All view lists one row per conversation the viewer may see, rendered
by a minimal row. Later tasks deepen each layer.

**Files:** `src/Enums/ConversationView.php`, `src/Data/ConversationFilters.php`,
`src/Data/ConversationRow.php`, `src/Queries/ConversationsQuery.php`, `EmailInboxPage.php`,
`email-inbox.blade.php`, `components/conversation-row.blade.php`, `ConversationsPageTest.php`.

- [ ] `ConversationView: string implements HasLabel` with cases `NeedsReply = 'needs_reply'`,
  `AwaitingReply = 'awaiting_reply'`, `All = 'all'`, `Unlinked = 'unlinked'`, `Automated = 'automated'`.
  Methods, each a `match` with no `default`: `getLabel()` through `__('filament/pages/email-inbox.views.<value>')`,
  `isPrimary(): bool` (Automated is secondary), `requiresOwnCopy(): bool` (NeedsReply, AwaitingReply,
  Unlinked), `includesAutomated(): bool` (Automated only). Constant
  `AWAITING_REPLY_BUSINESS_DAYS = 3`.
- [ ] `ConversationFilters` (`final readonly`, promoted, all nullable): `mailboxId`, `direction`
  (`EmailDirection`), `hasAttachment`, `sentAfter`, `sentBefore` (`CarbonImmutable`), `recordType`
  (`CrmEntity`, only `Company`, `People`, `Opportunity`), `recordId`, `category`, `search`. Named
  constructor `forRecord(Company|People|Opportunity $record): self`, reading the record's
  `CrmEntity` through `CrmEntity::morphMap()` keys, because `emailables.emailable_type` stores those
  aliases (`app/Providers/AppServiceProvider.php:688-691`).
- [ ] `ConversationRow` (`final readonly`): `conversationKey`, `viewerCopyEmailId`, `subject`
  (`?string`), `snippet` (`?string`), `senderNames` (`list<string>`), `participants`
  (`list<string>`), `messageCount`, `lastMessageAt` (`CarbonImmutable`), `hasAttachments`,
  `isUnread`, `ownerUserId` (`?string`), `ownerName` (`?string`), `access` (`EmailPrivacyTier`),
  `viewerHasPendingRequest`, `records` (`list<array{type: string, id: string, name: string}>`),
  `automatedReason` (`?AutomatedReason`). Docblock carries the array shapes only.
- [ ] `ConversationsQuery` (`final readonly`), constructor-injected `PrivacyService`,
  `PreferredEmailCopyService`, `EmailSearchService`. Public:
  `paginate(User $viewer, ConversationView $view, ConversationFilters $filters, int $perPage = 50, ?string $cursor = null): CursorPaginator`,
  `counts(User $viewer): array`, `messages(User $viewer, string $conversationKey): Collection`.
  First line of each: `Gate::forUser($viewer)->authorize('viewAny', Email::class)`, the sibling of
  the `viewAny` rule in `.ai/rules/queries.md`. Private steps, each under 60 lines:
  - `conversations(User, ConversationView, ConversationFilters): Builder<EmailConversation>`:
    `EmailConversation::query()->where('workspace_id', $viewer->current_workspace_id)` plus
    `whereExists($this->visibleCopiesOf($viewer, $filters)->whereColumn('emails.conversation_key', 'email_conversations.conversation_key')->toBase())`,
    then the view predicate. All excludes `is_automated`.
  - `visibleCopiesOf(User, ConversationFilters): Builder<Email>`: `Email::query()->withGlobalScope('visible', new VisibleEmailScope($viewer))`
    with `status in (synced, sent)`, the same delivered rule as
    `VisibleEmailsQuery::whereDelivered()` (`src/Queries/VisibleEmailsQuery.php:143-150`), plus each
    set filter. `search` calls `EmailSearchService::applyToQuery()` on this builder, so the tier gate of
    `.ai/rules/email-integration.md` ("Privacy-aware email search") holds.
  - `rows(User, Collection<int, EmailConversation>): Collection<int, ConversationRow>`: runs the
    page's hydration queries (next bullet) and maps each key to a row.
  - `newestVisibleCopies(User, list<string> $keys)`: one query,
    `selectRaw('distinct on (emails.conversation_key) emails.*')` ordered by `conversation_key`,
    `sent_at desc`, own copy first, `EmailPrivacyTier::opennessRanking()` desc, `id desc`, with
    `participants`, `shares`, `user`, `connectedAccount.user`, `labels`, plus
    `withExists` aliases `viewer_has_synced_copy` and `viewer_has_pending_access_request`, and PR 3's
    `withRecordLinkState()` so `Email::isLinkedToRecord()` reads the preloaded attribute
    (`2026-10-31-email-conversations-a-foundations.md:664`) instead of querying per row.
  - `visibleMessageStats(User, list<string>)`: one grouped query returning per key the count of
    `distinct coalesce(rfc_message_id, id)` and `max(sent_at)` among visible copies.
- [ ] Order `last_message_at desc, id desc` and call `cursorPaginate($perPage, ['*'], 'cursor', $cursor ? Cursor::fromEncoded($cursor) : null)`.
  Then replace the collection with `rows()`. `simplePaginate` and `paginate` are not used: both page
  by offset.
- [ ] `PrivacyService::effectiveTier()` calls `PreferredEmailCopyService::viewerHasSyncedCopy()`
  (`src/Services/PrivacyService.php:40`), which queries per teammate row
  (`src/Services/PreferredEmailCopyService.php:54-69`). Make `viewerHasSyncedCopy()` return the
  loaded `viewer_has_synced_copy` attribute when the model has it. That keeps one owner of the tier
  rule and removes the per-row query. Task 5's query-count test proves it.
- [ ] Page: add `use HasConversationsFlag`, `#[Url(as: 'view')] public ?ConversationView $view = null`,
  change `#[Url(as: 'tab')] public ?EmailPageTab $tab = null`, and in `mount()` set
  `$this->tab ??= EmailPageTab::DRAFTS` when the flag is off, so the flag-off page and
  `DestinationResolver`'s `?tab=drafts` links (`packages/Chat/src/Support/DestinationResolver.php:103-104`)
  keep working. `setView(string)` sets the view, clears `tab` and the cursor. `setTab()` clears `view`.
  Computed `conversations()` returns `ConversationsQuery::paginate(...)`. In this task, the blade
  renders the All rows with a minimal `conversation-row` (subject or "Subject hidden", time,
  `data-conversation`, `data-message-count`).
- [ ] Add the first two tests to `ConversationsPageTest.php`:

```php
it('shows a conversation held by two mailboxes once, through the viewer own copy', function (): void {
    $ownerCopy = conversationsPageMessage($this->ownerMailbox, 'conv-pricing', 'brian@airbnb.test', [
        'rfc_message_id' => '<pricing-1@airbnb.test>',
        'subject' => 'Pricing for 40 seats',
    ]);
    $teammateCopy = conversationsPageMessage($this->teammateMailbox, 'conv-pricing', 'brian@airbnb.test', [
        'rfc_message_id' => '<pricing-1@airbnb.test>',
        'subject' => 'Pricing for 40 seats',
    ]);
    $this->company->emails()->attach([$ownerCopy->id, $teammateCopy->id]);

    $html = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value)->html();

    expect(conversationsPageKeys($html))->toBe(['conv-pricing'])
        ->and($html)->toContain("selectEmail('{$ownerCopy->id}')")
        ->and($html)->toContain('data-message-count="1"')
        ->and($html)->not->toContain($teammateCopy->id);
});

it('never shows a teammate message that is linked to no record, alone or inside a shared conversation', function (): void {
    conversationsPageMessage($this->teammateMailbox, 'conv-ben-only', 'olivia@stripe.test', [
        'subject' => 'Offer letter for Ben',
    ]);
    conversationsPageMessage($this->ownerMailbox, 'conv-mixed', 'brian@airbnb.test', [
        'subject' => 'Renewal terms',
        'sent_at' => now()->subHour(),
    ]);
    conversationsPageMessage($this->teammateMailbox, 'conv-mixed', 'brian@airbnb.test', [
        'subject' => 'Re: Renewal terms',
        'snippet' => 'Our floor is 18 dollars a seat',
    ]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertDontSee('Offer letter for Ben')
        ->assertDontSeeHtml('data-conversation="conv-ben-only"')
        ->assertSeeHtml('data-conversation="conv-mixed"')
        ->assertSee('Renewal terms')
        ->assertDontSee('Our floor is 18 dollars a seat')
        ->assertSeeHtml('data-message-count="1"');
});
```

The second test fails when the count or the snippet comes from the summary row (note 5), and when
PR 3's rule is missing from the visibility predicate.

**Check:** `php artisan test --compact tests/Feature/EmailIntegration/ConversationsPageTest.php` and
`tests/Feature/EmailIntegration/EmailPageTabsTest.php` (flag off, unchanged expectations).

### Task 3: The other views, counts and the default

**Files:** `ConversationsQuery.php`, `EmailInboxPage.php`, `email-inbox.blade.php`,
`components/view-tab.blade.php`, `lang/*/filament/pages/email-inbox.php`, `ConversationsPageTest.php`.

- [ ] View predicates in `ConversationsQuery::conversations()`, each a private method named for the
  view:
  - `requiresOwnCopy()` views add `whereExists(emails where workspace_id, conversation_key match and user_id = viewer)`.
  - NeedsReply: `last_external_inbound_at is not null and (last_member_outbound_at is null or last_external_inbound_at > last_member_outbound_at) and is_automated = false`.
  - AwaitingReply: `last_member_outbound_at < :cutoff`, `(last_external_inbound_at is null or last_external_inbound_at < last_member_outbound_at)`,
    `is_automated = false`, and an exists over the viewer's own outbound copy whose `sent_at` equals
    `last_member_outbound_at`. `:cutoff` is
    `now()->setTimezone($viewer->timezone ?? config('app.timezone'))->subWeekdays(ConversationView::AWAITING_REPLY_BUSINESS_DAYS)->utc()`.
  - Unlinked: `whereNotExists(emailables join emails on email_id where emails.workspace_id and conversation_key match)`, automated excluded.
  - Automated: `is_automated = true`.
- [ ] `counts()` returns `['needs_reply' => int, 'unlinked' => int, 'automated' => int]`, three
  `count()` calls over `conversations()` with an empty `ConversationFilters`. No caching here: PR 11
  owns the cache.
- [ ] Page: computed `viewCounts()` and `activeView()`. `activeView()` returns `$this->view` when set,
  else `NeedsReply` when `viewCounts['needs_reply'] > 0`, else `All`. The resolved default is not
  written to the URL, so a shared link without `?view=` always opens on the default (D2 drops
  "remember the last view").
- [ ] Blade: primary tabs left (Needs reply with its count, Awaiting reply, All, Unlinked), secondary
  right in smaller type (Automated with its count, a divider, then the four `EmailPageTab` tabs with
  their badges as today). `view-tab` mirrors `page-tab.blade.php`, calls `setView`, and carries
  `data-view` and `aria-current="page"` when active. The row keeps `overflow-x-auto` so 390px scrolls.
- [ ] Copy in `lang/en/filament/pages/email-inbox.php` under `views`: `needs_reply` "Needs reply",
  `awaiting_reply` "Awaiting reply", `all` "All", `unlinked` "Unlinked", `automated` "Automated", and
  empty states per view: Needs reply "Nothing is waiting on you.", Awaiting reply "No one owes you a
  reply.", Unlinked "Every conversation is linked to a record.", Automated "No automated mail.", All
  "No conversations yet." Mirror every key in `lang/fr`.
- [ ] Tests:

```php
it('lists a conversation in Needs reply until any member answers it', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-waiting', 'brian@airbnb.test', ['sent_at' => now()->subHours(3)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'kim@notion.test', ['sent_at' => now()->subHours(3)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'ana@acme.test', ['sent_at' => now()->subHour()]);
    $inbound = conversationsPageMessage($this->ownerMailbox, 'conv-ben-answered', 'olivia@stripe.test', ['sent_at' => now()->subHours(3)]);
    $reply = conversationsPageMessage($this->teammateMailbox, 'conv-ben-answered', 'ben@acme.test', ['sent_at' => now()->subHour()]);
    $this->company->emails()->attach([$inbound->id, $reply->id]);
    conversationsPageMessage($this->ownerMailbox, 'conv-newsletter', 'news@substack.test', ['automated_reason' => AutomatedReason::List]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::NeedsReply->value)
        ->assertSeeHtml('data-conversation="conv-waiting"')
        ->assertDontSeeHtml('data-conversation="conv-answered"')
        ->assertDontSeeHtml('data-conversation="conv-ben-answered"')
        ->assertDontSeeHtml('data-conversation="conv-newsletter"');
});

it('brings a conversation back to Needs reply when the customer writes again', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'brian@airbnb.test', ['sent_at' => now()->subHours(3)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'ana@acme.test', ['sent_at' => now()->subHours(2)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'brian@airbnb.test', ['sent_at' => now()->subHour()]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::NeedsReply->value)
        ->assertSeeHtml('data-conversation="conv-thread"');
});

it('leaves a teammate linked conversation out of Needs reply but keeps it in All', function (): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-ben-lead', 'olivia@stripe.test', ['subject' => 'Demo next week']);
    $this->company->emails()->attach($email->id);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::NeedsReply->value)
        ->assertDontSeeHtml('data-conversation="conv-ben-lead"')
        ->call('setView', ConversationView::All->value)
        ->assertSeeHtml('data-conversation="conv-ben-lead"');
});

it('opens on All when nothing needs a reply and on Needs reply once something does', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'kim@notion.test', ['sent_at' => now()->subHours(2)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'ana@acme.test', ['sent_at' => now()->subHour()]);

    expect(livewire(EmailInboxPage::class)->instance()->activeView)->toBe(ConversationView::All);

    conversationsPageMessage($this->ownerMailbox, 'conv-waiting', 'brian@airbnb.test');

    livewire(EmailInboxPage::class)
        ->assertSeeHtml('data-conversation="conv-waiting"')
        ->assertDontSeeHtml('data-conversation="conv-answered"');
});

it('keeps an empty Needs reply view the viewer chose in the URL', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'kim@notion.test', ['sent_at' => now()->subHours(2)]);
    conversationsPageMessage($this->ownerMailbox, 'conv-answered', 'ana@acme.test', ['sent_at' => now()->subHour()]);

    Livewire::withQueryParams(['view' => 'needs_reply'])
        ->test(EmailInboxPage::class)
        ->assertSee(__('filament/pages/email-inbox.views.empty.needs_reply'))
        ->assertDontSeeHtml('data-conversation="conv-answered"');
});

it('lists what the viewer sent three business days ago with no answer since', function (): void {
    $this->owner->update(['timezone' => 'America/New_York']);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 15:00', 'America/New_York'));

    foreach (['conv-tuesday' => '2026-10-27 14:00', 'conv-wednesday' => '2026-10-28 16:00'] as $key => $sentAt) {
        conversationsPageMessage($this->ownerMailbox, $key, 'brian@airbnb.test', ['sent_at' => CarbonImmutable::parse($sentAt, 'America/New_York')->subDay()]);
        conversationsPageMessage($this->ownerMailbox, $key, 'ana@acme.test', ['sent_at' => CarbonImmutable::parse($sentAt, 'America/New_York')]);
    }

    $answeredByBen = conversationsPageMessage($this->ownerMailbox, 'conv-ben-followed', 'ana@acme.test', ['sent_at' => now()->subDays(6)]);
    $followUp = conversationsPageMessage($this->teammateMailbox, 'conv-ben-followed', 'ben@acme.test', ['sent_at' => now()->subDays(5)]);
    $this->company->emails()->attach([$answeredByBen->id, $followUp->id]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::AwaitingReply->value)
        ->assertSeeHtml('data-conversation="conv-tuesday"')
        ->assertDontSeeHtml('data-conversation="conv-wednesday"')
        ->assertDontSeeHtml('data-conversation="conv-ben-followed"');

    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/New_York'));

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::AwaitingReply->value)
        ->assertSeeHtml('data-conversation="conv-wednesday"');
});

it('hides automated mail from All behind a counted Automated view', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    conversationsPageMessage($this->ownerMailbox, 'conv-receipt', 'receipts@stripe.test', ['automated_reason' => AutomatedReason::RoleSender]);

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value);

    expect(conversationsPageKeys($page->html()))->toBe(['conv-human'])
        ->and($page->instance()->viewCounts['automated'])->toBe(1);

    expect(conversationsPageKeys($page->call('setView', ConversationView::Automated->value)->html()))->toBe(['conv-receipt']);
});
```

Add one more test in the same shape for Unlinked: an own unlinked conversation shows, an own
linked one and a teammate linked one do not. The 2026-10-30 date is a Friday and 2026-11-02 a Monday, so the second half proves the weekend is
skipped. The first test fails if Needs reply reads only the viewer's own outbound mail instead of
`last_member_outbound_at`.

**Check:** the test file, plus `tests/Feature/EmailIntegration/EmailPageTabsTest.php`.

### Task 4: The conversation row (D6, D7, D9 on the list)

**Files:** `components/conversation-row.blade.php`, `ConversationsQuery.php` (`linkedRecords`,
`senderNames`, `unreadKeys`), `lang/*/filament/pages/email-inbox.php` (`conversation_row`),
`ConversationsPageTest.php`.

- [ ] Query hydration additions, each one query per page:
  - `linkedRecords(User, list<string>)`: `emailables` joined to visible copies of the page's keys,
    grouped per key, ordered company, opportunity, then people, three per key. Names load through
    `Company::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', ...)` and
    its siblings. That is the explicit-filter shape of "Workspace scope (fails closed)", so a queued
    chat tool gets the same chips.
  - `senderNames(User, list<string>)`: `email_participants` with role `from` on visible copies,
    newest first, distinct, three per key. The viewer's own address becomes `__('...conversation_row.me')`.
  - `unreadKeys(User, list<string>)`: keys with an inbound visible copy that reached the viewer (own
    mailbox, or a direct `full` share to them, note 20) and has no `email_reads` row for the viewer.
- [ ] Row fields by tier: `subject` only when `access->showsSubject()`, `snippet` only when
  `access->showsBody()`, `participants` from the newest copy's from and to rows, Bcc only for the
  owner. `ownerUserId` and `ownerName` only when the copy is a teammate's.
- [ ] Component, props `row` (`ConversationRow`) and `selectable` (bool):
  - Root `<div data-conversation="{key}" data-unread="true|false" data-message-count="{n}">`, in that
    attribute order, so tests can match the pair.
  - Line 1: dot (`aria-label` "Unread") when unread, sender names with `(n)` when `n > 1`, the teammate
    avatar with the owner name as `title`, `ri-attachment-2` line icon, time with the relative rules
    from `list-row-wide.blade.php:22-30`.
  - Line 2: subject (semibold when unread), muted snippet hidden below `sm`, chips right-aligned. Up
    to two chips at `sm` and up, one plus "+N" below it. "Unlinked" chip when there are none. Chips
    link to `CompanyResource::getUrl('view', ...)` and its siblings, built in the component, not the
    query.
  - Locked row (`access` below `FULL`): chips and avatar stay. Line 2 reads "Subject hidden" in muted
    text when `access` is `METADATA_ONLY`. The existing `request-access-list-pill` renders below with
    the owner's name, or "Requested". Full rows are a `button` calling `selectEmail('{viewerCopyEmailId}')`.
    Read rows keep normal weight and no opacity change.
  - No "via Name" for the viewer's own mailbox.
- [ ] Page host: add `use HasEmailReaderActions`, `#[Url(as: 'email')] public ?string $selectedEmailId`,
  `selectedEmail()`, `pendingAccessRequests()`, `selectEmail()`, `deselectEmail()` following
  `BaseEmailsRelationManager.php:174-224`, and render `<x-email-integration::reader-overlay>` under the
  list. `selectedEmail()` resolves through `ConversationsQuery::messages()` so the overlay opens only a
  copy the viewer may see.
- [ ] Tests:

```php
it('bolds only conversations whose unread mail reached the viewer', function (): void {
    conversationsPageMessage($this->ownerMailbox, 'conv-own', 'brian@airbnb.test');
    $bens = conversationsPageMessage($this->teammateMailbox, 'conv-ben', 'olivia@stripe.test');
    $this->company->emails()->attach($bens->id);
    $read = conversationsPageMessage($this->ownerMailbox, 'conv-read', 'kim@notion.test');
    EmailRead::factory()->create(['email_id' => $read->id, 'user_id' => $this->owner->id, 'read_at' => now()]);
    $shared = conversationsPageMessage($this->teammateMailbox, 'conv-shared', 'lee@linear.test');
    $this->company->emails()->attach($shared->id);
    EmailShare::factory()->create([
        'email_id' => $shared->id,
        'shared_by' => $this->teammate->id,
        'shared_with' => $this->owner->id,
        'tier' => EmailPrivacyTier::FULL->value,
    ]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertSeeHtml('data-conversation="conv-own" data-unread="true"')
        ->assertSeeHtml('data-conversation="conv-ben" data-unread="false"')
        ->assertSeeHtml('data-conversation="conv-read" data-unread="false"')
        ->assertSeeHtml('data-conversation="conv-shared" data-unread="true"');
});

it('renders a teammate row only at the tier the viewer holds', function (EmailPrivacyTier $tier, bool $showsSubject, bool $showsSnippet): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-tier', 'olivia@stripe.test', [
        'subject' => 'Security review findings',
        'snippet' => 'Two critical issues remain open',
        'privacy_tier' => $tier,
    ]);
    $this->company->emails()->attach($email->id);

    $html = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value)->html();

    expect(str_contains($html, 'Security review findings'))->toBe($showsSubject)
        ->and(str_contains($html, 'Two critical issues remain open'))->toBe($showsSnippet)
        ->and($html)->toContain('Airbnb')
        ->and(str_contains($html, "selectEmail('{$email->id}')"))->toBe($tier === EmailPrivacyTier::FULL);
})->with([
    'metadata' => [EmailPrivacyTier::METADATA_ONLY, false, false],
    'subject' => [EmailPrivacyTier::SUBJECT, true, false],
    'full' => [EmailPrivacyTier::FULL, true, true],
]);

it('offers Request access on a locked teammate row', function (): void {
    $email = conversationsPageMessage($this->teammateMailbox, 'conv-locked', 'olivia@stripe.test', [
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($email->id);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->assertSee(__('filament/pages/email-inbox.subject.hidden'))
        ->assertActionVisible('requestAccess', ['emailId' => $email->id]);
});
```

**Check:** the test file. Confirm `EmailShareFactory` fills `workspace_id` from the email; pass it if not.

### Task 5: Cursor pagination and a fixed query count

**Files:** `EmailInboxPage.php`, `email-inbox.blade.php`, `ConversationsPageTest.php`.

- [ ] Page: `public ?string $conversationsCursor = null`, `nextConversationsPage()` and
  `previousConversationsPage()` reading `nextCursor()?->encode()` and `previousCursor()?->encode()`
  from the paginator. `setView()` and `updatedSearch()` reset the cursor. The footer shows "Newer"
  and "Older" buttons and no total, because a total is a `count(*)`.
- [ ] Tests:

```php
it('pages through conversations that share a timestamp with no repeats and no gaps', function (): void {
    $this->travelTo(now()->startOfMinute());

    foreach (range(1, 49) as $index) {
        conversationsPageMessage($this->ownerMailbox, "conv-{$index}", "c{$index}@client.test", ['sent_at' => now()->subMinutes($index)]);
    }

    foreach (['tie-a', 'tie-b', 'tie-c'] as $key) {
        conversationsPageMessage($this->ownerMailbox, $key, 'tie@client.test', ['sent_at' => now()->subHours(2)]);
    }

    $page = livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value);
    $first = conversationsPageKeys($page->html());
    $second = conversationsPageKeys($page->call('nextConversationsPage')->html());
    $back = conversationsPageKeys($page->call('previousConversationsPage')->html());

    expect($first)->toHaveCount(50)
        ->and($second)->toHaveCount(2)
        ->and(array_intersect($first, $second))->toBe([])
        ->and(array_unique([...$first, ...$second]))->toHaveCount(52)
        ->and($back)->toBe($first);
});

it('renders a page of conversations in the same number of queries for 3 or 20 rows', function (): void {
    $render = function (): int {
        app()->forgetScopedInstances();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        livewire(EmailInboxPage::class)->call('setView', ConversationView::All->value);

        return $queries;
    };

    $seed = function (int $from, int $to): void {
        foreach (range($from, $to) as $index) {
            $email = conversationsPageMessage($index % 2 === 0 ? $this->ownerMailbox : $this->teammateMailbox, "conv-{$index}", "c{$index}@client.test");
            $this->company->emails()->attach($email->id);
        }
    };

    $seed(1, 3);
    $few = $render();
    $seed(4, 20);

    expect($render())->toBe($few);
});
```

The tie rows straddle the 50-row boundary, so a cursor on `last_message_at` alone repeats or skips
one. The query-count test mixes teammate rows on purpose: it fails while `viewerHasSyncedCopy()` still
queries per row (Task 2).

**Check:** the test file.

### Task 6: Record Emails tabs on the same query

**Files:** `BaseEmailsRelationManager.php`, `emails-relation-manager.blade.php`,
`RecordEmailsConversationsTest.php`.

- [ ] `use HasConversationsFlag`. Make `selectedEmailId` `#[Url(as: 'email')]` in both flag states;
  a deep link then works on every tab (decisions D4, "smallest first step").
- [ ] Add `public bool $showAutomated = false` and computed `conversations()` calling
  `ConversationsQuery::paginate($user, $this->showAutomated ? ConversationView::Automated : ConversationView::All, ConversationFilters::forRecord($record) with the search, 20, $this->conversationsCursor)`,
  and computed `automatedCount()` reading `counts($user, ConversationFilters::forRecord($record))['automated']`.
  So `counts()` takes an optional second argument, `?ConversationFilters $filters = null`. The page
  calls it without one. PR 5 adds that argument to the master contract row.
- [ ] `hidesRecordMailbox()` (line 153-157) still short-circuits to the protected state first. The
  connect prompt (`showConnectPrompt()`) stays as is.
- [ ] Blade: under the flag, render `conversation-row` for each row, a "Show N automated" toggle
  next to the search bar when `automatedCount > 0`, and Newer and Older buttons. The flag-off branch
  keeps `list-row-wide` and the current offset pagination.
- [ ] `selectedEmail()` under the flag resolves through `ConversationsQuery::messages()` for the
  copy's key, still returning null below `viewBody` until PR 6.
- [ ] Tests in `RecordEmailsConversationsTest.php` (same setup, `mutates(BaseEmailsRelationManager::class, ConversationsQuery::class)`):

```php
it('lists each conversation linked to the company once', function (): void {
    $first = conversationsRecordMessage($this->ownerMailbox, 'conv-renewal', 'brian@airbnb.test', ['rfc_message_id' => '<r1@airbnb.test>']);
    $copy = conversationsRecordMessage($this->teammateMailbox, 'conv-renewal', 'brian@airbnb.test', ['rfc_message_id' => '<r1@airbnb.test>']);
    $reply = conversationsRecordMessage($this->ownerMailbox, 'conv-renewal', 'ana@acme.test');
    $this->company->emails()->attach([$first->id, $copy->id, $reply->id]);

    $html = livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])->html();

    expect(conversationsRecordKeys($html))->toBe(['conv-renewal'])
        ->and($html)->toContain('data-message-count="2"');
});

it('shows a teammate conversation on the company only while it is linked there', function (): void {
    $linked = conversationsRecordMessage($this->teammateMailbox, 'conv-linked', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($linked->id);
    conversationsRecordMessage($this->teammateMailbox, 'conv-elsewhere', 'olivia@airbnb.test', ['subject' => 'Lunch on Friday']);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSeeHtml('data-conversation="conv-linked"')
        ->assertDontSee('Contract redlines')
        ->assertDontSeeHtml('data-conversation="conv-elsewhere"')
        ->assertDontSee('Lunch on Friday');
});

it('keeps automated mail behind a counted toggle on the record', function (): void {
    $human = conversationsRecordMessage($this->ownerMailbox, 'conv-human', 'brian@airbnb.test');
    $invoice = conversationsRecordMessage($this->ownerMailbox, 'conv-invoice', 'billing@airbnb.test', ['automated_reason' => AutomatedReason::RoleSender]);
    $this->company->emails()->attach([$human->id, $invoice->id]);

    $tab = livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class]);

    expect(conversationsRecordKeys($tab->html()))->toBe(['conv-human']);
    $tab->assertSee(trans_choice('filament/pages/record-emails.automated_toggle', 1, ['count' => 1]));
    expect(conversationsRecordKeys($tab->set('showAutomated', true)->html()))->toBe(['conv-invoice']);
});
```

The helpers are the shared ones renamed with the `conversationsRecord` prefix.

**Check:** the new file, plus every existing `RecordEmails*Test.php` and
`EmailsRelationManagerReadingTest.php` with the flag off.

### Task 7: The resolver route and stable links

**Files:** `OpenEmailController.php`, `routes/web.php`, `Email.php`, the three notification and inbox
callers, `EmailForAgent.php`, `EmailAccessNotificationHandler.php`,
`EmailIntegrationServiceProvider.php`, `OpenEmailRouteTest.php`, `EmailAccessNotificationHandlerTest.php`,
`EmailSharedNotificationTest.php`, `tests/Feature/Mcp/EmailToolsTest.php`, `tests/Feature/Chat/EmailToolsTest.php`.

- [ ] Route in the existing `['web', 'auth', 'verified']` group:
  `Route::get('/email/open/{email}', OpenEmailController::class)->name('email.open')->whereUlid('email')->middleware('throttle:60,1')`.
  No implicit model binding: binding would 404 differently for a trashed mailbox.
- [ ] `final class OpenEmailController`, `__invoke(Request $request, string $email, ConversationsQuery $conversations): RedirectResponse`.
  Steps, each a private method:
  1. Load `Email::query()->withoutGlobalScope(ActiveAccountScope::class)->whereKey($email)->first()`. Null, or the user
     not a member of its workspace (`belongsToWorkspaceId`), aborts 404. So does a null
     `conversation_key` with the flag on (note 7).
  2. Bind the workspace in memory, as `SetApiWorkspaceContext` does
     (`app/Http/Middleware/SetApiWorkspaceContext.php:61-65`): `forceFill(['current_workspace_id' => ...])`
     and `setRelation('currentWorkspace', ...)`. Never `switchWorkspace()`.
  3. Flag off: redirect to `Dashboard::getUrl(viewBody ? ['email' => id] : [], panel: 'app', tenant: $workspace)`,
     which is today's `appUrl()` target, so old links behave the same.
  4. Flag on: `$conversations->messages($user, $email->conversation_key)`; take the copy with the same
     `rfc_message_id` (own copy first, by the query's ordering) or the email itself. None aborts 404.
  5. Redirect to the first linked company, then opportunity, then person of that copy, via
     `CompanyResource::getUrl('view', ['record' => $record, 'relation' => $key, 'email' => $copy->id], tenant: $workspace)`,
     where `$key` is `(string) array_search(EmailsRelationManager::class, CompanyResource::getRelations(), true)`
     (the `relation` query key is the manager's index,
     `vendor/filament/filament/src/Resources/Pages/Concerns/HasRelationManagers.php:22-23`), and the same with `OpportunityResource` and `PeopleResource`. With no
     linked record, redirect to `EmailInboxPage::getUrl(['view' => 'all', 'email' => $copy->id], tenant: $workspace)`.
- [ ] `Email::appUrl(): string` returns `route('email.open', $this)`. Delete `appUrlFor()` and move the
  four callers in the note 15 list to `appUrl()`.
- [ ] `EmailForAgent::summaryAt()` gains `'url' => $email->appUrl()`. Run `SurfaceParityTest` after.
- [ ] Handler: `#[Locked] public bool $hostsReader = false`. `mount()` skips `openEmailReader()` and
  `selectedEmail()` returns null when it is true, without touching `selectedEmailId`, so the host's
  `?email=` stays in the URL. `selectEmail()` (the bell) on a host page dispatches
  `email:open` with the id instead, and both hosts listen with `#[On('email:open')]`.
- [ ] Provider render hook (line 153-171): the closure takes `array $scopes` and passes
  `hostsReader: Feature::for($tenant)->active(EmailConversations::class) && array_intersect($scopes, [ViewCompany::class, ViewPeople::class, ViewOpportunity::class]) !== []`.
  `EmailInboxPage` joins the list in PR 6.
- [ ] Tests in `OpenEmailRouteTest.php` (`mutates(OpenEmailController::class)`):

```php
it('opens a linked email on the first linked company Emails tab', function (): void {
    $email = conversationsRouteMessage($this->ownerMailbox, 'conv-route', 'brian@airbnb.test');
    $this->company->emails()->attach($email->id);

    $relation = (string) array_search(CompanyEmailsRelationManager::class, CompanyResource::getRelations(), true);

    $this->get(route('email.open', $email))
        ->assertRedirect(CompanyResource::getUrl('view', ['record' => $this->company, 'relation' => $relation, 'email' => $email->id], tenant: $this->workspace));
});

it('opens an unlinked email on the Emails page', function (): void {
    $email = conversationsRouteMessage($this->ownerMailbox, 'conv-loose', 'zoe@figma.test');

    $this->get(route('email.open', $email))
        ->assertRedirect(EmailInboxPage::getUrl(['view' => 'all', 'email' => $email->id], tenant: $this->workspace));
});

it('opens the viewer own copy when a link names a teammate copy of the same message', function (): void {
    $bens = conversationsRouteMessage($this->teammateMailbox, 'conv-both', 'brian@airbnb.test', ['rfc_message_id' => '<both@airbnb.test>']);
    $mine = conversationsRouteMessage($this->ownerMailbox, 'conv-both', 'brian@airbnb.test', ['rfc_message_id' => '<both@airbnb.test>']);

    $this->get(route('email.open', $bens))
        ->assertRedirectContains("email={$mine->id}");
});

it('answers missing, private, unlinked teammate and foreign mail with the same not found page', function (): void {
    $private = conversationsRouteMessage($this->teammateMailbox, 'conv-private', 'olivia@stripe.test', [
        'subject' => 'Private compensation thread',
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
    ]);
    $this->company->emails()->attach($private->id);
    $unlinked = conversationsRouteMessage($this->teammateMailbox, 'conv-unlinked', 'olivia@stripe.test', ['subject' => 'Ben side project']);
    $stranger = User::factory()->withWorkspace()->create();
    $foreign = conversationsRouteMessage(conversationsRouteMailbox($stranger, 'sam@other.test'), 'conv-foreign', 'x@y.test', ['subject' => 'Other workspace deal']);

    foreach ([(string) Str::ulid(), $private->id, $unlinked->id, $foreign->id] as $id) {
        $this->get(route('email.open', $id))
            ->assertNotFound()
            ->assertDontSee('Private compensation thread')
            ->assertDontSee('Ben side project')
            ->assertDontSee('Other workspace deal');
    }
});
```

- [ ] Add a flag-off case in the same file: `Feature::for($this->workspace)->deactivate(...)`, then the
  route redirects to `Dashboard::getUrl(['email' => $email->id], panel: 'app', tenant: $this->workspace)`.
- [ ] Extend `EmailAccessNotificationHandlerTest.php`:

```php
it('leaves the reader to the host page when the page hosts one', function (): void {
    Livewire::withQueryParams(['email' => $this->email->getKey()])
        ->test(EmailAccessNotificationHandler::class, ['hostsReader' => true])
        ->assertSet('selectedEmailId', $this->email->getKey())
        ->assertDontSeeHtml('fi-email-reader-panel')
        ->call('selectEmail', $this->email->getKey())
        ->assertDispatched('email:open', emailId: $this->email->getKey());
});
```

- [ ] Update `EmailSharedNotificationTest.php` and the two `EmailToolsTest.php` files to expect
  `route('email.open', $email)`.

**Check:** the five test files, then `php artisan test --compact tests/Feature/CRM/SurfaceParityTest.php`.

### Task 8: Query performance and indexes

**Files:** `database/migrations/2026_11_02_000000_index_email_conversations_for_views.php` (only what
the EXPLAIN shows is needed), the PR body.

- [ ] Build a scratch database shaped like the largest production workspace: `createdb
  relaticle_explain`, `DB_DATABASE=relaticle_explain php artisan migrate --force`, then one
  `psql relaticle_explain` session that inserts, with `generate_series` and no app code: one
  workspace, two members, an owner mailbox with 140,000 emails and a teammate mailbox with 10,000;
  about 60,000 conversation keys (three copies a key on average, 5% of keys held by both mailboxes);
  46% of emails linked through `emailables` to 2,000 companies; 30% with `automated_reason`; 60% of
  owner inbound mail with an `email_reads` row; teammate mailbox at `metadata_only`. Fill
  `email_conversations` with one `insert ... select ... group by workspace_id, conversation_key`
  instead of PHP, then `analyze`. The script is scratch and is not committed.
- [ ] Capture each statement the page runs with `DB::listen` in a local request, then run
  `explain (analyze, buffers)` for: All, Needs reply, Awaiting reply, Unlinked, Automated, the three
  `counts()` counts, the record tab for the busiest company, `newestVisibleCopies`,
  `visibleMessageStats`, `linkedRecords`, `senderNames`, `unreadKeys`, and `messages()` for a
  40-message conversation. Run each as the owner and as the teammate (the teammate case scans many
  conversations to find 50 visible ones).
- [ ] Indexes to expect from PR 2: `emails (workspace_id, conversation_key)` and
  `email_conversations (workspace_id, last_message_at desc)`. Indexes this PR adds when the plan
  shows a sort or a filter step over more than 1,000 rows, each `create index concurrently` in a
  migration with `public $withinTransaction = false`. The Needs reply index ships in any case, because
  its predicate compares two columns and no plain index can serve it:
  - `email_conversations_workspace_last_message_id_idx` on `(workspace_id, last_message_at desc, id desc)`
    when PR 2's index lacks `id` (note 8).
  - `email_conversations_needs_reply_idx` on `(workspace_id, last_message_at desc, id desc) where last_external_inbound_at is not null and (last_member_outbound_at is null or last_external_inbound_at > last_member_outbound_at) and not is_automated`.
  - `email_conversations_automated_idx` on `(workspace_id, last_message_at desc, id desc) where is_automated`.
  - `emails_workspace_user_conversation_idx` on `(workspace_id, user_id, conversation_key)` only if the
    own-copy exists probe does not stay an index scan on `(workspace_id, conversation_key)`. This one
    touches `emails`, so the master rehearsal rule applies.
- [ ] Budget per statement on the scratch database: under 50 ms each, under 150 ms for the whole
  page's database time. A statement over budget gets a rewrite or an index before merge.
- [ ] Paste every plan's top node, timing and the index it used into the PR body.

**Check:** rerun the EXPLAIN after the migration and confirm each statement names the expected index.
Drop the scratch database.

### Task 9: Local data, UI verification and the PR

**Files:** `database/seeders/EmailConversationsSeeder.php`, `database/seeders/LocalSeeder.php`.

- [ ] `EmailConversationsSeeder` (local only, called from `LocalSeeder::run()` after the personas)
  gives the Acme Sales workspace (owner@relaticle.test) a teammate mailbox, a third member with an
  empty mailbox, and about 40
  conversations: six needing a reply, three awaiting a reply, one held by both mailboxes, two
  teammate rows linked to Airbnb at `metadata_only` and `subject`, four unlinked, five automated
  (newsletter, receipts), and three with attachments. It writes through the factories and
  `RefreshConversationSummary`, with names and subjects that read like a sales team's mail.
- [ ] UI verification with agent-browser, following `.claude/skills/agent-browser-relaticle` for login
  and URL discovery. Set `RELATICLE_FEATURE_EMAIL_CONVERSATIONS` to the Acme Sales id in `.env`, then
  `amp orb service restart app`. Save each capture under `.amp/in/artifacts/pr5/`:
  - 1440 and 390, light and dark: Emails page on Needs reply; All; Automated; Unlinked; the drafts tab.
  - Empty state: empty@relaticle.test shows the connect card; the seeder's third member, whose
    mailbox holds no mail, shows the All empty state after the fallback.
  - Locked row: Airbnb company Emails tab as owner@ showing the teammate row with "Subject hidden"
    and Request access; click it and confirm the modal.
  - 390px: one chip plus "+N", no snippet, tabs scroll sideways.
  - Resolver: open `/email/open/<id>` for a linked and an unlinked email and confirm the landing page
    and the open overlay.
- [ ] WebKit: this PR adds no browser test. Run the existing reader browser test in both engines to
  prove the row and host changes did not break the overlay:
  `php artisan test --compact tests/Browser/EmailIntegration/AccessRequestOpenEmailBrowserTest.php --browser safari`.

## PR 5 checklist

- [ ] Flag off: `EmailPageTabsTest`, `EmailsRelationManagerReadingTest`, every `RecordEmails*Test`,
  `EmailAccessNotificationHandlerTest` pass with no edited expectation.
- [ ] Every conversation test from Tasks 2 to 7 passes.
- [ ] Master contract table edited for notes 4 and 19.
- [ ] `vendor/bin/phpstan analyse` clean: no new ignore, no `MethodLengthRule` entry.
- [ ] `composer test:arch` passes (`ConventionsTest`: `OpenEmailController` suffix, no `Carbon`,
  no `contact` or `deal` labels; `ArchTest`: the query reads no ambient user).
- [ ] EXPLAIN results and the index list in the PR body.
- [ ] Screenshots from Task 9 in the PR body.
- [ ] Rollout note in the PR body: before listing a workspace in `RELATICLE_FEATURE_EMAIL_CONVERSATIONS`,
  run `php artisan email:backfill-conversations --workspace=<id>` and
  `email:backfill-automated-reasons`, and confirm zero null `conversation_key` rows for it with one
  read-only count.

---

# PR 6: The reader drawer

**Branch:** `feat/email-reader-drawer`, from `feat/email-conversations-list` until PR 5 merges.

**Goal:** With the flag on, every reader host (the Emails page, the three record tabs, and the
panel-wide handler on other pages) opens a non-modal right drawer. It shows the whole conversation
at the viewer's tier, the privacy gate for locked copies, prev and next, and keeps the list live. The
URL carries `?email=`. Focus moves in on open and back on close. Below 640px it is full screen.

## File map

| File | Change | Owns |
|---|---|---|
| `resources/views/components/reader-drawer.blade.php`, `reader-thread.blade.php`, `reader-gate.blade.php` | create | frame, thread, gate |
| `resources/views/components/email-view.blade.php` | modify | one expanded message; snippet needs `viewBody` (138) |
| `src/Filament/Concerns/HasConversationReader.php` | create | thread, expanded ids, adjacent rows |
| `src/Filament/Concerns/HasEmailReaderActions.php` | modify | locked copies open at the gate |
| `EmailInboxPage`, `BaseEmailsRelationManager`, `EmailAccessNotificationHandler` and their blades | modify | drawer hosts |
| `src/EmailIntegrationServiceProvider.php`, `resources/views/components/conversation-row.blade.php` | modify | page joins `hostsReader`; locked rows open |
| `lang/en/filament/pages/email-inbox.php`, `lang/fr/...` | modify | `drawer.*` |
| `tests/Feature/EmailIntegration/ConversationsPageTest.php`, `RecordEmailsConversationsTest.php`, `OpenEmailRouteTest.php` | modify | thread, gate, deep links |
| `tests/Browser/EmailIntegration/ConversationDrawerBrowserTest.php` | create | drawer in Chromium and WebKit |

## Tasks

### Task 1: A drawer shell on the record tab

**Files:** `reader-drawer.blade.php`, `BaseEmailsRelationManager.php`, `emails-relation-manager.blade.php`.

- [ ] Copy `reader-overlay.blade.php` to `reader-drawer.blade.php` and change only the frame: drop the
  backdrop (`reader-overlay.blade.php:43-46`) and the centring; root becomes
  `<aside role="dialog" aria-modal="false" aria-labelledby="email-reader-heading" class="fi-email-reader-drawer fixed inset-0 z-40 flex flex-col bg-white shadow-xl ring-1 ring-gray-950/10 sm:inset-y-0 sm:left-auto sm:right-0 dark:bg-gray-900 dark:ring-white/10">`
  with a width style from Task 6. Keep `closeReader()`, the `closing` flag, the Esc guard against open
  modals and dropdowns (line 40) and `wire:key="email-reader-{{ $email->getKey() }}"`, all from
  `c1e284531`, because Alpine `x-show` state does not survive Livewire morphing.
- [ ] Record tab blade: under the flag, render `reader-drawer` and drop the `invisible` class on the
  list (line 19-22). Flag off keeps the overlay and the class.
- [ ] Add to `RecordEmailsConversationsTest.php`:

```php
it('keeps the list visible beside the open reader', function (): void {
    $email = conversationsRecordMessage($this->ownerMailbox, 'conv-open', 'brian@airbnb.test', ['subject' => 'Kickoff agenda']);
    $other = conversationsRecordMessage($this->ownerMailbox, 'conv-other', 'kim@airbnb.test', ['subject' => 'Invoice question']);
    $this->company->emails()->attach([$email->id, $other->id]);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->call('selectEmail', $email->id)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertDontSeeHtml('fi-email-reader-backdrop')
        ->assertSeeHtml('data-conversation="conv-other"')
        ->assertDontSeeHtml('flex flex-col invisible');
});
```

**Check:** the test file and `EmailsRelationManagerReadingTest.php` with the flag off.

### Task 2: The thread

**Files:** `HasConversationReader.php`, `reader-thread.blade.php`, `email-view.blade.php`,
`reader-drawer.blade.php`, both host classes.

- [ ] Trait `HasConversationReader`, used by the page, the record tab and the handler:
  - Computed `thread(): Collection<int, Email>` from `ConversationsQuery::messages($user, $selected->conversation_key)`.
  - `public array $expandedEmailIds = []` (`list<string>`). On open, it holds the newest message and
    the one it replies to (`in_reply_to` match), when both exist.
  - `expandMessage(string $id)` adds the id only when it is in `thread()`. Computed `expandedBodies()`
    loads `body`, `attachments` for expanded ids the viewer may `viewBody`, in one query. A body loads
    once and stays mounted.
  - Opening marks read every message in the thread the viewer may `viewBody`, through
    `MarkEmailAsReadAction` (PR 7 swaps in `MarkConversationsRead`). A copy below `viewBody` is never
    marked read.
- [ ] `reader-thread`: earlier messages as one-line buttons (sender, snippet at tier, date) with
  `wire:click="expandMessage('{id}')"` and `wire:key="thread-row-{id}"`. Expanded messages render
  `email-view` for that copy. The inline composer renders once, after the newest `viewBody` message,
  keyed `inline-reply-composer` as today (`email-view.blade.php:476`). Move that `@livewire` out of
  `email-view` into the thread so a thread never mounts two composers.
- [ ] Drawer header: subject of the newest visible message at its tier, record chips from the row, a
  labelled Reply button (`data-reader-action="reply"`, shown with `viewBody`) that opens the inline
  composer, the existing `detail-action-bar`, and a source line "Visible through Ben's mailbox" for a
  teammate copy.
- [ ] `email-view.blade.php:138`: the snippet line requires `$canViewBody`, not `$canViewSubject`.
- [ ] Tests in `ConversationsPageTest.php`:

```php
it('shows every visible message of the conversation oldest first and loads bodies on expand', function (): void {
    $first = conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'brian@airbnb.test', ['subject' => 'Pilot scope', 'snippet' => 'First pass at scope', 'sent_at' => now()->subHours(3)]);
    EmailBody::factory()->create(['email_id' => $first->id, 'body_text' => 'Body of the first message']);
    $reply = conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'ana@acme.test', ['subject' => 'Re: Pilot scope', 'snippet' => 'Looks good', 'sent_at' => now()->subHours(2)]);
    $last = conversationsPageMessage($this->ownerMailbox, 'conv-thread', 'brian@airbnb.test', ['subject' => 'Re: Pilot scope', 'snippet' => 'One more change', 'sent_at' => now()->subHour(), 'in_reply_to' => $reply->rfc_message_id]);

    livewire(EmailInboxPage::class)
        ->call('selectEmail', $last->id)
        ->assertSeeInOrder(['First pass at scope', 'Looks good', 'One more change'])
        ->assertDontSee('Body of the first message')
        ->call('expandMessage', $first->id)
        ->assertSee('Body of the first message');
});

it('marks read only the messages the viewer can open', function (): void {
    $mine = conversationsPageMessage($this->ownerMailbox, 'conv-mixed-tier', 'brian@airbnb.test', ['sent_at' => now()->subHour()]);
    $bens = conversationsPageMessage($this->teammateMailbox, 'conv-mixed-tier', 'olivia@airbnb.test', ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);
    $this->company->emails()->attach([$mine->id, $bens->id]);

    livewire(EmailInboxPage::class)->call('selectEmail', $bens->id);

    expect(EmailRead::query()->where('user_id', $this->owner->id)->pluck('email_id')->all())->toBe([$mine->id]);
});
```

**Check:** the test file.

### Task 3: The privacy gate and the not-available state

**Files:** `reader-gate.blade.php`, `HasEmailReaderActions.php`, `HasConversationReader.php`,
`conversation-row.blade.php`, `RecordEmailsConversationsTest.php`, `OpenEmailRouteTest.php`.

- [ ] Under the flag, `openEmailReader()` (`HasEmailReaderActions.php:46-64`) keeps the selection for
  any copy in `thread()`, not only `viewBody` ones. Flag off keeps today's early return.
  `selectedEmail()` on each host stops requiring `viewBody` under the flag.
- [ ] `reader-gate`: lock icon, the owner's name, what the viewer can see and why, from
  `PrivacyService::effectiveTier()` (note 14), and a labelled "Request access" button that mounts the
  existing `requestAccess` action with `emailId`, or "Requested" when pending. An optional note field
  is PR 13. The drawer renders the gate for the selected copy when it is below `viewBody`, and for
  each locked expanded message in the thread.
- [ ] Not available: when `?email=` names an id the viewer cannot `view`, the drawer opens with one
  line, "This email is not available to you.", and no subject, owner or date. It never 404s inside a
  host, and it reads the same for missing, private and unlinked teammate mail.
- [ ] Locked rows on the list become buttons that call `selectEmail`, with Request access still on
  hover and focus.
- [ ] Tests:

```php
it('opens a locked teammate conversation at the gate without its subject', function (): void {
    $email = conversationsRecordMessage($this->teammateMailbox, 'conv-gate', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'snippet' => 'Clause 7 is a blocker',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($email->id);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->call('selectEmail', $email->id)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertSee(__('filament/pages/email-inbox.subject.hidden'))
        ->assertDontSee('Contract redlines')
        ->assertDontSee('Clause 7 is a blocker')
        ->assertActionVisible('requestAccess', ['emailId' => $email->id]);

    expect(EmailRead::query()->where('email_id', $email->id)->exists())->toBeFalse();
});

it('deep links to a locked email land on the gate, and to hidden mail on the not available state', function (): void {
    $locked = conversationsRecordMessage($this->teammateMailbox, 'conv-locked', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($locked->id);
    $hidden = conversationsRecordMessage($this->teammateMailbox, 'conv-hidden', 'olivia@airbnb.test', ['subject' => 'Ben side project']);

    Livewire::withQueryParams(['email' => $locked->id])
        ->test(EmailsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => ViewCompany::class])
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertDontSee('Contract redlines');

    Livewire::withQueryParams(['email' => $hidden->id])
        ->test(EmailInboxPage::class)
        ->assertSee(__('filament/pages/email-inbox.drawer.not_available'))
        ->assertDontSee('Ben side project');
});
```

- [ ] Extend `OpenEmailRouteTest.php` with the full loop for a locked email: follow the redirect from
  `route('email.open', $locked)` with `followingRedirects()` and assert the response contains
  "Subject hidden" and not "Contract redlines", in the page body and in `<title>`.

**Check:** the three test files.

### Task 4: The Emails page as a host, URL sync and one reader per page

**Files:** `EmailInboxPage.php`, `email-inbox.blade.php`, `EmailAccessNotificationHandler.php` and its
blade, `EmailIntegrationServiceProvider.php`, `ConversationsPageTest.php`.

- [ ] Page: use `HasConversationReader`, render `reader-drawer` under the flag, keep
  `#[Url(as: 'email')]` from PR 5. `selectEmail()` replaces the `email` query value; Livewire writes it
  with `history.replaceState`, so the back button leaves the page rather than stepping through
  conversations. Opening from the list keeps `?view=` and the cursor.
- [ ] Add `EmailInboxPage::class` to the provider's `hostsReader` scope list.
- [ ] Handler blade: render `reader-drawer` under the flag, so the bell opens the same drawer on the
  Dashboard and every page without a host.
- [ ] `updatedSelectedEmailId()` on each host dispatches `composer:dismiss-inline`, as the handler does
  today (`EmailAccessNotificationHandler.php:67-70`), so a client-side clear (mobile back) saves the
  reply draft.
- [ ] Test:

```php
it('opens the drawer from the URL on the Emails page and clears it on close', function (): void {
    $email = conversationsPageMessage($this->ownerMailbox, 'conv-url', 'brian@airbnb.test', ['subject' => 'Quarterly review']);

    Livewire::withQueryParams(['email' => $email->id, 'view' => 'all'])
        ->test(EmailInboxPage::class)
        ->assertSeeHtml('fi-email-reader-drawer')
        ->assertSee('Quarterly review')
        ->call('deselectEmail')
        ->assertSet('selectedEmailId', null)
        ->assertSet('view', ConversationView::All)
        ->assertDispatched('composer:dismiss-inline');
});
```

**Check:** the test file and `EmailAccessNotificationHandlerTest.php`.

### Task 5: Focus, Esc, prev and next

**Files:** `reader-drawer.blade.php`, `HasConversationReader.php`, `conversation-row.blade.php`,
`ConversationsPageTest.php`.

- [ ] Focus: on mount the drawer focuses its heading (`tabindex="-1"`, id
  `email-reader-heading`). Before opening, the row stores its key in Alpine
  (`$store.emailReader.returnTo`). After `closeReader()`, focus returns to
  `[data-conversation="{key}"]` when it is still in the DOM, else to the list's first row. No focus
  trap: the drawer is non-modal, so Tab moves on into the page.
- [ ] Esc keeps today's guard and route through `closeReader()`, so a typed reply is saved before the
  drawer goes (`reader-overlay.blade.php:22-36`).
- [ ] Prev and next: host methods `openAdjacentConversation(string $direction)` read the current page of
  `ConversationRow`s, find the open key, and select the neighbour's `viewerCopyEmailId`. At either end
  of the loaded page the button is disabled; crossing a page is PR 7's `j` and `k`. Buttons carry
  `aria-label` "Previous conversation" and "Next conversation" and `ri-arrow-up-s-line` and
  `ri-arrow-down-s-line` icons. The open row gets `aria-current="true"`.
- [ ] Test:

```php
it('moves to the next and previous conversation in list order', function (): void {
    $newest = conversationsPageMessage($this->ownerMailbox, 'conv-a', 'a@client.test', ['sent_at' => now()->subMinute()]);
    $middle = conversationsPageMessage($this->ownerMailbox, 'conv-b', 'b@client.test', ['sent_at' => now()->subMinutes(2)]);
    $oldest = conversationsPageMessage($this->ownerMailbox, 'conv-c', 'c@client.test', ['sent_at' => now()->subMinutes(3)]);

    livewire(EmailInboxPage::class)
        ->call('setView', ConversationView::All->value)
        ->call('selectEmail', $middle->id)
        ->call('openAdjacentConversation', 'next')
        ->assertSet('selectedEmailId', $oldest->id)
        ->call('openAdjacentConversation', 'next')
        ->assertSet('selectedEmailId', $oldest->id)
        ->call('openAdjacentConversation', 'previous')
        ->call('openAdjacentConversation', 'previous')
        ->assertSet('selectedEmailId', $newest->id);
});
```

**Check:** the test file.

### Task 6: Width, resize and mobile full screen

**Files:** `reader-drawer.blade.php`.

- [ ] Width: an Alpine `width` in vw, default 55, clamped to 40 to 70, stored in `localStorage`
  under `relaticle.emailReader.width`. Applied as `sm:w-[var(--ei-reader-width)]` with
  `max-width: calc(100vw - var(--sidebar-width, 0px))`. A left-edge handle (`role="separator"`,
  `aria-orientation="vertical"`, arrow keys step by 5) resizes it with pointer events.
- [ ] Below 640px the root is `fixed inset-0`: full screen, handle hidden, the existing back arrow in
  `email-view.blade.php:104-113` closes through `closeReader()` instead of `wire:click="deselectEmail"`
  so the draft is saved.
- [ ] Body scroll is never locked and the drawer scrolls on its own (`overflow-y-auto` on the thread),
  so the list behind it scrolls too.

**Check:** Task 7's browser test at 390px.

### Task 7: Browser tests

**Files:** `tests/Browser/EmailIntegration/ConversationDrawerBrowserTest.php`.

```php
<?php

declare(strict_types=1);

use App\Features\EmailConversations;
use App\Models\Company;
use App\Models\User;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\Email;

mutates(EmailInboxPage::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);
    $this->ownerMailbox = drawerBrowserMailbox($this->owner, 'ana@acme.test');
    $this->teammateMailbox = drawerBrowserMailbox($this->teammate, 'ben@acme.test');
    $this->company = Company::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Airbnb']);

    Feature::for($this->workspace)->activate(EmailConversations::class);
});

it('reads a conversation beside a live list and closes with Escape', function (string $theme): void {
    $first = drawerBrowserMessage($this->ownerMailbox, 'conv-first', 'brian@airbnb.test', ['subject' => 'Pricing for 40 seats']);
    drawerBrowserMessage($this->ownerMailbox, 'conv-second', 'kim@notion.test', ['subject' => 'Security questionnaire', 'sent_at' => now()->subHour()]);

    $page = loginViaBrowser($this->owner, $theme)
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->resize(1440, 900)
        ->click('[data-conversation="conv-first"]')
        ->assertVisible('.fi-email-reader-drawer')
        ->assertMissing('.fi-email-reader-backdrop')
        ->assertScript('new URL(location.href).searchParams.get("email")', $first->id)
        ->assertScript('document.activeElement.id', 'email-reader-heading');

    $page->screenshot(filename: "conversation-drawer-{$theme}");

    $page->click('[data-conversation="conv-second"]')
        ->assertSee('Security questionnaire')
        ->keys('#email-reader-heading', 'Escape')
        ->assertMissing('.fi-email-reader-drawer')
        ->assertScript('document.activeElement.closest("[data-conversation]")?.dataset.conversation', 'conv-second')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('lands a resolver link on the company tab with exactly one reader open', function (string $theme): void {
    $email = drawerBrowserMessage($this->ownerMailbox, 'conv-linked', 'brian@airbnb.test', ['subject' => 'Renewal terms']);
    $this->company->emails()->attach($email->id);

    loginViaBrowser($this->owner, $theme)
        ->navigate(route('email.open', $email, absolute: false))
        ->assertPathIs("/app/{$this->workspace->slug}/companies/{$this->company->id}")
        ->assertSee('Renewal terms')
        ->assertScript('document.querySelectorAll(".fi-email-reader-drawer, .fi-email-reader-panel").length', 1)
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('opens a locked row at the gate with Request access', function (string $theme): void {
    $email = drawerBrowserMessage($this->teammateMailbox, 'conv-locked', 'olivia@airbnb.test', [
        'subject' => 'Contract redlines',
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->company->emails()->attach($email->id);

    $page = loginViaBrowser($this->owner, $theme)
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-locked"]')
        ->assertVisible('.fi-email-reader-drawer')
        ->assertDontSee('Contract redlines')
        ->assertSee(__('filament/pages/record-emails.actions.request_access.label'));

    $page->screenshot(filename: "conversation-drawer-gate-{$theme}");
    $page->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);

it('fills the screen on a phone and saves a typed reply when it closes', function (string $theme): void {
    drawerBrowserMessage($this->ownerMailbox, 'conv-phone', 'brian@airbnb.test', ['subject' => 'Can we talk Monday']);

    $page = loginViaBrowser($this->owner, $theme)
        ->resize(390, 844)
        ->navigate("/app/{$this->workspace->slug}/email?view=all")
        ->click('[data-conversation="conv-phone"]')
        ->assertScript('Math.round(document.querySelector(".fi-email-reader-drawer").getBoundingClientRect().width) === window.innerWidth', true);

    $page->screenshot(filename: "conversation-drawer-phone-{$theme}");

    $page->click('[data-reader-action="reply"]')
        ->type('[data-inline-composer] [contenteditable="true"]', 'Monday at 10 works')
        ->keys('[data-inline-composer] [contenteditable="true"]', 'Escape')
        ->keys('#email-reader-heading', 'Escape')
        ->assertMissing('.fi-email-reader-drawer')
        ->assertNoJavaScriptErrors();

    expect(Email::query()->where('user_id', $this->owner->id)->where('status', EmailStatus::DRAFT)->exists())->toBeTrue();
})->with(['inLightMode', 'inDarkMode']);
```

The helpers `drawerBrowserMailbox()` and `drawerBrowserMessage()` are the shared helpers under this
file's prefix. Confirm the editor selector against `email-composer.blade.php` before the first run,
and adjust the selector, not the behaviour, if it differs. The second test fails while the handler and the record
tab both open a reader (note 11).

**Check:** `php artisan test --compact tests/Browser/EmailIntegration/ConversationDrawerBrowserTest.php`,
then the same with `--browser safari`. Run `pnpm exec playwright install webkit` once if WebKit is
missing. Also rerun `tests/Browser/EmailIntegration/AccessRequestOpenEmailBrowserTest.php` in both
engines: with the flag off it must still find `.fi-email-reader-panel`.

### Task 8: Performance, UI verification and the PR

- [ ] Query performance on the PR 5 scratch database (rebuild it the same way if it was dropped):
  `explain (analyze, buffers)` for `messages()` on a 40-message conversation held by both mailboxes,
  `expandedBodies()` for two ids, and the mark-read writes. Budget: 50 ms for `messages()` using
  `emails (workspace_id, conversation_key)`, 20 ms for the body load by primary key. Paste the plans
  in the PR body.
- [ ] Measure the drawer open in the browser test run with `performance.now()` around the click and
  the heading becoming visible. Target under 400 ms locally (spec latency table, "Open drawer, not
  prefetched"). Record the number; prefetch is PR 11.
- [ ] agent-browser, following `.claude/skills/agent-browser-relaticle`, with the PR 5 seed, captures
  under `.amp/in/artifacts/pr6/`, 1440 and 390, light and dark:
  - Emails page with the drawer open on a five-message thread, list visible behind it.
  - Record tab (Airbnb) with the drawer open; click a second row while open.
  - Locked row: the gate with Request access, then the Requested state after submitting.
  - Not available: `/app/<slug>/email?email=<hidden id>`.
  - Empty state: a member with no mail opens the page; no drawer, All empty state.
  - Resize: drag to the 40% and 70% stops; reload keeps the width.
  - Keyboard: Tab from the heading through the actions, Esc, focus back on the row.
  - Dashboard bell: an access notification opens the same drawer on the Dashboard.
- [ ] Before and after: one screenshot of the flag-off modal next to the flag-on drawer for the PR body.

## PR 6 checklist

- [ ] Flag off: every reader test from before PR 5 passes unchanged, including
  `EmailsRelationManagerReadingTest` "stays closed when the viewer cannot read the body", which is
  flag-off behaviour now.
- [ ] Flag on: Tasks 1 to 5 feature tests and the four browser tests pass in Chromium and WebKit.
- [ ] No page renders two readers (the resolver browser test).
- [ ] Opening a locked copy writes no `email_reads` row.
- [ ] `vendor/bin/phpstan analyse`, `composer test:arch`, `composer test:lint` clean.
- [ ] Screenshots, the drawer timing and the EXPLAIN plans in the PR body.

---

## Self-review against the spec

Every spec item for PRs 5 and 6 has a task: visibility and leaks (PR 5 Tasks 2, 4, 6), views and the
default (Task 3), cursor pagination (Task 5), the row (Task 4), record tabs (Task 6), the resolver,
`Email::appUrl()` and `EmailForAgent.url` (Task 7), the flag (Task 1), the drawer frame (PR 6 Tasks 1
and 6), the thread and lazy bodies (Task 2), the gate (Task 3), host URLs and one reader per page
(Task 4), focus and prev and next (Task 5). Each spec test row for these PRs maps to a test above.
Deferred by the phases table or by the notes: Done and the keyboard map (PR 7), chips and full-text
search (PR 8), linking, the per-sender bundle and opportunity matching (PR 9), the counts cache and
prefetch (PR 11), the Rela slot (PR 14).
