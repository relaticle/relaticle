# Email Conversations, part D: triage, search and live updates (PRs 7, 8, 11)

> **For agentic workers:** REQUIRED SUB-SKILL: use the `sdd-lean` skill to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A seller clears the Conversations list from the keyboard or a checkbox bar, finds any
thread by a word in its body or a fragment of an address, and sees new mail appear without a
reload. Nobody sees, matches or is signalled about mail above their tier.

**Spec:** `docs/superpowers/specs/2026-10-31-email-conversations-design.md`, sections "Keyboard
(D11)", "Bulk actions (D12)", "Triage behaviour (D10)", "Search (D13)", "Filters (D14)", "Live
updates (D28)", "Migrations and deploy", "Testing", "Phases and pull requests". Reasoning:
`docs/email/decisions/b-triage-and-search.md` (D10 to D14) and
`docs/email/decisions/e-speed-ai-and-later.md` (D28).

**Master plan:** `docs/superpowers/plans/2026-10-31-email-conversations.md`. Its global
constraints, per-task loop, pre-push checks and commit format apply to every task here and are
not repeated. Names below match its cross-PR contracts table. Where this plan adds to a contract,
"Plan notes" says so and the PR updates the table in the same change.

**Depends on:** PR 5 (`ConversationsQuery`, `ConversationView`, `ConversationFilters`,
`ConversationRow`, `<x-email-integration::conversation-row>`, the `EmailConversations` flag) and
PR 6 (`<x-email-integration::reader-drawer>`). PR 2 (`email_conversations`,
`RefreshConversationSummary`, `emails.conversation_key`) and PR 3 (the linked-only teammate rule in
`VisibleEmailScope`) are merged before all three PRs here. This plan treats their contracts as
available and names the methods it adds to them.

## Code read for this plan (2026-10-31)

| Fact | Where |
|---|---|
| Chat binds bare `j`, `k` and `n` on `window`, guarded only against inputs, modifiers, dialogs and the open side panel | `packages/Chat/resources/views/livewire/app/chat/chat-sidebar-nav.blade.php:4-40` (handler `4-38`, `n` at `18-24`, `j/k` at `26-37`, `@keydown.window` at `40`) |
| That nav renders on every app page through `SIDEBAR_NAV_END` | `packages/Chat/src/ChatServiceProvider.php:240-243` |
| The composer binds bare `c` on `window` | `packages/EmailIntegration/resources/views/livewire/email-composer.blade.php:21-27` |
| Filament search owns Cmd/Ctrl K | `app/Providers/Filament/AppPanelProvider.php:444` |
| Panel scripts are registered once as Filament assets and served from the published copy | `app/Providers/AppServiceProvider.php:854-865` (`registerPanelScripts`) |
| Per-user preferences sit on `users` with `#[Fillable]` and casts | `app/Models/User.php:86-93`, `134-146` |
| A one-preference write is an action in `app/Actions/User` | `app/Actions/User/UpdateNotificationPreferences.php` |
| Read state is one `email_reads` row per email and user | `database/migrations/2026_03_21_050530_create_email_reads_table.php:17-26` |
| The single-email read action checks visibility, then `firstOrCreate` | `packages/EmailIntegration/src/Actions/MarkEmailAsReadAction.php:19-36` |
| Search is `ILIKE` across participants, subject (SUBJECT or FULL) and snippet (FULL), BCC rows gated | `packages/EmailIntegration/src/Services/EmailSearchService.php:24-58`, tier helpers `73-129` |
| `VisibleEmailsQuery` and the record tab both call it | `packages/EmailIntegration/src/Queries/VisibleEmailsQuery.php:69-71`, `packages/EmailIntegration/src/Filament/RelationManagers/BaseEmailsRelationManager.php:112-114` |
| `email_bodies` has no `workspace_id`, one row per email | `database/migrations/2026_03_21_050455_create_email_bodies_table.php:13-21` |
| Store writes the email, then its body, in one transaction, then calls `SyncEmailThreadAction` and `LinkEmailAction` inline | `packages/EmailIntegration/src/Actions/StoreEmailAction.php:61-149` |
| No migration creates `pg_trgm`, `btree_gin` or any `tsvector` | `rg 'pg_trgm\|tsvector\|create extension' database/migrations` returns nothing |
| The concurrent index precedent, with the invalid-index cleanup | `database/migrations/2026_10_21_000002_index_inbox_columns_on_notifications_table.php:10-42` |
| `VisibleEmailScope` binds the viewer's `current_workspace_id` | `packages/EmailIntegration/src/Models/Scopes/VisibleEmailScope.php:37-55` |
| The user channel exists and nothing email uses it | `routes/channels.php:8-10` |
| Filament boots `window.Echo` only when Reverb is the broadcaster | `config/filament.php:26-38`, `tests/Browser/Chat/EchoClientTest.php:28-35` |
| New mail arrives by polling every five minutes | `bootstrap/app.php:384-388` |
| `app/Queries` grammar helpers a package may import | `app/Queries/FilterErrors.php:11-39`, `app/Queries/Operand.php:16-145`, `app/Queries/FilterTree.php:48` (takes a `CrmEntity`, so email cannot call `validate()`) |
| A class under `packages/*/src/Queries` must end in `Query` | `tests/Arch/ConventionsTest.php:1078-1094` |

## Plan notes: conflicts with the spec, the decisions and the contracts

Each note gives the decision this plan makes and what changes if the reviewer overrules it.

1. **Spec cites the wrong chat lines.** Spec "Keyboard (D11)" says the chat keys are at
   `chat-sidebar-nav.blade.php:26-40`. The handler spans `4-38`, `n` sits at `18-24`, and the
   window binding is line `40`. This plan moves all three keys, not only `j/k`. The spec line
   should read `:4-40`.
2. **`users.single_key_shortcuts` is a bool column, not jsonb.** D11's "smallest first step"
   (`b-triage-and-search.md`, section 11) stores the setting in a `users` jsonb column like
   `notification_preferences`. The contract table and the spec's `users` section say bool
   default true. The contract wins. A boolean with a constant default is a catalogue-only change
   in Postgres 11 and later, so no rewrite.
3. **The off switch covers every single-key shortcut, chat included.** WCAG 2.1.4 exempts
   focus-only keys, but one switch meaning "no letter keys anywhere" is simpler to explain and
   test. If overruled, chat's focused scope ignores the flag.
4. **The Done table lands in PR 7, but PR 5 owns `ConversationView::Done` and the Needs reply
   rule "not Done for the viewer".** PR 5 cannot join a table that does not exist. Decision: PR 5
   ships the `Done` case with its tab hidden and no Done predicate; PR 7 creates the table and
   adds the predicate to `needs_reply`, `all`-row `is_done`, and the `done` view. If the C plan
   already joins the table, PR 7's migration moves into PR 5 and PR 7 keeps only the actions.
5. **Bulk "Link to record" and the `l` key need PR 9's `LinkEmailToRecord`, and bulk "Change
   sharing" has no batch action in any contract.** PR 7 depends only on PR 6. Decision: PR 7's
   bar ships Done, Mark read and Mark unread, plus Reopen in the Done view. PR 9 adds the Link
   verb and the `EmailShortcut::Link` case. "Change sharing" and "Request access" move to PR 13,
   which owns sharing and access and adds a batch action for them. D12 lists both on the bar;
   the spec PR table gives PR 7 only "bulk bar".
6. **The four batch actions return an outcome.** The contract fixes only the input
   (`User $user, array $conversationKeys`). The spec requires the bar to say how many rows were
   skipped and why, so each action returns `Relaticle\EmailIntegration\Data\TriageOutcome`.
   Contract table addition in PR 7.
7. **`ConversationsQuery` gains `visibleKeys(User $viewer, array $conversationKeys): list<string>`.**
   The four actions and the PR 11 audience check need the same read: which of these keys the
   viewer may see. A read with several callers is a query method. Contract table addition in PR 7.
8. **The "after Done" setting (next, previous, close) is deferred.** The spec names it with no
   contract column. PR 7 ships "next" only.
9. **`s` (Summarize) is in the drawer header but not the keyboard table.** PR 14 adds the
   case. Composer keys and Undo-send's `z` come with PR 12. Cases for unmerged features are not
   added early, or the `?` sheet would list dead keys.
10. **Search orders by recency, not `ts_rank`.** The spec ranks by `ts_rank` then recency, but
    every view pages by cursor on `last_message_at desc, id desc` (PR 5 contract), which a rank
    cannot join. D13 also says "Results sort newest first". A rank needs offset paging for
    search, worth it only if the right thread often falls below the first page.
11. **Address search uses `ILIKE '%term%'` on a trigram index, not the `%` operator.** The spec
    says "`email_address % :q` or `ILIKE` prefix". Similarity at 0.3 misses a short fragment of
    a long address (`airbnb` in `brian.chesky@airbnb.com`); `gin_trgm_ops` serves a leading
    wildcard for terms of three or more characters. A second trigram index on
    `email_participants.name` follows the spec's "addresses and names".
12. **`pg_trgm` must be created.** The spec says it "already ships with `postgres:17-alpine`".
    It ships as a contrib module but no migration runs `create extension`. PR 8's first migration
    does, with `if not exists`. The rehearsal confirms the production role may create it (spec
    open question 3).
13. **The search backfill is a command, though core.md prefers a migration for SQL-only
    backfills.** Parsing 0.75 to 2.2 GB of text (D13) would hold the deploy transaction for
    minutes. The brief and the spec's deploy table call for a queued command. The `imports` lane
    allows 300 s and two tries, so the command stops at a time budget and queues itself again.
    If the rehearsal shows the whole run under 60 s, it moves into a non-transactional migration
    chunked with `eachById` and the command is dropped.
14. **D14's "replace the flat MCP parameters with the tree in the same change" is out of PR 8.**
    The spec's Agents section keeps `VisibleEmailsQuery` flat apart from `conversation_key`. PR 8
    changes what `search` matches on every surface (one owner), adds the tree to the page only,
    and leaves the MCP and chat argument shape alone. A follow-up issue under "AI Intelligence"
    carries the MCP tree.
15. **The filter owner is an enum, `Relaticle\EmailIntegration\Enums\EmailFilter`, not a class
    `EmailFilters`.** D14 names a class. A class under `src/Queries` must end in `Query`
    (`ConventionsTest.php:1078`), and each filter's operator word, chip label, tree field and
    operand type are pure functions of the filter, which `architecture.md` says live on the enum.
16. **`ConversationChanged` is broadcast from `RefreshConversationSummary`, not from each
    writer.** The spec names store, link and tier changes. PR 2's action already runs on every
    store, send, delete and purge, so one dispatch there covers them. Per-email sharing changes
    dispatch from `UpdateEmailSharingAction`. A mailbox-wide tier change
    (`SaveMailboxSharingTier`) does not broadcast per conversation: it can touch thousands. The
    client refetches on window focus instead. PR 9's link actions call
    `RefreshConversationSummary` or dispatch the event themselves; whichever of PR 7, PR 9 and
    PR 11 lands second adds the dispatch from its own actions. The sequencing chart puts PR 11
    first.
17. **The event also implements `ShouldBeUnique` and `ShouldDispatchAfterCommit`.** The contract
    names only the channel and payload. Uniqueness per workspace and key collapses a sync burst;
    after-commit keeps a rolled-back store silent.
18. **The broadcast audience is limited to members whose `current_workspace_id` is the
    conversation's workspace.** `VisibleEmailScope` binds the viewer's current workspace
    (`VisibleEmailScope.php:37-55`), so a member active elsewhere cannot be checked correctly and
    is skipped. D28 already has the client drop events for another workspace. The cost is a
    missed signal for a member who switches workspace and returns to the old tab, which the focus
    refetch covers.
19. **Search changes meaning for partial words.** Today `ricing` matches "Pricing". Full text
    matches whole words plus a prefix on the last one. Existing fragment assertions in
    `EmailsRelationManagerReadingTest.php` move to whole words or prefixes: a stale assertion
    under a spec-changed behaviour, not a weakened one.
---

# PR 7: Triage and keyboard

**Branch:** `feat/email-triage-and-keyboard`, from PR 6's branch until it merges, then rebased on
`main`.

**Goal:** Done, Mark read and Mark unread work on one conversation from the row, the drawer or a
key, and on many from a checkbox bar. Every action applies at once with a five-second Undo. One
shortcut map drives the Emails page, the `?` sheet and button hints. Single-letter keys never fire
while typing, and one setting turns all of them off, chat's included.

## File map

| File | Change | Owns |
|---|---|---|
| `database/migrations/2026_11_16_000001_create_email_conversation_states_table.php` | create | `email_conversation_states` per contract |
| `database/migrations/2026_11_16_000002_add_single_key_shortcuts_to_users_table.php` | create | `users.single_key_shortcuts` bool default true |
| `packages/EmailIntegration/src/Models/EmailConversationState.php` | create | model, `#[Table]`, `#[Fillable]`, `done_at` cast |
| `packages/EmailIntegration/src/Data/TriageOutcome.php` | create | `appliedKeys`, `skipped` counts per `TriageSkipReason` |
| `packages/EmailIntegration/src/Enums/TriageSkipReason.php` | create | `NotAvailable`, `NotInYourMailbox`, each with `label()` |
| `packages/EmailIntegration/src/Actions/MarkConversationsDone.php` | create | upsert `done_at = now()` per visible key |
| `packages/EmailIntegration/src/Actions/ReopenConversations.php` | create | delete the viewer's state rows |
| `packages/EmailIntegration/src/Actions/MarkConversationsRead.php` | create | set-based insert into `email_reads` for reached copies |
| `packages/EmailIntegration/src/Actions/MarkConversationsUnread.php` | create | delete the viewer's read row on the newest reached copy |
| `packages/EmailIntegration/src/Queries/ConversationsQuery.php` | modify | `visibleKeys()`, the Done predicate in `needs_reply`, `done` and `is_done` |
| `packages/EmailIntegration/src/Enums/EmailShortcut.php` | create | the email shortcut map |
| `packages/EmailIntegration/src/Enums/ShortcutScope.php` | create | `List`, `Reader`, `Composer`, `Global` |
| `resources/js/filament/shortcuts.js` | create | the one keydown dispatcher and `Alpine.store('shortcuts')` |
| `app/Providers/AppServiceProvider.php` | modify | register `shortcuts.js` in `registerPanelScripts()` |
| `app/Providers/Filament/AppPanelProvider.php` | modify | `BODY_START` hook rendering the user's flag |
| `resources/views/filament/app/shortcut-preferences.blade.php` | create | `<div hidden data-single-key-shortcuts="...">` |
| `app/Models/User.php` | modify | fillable, cast, `@property bool $single_key_shortcuts` |
| `app/Actions/User/UpdateKeyboardShortcuts.php` | create | the off switch write |
| `app/Livewire/App/Profile/UpdateKeyboardShortcuts.php` | create | toggle on the Profile page |
| `app/Filament/Pages/EditProfile.php` | modify | add the component to `form()` |
| `packages/Chat/resources/views/livewire/app/chat/chat-sidebar-nav.blade.php` | modify | `j/k/n` on the list's own `keydown`, honour the flag |
| `packages/EmailIntegration/resources/views/livewire/email-composer.blade.php` | modify | drop the inline `c` listener (`21-27`) |
| `packages/EmailIntegration/resources/views/components/shortcut-sheet.blade.php` | create | the `?` sheet |
| `packages/EmailIntegration/resources/views/components/triage-bar.blade.php` | create | floating action bar |
| `packages/EmailIntegration/resources/views/components/undo-toast.blade.php` | create | toast plus polite live region |
| `packages/EmailIntegration/resources/views/components/conversation-row.blade.php` | modify | checkbox, `aria-selected`, roving tabindex, hover pill wired to actions |
| `packages/EmailIntegration/resources/views/components/reader-drawer.blade.php` | modify | Done and Mark unread buttons with hints |
| `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php` | modify | renderless triage methods, shortcut store data, Done tab shown |
| `lang/en/filament/pages/email-inbox.php`, `lang/en/profile.php` | modify | copy |
| `tests/Feature/EmailIntegration/ConversationsPageTest.php` | modify | Done and read behaviour (file created by PR 5) |
| `tests/Feature/Profile/KeyboardShortcutsTest.php` | create | the off switch write |
| `tests/Browser/EmailIntegration/ConversationsKeyboardBrowserTest.php` | create | keys, guard, off switch, bar |
| `tests/Browser/Chat/KeyboardTest.php` | modify | chat keys need chat focus |
| `tests/Arch/ConventionsTest.php` | modify | no two shortcut cases share a key in overlapping scopes |

If PR 5 named its page test differently, extend that file instead and keep the test names below.

## Tasks

### Task 1: Done and read tests first, then the table and the actions

**Files:** `tests/Feature/EmailIntegration/ConversationsPageTest.php`, the two migrations,
`EmailConversationState.php`, `TriageOutcome.php`, `TriageSkipReason.php`, the four actions,
`ConversationsQuery.php`.

- [ ] Write these Livewire tests against `EmailInboxPage`, calling the page methods Task 2 adds
  (`markDone`, `reopen`, `markRead`, `markUnread`). Store mail through `StoreEmailAction` with
  fixtures, so `RefreshConversationSummary` runs as in production. Steer time with
  `$this->travelTo()` so `done_at` and the next message never share a second.
  - `it('brings a done conversation back to needs reply when the customer writes again')`:
    inbound from an external address, mark done, assert absent from `needs_reply` and present in
    `done`. Travel one hour, store a second external inbound with `In-Reply-To` the first. Assert
    present in `needs_reply`, absent from `done`.
  - `it('keeps a conversation done when a member replies')`: same start, then store an outbound
    reply from the viewer's mailbox and, separately, an inbound from a teammate's address (a
    member, so not external). Assert still absent from `needs_reply` and present in `done`.
  - `it('keeps a conversation done when an automated message arrives')`: inbound with
    `List-Id` after Done does not bring it back (`last_external_inbound_at` excludes automated).
  - `it('does not share done between two members holding the same conversation')`: Alice and Bob
    each have a mailbox holding a copy of the same Message-ID. Alice marks done. Bob's
    `needs_reply` still lists it and his `done` view does not. Alice's `done` lists it.
  - `it('refuses to mark a conversation done that the viewer cannot see')`: Bob's private-tier
    conversation key passed by Alice. Assert no `email_conversation_states` row and a skipped
    count of one with `NotAvailable`. This is the forged-key case, as `MarkEmailAsReadAction`
    guards today.
  - `it('marks unread only the viewer's own read state')`: Alice and Bob both read; Alice marks
    unread; Bob's row reads, Alice's shows `data-unread`.
  - `it('skips teammate copies that never reached the viewer when marking read')`: a linked
    full-tier teammate conversation returns `NotInYourMailbox` and writes no `email_reads` row,
    matching D7's "reached the viewer" rule.
  - `it('reopens a done conversation')`: Undo path.
- [ ] Run them and confirm they fail for the missing table and methods.
- [ ] `create_email_conversation_states_table`: `ulid id`, `foreignUlid workspace_id` cascade,
  `foreignUlid user_id` cascade, `string('conversation_key', 64)`, `timestamp done_at`,
  `timestamps()`, unique `(workspace_id, user_id, conversation_key)`. New table, no rehearsal.
- [ ] `add_single_key_shortcuts_to_users_table`: `boolean('single_key_shortcuts')->default(true)`.
- [ ] `EmailConversationState` with `#[Table('email_conversation_states')]`,
  `#[Fillable(['workspace_id', 'user_id', 'conversation_key', 'done_at'])]`, `done_at` cast to
  `immutable_datetime`.
- [ ] `ConversationsQuery::visibleKeys(User $viewer, array $conversationKeys): list<string>`:
  distinct `conversation_key` from delivered emails under `VisibleEmailScope($viewer)`, bound to
  the viewer's workspace, `whereIn` the keys. Same delivered rule as
  `VisibleEmailsQuery::whereDelivered` (`VisibleEmailsQuery.php:143-150`).
- [ ] Done predicate in `ConversationsQuery`: a `leftJoin` on
  `email_conversation_states` for `(workspace_id, viewer id, conversation_key)`; Done when
  `done_at is not null and (last_external_inbound_at is null or done_at >= last_external_inbound_at)`.
  `needs_reply` adds the negation, `done` the predicate ordered `done_at desc`, and every row
  sets `is_done`. No job ever clears a row: a newer inbound beats the timestamp.
- [ ] The four actions, each `final readonly`, one `execute(User $user, array $conversationKeys): TriageOutcome`:
  - Authorize with `abort_unless($user->can('viewAny', Email::class), 403)`, reject more than
    `MAX_KEYS = 100` keys with a validation error, de-duplicate.
  - `visibleKeys()` first; the rest count as `NotAvailable`.
  - `MarkConversationsDone`: one `upsert` on the unique key with `done_at = now()`.
  - `ReopenConversations`: delete the viewer's rows for the visible keys.
  - `MarkConversationsRead`: one `insert ... on conflict (email_id, user_id) do nothing` via
    `EmailRead::query()->insertOrIgnore()` for copies with `user_id = viewer` or shared to the
    viewer after the share date (the PR 5 `is_unread` rule). Keys with no reached copy count as
    `NotInYourMailbox`.
  - `MarkConversationsUnread`: delete the viewer's read row on the newest reached copy per key.
- [ ] Run the tests until they pass. Commit `feat(email): add per-user done and batch read actions`.

**Check:** the eight tests pass; `composer test:arch` passes (no `Action` suffix, one public
method).

### Task 2: Triage on the page, the drawer and the row, with Undo

**Files:** `EmailInboxPage.php`, `conversation-row.blade.php`, `reader-drawer.blade.php`,
`undo-toast.blade.php`, `lang/en/filament/pages/email-inbox.php`.

- [ ] `EmailInboxPage` gains `markDone`, `reopen`, `markRead`, `markUnread`, each
  `#[Renderless]`, taking `list<string> $keys`, resolving the action and returning
  `TriageOutcome::toArray()` to Alpine. They `unset($this->counts)` so the next render reads
  fresh counts. No Eloquent write sits in the page.
- [ ] Alpine on the list owns optimistic state: hide or restyle the affected rows at once, move
  focus to the next row (Done advances, per the spec), call the method, and restore rows if the
  call throws. The spec budget is 100 ms visible for Done and advance.
- [ ] `undo-toast` shows "Done in Relaticle" (D10 risk note: users expect Gmail to follow), the
  count, an Undo button, and announces "Done. Press z to undo." in an `aria-live="polite"`
  region. Undo calls the inverse method (`reopen` for Done, `markUnread` for read and back). The
  toast lasts five seconds. A second action replaces the toast and keeps only the newest undo.
- [ ] Row hover pill (PR 5 mounts it on hover and focus): Done, Mark unread call the same Alpine
  handlers. Drawer header overflow (PR 6): Done and Mark unread call them and keep the drawer on
  the next conversation after Done. Opening a locked copy still never marks it read (spec D9).
- [ ] Show the Done tab that PR 5 hid (Plan note 4). Empty state copy for Done: "Conversations you
  mark Done appear here. They come back when someone writes again."
- [ ] Commit `feat(email): triage conversations from the row and drawer`.

**Check:** Livewire test `it('returns the skipped count to the page')` in
`ConversationsPageTest.php`; manual click-through of Done, Undo, Mark unread at 1440 px.

### Task 3: The shortcut map and the dispatcher

**Files:** `EmailShortcut.php`, `ShortcutScope.php`, `resources/js/filament/shortcuts.js`,
`AppServiceProvider.php`, `AppPanelProvider.php`, `shortcut-preferences.blade.php`, `User.php`,
`tests/Arch/ConventionsTest.php`.

- [ ] `EmailShortcut` cases for PR 7: `Next` (`j`), `Previous` (`k`), `Open` (`Enter`, `o`),
  `Close` (`Escape`), `Reply` (`r`), `ReplyAll` (`a`), `Forward` (`f`), `Done` (`e`),
  `MarkUnread` (`u`), `Select` (`x`), `FocusSearch` (`/`), `Compose` (`c`), `Help` (`?`),
  `Undo` (`z`). Methods, each a `match` with no `default`:
  - `keys(): list<string>` in `event.key` form, modifiers as `Mod+` where needed.
  - `scopes(): list<ShortcutScope>`: per the spec table. `j/k` are `List` and `Reader` (moves the
    drawer); `e` and `u` are `List` and `Reader`; `Escape` is `Reader` and `Composer`.
  - `label(): string` through `__()`.
  - `action(): string`, the Alpine handler name.
  - `isCharacterKey(): bool`: true when a key is one printable character with no modifier. The
    off switch disables exactly these. `Enter` and `Escape` are not character keys.
  - `firesInEditors(): bool`: true only for `Close`.
  - static `forStore(): list<array{keys: list<string>, scopes: list<string>, label: string, action: string, character: bool, editors: bool}>`.
- [ ] Arch test in `ConventionsTest.php`, `it('gives every email shortcut key one meaning per scope')`:
  for each pair of cases sharing a key, their scopes do not overlap, with `Global` overlapping
  every scope. Plant a duplicate `e` in `Reader` locally to prove the test fails, then remove it.
  This is the collision gate D11 asks for, and it reads structure, not behaviour.
- [ ] `shortcuts.js`: on `alpine:init`, `Alpine.store('shortcuts', ...)` with:
  - `singleKeys`: read from `[data-single-key-shortcuts]` on every `livewire:navigated`.
  - `register(name, {entries, handlers, isActive})` and `unregister(name)`. Components register
    on `init` and unregister on `destroy`, so `wire:navigate` never leaves stale handlers.
  - one `keydown` listener on `document`. It returns early when a modifier other than the entry's
    own is held, when the target is `input`, `textarea`, `select` or `isContentEditable` (unless
    the entry `editors` is true), when the target is inside `[role=dialog]` other than the
    shortcut sheet, `[role=menu]`, `[role=listbox]` or `.fi-modal`, when `.fi-modal-open` exists,
    or when the chat side panel is visible (the guards copied from
    `chat-sidebar-nav.blade.php:5-16`, now in one place). A character entry also needs
    `singleKeys`.
  - Match `event.key`; when it is not a Latin letter, fall back to `event.code` (`KeyJ` to `j`),
    so Cyrillic and Armenian layouts work (D11).
  - Resolve active scopes newest first: composer, reader, list, global. The first registered
    component whose `isActive()` is true and whose entries contain the key handles it and calls
    `preventDefault()`. Nothing else is swallowed, so Ctrl K and Cmd J reach Filament and chat.
  - `allows(event)`: the same guard, exposed for chat (Task 5).
- [ ] Register it in `AppServiceProvider::registerPanelScripts()` as
  `Js::make('shortcuts', resource_path('js/filament/shortcuts.js'))`, then run
  `php artisan filament:assets` (the comment at `AppServiceProvider.php:856`).
- [ ] `AppPanelProvider` `BODY_START` hook renders `filament.app.shortcut-preferences` with
  `auth()->user()?->single_key_shortcuts ?? true`. `User` gets the fillable entry, the `boolean`
  cast and the property docblock.
- [ ] Commit `feat(email): add one scoped shortcut map with an off switch`.

**Check:** `composer test:arch`; in agent-browser,
`window.Alpine.store('shortcuts').singleKeys === true` on the dashboard.

### Task 4: Keys on the Emails page, the `?` sheet and hints

**Files:** `EmailInboxPage.php` (view data), `conversation-row.blade.php`,
`reader-drawer.blade.php`, `shortcut-sheet.blade.php`, `email-composer.blade.php`.

- [ ] The page view passes `EmailShortcut::forStore()` into an Alpine component that registers
  `email-list` (active while no drawer has focus) and `email-reader` (active while the drawer is
  open). Handlers: `next`, `previous` (roving tabindex over `[data-conversation]`, scroll into
  view, move the drawer when open), `open`, `close` (through PR 6's `closeReader()` so drafts
  save), `done`, `markUnread`, `select`, `focusSearch`, `reply`, `replyAll`, `forward` (PR 6
  buttons), `help`, `undo`.
- [ ] With nothing selected, list actions apply to the focused row, else the hovered row (spec).
  With a selection, `e` and `u` apply to the selection.
- [ ] Remove the composer's inline `c` binding (`email-composer.blade.php:21-27`). The global
  `Compose` handler, registered by the floating composer, dispatches `composer:open` as before.
  This brings `c` under the off switch.
- [ ] `shortcut-sheet`: a `role="dialog"` with `aria-labelledby`, focus trapped, Esc closes,
  groups by scope, each row shows the key in `<kbd>` and the label. Footer: "Single-key
  shortcuts are on" with a link to Profile. Rendered once from the email page and from the
  global `?` handler on other pages, where only `Global` rows show.
- [ ] Hints: each button with a shortcut gets `aria-keyshortcuts` and a tooltip such as
  "Done (E)", read from the store, so hints and handlers cannot drift. When `singleKeys` is
  false, character hints are hidden.
- [ ] Rows get `role="option"` inside a `role="listbox"` list, `aria-selected` for focus,
  checkbox state in `aria-checked` on the checkbox only (WCAG 2.1.1: every action keeps a
  labelled button).
- [ ] Commit `feat(email): drive the conversations list from the keyboard`.

**Check:** Task 7's browser tests.

### Task 5: Move chat's keys into a focus scope

**Files:** `chat-sidebar-nav.blade.php`, `tests/Browser/Chat/KeyboardTest.php`.

- [ ] Write the failing browser tests first in `KeyboardTest.php`:
  - `it('ignores j on the dashboard until the chat list has focus')`: seed two chats, open the
    dashboard, press `j`, assert the path is unchanged. Focus the first chat link, press `j`,
    assert the path is the second chat.
  - `it('ignores chat letter keys when single-key shortcuts are off')`: same, with
    `single_key_shortcuts` false, focused list, `j` does nothing.
- [ ] Replace `@keydown.window="onKeydown($event)"` (line 40) with `@keydown="onKeydown($event)"`
  on the `<li>`, so the handler runs only for key events from inside the chat list. Inside
  `onKeydown`, replace the inline guards (lines 5-16) with
  `if (! $store.shortcuts?.allows(e)) return;`, keeping a fallback to the current guard when the
  store is absent. The `n` (dashboard) branch moves with it.
- [ ] Commit `fix(chat): scope sidebar letter keys to the focused chat list`.

**Check:** both tests pass in Chromium; the existing `SidebarAllChatsPanelTest.php` still passes.

### Task 6: The checkbox column and the floating bar

**Files:** `conversation-row.blade.php`, `triage-bar.blade.php`, `EmailInboxPage.php` view,
lang.

- [ ] Row checkbox: visible on hover, focus and whenever any row is selected; `x` toggles the
  focused row; Shift-click selects a range in page order; a header checkbox selects the loaded
  page only. Selection lives in Alpine (a `Set` of keys) and clears on view, filter or search
  change.
- [ ] `triage-bar`: fixed at the bottom centre with `role="toolbar"`, "N selected", Mark read,
  Mark unread, Done (Reopen instead in the Done view), Clear. After an action it reads "Done for
  12. 2 skipped: not available." using `TriageSkipReason::label()` and the returned counts. The
  count only ever names rows the viewer can see (D12 risk). At 390 px it spans the width, shows
  icons with labels under them, and moves Clear into an overflow.
- [ ] One call per verb with all selected keys; a selection above `MAX_KEYS` is impossible
  because a page holds 50.
- [ ] Commit `feat(email): select conversations and act on them in bulk`.

**Check:** Livewire test `it('marks every selected conversation done in one call and reports the skipped ones')`
in `ConversationsPageTest.php`.

### Task 7: The off switch, browser tests and verification

**Files:** `UpdateKeyboardShortcuts` action and component, `EditProfile.php`,
`tests/Feature/Profile/KeyboardShortcutsTest.php`,
`tests/Browser/EmailIntegration/ConversationsKeyboardBrowserTest.php`, `lang/en/profile.php`.

- [ ] `App\Actions\User\UpdateKeyboardShortcuts::execute(User $user, bool $enabled): void`
  writes the column. The Livewire component on Profile shows one toggle, "Single-key shortcuts",
  with the help line "Letter keys such as J, K and E act on the page. Turn off if you use speech
  input." Feature test: `it('turns single-key shortcuts off from the profile page')`, and the
  `BODY_START` flag renders `false` afterwards.
- [ ] Browser tests, `->with(['inLightMode', 'inDarkMode'])`, seeded with three conversations:
  - `it('moves through conversations with j and k and marks done with e')`.
  - `it('does not fire shortcuts while typing in the search field')`: focus the search input,
    type `jeu`, assert the input holds `jeu`, no row lost focus, no row left `needs_reply`, and
    the drawer did not move. Repeat inside the reply editor of the open drawer.
  - `it('disables letter keys but keeps modified and named keys when single-key shortcuts are off')`:
    set the flag false. `j`, `e`, `c` and `?` do nothing. `Escape` closes the open drawer,
    `Enter` opens the focused row, and Ctrl K still opens Filament's search field.
  - `it('undoes done with z while the toast is visible')`.
  - `it('selects a range with shift-click and clears it after mark read')`.
  - `it('lists the shortcuts in the help sheet')`: `?` opens it, Esc closes it, focus returns.
- [ ] Run each changed browser file once in WebKit: `php artisan test --compact <file> --browser safari`.
- [ ] UI verification (see "Verification per PR").
- [ ] Commit `test(email): cover triage keys and the off switch in the browser`.

## PR 7 checklist

- [ ] Every test named in Tasks 1, 2, 5, 6 and 7 exists and passes locally.
- [ ] `composer test:arch` passes, including the shortcut collision test.
- [ ] Contract table updated: `TriageOutcome` return, `ConversationsQuery::visibleKeys()`.
- [ ] `phpstan.neon` needs no new ignore; no Eloquent write in the page or components.
- [ ] `php artisan filament:assets` run and the published `public/js/app/shortcuts.js` committed
  if the repository tracks the other published copies.
- [ ] Screenshots at 1440 and 390 px, light and dark: list with selection and bar, toast, `?`
  sheet, Done empty state, Profile toggle.
- [ ] Chat `j/k` checked by hand on the dashboard and on a chat page.
- [ ] If PR 11 merged first: the four actions dispatch `ConversationChanged` per applied key
  (Plan note 16), and the PR 11 test file gains a case for Done.
- [ ] PR body states that Link to record, Change sharing and Request access come with PRs 9 and 13.

---

# PR 8: Search and filters

**Branch:** `feat/email-search`, from PR 5's branch until it merges.

**Goal:** One search box finds conversations by a word in the subject or body and by any fragment
of a participant name or address, through `EmailSearchService` on every surface, never matching
text above the viewer's tier. Chips and typed operators narrow the list through one filter owner.

## File map

| File | Change | Owns |
|---|---|---|
| `database/migrations/2026_11_09_000001_add_search_vectors_to_emails_table.php` | create | `pg_trgm`, the two columns, the two trigger functions and triggers |
| `database/migrations/2026_11_09_000002_index_email_search_columns.php` | create | four GIN indexes, `create index concurrently`, `$withinTransaction = false` |
| `database/migrations/2026_11_09_000003_queue_email_search_vector_backfill.php` | create | queues the command |
| `packages/EmailIntegration/src/Console/Commands/BackfillEmailSearchVectorsCommand.php` | create | `email:backfill-search-vectors` |
| `packages/EmailIntegration/src/EmailIntegrationServiceProvider.php` | modify | register the command |
| `packages/EmailIntegration/src/Services/EmailSearchService.php` | modify | full text and trigram branches |
| `packages/EmailIntegration/src/Enums/EmailFilter.php` | create | filter names, operator words, chip labels, operand kinds |
| `packages/EmailIntegration/src/Data/ConversationFilters.php` | modify | `fromTree()`, `withTyped()`, `toTree()` |
| `packages/EmailIntegration/src/Queries/ConversationsQuery.php` | modify | compile each filter, call the search service |
| `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php` | modify | `#[Url] filter`, search state, chip actions |
| `packages/EmailIntegration/resources/views/components/filter-chip-bar.blade.php` | create | chips and the "Linked to" picker |
| `lang/en/filament/pages/email-inbox.php`, `lang/en/validation.php` | modify | chip and error copy |
| `tests/Feature/EmailIntegration/EmailsRelationManagerReadingTest.php` | modify | tier-gated body search, trigram, stale fragment assertions |
| `tests/Feature/EmailIntegration/ConversationsPageTest.php` | modify | operators, chips, search on the page |
| `tests/Feature/Mcp/EmailToolsTest.php` | modify | the MCP list matches body text at FULL only |
| `tests/Browser/EmailIntegration/ConversationsSearchBrowserTest.php` | create | search box, operator to chip, 390 px |

## Tasks

### Task 1: Measure before writing a migration

- [ ] Run the spec's one read-only aggregate in production (open question 4):
  `select count(*), avg(octet_length(body_text)), percentile_cont(array[0.5,0.95,0.99]) within group (order by octet_length(body_text)) from email_bodies`.
  Record the numbers in the PR body. They size the index and decide whether the 64 KB cap
  matters.
- [ ] Confirm on production with a read-only query that the migration role may create
  extensions: `select has_database_privilege(current_user, current_database(), 'CREATE')` and
  `select * from pg_available_extensions where name = 'pg_trgm'`.

**Check:** both answers in the PR draft before Task 2 starts. If the role cannot create
`pg_trgm`, stop and raise it: the trigram half needs the hosting provider.

### Task 2: Tier-gated search tests first

**Files:** `EmailsRelationManagerReadingTest.php`, `tests/Feature/Mcp/EmailToolsTest.php`.

- [ ] Add, through the record tab's `search` property and the MCP `list-emails` tool:
  - `it('matches a body word for the owner but not for a metadata-tier teammate')`: Alice's
    mailbox at `metadata_only`, the email linked to a company both can see (PR 3's rule), body
    contains "kumquat". Alice's search for `kumquat` finds it; Bob's returns nothing on the tab
    and an empty list from the tool.
  - `it('matches a subject word at subject tier but not a body word')`.
  - `it('applies a metadata share over a full email default')`: FULL email, Bob holds a
    `metadata_only` share; `kumquat` does not match for Bob (`.ai/rules/email-integration.md`,
    "Per-viewer shares override the email default in search").
  - `it('finds an address by a fragment in the middle')`: participant
    `brian.chesky@airbnb.com`; `irbnb` and `AIRBNB` both match.
  - `it('finds a word stored after the email was created')`: store through `StoreEmailAction`,
    then search a body word. Proves the body trigger, not a backfill.
  - `it('matches the last word as a prefix')`: `kumq` finds "kumquat".
  - `it('does not match another member's bcc row')`: unchanged gate, kept as a regression.
- [ ] Update any existing assertion that searches a mid-word fragment of a subject or snippet to
  a whole word or prefix (Plan note 19). Name each changed test in the PR body.
- [ ] Run: the new body, prefix and trigger tests fail against `ILIKE` on `snippet` only.

### Task 3: Columns, triggers and the extension

**Files:** migration `000001`.

- [ ] One transactional migration, `up()` only:
  - `set local lock_timeout = '5s'`, so the `alter table` fails fast instead of queueing every
    write behind a long read. A failed deploy is retried.
  - `create extension if not exists pg_trgm`.
  - `alter table emails add column if not exists subject_search tsvector, add column if not exists body_search tsvector`.
    Nullable columns with no default add without a rewrite.
  - Function `emails_subject_search_refresh()`, `BEFORE INSERT OR UPDATE OF subject ON emails`:
    `new.subject_search := to_tsvector('simple', coalesce(new.subject, ''))`.
  - Function `emails_body_search_refresh()`, `AFTER INSERT OR UPDATE OF body_text ON email_bodies`:
    `update emails set body_search = to_tsvector('simple', coalesce(emails.snippet, '') || ' ' || left(coalesce(new.body_text, ''), 65536)) where emails.id = new.email_id`.
    The snippet joins the body vector because the spec gates snippet and body at FULL together.
  - Function `emails_snippet_search_refresh()`, `BEFORE UPDATE OF snippet ON emails`: recompute
    `new.body_search` from the stored body, so a changed snippet never leaves a stale vector.
  - Use `create or replace trigger` (Postgres 14 and later) so a rerun is safe.
- [ ] `StoreEmailAction` needs no change: it inserts the email, then the body
  (`StoreEmailAction.php:62-90`), so the body trigger fills `body_search` in the same transaction.
- [ ] Run the Task 2 tests: the trigger test passes; others may still fail on the service.

### Task 4: Concurrent indexes

**Files:** migration `000002`.

- [ ] Copy the shape of `2026_10_21_000002_index_inbox_columns_on_notifications_table.php`:
  `public $withinTransaction = false;`, an `INDEXES` constant, and `dropInvalidIndex()` before each
  statement.
  - `emails_subject_search_idx`: `create index concurrently if not exists ... on emails using gin (subject_search)`.
  - `emails_body_search_idx`: same on `body_search`.
  - `email_participants_email_address_trgm_idx`: `using gin (email_address gin_trgm_ops)`.
  - `email_participants_name_trgm_idx`: `using gin (name gin_trgm_ops)`.
- [ ] No partial index on `workspace_id`: every query already bounds the workspace through the
  btree on `(workspace_id, ...)` (`create_emails_table.php:61-63`) and the planner combines them
  with a bitmap AND. `btree_gin` is not added; revisit only if the rehearsal shows the bitmap AND
  over a large workspace above the 400 ms budget.

**Check:** `php artisan migrate` on a local copy runs both migrations; `\d emails` shows the
columns, triggers and indexes.

### Task 5: The backfill command, queued from a migration

**Files:** `BackfillEmailSearchVectorsCommand.php`, the service provider, migration `000003`.

- [ ] `email:backfill-search-vectors {--force} {--workspace=} {--max-seconds=240}`.
  - Without `--force`: print the count of emails with `body_search is null`, per workspace when
    `--workspace` is absent.
  - With `--force`: walk `DB::table('emails')->whereNull('body_search')->select('id')` with
    `chunkById(1000)` and, per chunk, one set-based
    `update emails set subject_search = ..., body_search = ... from emails e left join email_bodies b on b.email_id = e.id where emails.id = e.id and e.id in (...)`.
    A row with no body gets the snippet vector, so it never stays null. The `DB` facade carries
    no `WorkspaceScope`, and the command touches only search columns, so no workspace binding is
    needed.
  - Stop when `--max-seconds` passes and, if rows remain, queue itself again with the same
    `Artisan::queue(...)->onQueue('imports')->delay(now()->addMinute())`. The `imports` lane
    allows 300 s per try (core.md). Rerunning is safe because the filter is `whereNull`.
- [ ] Migration `000003`: `Artisan::queue('email:backfill-search-vectors', ['--force' => true])->onQueue('imports')->delay(now()->addMinutes(5))->afterCommit()`.
  `ConventionsTest` fails if the command name is wrong or the delay is missing.
- [ ] No test for the command or the migrations (testing.md). The rehearsal proves them.

### Task 6: The search service on full text and trigrams

**Files:** `EmailSearchService.php`.

- [ ] Keep `applyToQuery(Builder|Relation $query, User $viewer, string $search)` and its three
  branches. Change only the match in each:
  - Participants: keep the BCC role gate (`EmailSearchService.php:30-44`) and the `ilike`
    `%term%` on `name` and `email_address`, now served by the trigram indexes. Escape `%`, `_`
    and `\` in the term so a typed underscore is literal.
  - Subject: `whereRaw('emails.subject_search @@ (' . $tsQuery . ')', $bindings)` plus
    `whereViewerCanSeeSubject()`.
  - Body: the same on `body_search` plus `whereViewerCanSeeBody()`. The snippet `ilike` goes.
- [ ] Private `tsQuery(string $search): array{0: string, 1: list<string>}`: SQL
  `websearch_to_tsquery('simple', ?)`, and when the search ends in a bare word of letters and
  digits, `&& to_tsquery('simple', ?)` with that word plus `:*` for a prefix match. The word is
  matched with `/[\p{L}\p{N}]+$/u` before it reaches `to_tsquery`, so no user text is parsed as
  tsquery syntax. A search with no word left skips both full-text branches.
- [ ] Qualify every column with `emails.` so the service works under `VisibleEmailsQuery`, the
  record tab's relation and `ConversationsQuery`'s copies subquery alike.
- [ ] The Task 2 tests pass. Run `EmailsRelationManagerReadingTest.php`, `EmailToolsTest.php`
  (MCP and chat) and `VisibleEmailScopeTest.php`.

### Task 7: One filter owner and typed operators

**Files:** `EmailFilter.php`, `ConversationFilters.php`, `ConversationsQuery.php`, lang.

- [ ] `EmailFilter` cases: `Mailbox`, `From`, `To`, `Direction`, `HasAttachment`, `SentAt`,
  `Record` (company, person or opportunity), `Category`. Each a `match` with no `default`:
  - `field(): string` for the tree (`mailbox`, `from`, `to`, `direction`, `has_attachment`,
    `sent_at`, `record`, `category`).
  - `operators(): list<string>` from the `app/Queries` grammar: `$in` for mailbox, direction,
    category and record; `$contains` for from and to; `$eq` for has_attachment; `$gte` and
    `$lte` for sent_at.
  - `typedWords(): list<string>`: `from`, `to`, `has` (only `attachment`), `after` and `before`
    (to `SentAt`), `company`, `person`, `opportunity` (to `Record`).
  - `label(): string` for the chip.
- [ ] `ConversationFilters` keeps PR 5's fields and adds:
  - static `fromTree(array $tree, string $viewerZone): self`. Validates against `EmailFilter`
    only, throws `FilterErrors::at('filter.<field>', ...)` with `validation.filter.*` messages,
    parses operands with `Operand::date()`, `Operand::boolean()` and `Operand::stringList()`,
    and caps lists at `CustomFieldFilterSchema::MAX_LIST_VALUES`. `FilterTree::validate()` is not
    called: it takes a `CrmEntity` (`FilterTree.php:48`).
  - `withTyped(string $input): self`: splits `word:value` tokens. A token whose word
    `EmailFilter` knows becomes a filter; quotes allow spaces (`from:"Ana Lopez"`). Unknown
    words, and known words with an invalid value such as `after:soon`, stay in `search` as free
    text, per the spec.
  - `toTree(): array` for the URL and the chips.
- [ ] `ConversationsQuery` compiles each filter inside its copies subquery, after the visibility
  bound, so a filter can only narrow what the viewer may see:
  - `From` and `To`: `whereHas('participants')` with the role (`from`, or `to`/`cc`, plus `bcc`
    only for the viewer's own copies) and the same `ilike` as search. A metadata-tier row shows
    participants, so it may match (D14 trust test).
  - `Record`: `whereHas` on `emailables`, each id checked to be in the viewer's workspace, as
    `VisibleEmailsQuery::restrictToRecord()` does (`VisibleEmailsQuery.php:153-169`).
  - `company:Acme` resolves the name to company ids through `Company::query()` bound to the
    viewer's workspace, `ilike` prefix, at most `MAX_LIST_VALUES`.
  - `search`: `EmailSearchService::applyToQuery()`. Never a raw `where` on text (D14 risk).
- [ ] Tests in `ConversationsPageTest.php`:
  - `it('turns from and has attachment operators into filters and keeps the rest as words')`:
    `from:airbnb.com has:attachment pricing foo:bar` filters to the one matching conversation,
    and `search` holds `pricing foo:bar`.
  - `it('keeps an invalid date operator as free text')`.
  - `it('rejects an unknown filter field in the url with a validation message')`: a crafted
    `?filter[subject][$contains]=x` renders the error, not a 500, and lists nothing extra.
  - `it('does not match another member's bcc address through to')`.
  - `it('filters to one company through company:')`.

### Task 8: The search box and chip bar on the page

**Files:** `EmailInboxPage.php`, `filter-chip-bar.blade.php`, lang.

- [ ] `#[Url(as: 'q')] public string $search` and `#[Url(as: 'filter')] public array $filter`.
  The search input debounces 300 ms with `wire:model.live.debounce.300ms`. On submit, recognised
  operators leave the box and become chips. An invalid tree from the URL shows the error under
  the bar and lists as if no filter were set.
- [ ] Chips: Mailbox (the viewer's own mailboxes), Has attachment, Date (after, before, or both,
  in the viewer's time zone), Linked to (the existing linked-records picker from
  `resources/js/filament/linked-records-picker.js`), Category. Each active chip shows its value
  and an `x` with an accessible label. "Clear all" appears with two or more chips.
- [ ] `/` focuses the search box through PR 7's `FocusSearch` handler when PR 7 has merged.
  Otherwise the box keeps its own `x-on:keydown.slash.window` guard until PR 7 removes it.
- [ ] Empty result copy: "No conversations match. Search covers subjects and bodies you can read,
  and every name and address."
- [ ] 390 px: the chip bar scrolls horizontally in one line; the search box takes the full width.
- [ ] Browser test `ConversationsSearchBrowserTest.php`, light and dark:
  `it('turns a typed operator into a chip and back into text when removed')`,
  `it('finds a conversation by a body word')`, `it('keeps search and chips in the url across reload')`.
  Run once with `--browser safari`.

### Task 9: Rehearsal and verification

- [ ] Rehearse per core.md and "Rehearsal: PR 8" below.
- [ ] UI verification per "Verification per PR".
- [ ] `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:lint`,
  `composer test:arch`, then push and watch the `Tests` workflow.

## Rehearsal: PR 8

Run on anonymised production data, per core.md, Database. Personal columns are replaced in the
export query, but body length must survive, because it is what the rehearsal measures. Replace
`body_text` with `repeat('lorem ', greatest(1, octet_length(body_text) / 6))` and `subject` with
a word count of the same length, so vector and index sizes stay realistic.

1. Export: `pg_dump -s`; `pg_dump -a -t migrations`; `\copy` of `emails`, `email_bodies`,
   `email_participants` (names and addresses replaced, domains kept so trigram selectivity
   holds), `email_shares`, `emailables`, `connected_accounts`, `workspace_user`, `users` (ids,
   `current_workspace_id` only).
2. Load into a scratch database with `set session_replication_role = replica`. Save before-state:
   `select count(*) from emails`, `select pg_total_relation_size('emails')`,
   `select pg_total_relation_size('email_participants')`.
3. `DB_DATABASE=<scratch> php artisan migrate --force`. Confirm only the three new migrations
   ran. Record:
   - wall time of migration `000001`, and the longest lock wait, from
     `log_lock_waits = on` and `log_min_duration_statement = 0` on the scratch server;
   - per index in `000002`: build time and `pg_relation_size('<index>')`;
   - while `000002` runs, a concurrent `insert` into `emails` from a second session succeeds
     (proves `concurrently` holds no write lock).
4. Run `php artisan email:backfill-search-vectors --force --max-seconds=100000` on the scratch
   database. Record total time, rows per second, `pg_total_relation_size('emails')` after, and
   dead tuples from `pg_stat_user_tables`. Then `vacuum (analyze) emails` and record its time.
5. After-state: zero rows with `body_search is null`; row counts unchanged; for the largest
   workspace (156,632 emails), `explain (analyze, buffers)` of the page's search for a rare word,
   a common word and an address fragment, as the owner and as a metadata-tier teammate. Record
   p95 over 20 runs against the 400 ms budget.
6. Run `migrate` again: a no-op. Drop the scratch database and delete the export.

The PR body carries every number. Stop and reconsider (D13, "What would change our mind") if
p95 exceeds 500 ms on the largest mailbox, or if the body GIN index is larger than the `emails`
heap. If the backfill takes over three times longer with the indexes present than without, move
`000002` into a follow-up PR that ships after the backfill finishes.

## PR 8 checklist

- [ ] Task 1 aggregates and the extension privilege recorded in the PR body.
- [ ] Rehearsal numbers: lock time, index build time and size per index, backfill time, p95.
- [ ] Every Task 2 and Task 7 test exists and passes; changed stale assertions listed.
- [ ] `EmailSearchService` is still the only place that matches text; `rg "'ilike'" packages/EmailIntegration/src` shows only the participant branch and existing non-mail uses.
- [ ] Screenshots: search with results, operator chip, empty result, invalid filter message,
  390 px chip bar, light and dark.
- [ ] PR body states the search behaviour change for partial words (Plan note 19) and that MCP
  keeps flat filters (Plan note 14).
- [ ] Deploy note: none beyond `migrate`. Self-hosted installs get the backfill through the
  queued command.

---

# PR 11: Live updates

**Branch:** `feat/email-live-updates`, from PR 5's branch until it merges.

**Goal:** When a conversation changes, every member who may see it gets a signal holding only the
workspace id and the conversation key. Their open Emails page or record Emails tab refetches that
row and the counts through `ConversationsQuery`. A member who may not see it gets nothing.

## File map

| File | Change | Owns |
|---|---|---|
| `packages/EmailIntegration/src/Events/ConversationChanged.php` | create | the broadcast event (new `Events` folder) |
| `packages/EmailIntegration/src/Queries/ConversationsQuery.php` | modify | counts cache key, `rows(User, list<string>)` for refetch |
| `packages/EmailIntegration/src/Actions/RefreshConversationSummary.php` | modify | dispatch after the upsert |
| `packages/EmailIntegration/src/Actions/UpdateEmailSharingAction.php` | modify | dispatch for the shared email's conversation |
| `packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php` | modify | `refreshConversations(list<string>)` |
| `packages/EmailIntegration/src/Filament/RelationManagers/BaseEmailsRelationManager.php` | modify | the same method on record tabs |
| `packages/EmailIntegration/resources/views/components/live-conversations.blade.php` | create | Alpine Echo listener with debounce |
| `tests/Feature/EmailIntegration/ConversationChangedBroadcastTest.php` | create | audience and payload |
| `tests/Feature/EmailIntegration/ConversationsPageTest.php` | modify | refetch adds, updates and removes rows |
| `tests/Browser/EmailIntegration/ConversationsLiveBrowserTest.php` | create | subscription and debounce |

## Tasks

### Task 1: Audience tests first

**Files:** `ConversationChangedBroadcastTest.php`.

- [ ] One dataset: workspace with Alice (owner of the mailbox), Bob (teammate), Carol (teammate),
  Dan (member whose `current_workspace_id` is another workspace), and Eve (member of another
  workspace). Alice's mailbox stores an inbound email linked to a company.
- [ ] Tests, each storing through `StoreEmailAction` with `Event::fake([ConversationChanged::class])`
  and reading channels from the dispatched event's `broadcastOn()`:
  - `it('signals the owner and a teammate who may see the linked conversation')`: mailbox at
    `metadata_only`; channels are `private-App.Models.User.{alice}` and `{bob}`.
  - `it('does not signal a teammate when the mailbox is private')`: tier `private`; only Alice.
  - `it('does not signal a teammate when the conversation is linked to no record')`: PR 3's
    linked-only rule; only Alice.
  - `it('does not signal a teammate blocked by a workspace visibility rule')`: participant on
    the workspace blocklist; only Alice.
  - `it('does not signal a member whose current workspace is another one')`: Dan never appears
    (Plan note 18). Eve never appears.
  - `it('carries only the workspace id and the conversation key')`:
    `expect(array_keys($event->broadcastWith()))->toBe(['workspace_id', 'conversation_key'])`
    and `broadcastAs()` is `email.conversation.changed`.
  - `it('sends nothing when the store rolls back')`: force a failure after the summary refresh
    inside the transaction; `Event::assertNotDispatched`.
  - `it('signals a teammate after the owner shares the email')`: private tier, then
    `UpdateEmailSharingAction` with a `subject` share for Carol; the event lists Carol.
  - `it('refuses another member the user channel')`: Bob authenticating
    `private-App.Models.User.{alice}` through `/broadcasting/auth` gets 403 (`routes/channels.php:8-10`).
- [ ] Run and confirm they fail for the missing event.

### Task 2: The event and its audience

**Files:** `ConversationChanged.php`, `ConversationsQuery.php`.

- [ ] `ConversationChanged implements ShouldBroadcast, ShouldBeUnique, ShouldDispatchAfterCommit`,
  `use Dispatchable, InteractsWithSockets`. Constructor: `public readonly string $workspaceId`,
  `public readonly string $conversationKey`.
  - `broadcastAs()`: `email.conversation.changed`.
  - `broadcastWith()`: `['workspace_id' => ..., 'conversation_key' => ...]`.
  - `uniqueId()`: `"{$workspaceId}:{$conversationKey}"`; `uniqueFor()`: 10 seconds. Before
    relying on it, read `vendor/laravel/framework/src/Illuminate/Broadcasting/BroadcastManager.php`
    and confirm the installed version wraps a `ShouldBeUnique` event in `UniqueBroadcastEvent`.
    If not, drop the interface and rely on the client debounce alone.
  - `broadcastQueue()`: `default`.
  - `broadcastOn(): list<PrivateChannel>`: runs on the worker, after commit, so it reads the
    final state. Members of the workspace (owner plus `workspace_user`, as
    `StoreEmailAction.php:118-122` reads them), filtered to
    `current_workspace_id === $workspaceId` and to users for whom
    `ConversationsQuery::visibleKeys($user, [$conversationKey])` is not empty. One
    `PrivateChannel("App.Models.User.{$id}")` per user. An empty list sends nothing.
  - `broadcastWhen()`: false when the `EmailIntegration` feature is off for the workspace.
- [ ] Members per workspace are under 60 today, so the check is at most 60 indexed `exists`
  queries per change, after uniqueness collapses bursts. If a workspace grows past 200 members,
  replace the loop with one query that returns visible user ids.
- [ ] Task 1 tests that only need the event class now pass when the event is dispatched by hand.

### Task 3: Dispatch points

**Files:** `RefreshConversationSummary.php`, `UpdateEmailSharingAction.php`.

- [ ] `RefreshConversationSummary::execute()` dispatches `ConversationChanged` after its upsert or
  delete. It runs on every store, send, delete and purge (PR 2), and inside
  `StoreEmailAction`'s transaction, where `ShouldDispatchAfterCommit` holds the event until
  commit. `MergeConversations` refreshes both keys, so a merge signals both.
- [ ] `UpdateEmailSharingAction::execute()` dispatches for the email's `conversation_key` when it
  is set. Tier changes on one email change who may see it, and the audience is computed after
  commit, so a newly permitted teammate gets it and a newly excluded one does not.
- [ ] If PR 7 has merged, its four actions dispatch per applied key. If PR 9 has merged, its link
  actions do too. Otherwise those PRs add the dispatch (Plan note 16).
- [ ] All Task 1 tests pass.

### Task 4: Refetch on the server, with the counts cache

**Files:** `ConversationsQuery.php`, `EmailInboxPage.php`, `BaseEmailsRelationManager.php`,
`ConversationsPageTest.php`.

- [ ] `ConversationsQuery::counts()` caches per user for 60 s under
  `ConversationsQuery::countsCacheKey(User $viewer)` (`email-conversation-counts:{workspace}:{user}`),
  as the spec's `ConversationsQuery` section says. The triage actions forget the actor's key.
- [ ] `ConversationsQuery::rows(User $viewer, ConversationView $view, ConversationFilters $filters, array $conversationKeys): Collection<string, ConversationRow>`
  returns the rows for those keys that still belong in the view, built the same way as a page.
  Contract table addition.
- [ ] `EmailInboxPage::refreshConversations(array $keys)`: at most 50 keys, forget the viewer's
  counts key, load `rows()`, and update only those entries in the rendered list. A key with no
  row is removed; a key newer than the first row is prepended. The record tab's method does the
  same through its record filter.
- [ ] Livewire tests:
  - `it('adds a new conversation to needs reply when it is refreshed')`.
  - `it('removes a row the viewer may no longer see')`: Alice views a teammate's linked
    conversation, the owner sets the mailbox private, `refreshConversations([$key])` removes it.
  - `it('ignores a key from another workspace')`: no row, no error.

### Task 5: The Echo listener on the page and record tabs

**Files:** `live-conversations.blade.php`, the page view, the record tab view.

- [ ] An Alpine component included once on the Emails page and once per record Emails tab:
  - Returns at once when `window.Echo` is undefined, so installs without Reverb work
    (`EchoClientTest.php:28-35`).
  - Subscribes with `window.Echo.private('App.Models.User.' + userId).listen('.email.conversation.changed', handler)`.
    On `destroy` it calls `stopListening('.email.conversation.changed', handler)` and never
    `Echo.leave()`, which would also drop Filament's own subscription on the same channel.
  - Drops events whose `workspace_id` is not the current tenant's id (D28 risk).
  - Collects keys in a `Set` and calls `$wire.refreshConversations([...keys])` 750 ms after the
    last event, at most once per 750 ms. A sync batch of 50 messages becomes one round trip.
  - While the tab is hidden it only collects keys, and flushes them on `visibilitychange`.
  - Refetches counts on `focus` after the tab was hidden more than 60 s, as the fallback when
    Reverb is down (D28) or for a mailbox-wide tier change (Plan note 16).
- [ ] Never replace a row the user is acting on: keys whose row has a pending optimistic action
  wait until it settles.

### Task 6: Browser test and verification

**Files:** `ConversationsLiveBrowserTest.php`.

- [ ] With broadcasting configured as in `EchoClientTest.php`, `->with(['inLightMode', 'inDarkMode'])`:
  - `it('subscribes to the user channel on the emails page')`: assert
    `Object.keys(window.Echo.connector.channels)` contains `private-App.Models.User.{id}`.
  - `it('refreshes once for a burst of signals')`: replace the listener's `$wire` call with a
    counter through `page->script`, fire the registered callback five times with keys, wait one
    second, assert one call carrying five keys.
  - `it('ignores a signal for another workspace')`.
  - `it('boots without errors when broadcasting is off')`: `assertNoJavaScriptErrors()`.
- [ ] Production-shaped check by hand (chat.md's rule applies to any Reverb feature): run
  `amp orb services ensure` (Horizon, Reverb, `QUEUE_CONNECTION=redis`), open the Emails page as
  owner@relaticle.test and a teammate in two sessions, store an inbound email through
  `php artisan tinker` calling `StoreEmailAction` with a fixture, and confirm the row appears for
  both within five seconds. Repeat with the mailbox private: the teammate's page does not change
  and the Network panel shows no frame for that user.
- [ ] Run each changed browser file once with `--browser safari`.

## PR 11 checklist

- [ ] Every Task 1 and Task 4 test exists and passes.
- [ ] The payload test proves ids only; no subject, snippet, address or name anywhere in the event.
- [ ] The hand check on the real queue and Reverb is written up in the PR body with timings.
- [ ] Contract table updated: `ShouldBeUnique` and after-commit on `ConversationChanged`,
  `ConversationsQuery::rows()` and `countsCacheKey()`.
- [ ] Screenshots of a row arriving (before and after), light and dark, 1440 and 390 px.
- [ ] Prefetch from the spec's PR 11 row is not in this PR. It ships with or after PR 6's
  drawer as a follow-up task, because it renders drawer headers and has no broadcast part.

---

## Verification per PR

Each PR is clicked through with agent-browser at the portal URL from `amp orb services ensure`,
signed in as owner@relaticle.test (password `password`), with `agent-browser set viewport 1440 900 2`
and then `set viewport 390 844 2`, each in light and dark (Filament's theme switcher in the user
menu). Screenshots go under `.amp/in/artifacts/` and into the PR body. The empty state is always
included.

| PR | States to capture |
|---|---|
| 7 | Needs reply with focus ring on a row; three rows selected with the bar; toast after Done; Done view empty and filled; `?` sheet; drawer with Done hint; Profile toggle off; at 390 px the bar and the sheet |
| 8 | Search with body-word results; operator typed then shown as a chip; the chip bar with three chips; no results; invalid URL filter message; at 390 px the scrolling chip bar |
| 11 | A row arriving while the page is open; a row leaving after a tier change; the page with broadcasting off |

Before marking each PR ready, a second pass: re-run the named tests, re-grep for the old
listener (`rg "keydown.window" packages/Chat packages/EmailIntegration`) in PR 7 and for `'ilike'`
in PR 8, and repeat the browser walk once more on a fresh session.

## Decisions made for the caller

Beyond the plan notes above:

- **Dispatcher location:** `resources/js/filament/shortcuts.js`, an app asset, because chat and
  email are separate packages and both need it. A package-owned copy would duplicate the guard.
- **Flag delivery:** a hidden `data-` element from a `BODY_START` hook, re-read on
  `livewire:navigated`. `registerScriptData` runs at boot, before the user is known.
- **Read granularity:** read marks every reached copy; unread clears the newest reached copy.
- **Vector content:** snippet plus the first 65,536 characters of `body_text` (spec: 64 KB; D13
  suggested 20,000). Lower it if the index exceeds the heap.
- **Debounce:** 750 ms trailing on the client, plus 10 s server uniqueness.

## Risks and unknowns

| Risk | Cheapest early check |
|---|---|
| The production role cannot create `pg_trgm` | PR 8 Task 1 query, before any code |
| GIN on `body_search` larger than planned | The rehearsal's `pg_relation_size`; the aggregate in Task 1 predicts it |
| The backfill bloats `emails` with 372,714 dead tuples | Rehearsal step 4 records dead tuples and `vacuum` time; autovacuum settings reviewed if high |
| Provider sync flips a manual Mark unread back to read | `IncrementalEmailSyncJob.php:106-140` writes only on provider label changes, so a later Gmail read wins. Accepted: provider state is the owner's truth. Say so in the toast copy only if support hears it |
| Echo shares the user channel with Filament notifications | `stopListening` only; the browser test asserts the channel survives navigation |
