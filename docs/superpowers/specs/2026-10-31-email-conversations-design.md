# Email Conversations

**Date:** 2026-10-31
**Branch:** none yet. Each phase below lands as its own pull request from `main`.
**Status:** design, awaiting review
**Research:** `docs/email/` (audit, 32 decisions, 23 competitor analyses, method). Every
decision named here as "D<n>" is reasoned in full in `docs/email/decisions/`.

## Goal

The `Emails` page becomes the place a seller answers two questions every day: who is
waiting on me, and what did this company say. It lists conversations with the people,
companies and opportunities the workspace tracks. Every row carries its record context.
A drawer shows the whole thread beside the record. Rela, the MCP server and the page read
the same facts with the viewer's permissions.

It is not a mail client. It has no folders, no labels, no spam, and it never writes to
Gmail or Microsoft except to send.

## What is wrong today

Measured on production (aggregates only) and the code, 2026-10-31:

- 372,714 synced emails in 29 workspaces. The `Emails` page shows none of them. It opens
  on an empty Drafts table (`EmailPageTab.php:17-20`, `EmailInboxPage.php:55-56`).
- Mail is reachable only from record tabs, one row per message. Five conversations render
  as 13 rows (`BaseEmailsRelationManager.php:118`).
- 54% of mail is linked to no record, so it is visible nowhere. Over half of that is
  likely older marketing mail.
- Unread state is loaded and never shown (`BaseEmailsRelationManager.php:105`).
- The reader is a modal that hides the record, shows one message, and cannot be linked
  to from a record tab (`reader-overlay.blade.php:41-48`, `BaseEmailsRelationManager.php:51`).
- `email_threads` is unique per mailbox, so one conversation held by two teammates is two
  threads (`create_email_threads_table.php:30`).
- Search is `ILIKE` over subject, snippet and participants, with no index and no body
  search (`EmailSearchService.php:24-58`).
- Microsoft sync never stores `In-Reply-To` (`MicrosoftGraphMailService.php:152`). No
  provider stores `References`.
- 3 of 32 mailboxes are broken, and one `active` mailbox has not synced for 375 hours.
  Only staff see the stale check.
- 27 emails sent from the app in 90 days. 0 access requests and 0 shares ever.

## Decisions

The founder decided D1 to D9 one by one on 2026-10-31 and accepted D10 to D31 as
recommended. D8 and D32 were merged.

| # | Decision | Choice |
|---|---|---|
| D1 | Job of the page | A CRM Conversations list. Drafts, Outbox, Failed and Templates become secondary tabs |
| D2 | Default view | Needs reply, falling back to All when empty |
| Q2 | Which mail appears | All of the viewer's own mail, plus teammates' conversations only where linked to a record, at their tier |
| D3 | Reader | A non-modal right drawer on every host |
| D4 | Deep links | Resolver route `/email/open/{email}`, plus `?email=` on each host |
| D5 | Rows | One row per conversation, merged across mailboxes by RFC headers. Store `References` |
| D6 | Row content | Two lines with right-aligned record chips |
| D7 | Unread | Bold plus a dot, scoped to mail that reached the viewer |
| D8, D32 | Noise | A stored `automated_reason` from headers; hidden behind an Automated view; bundled per sender on record tabs |
| D9 | Locked rows | Open the drawer at the privacy gate with Request access |
| D10 | Done and read | Per-user Done in Relaticle only; returns on new inbound mail; read and unread. No provider write-back |
| D11 | Keyboard | One scoped shortcut map, `?` sheet, hints, an off switch for single keys |
| D12 | Bulk actions | Checkbox column with a floating action bar |
| D13 | Search | Postgres full text, separate subject and body vectors gated by tier, `pg_trgm` for addresses |
| D14 | Filters | Chips and typed operators over one email filter owner using the `app/Queries` grammar |
| D15 | Composer surface | Corner window for new mail, inline for replies |
| D16 | Draft safety | Debounced server autosave with a stale-write check |
| D17 | Send later | Split Send with presets in the sender's time zone |
| D18 | Undo send | Per-user window, default 10 s; Undo reopens the draft |
| D19 | Templates and variables | `/` for templates, `{` chips from custom fields, fallbacks, per-recipient preview |
| D20 | AI drafting | "Draft with Rela" through the existing draft pipeline; never sends |
| D21 | Toolbar | Short fixed row plus the existing selection bubble |
| D22 | Linking | Stored links, link and unlink chips, a suppression table, an Unlinked senders view |
| D23 | Sharing default | Ask at every connect with Participants only preselected; raising the workspace default never widens past mail |
| D24 | Access requests | Labelled request on locked rows and in the drawer, plus a per-record batch request |
| D25 | Opportunity email | Read-time company and point-of-contact mail in the opportunity's time window, plus pins |
| D26 | Sync health | A scheduled watchdog stores a health state per mailbox; banner, nav dot, owner then admin notices |
| D27 | Connect and empty states | Connect card above usable tabs; sharing step after every connect; no-provider state |
| D28 | Speed and live updates | Fast server path plus a content-free Reverb signal per user |
| D29 | Tracking | No pixels. Engagement comes from replies and meetings |
| D30 | Follow-ups | Awaiting reply as a query, in business days |
| D31 | Rela in the reader | Summary line plus approvable cards on the proposal pipeline, with a facts card that needs no AI key |

## Non-goals

- Folders, labels, spam, archive in the provider, or any provider write other than send.
- Open or click tracking (D29).
- Sequences and multi-step campaigns (D30).
- A digest email of FYI mail (D32).
- Shared assignment, internal comments and collision detection. Revisit when
  multi-mailbox workspaces are common.
- A split pane. It ships only if D3's signal fires.

## Glossary

| Term | Meaning |
|---|---|
| Conversation | All messages in one workspace that share a `conversation_key`, across every mailbox |
| Copy | One mailbox's stored row of a message. A message held by two mailboxes has two copies |
| Viewer's copy | The copy `PreferredEmailCopyService` picks for the viewer: their own first, then the most open |
| Reached the viewer | The copy is in the viewer's mailbox, or was shared with them after the share date |
| External | A participant whose address is not a workspace member's mailbox or alias |
| Automated | A message with a non-null `automated_reason` |

## Architecture

```
sync (Gmail, Graph)
  └─ FetchedEmailData  +inReplyTo (Graph)  +references  +automated signals
       └─ StoreEmailAction
            ├─ ConversationKeyResolver      -> emails.conversation_key
            ├─ AutomatedMailDetector        -> emails.automated_reason
            ├─ search vectors (trigger)     -> emails.subject_search, body_search
            └─ RefreshConversationSummary   -> email_conversations row
                 └─ ConversationChanged event (ids only) -> Reverb, per user

ConversationsQuery  (one owner of "conversations a viewer may see")
  ├─ Emails page (Conversations views)
  ├─ record Emails tabs (company, person, opportunity)
  ├─ Rela chat tools
  └─ MCP email tools

/email/open/{email}  -> resolver -> record tab ?email=  |  Conversations ?email=
```

### Ownership

| Fact | Owner |
|---|---|
| Which emails a viewer may see, and at which tier | `VisibleEmailScope` and `EmailPolicy`, unchanged in shape, with the linked-only teammate rule added |
| Which conversations a viewer may see, in which view | `ConversationsQuery` (new) |
| A message's conversation | `ConversationKeyResolver` (new) |
| Whether a message is automated, and why | `AutomatedMailDetector` (new), extending `Support\AutomatedSenderMatcher` |
| Needs reply, Awaiting reply, Done | `ConversationsQuery` views over `email_conversations` and `email_conversation_states` |
| A conversation's URL | `Email::appUrl()`, pointed at the resolver route |
| The email shortcut map | `EmailShortcut` enum (new) |
| What an agent sees of an email | `EmailForAgent`, extended |
| Mailbox health | `MailboxHealth` state on `connected_accounts`, written by the watchdog |

`app/Queries` cannot import a package and `FilterTree` accepts only `CrmEntity`
(`app/Queries/FilterTree.php:48`). So `ConversationsQuery` lives in
`packages/EmailIntegration/src/Queries` and speaks the same filter grammar through its own
filter definitions (D14). Folding email into `app/Queries` is a later step.

## Data model

All new datetime columns hold UTC and are written from PHP `now()`. Migrations have only
`up()`. Every migration that touches `emails` is rehearsed on anonymised production data
before merge (core.md, Database).

### `emails`: new columns

| Column | Type | Notes |
|---|---|---|
| `conversation_key` | `string(64)`, nullable until backfilled | See "Conversation key" |
| `references` | `text`, nullable | Raw `References` header, Message-IDs only, max 50 kept |
| `automated_reason` | `string(32)`, nullable | `AutomatedReason` enum value |
| `subject_search` | `tsvector`, nullable | Maintained by trigger, `simple` config |
| `body_search` | `tsvector`, nullable | Maintained by trigger from `email_bodies.body_text`, `simple` config, first 64 KB |
| `draft_version` | `integer` default 0 | Drafts only. Autosave sends the version it loaded; a mismatch rejects the write (D16) |

Indexes:

- `(workspace_id, conversation_key)` btree.
- `subject_search` and `body_search` GIN.
- `email_participants.email_address` GIN `gin_trgm_ops` (`pg_trgm` already ships with
  `postgres:17-alpine`).

`body_search` sits on `emails`, not `email_bodies`, because `email_bodies` has no
`workspace_id`. The columns are plain, kept by triggers, because a generated column would
rewrite a large table under a lock.

### `email_conversations` (new)

One row per workspace and conversation. It holds only facts that are safe to read
regardless of tier: timestamps, counts and directions. It never holds a subject,
snippet or participant name.

| Column | Type | Notes |
|---|---|---|
| `id` | ULID | |
| `workspace_id` | ULID FK | |
| `conversation_key` | `string(64)` | Unique with `workspace_id` |
| `message_count` | int | All copies de-duplicated by `rfc_message_id` |
| `first_message_at`, `last_message_at` | timestamp | |
| `last_external_inbound_at` | timestamp nullable | Latest non-automated message from an external sender |
| `last_member_outbound_at` | timestamp nullable | Latest message sent from any member mailbox |
| `has_attachments` | bool | |
| `is_automated` | bool | Every message in it is automated |
| `updated_at` | timestamp | |

Index `(workspace_id, last_message_at desc)` and
`(workspace_id, last_external_inbound_at desc)`.

`RefreshConversationSummary` recomputes one row from its messages on every store, send,
delete and purge. It is idempotent, so a duplicate job is harmless.

### `email_conversation_states` (new)

Per-user Done (D10). Read state stays in `email_reads`.

| Column | Type |
|---|---|
| `id` | ULID |
| `workspace_id` | ULID FK |
| `user_id` | ULID FK |
| `conversation_key` | `string(64)` |
| `done_at` | timestamp |

Unique `(workspace_id, user_id, conversation_key)`. A conversation is Done for a user while
`done_at >= last_external_inbound_at`. A new inbound message brings it back with no job.

### `email_link_suppressions` (new)

A manual unlink must survive the next sync and relink (D22).

| Column | Type |
|---|---|
| `id` | ULID |
| `workspace_id` | ULID FK |
| `email_id` | ULID FK, cascade |
| `emailable_type`, `emailable_id` | morph |
| `user_id` | ULID FK, who unlinked |
| `created_at` | timestamp |

Unique `(email_id, emailable_type, emailable_id)`. `LinkEmailAction` and
`RelinkRecordHistoryJob` skip suppressed pairs.

### `connected_accounts`: new columns

| Column | Type | Notes |
|---|---|---|
| `health` | `string(24)` | `MailboxHealth`: `healthy`, `stale`, `reauth_required`, `error`, `paused_billing` |
| `health_reason` | `string(64)` nullable | `SyncErrorReason` value or `no_sync_24h` |
| `health_changed_at` | timestamp nullable | |
| `health_notified_at` | timestamp nullable | Last owner or admin notice |

### `users`: new preferences

`email_undo_seconds` smallint default 10 (5, 10, 20 or 30), and `single_key_shortcuts`
bool default true (D11, WCAG 2.1.4).

## Conversation key

`ConversationKeyResolver::resolve(FetchedEmailData, ConnectedAccount): string` runs inside
`StoreEmailAction`, in this order, inside the email's workspace:

1. For each Message-ID in `In-Reply-To`, then `References` from newest to oldest: if a
   stored email in the workspace has that `rfc_message_id`, take its `conversation_key`.
2. Else, if another message from the same mailbox and provider `thread_id` is stored,
   take its key.
3. Else, if a stored email in the workspace has the same `rfc_message_id` (another
   mailbox's copy of this message), take its key.
4. Else start a new key: `sha256(workspace_id . root)`, where root is the oldest
   Message-ID in `References`, or `In-Reply-To`, or the message's own Message-ID.

Rule 4 hashes the root, not the message. So when a reply arrives before its parent,
both later resolve to the same key once the parent's `References` names the same root.
When two keys are found to be one conversation (a late parent links them), a queued
`MergeConversations` job rewrites the newer key to the older one and refreshes the
summary. It is unique per workspace and key.

Merging only ever happens on an exact Message-ID inside one workspace.

### Provider changes

- `FetchedEmailData` gains `references` (`?string`) and keeps `inReplyTo`.
- Gmail reads both from headers it already fetches (`GmailService.php:149`).
- Graph selects `internetMessageHeaders` (already selected at
  `MicrosoftGraphMailService.php:125`) and fills `inReplyTo` and `references` from them.
  `conversationId` is used as the provider `thread_id`, as today.

## Automated mail

`AutomatedMailDetector::detect(FetchedEmailData): ?AutomatedReason`, first match wins:

| Reason | Signal |
|---|---|
| `list` | `List-Unsubscribe` or `List-Id` present |
| `auto_submitted` | `Auto-Submitted` present and not `no` |
| `bulk` | `Precedence: bulk`, `junk` or `list` |
| `role_sender` | `AutomatedSenderMatcher` matches the local part (`noreply`, `notifications` ...) |
| `provider_category` | Gmail promotions, social, updates or forums label. Microsoft has no equivalent, so its mail relies on the header rules above |

The existing bulk drop at sync stays (`StoreEmailJob.php:147`). The detector marks what
is kept. `EmailClassifier` stops letting Gmail's `CATEGORY_PERSONAL` override the other
rules (`EmailClassifier.php:46-48`, `GmailService.php:161`).

A per-workspace correction, "Not automated", stores the sender address in
`workspace_email_blocklists`' sibling list `automated_sender_overrides` (new table,
`workspace_id`, `email_address` unique). The detector returns null for those senders.

Badge colours and labels move into `EmailCategory`. `category-badge.blade.php` reads the
enum and its own `match` is deleted.

## Visibility rules

One change to the existing rule, everything else unchanged:

- **Teammates see another mailbox's email only where it is linked to a record** (Q2,
  D23). `VisibleEmailScope::whereTeammateMaySeeMetadata()` gains
  `whereExists(emailables where email_id = emails.id)`. This matches what record tabs
  show today, and makes the list, Rela and MCP agree.

Consequences the founder approved:

- Needs reply and Awaiting reply cover only conversations that include a copy in the
  viewer's own mailbox.
- The Unlinked view shows only the viewer's own mail.
- A conversation held by the viewer and a teammate shows once, at the viewer's own access.

Row fields come only from messages the viewer may see, at the tier they may see. A row
whose newest visible message is below `viewSubject` reads "Subject hidden".

## `ConversationsQuery`

```php
final readonly class ConversationsQuery
{
    /** @return CursorPaginator<int, ConversationRow> */
    public function paginate(User $viewer, ConversationFilters $filters, int $perPage = 50, ?string $cursor = null): CursorPaginator

    public function counts(User $viewer): ConversationCounts   // needs_reply, unlinked, automated

    /** @return Collection<int, Email> ordered oldest first, bodies not loaded */
    public function messages(User $viewer, string $conversationKey): Collection
}
```

- It takes the acting user and reads no ambient user, request or workspace, so chat tools
  in queued jobs can call it.
- It binds the viewer's workspace explicitly and removes `WorkspaceScope` for its own
  filter, as `ListsEntity` does.
- It is the only reader of `email_conversations`. A PHPStan entry for its folder keeps it
  write-free (`EloquentWriteOutsideActionRule`).

### Views

| View | Rule |
|---|---|
| `needs_reply` | Conversation has a copy in the viewer's mailbox; `last_external_inbound_at` is not null and later than `last_member_outbound_at`; not Done for the viewer; not automated |
| `awaiting_reply` | Viewer sent the latest member message; no external inbound after it; it is older than `awaiting_reply_business_days` (workspace setting, default 3) counted in the viewer's time zone, weekends skipped |
| `all` | Every conversation the viewer may see, automated excluded |
| `unlinked` | Viewer's own conversations with no linked record, automated excluded, grouped by sender address on demand |
| `automated` | Conversations where `is_automated`, viewer's own and linked teammate ones |
| `done` | Done for the viewer, newest first |

Reply status is plain SQL. It needs no AI key. A Rela refinement ("thanks!" is not a
question) is out of scope for this spec and recorded as follow-up work in D2.

Each view orders by `last_message_at desc, id desc` and uses cursor pagination. No view
runs a `count(*)` per page. `counts()` runs three indexed counts and is cached per user
for 60 seconds, invalidated by `ConversationChanged`.

### `ConversationRow`

A plain `final readonly` data class built from the summary row plus the viewer's newest
visible copy (one `LATERAL` join per page):

`conversation_key`, `viewer_copy_email_id`, `subject` (null below tier), `snippet` (null
below body), `participants` (names allowed at the tier), `message_count`,
`last_message_at`, `has_attachments`, `is_unread`, `is_done`, `owner_user_id` when the
copy is a teammate's, `access` (`full`, `subject`, `metadata`), `records` (up to the first
three linked records the viewer may see), `automated_reason`.

### Filters (D14)

`ConversationFilters` accepts the same tree shape as `app/Queries` and these fields:
`mailbox`, `direction`, `has_attachment`, `sent_after`, `sent_before`, `record_type` +
`record_id`, `category`, `search`. The chip bar and typed operators (`from:`, `to:`,
`has:attachment`, `after:`, `before:`, `company:`) both produce this object. Unknown
operators stay as free text.

## Search (D13)

`EmailSearchService` stays the single owner, called by `ConversationsQuery` and
`VisibleEmailsQuery`. It changes from `ILIKE` to:

- Subject: `subject_search @@ websearch_to_tsquery('simple', :q)`, only on copies at
  `SUBJECT` or `FULL` for the viewer.
- Body and snippet: `body_search @@ ...`, only on copies at `FULL` for the viewer.
- Addresses and names: `email_participants.email_address % :q` or `ILIKE` prefix, using
  the trigram index.
- Ranking: `ts_rank` on subject times 2 plus body, then recency.

The tier gate is the existing correlated predicate, so no index ever returns a match the
viewer could not have seen. Estimated index size today: 0.7 to 2.2 GB of vectors and
index, to be measured by one read-only aggregate on body length before the migration.

## Routes

| Route | Name | Behaviour |
|---|---|---|
| `GET /email/open/{email}` | `email.open` | Loads the email in its own workspace. Refuses when the user is not a member. Picks the viewer's copy of the conversation. Redirects to the first linked record's Emails tab with `?email=` when one is visible, else to the Emails page with `?email=`. Missing, private and unlinked teammate mail all render the same "not available" page. No subject in the title below `viewSubject` |

`Email::appUrl()` returns this route. Existing notification links keep working because
the Dashboard handler still reads `?email=` (`EmailAccessNotificationHandler.php:45-53`),
and it opens only when the page has no reader host of its own.

## UI

All copy goes through `__()`. No em-dashes. Icons follow `ui.md`: line for UI actions,
fill for status. Every state is verified in light, dark and 390px, and the browser tests
run in Chromium and WebKit.

### The Emails page

`EmailInboxPage` keeps its slug, title and nav position. Its tab enum becomes:

- Primary views (left): Needs reply, Awaiting reply, All, Unlinked.
- Secondary (right, smaller): Automated, Done, then a divider, then Drafts, Outbox,
  Failed, Templates.

State lives in the URL: `?view=`, `?email=`, filter chips as `?filter[...]`. The default
is `needs_reply`, falling back to `all` when its count is zero.

Below the tabs: the search field, then the chip bar (Mailbox, Has attachment, Date,
Linked to, Category). Then the list.

With no mailbox: the connect card sits above the tabs and Drafts and Templates stay
usable (D27). With no provider configured on a self-hosted install, the card explains
how to configure one and links to the docs.

While the first import runs, a progress strip shows above the list with the imported and
estimated counts already on `connected_accounts`.

### The row (D6, D7)

`<x-email-integration::conversation-row>` replaces `list-row-wide` on every host.

```
[•] [avatar] Brian, Olivia (3)            [teammate avatar] [clip]      2:45 PM
             Re: Pricing for 40 seats  Thanks for the call...   [Airbnb] [Renewal]
```

- Line 1: sender names with the message count, the owner's avatar when the copy is a
  teammate's, attachment icon, time. Relative time rules as today.
- Line 2: subject, muted snippet, up to two right-aligned chips (company first, then
  opportunity, then person) or an "Unlinked" chip with Link and Create person actions.
- Unread: semibold sender and subject, a dot with `aria-label`. Only when
  `is_unread`, which already applies the reached-the-viewer rule. Read rows keep normal
  weight; no opacity change.
- Locked rows keep chips and avatar. Line 2 reads "Subject hidden" in muted text, with
  "Request access" on hover and focus.
- Hover and focus reveal a pill: Done, Mark unread, Link. Mounted only while hovered.
- "via Name" is gone for the viewer's own mailbox.
- At 390px: one chip plus "+N", no snippet.
- `data-unread`, `data-conversation` attributes for tests.

### Record Emails tabs

`BaseEmailsRelationManager` renders the same rows through `ConversationsQuery` with a
record filter. Automated conversations from one sender collapse into one row ("6
automated emails from Stripe") that expands in place. The search field stays.

Opportunity tabs use read-time matching (D25): conversations linked to the opportunity,
plus the company's and the point of contact's conversations from 30 days before the
opportunity's creation to 30 days after it closed, plus pins. A pin is a stored
`emailables` row with `link_source = manual`. Removing one writes a suppression.

### The drawer (D3)

`reader-overlay` becomes a right-anchored, non-modal panel, 55% of the content width,
resizable between 40% and 70%, full screen below 640px. No backdrop. The list behind it
stays visible and clickable; the `invisible` class is removed. It keeps the CSS mount
animation and `wire:key` per email from `c1e284531`, because Alpine `x-show` state does
not survive Livewire morphing.

Contents, top to bottom:

1. Header: subject, record chips with "+ Link record", and labelled buttons Reply (`r`),
   Summarize (`s`, only with a model configured), Share (owner only), and an overflow
   with Mark unread, Done, Request access, Open in new tab.
2. Source line for teammate copies: "Visible through Ben's mailbox".
3. Rela slot (D31), collapsible, remembered per user:
   - With a model: one "Summary" line, then approvable cards for suggested record
     updates and a draft reply. Each card shows its sources ("from this thread and the
     Airbnb opportunity") and its state ("Draft, not sent").
   - With no model: a facts card. Last reply from them, last reply from us, days waiting,
     open opportunity and stage, next meeting.
4. Earlier messages as one-line rows (sender, snippet at tier, date). A body loads on
   first expand and stays mounted.
5. The latest message and the one being replied to, expanded. Header with sender,
   "to me and 2 others" popover (Bcc shown to the mailbox owner only), date.
6. Inline reply composer at the bottom.

The privacy gate (D9) renders inside the drawer for copies below `viewBody`, with the
owner, what the viewer can see and why, and Request access with an optional note.
Opening a locked copy never marks it read.

Message body rendering:

- The sandboxed iframe gets a base stylesheet: system font stack, 14px, line height 1.6,
  16px padding. It applies only where the email sets no font of its own.
- Plain-text emails render in the app theme, not a white frame.
- The reader no longer repeats the snippet under the subject. The snippet line requires
  `viewBody` everywhere (`email-view.blade.php:138`).

### Keyboard (D11)

`EmailShortcut` enum is the single owner. Each case has keys, scope (`list`, `reader`,
`composer`, `global`), a label and an action name. The page renders it into an Alpine
store. The `?` sheet, button tooltips and handlers all read that store.

| Key | Scope | Action |
|---|---|---|
| `j` / `k` | list | Next and previous conversation; moves the drawer when open |
| `Enter` / `o` | list | Open |
| `Esc` | reader, composer | Close (through `closeReader()` so drafts save) |
| `r` / `a` / `f` | reader | Reply, reply all, forward |
| `e` | list, reader | Done, then advance to the next conversation |
| `u` | list, reader | Mark unread |
| `l` | list, reader | Link to record |
| `x` | list | Select |
| `/` | list | Focus search |
| `c` | global | Compose (exists today) |
| `?` | global | Shortcut sheet |
| `Cmd/Ctrl Enter` | composer | Send |
| `Cmd/Ctrl Shift Enter` | composer | Send later picker |
| `z` | global | Undo while an undo toast is visible |

- No key means one thing in the list and another in the reader.
- With nothing selected, list actions apply to the hovered or focused row.
- Single-key shortcuts are disabled when `users.single_key_shortcuts` is false, and never
  fire inside inputs or editors.
- Chat's bare `j`, `k` and `n` (`chat-sidebar-nav.blade.php:26-40`) move into a scope that
  is active only while the chat list has focus. Today they fire on every page and cannot
  be turned off, which fails WCAG 2.1.4.
- Filament's Ctrl K stays record search. Email actions do not register there until an
  action palette exists.

### Bulk actions (D12)

A checkbox column and a floating bar: Mark read, Mark unread, Done, Link to record, and
Change sharing (owner's copies only). Each is one batch action. The bar says how many
were skipped and why.

### Triage behaviour (D10)

- Done writes `email_conversation_states` and advances to the next row (user setting:
  next, previous, close; default next).
- Every triage action applies at once and shows an Undo toast for 5 seconds. Undo calls
  the inverse action. Writes are local, so nothing is deferred.
- Nothing is written to Gmail or Microsoft. The app keeps `gmail.readonly` and
  `gmail.send` and Graph read and send scopes.

### Composer (D15 to D21)

- New mail opens in the corner window (`EmailComposer` already has the state). Expand is
  one click. Replies dock inline at the end of the thread. Mass send and drafts of
  replies open fitted.
- Autosave 2 seconds after typing stops, through a light save path that does not fire
  `drafts:changed` or copy attachments. A `version` column on the draft rejects a stale
  write from a second tab and offers to reload. No browser-side copy, so GDPR erasure
  stays complete. A "Saved" state shows in the header.
- Send is a split button. The caret offers Tomorrow 8:00, Monday 8:00 and Pick a time, in
  the sender's time zone, and writes `scheduled_for`. The outbox dispatcher already runs
  every minute (`bootstrap/app.php:402-406`).
- Undo uses `users.email_undo_seconds`. Undo reopens the draft with its content. The MCP
  hold from `HoldAgentEmail` and `PickAgentSendTime` is never shortened by this setting.
- `/` in the editor opens templates. `{` opens variables. Variables come from one owner
  that lists core fields and every active custom field for person, company and
  opportunity, through `CustomFieldType::inputFormat()`. A variable chip shows yellow
  without a fallback and blue with one. Sending warns when any recipient would get an
  empty value or an unknown tag. Mass send shows a per-recipient preview.
- "Draft with Rela" (`Cmd/Ctrl J`) writes into the composer through the existing draft
  pipeline and the credit system, with a new credit type. It never sends. It drafts only
  for mail addressed to the viewer, skips cc-only mail, and skips conversations a
  teammate already answered.
- Toolbar: bold, italic, list, link, attach, template, signature. Alignment, undo and
  redo buttons are removed; the selection bubble stays. The inline-image button and the
  attachment paper clip get distinct icons and labels.
- The reply subject keeps an existing `Re:`, `RE:`, `Fwd:`, `FW:`, `Aw:`, `Sv:` or `Wg:`
  prefix instead of adding a second (`EmailComposer.php:322`).

### Sharing and access (D23, D24, D27)

- After every mailbox connect, a sharing step asks for the mailbox tier with Participants
  only preselected. It previews three of the owner's own recent threads as a teammate
  would see them. The workspace setup step already does this once
  (`SaveOnboardingSharing`); later connects now get it too.
- The tier label `metadata_only` is renamed in copy to "Who and when". The description
  line stays for one release.
- Raising the workspace default applies to mail synced from then on. Lowering it applies
  to past mail too. Mailboxes that follow the default get an inbox notice when it rises.
  This changes `ApplyDefaultSharingTierToExistingEmailsAction`.
- Automated mail starts Private for teammates.
- "Request access" is a labelled button on locked rows and in the drawer gate, with an
  optional note.
- On a record tab with several locked conversations from one owner, a batch request
  reads "Request Ana's 12 emails on Airbnb". The owner can answer with "Share all my mail
  on this record", which writes the existing `emailable_type` and `emailable_id` columns
  of `email_access_requests`. Creating a request and sharing by hand are added to the
  audit log.

### Linking (D22)

- The drawer header shows link chips. "+ Link record" opens a record picker (company,
  person, opportunity). A chip's menu has Unlink.
- New actions: `LinkEmailToRecord` and `UnlinkEmailFromRecord`. Both act on every copy of
  the conversation the actor may see. Unlink writes `email_link_suppressions`.
- The Unlinked view groups the viewer's own conversations by sender, with "Create person"
  (prefilled from the address and name) and "Link to existing".
- Adding an address to a person still queues `RelinkRecordHistoryJob`, which fills
  history and respects suppressions.

### Sync health (D26)

- `email:check-mailbox-health` runs every 15 minutes from `withSchedule()` in
  `bootstrap/app.php`, on one server. It sets `health` per mailbox:
  - `reauth_required` and `error` from `status` and `SyncErrorReason`.
  - `stale` when `active` and the sync cursor has not advanced for 24 hours. The cursor is
    the signal, not `last_synced_at`, which moves even when a batch fails
    (`IncrementalEmailSyncJob.php:174-183`).
  - `paused_billing` when the workspace lacks paid access, so it is never reported as
    stale.
- Notices: the owner at once; workspace admins after 72 hours, or at once for billing
  pauses. `health_notified_at` prevents repeats.
- The Emails page and record Emails tabs show a banner for the viewer's own unhealthy
  mailbox with Reconnect. The Emails nav item shows a dot.
- The SystemAdmin `stale()` and `needingAttention()` read the same column.

## Live updates (D28)

- `ConversationChanged` implements `ShouldBroadcast` on the existing
  `App.Models.User.{id}` channel. Payload: `workspace_id`, `conversation_key`. No subject,
  no snippet, no address.
- It is sent only to users who may see at least one copy in the conversation, computed
  after store and after any tier or link change.
- The page reacts by refetching the affected row and the counts through
  `ConversationsQuery`. A row the viewer may no longer see disappears.
- Mail still arrives by polling every 5 minutes (`bootstrap/app.php:384-388`). Push
  sync is a separate project.

Latency budgets, measured in the browser suite against a seeded workspace of 200,000
emails:

| Interaction | Budget (p95) |
|---|---|
| List first paint after navigation | 300 ms server time |
| Next page of 50 rows | 250 ms |
| `j` / `k` with the drawer open, prefetched | 100 ms to visible change |
| Open drawer, not prefetched | 400 ms |
| Done and advance | 100 ms visible, write in the background |
| Search, first results | 400 ms |

Prefetch: hovering a row for 120 ms, or opening a conversation, warms that conversation
and its neighbours with one Livewire call that returns rendered message headers.

## Agents (Rela and MCP)

The same facts reach agents through the existing owners.

- `EmailForAgent` gains `url` (the resolver route), `conversation_key`, `is_read` for the
  acting user and `automated_reason`.
- `VisibleEmailsQuery` gains a `conversation_key` filter that replaces `thread_id` for
  agents. `thread_id` stays accepted for one release and then goes, per core.md's
  internal contract rule (migrate all callers in the same change: chat tools and MCP).
- `ListEmailsTool` and its MCP twin gain `view` (`needs_reply`, `awaiting_reply`,
  `unlinked`, `automated`) backed by `ConversationsQuery`.
- New chat write tool `RequestEmailAccessTool`, batch input (`email_ids[]`, `note`). One
  call is one `PendingAction`. It needs a `ProposalEntity` case, a `toolLabels` entry and
  `LoadingShimmerTest` passing.
- New chat write tools `LinkEmailsTool` and `UnlinkEmailsTool` (batch), through the new
  actions. `SurfaceParityTest` covers MCP parity.
- Rela never sees more than the viewer. Every tool calls the query owners with the acting
  user.

## Things this spec removes

- `list-row-wide.blade.php` and the per-message record list.
- The centred modal behaviour of `reader-overlay`.
- `category-badge`'s own colour map.
- Orphan translation keys in `lang/en/filament/pages/email-inbox.php` that the new page
  does not reuse.
- The `Status tab` filter chip on the Outbox; statuses become segmented tabs with labels,
  sorted by `scheduled_for`.

## Migrations and deploy

| Migration | Shape | Rehearsal focus |
|---|---|---|
| Add `conversation_key`, `references`, `automated_reason` to `emails` | Additive, nullable | Lock time on 372k rows |
| Create `email_conversations`, `email_conversation_states`, `email_link_suppressions`, `automated_sender_overrides` | New tables | None |
| Add search vectors, triggers, GIN indexes, trigram index | `CREATE INDEX CONCURRENTLY` in its own migration with `withinTransaction = false` | Index build time and size |
| Add health columns to `connected_accounts` | Additive | None |
| Add user preferences | Additive with defaults | None |
| Backfill conversation keys, automated reasons and summaries | A command, queued from a migration with `Artisan::queue(...)->onQueue('imports')->delay(now()->addMinutes(5))->afterCommit()` | Merge correctness, run time on the 156,632-email mailbox |

The backfill is a command, not a migration body, because it needs app code (the resolver
and the detector). It reports by default, writes on `--force`, is chunked with `eachById`
per workspace, and is safe to rerun. Older mail has no stored headers, so its keys come
from `rfc_message_id`, `in_reply_to` and the provider thread, and its automated reason
comes from the Marketing label and the role-sender rule.

Until the backfill finishes, `ConversationsQuery` treats a null `conversation_key` as
`sha256(workspace_id . thread_id . connected_account_id)`, so the list works on day one.
This shim is removed once production shows no null keys.

A self-hosted install gets all of this through `docker compose pull && up -d`.

## Feature flag

`App\Features\EmailConversations`, a Pennant feature scoped to the workspace, on top of
`EmailIntegration`. It gates the new page views, the drawer and the row on record tabs.
It starts on for two workspaces, then for all after the metrics below hold for two weeks.
The data model, search and health work ship unflagged, because they change no surface.

## Testing

Per `testing.md`: no unit tests of internals; through real entry points.

| Behaviour | Test |
|---|---|
| One row per conversation across two mailboxes; the viewer's copy wins | Feature, Emails page Livewire test with two mailboxes holding the same Message-ID |
| A late parent merges two keys | Feature, store two messages out of order through `StoreEmailAction` |
| Microsoft replies thread | Feature, Graph fixture with `In-Reply-To` and `References` |
| Teammate unlinked mail is invisible on the page, record tabs, `ListEmailsTool` and the MCP tool | Feature, one dataset, four entry points |
| Row fields never leak a hidden subject or snippet | Feature, mixed-tier conversation, assert rendered HTML per tier |
| Needs reply: inbound shows, member reply clears, Done hides, new inbound returns | Feature, page views |
| Awaiting reply counts business days in the viewer's zone | Feature with `travelTo` on a Friday |
| Automated reasons per header | Feature, store fixtures for each signal; "Not automated" override |
| Unread scoped to mail that reached the viewer | Feature, teammate linked conversation never unread |
| Resolver: redirects, same page for missing, private and unlinked teammate mail, foreign workspace refused | Feature, HTTP |
| Search respects tiers | Feature, a body term matches for the owner and not for a metadata-tier teammate |
| Unlink survives relink | Feature, unlink then run `RelinkRecordHistoryJob` |
| Raising the workspace default does not widen past mail | Feature, settings page |
| Health watchdog states and notices | Feature, command with `travelTo` |
| `ConversationChanged` goes only to permitted users and carries ids only | Feature, `Event::fake` plus channel authorization |
| Reply subject keeps one prefix | Feature, composer |
| Drawer opens, `j/k`, `e`, `Esc` saves the draft, locked gate, 390px | Browser, light and dark, Chromium and WebKit |
| Shortcut off switch | Browser |
| New chat tools have shimmer labels | `tests/Browser/Chat/LoadingShimmerTest.php` |
| No reader host and the panel handler both open | Browser, `?email=` on a record tab |

Arch: `ConversationsQuery` is in the `Queries` PHPStan write ban; new actions have no
`Action` suffix and one public `execute()`.

## Phases and pull requests

| PR | Phase | Scope | Depends on |
|---|---|---|---|
| 1 | Polish | Reply prefix, iframe base styles and plain-text theme, snippet line and leak, Outbox labels and sort, connect card above tabs, mobile tab scroll, category badge reads the enum | none |
| 2 | Data | `references` and Graph `In-Reply-To`, `conversation_key` and resolver, `email_conversations`, backfill command, null-key shim | none |
| 3 | Privacy rule | Linked-only teammate condition in `VisibleEmailScope`; raising the default never widens past mail | none |
| 4 | Automated | Detector, `automated_reason`, overrides, Personal short-circuit fix, backfill | 2 |
| 5 | Conversations | `ConversationsQuery`, the page views, the row, record tabs on the new row, resolver route, `EmailForAgent.url`, feature flag | 2, 3, 4 |
| 6 | Drawer | Non-modal drawer, thread rendering, lazy bodies, privacy gate, deep links on hosts | 5 |
| 7 | Triage | Done, read and unread, bulk bar, shortcut map, chat shortcut scope, Undo toasts | 6 |
| 8 | Search | Vectors, triggers, indexes, search service, chips and operators | 5 |
| 9 | Linking | Link and unlink actions, suppressions, chips, Unlinked senders view, opportunity read-time matching, chat tools | 6 |
| 10 | Health | Watchdog, columns, banner, nav dot, notices, SystemAdmin reads the column | none |
| 11 | Live | `ConversationChanged`, counts cache, prefetch | 5 |
| 12 | Composer | Corner window, autosave, send later, undo setting, slash templates, variables, toolbar | 6 |
| 13 | Sharing and access | Connect sharing step, rename, automated mail Private, labelled requests, batch request, audit entries, request-access chat tool | 6 |
| 14 | Rela | Summary line, cards, facts card, Draft with Rela, credit type, summary service fixes (credits, tenant binding) | 6, 12 |

PRs 1, 2, 3 and 10 can start at once.

## Success metrics

Measured from production aggregates before each phase and 30 days after.

| Metric | Baseline | Target |
|---|---|---|
| Users who open a conversation from the Emails page per week | 0 | 60% of users with a mailbox |
| Share of page openers who open a conversation (D1 signal) | n/a | Above 20%, else fall back to the outbound page plus Unlinked |
| Emails sent from the app per 90 days | 27 | 270 |
| Manual links per month | about 0 | 50 |
| Mailboxes unhealthy for over 24 h with no notice | at least 3 | 0 |
| Access requests per month in multi-mailbox workspaces | 0 | above 0, and requests per gate open above 2% |
| Users marking automated rows "Not automated" | n/a | below 10% of automated rows |
| Median time from first to fifth conversation read in a session (D3 signal) | n/a | below 60 s, else add a split pane |

## Open questions

1. Should Needs reply include teammates' linked conversations when the viewer owns the
   company? Decided no for now (Q2). Revisit with assignment.
2. Does the hosted app already hold a Google CASA assessment for `gmail.readonly`? It
   affects nothing in this spec, but it matters for any future scope.
3. Can the production database role create GIN indexes concurrently within the deploy
   window? Measure during the rehearsal.
4. Body-length distribution, to size the search index. One read-only aggregate.
5. How many conversations span two or more mailboxes (D5 signal). One read-only aggregate
   before PR 2.

## Risks

| Risk | Mitigation |
|---|---|
| The list drifts into a Gmail clone | Non-goals above; every row has record chips or an Unlinked chip |
| A row leaks a hidden subject | Row fields only from visible copies; the summary table holds no text; one test per tier |
| Merging links unrelated mail | Exact Message-ID inside one workspace only |
| The backfill is slow on large mailboxes | Chunked, per workspace, on the `imports` lane, rerunnable; the null-key shim keeps the list working meanwhile |
| Search indexes are large | Measure first; `simple` config; body capped at 64 KB |
| Broadcasts leak | Ids only; permitted users only; the client refetches through the query owner |
| Shortcut collisions with chat | One map, scoped; chat keys need chat focus |
| The linked-only rule hides useful teammate mail | The owner still sees it; linking from Unlinked or a per-record share widens it deliberately |
