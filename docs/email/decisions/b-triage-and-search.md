# Triage and search: decisions 10 to 14

This file reasons through the five decisions behind phase 2 of the roadmap in
[../README.md](../README.md): Done and read controls, the keyboard, bulk actions,
the search engine, and filters with saved views. Each section follows
[../method.md](../method.md). Code references point at the repository on
2026-10-31. Production numbers are the aggregates in the README: 372,714 emails,
156,632 in the largest mailbox, 95.4% at `metadata_only`, 1.12 messages per thread.

"Inferred" marks our reading of a competitor's motive where they never stated it.

| # | Decision | Pick | Runner-up | Weighted score (pick / runner-up, of 60) | Confidence |
|---|---|---|---|---|---|
| 10 | Done and read controls | C. Per-user Done kept in Relaticle, plus read and unread. A new inbound message brings the thread back | B. Read and unread only | 57 / 54 | High |
| 11 | Keyboard | C. One scoped shortcut map with a `?` sheet, hints, and an off switch for single keys | E. C plus full per-user remapping now | 55 / 52 | High |
| 12 | Bulk actions on received mail | B. Checkbox column with a floating action bar, each verb a batch action | C. Filament table bulk actions | 56 / 47 | High |
| 13 | Search engine | C. Postgres full text search with separate subject and body indexes, gated by tier, plus `pg_trgm` for addresses | B. `pg_trgm` only | 56 / 45 | Medium |
| 14 | Filters and saved views | C. Chip bar and typed operators over one email filter owner that speaks the `app/Queries` grammar | E. Fold email into `app/Queries` as a filter-tree entity now | 58 / 51 | Medium |

---

## 10. Done and read controls

### The question

How does a seller say "this thread is handled", and should that reach Gmail or Outlook?

### Today

- Read state is per user. `email_reads` holds one row per email and user, unique on both
  (`database/migrations/2026_03_21_050530_create_email_reads_table.php:17-26`).
- Opening the reader calls `MarkEmailAsReadAction`
  (`packages/EmailIntegration/src/Filament/Concerns/HasEmailReaderActions.php:61`). The action
  checks visibility first, then inserts the row
  (`packages/EmailIntegration/src/Actions/MarkEmailAsReadAction.php:23-35`).
- Provider read state flows one way, into Relaticle, for the mailbox owner only. Gmail history
  tracks the `UNREAD` label (`packages/EmailIntegration/src/Services/GmailService.php:85-97`).
  `IncrementalEmailSyncJob` creates or deletes the owner's rows
  (`packages/EmailIntegration/src/Jobs/IncrementalEmailSyncJob.php:106-140`). Microsoft does
  the same with `isRead` (`packages/EmailIntegration/src/Services/MicrosoftGraphMailService.php:64-80`).
- Nothing writes back to the provider. Reading in Relaticle leaves the message unread in Gmail.
- No mark-unread action exists. No Done or archive state exists anywhere.
- Scopes: the redirect asks for `gmail.readonly`, `gmail.send` and `calendar.events`
  (`packages/EmailIntegration/src/Controllers/RedirectController.php:69-76`). Microsoft gets
  `Mail.Read`, `Mail.Send`, `User.Read`, `offline_access` and `Calendars.ReadWrite` (`:79-88`).
  The callback also accepts `gmail.modify` and `https://mail.google.com/` as read grants
  (`packages/EmailIntegration/src/Controllers/CallbackController.php:30-34`), but never requests them.

### What competitors chose and why

- **Superhuman and Shortwave** are mail clients. Their Done is Gmail archive, because
  the mailbox is the product. Superhuman frames archive as "marking the email as done"
  and opens the next email to protect flow
  ([inbox zero](https://blog.superhuman.com/inbox-zero-in-7-steps),
  [flow](https://blog.superhuman.com/how-to-design-for-flow/)). They pay for restricted
  scopes because no client works without them.
- **Close** archives in Gmail when you mark Done, and the reverse. Its stated reason: "You
  can work out of either Close Inbox or your regular email account, and the changes
  propagate" ([configuration](https://help.close.com/feature-guide/emailing/email-configuration)).
  Close calls its inbox "a to-do list" for reps who should "work exclusively from their
  Close inbox" ([inbox](https://help.close.com/feature-guide/inbox)). It accepted the cost
  of an opt-out toggle and of filters that archive mail before a rep sees it.
- **HubSpot and Front** close a conversation for the whole team, because they model shared
  inboxes with one assignee (inferred from their assignment docs in [../benchmark.md](../benchmark.md)).
- **Attio** has no Done at all. Inferred: with no inbox there is nothing to clear.

### Options

- **A. Nothing new (today).** Read state syncs in. Failure: Needs reply never shrinks, so it becomes noise.
- **B. Read and unread only.** Add a mark-unread action and an unread filter. Strength:
  every client has it, and the data already exists. Failure: unread is not handled. A
  seller who reads a message and must answer tomorrow has no way to keep it in view.
- **C. Per-user Done kept in Relaticle.** A new table stores
  `(user_id, email_thread_id, done_at)`. Needs reply hides a thread while
  `done_at >= last_inbound_at`. A newer inbound message beats the timestamp, so the thread
  returns with no job and no write. Undo restores the previous row. Strength: one
  comparison, reversible, provider neutral. Failure: a Gmail-first user archives twice.
- **D. C plus a one-way import.** Archiving in Gmail or Outlook marks the thread Done in
  Relaticle. Read-only scopes can see the `INBOX` label leave or the Graph folder change.
  Failure: Gmail filters that skip the inbox would mark mail Done before anyone saw it.
- **E. Two-way Done, as in Close.** Done archives in the provider. This needs
  `gmail.modify` and Microsoft `Mail.ReadWrite`.

**Scopes and assessment for E.** `gmail.readonly` is already a restricted scope. Google
requires an annual CASA assessment for any server that stores restricted data
([restricted scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification)).
So E adds no new assessment class. It does change the restricted scope set, which
Google re-verifies ([FAQ](https://support.google.com/cloud/answer/13463817)). Labs
quote $540 to $5,400 a year depending on the level
([TAC via Google group](https://groups.google.com/g/google-apps-script-community/c/XgsHT2Fr7u8),
[Carly](https://usecarly.com/blog/gmail-api-limits/)). The bigger costs come after
verification. All 27 Gmail and 5 Microsoft mailboxes must consent again. Entra tenants
that require admin consent need an admin. Self-hosters with public OAuth apps repeat the
review. Buyers also hear "the CRM can change my mailbox".

**Conflict risk for E.** Two writers on one flag race. If a rep archives in Gmail while a
teammate's reply arrives, who wins? Close's own doc warns that filter-archived mail never
reaches its inbox. **Team semantics.** A Relaticle mailbox belongs to one user. Needs
reply asks "what do I owe a reply", so Done is per user by nature. A shared Done would
let a teammate with `full` access clear the owner's queue. Under E it would even archive
mail in the owner's Gmail. Replies are shared facts already: an outbound message in any
copy clears Needs reply (decision 2).

### Matrix

| Option | User value ×3 | Clarity ×2 | Fit ×3 | Future-proof ×3 | Cost ×1 | Total |
|---|---|---|---|---|---|---|
| A. Nothing new | 1 | 3 | 3 | 3 | 5 | 32 |
| B. Read and unread only | 3 | 5 | 5 | 5 | 5 | 54 |
| **C. Per-user Done in Relaticle** | 5 | 4 | 5 | 5 | 4 | **57** |
| D. C plus one-way import | 5 | 3 | 5 | 4 | 3 | 51 |
| E. Two-way Done | 4 | 3 | 2 | 2 | 1 | 31 |

- C's clarity is 4, not 5: "Done here, still in Gmail" needs one line in the empty state.
- D's clarity is 3 because a thread can vanish for a reason outside the app.
- E's fit is 2 because one teammate's action would write to another person's mailbox.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass. Done is a row the reply-status query reads, so Rela and MCP see one fact | Pass |
| No AI key | Pass | Pass |
| Scale 10x | Pass. One indexed row per handled thread, compared at read time | Pass |
| Providers | Pass. Nothing provider-specific | Pass |
| Trust | Pass. Relaticle never writes to the mailbox | Pass |
| Reversibility | Pass. Drop the table and the list still works | Pass |

### Recommendation

Pick **C**, which includes B's mark-unread. It beats B because only C empties Needs
reply. It beats D and E because both tie Done to provider behaviour we cannot see or
control. Keep read sync inbound only.

**What would change our mind.** Fetch the Gmail labels for a sample of Needs reply threads
with the read scope we already hold. If more than 30% are already out of the inbox, ship D.
Ship E only if paying workspaces ask for write-back and D has been live a quarter.

**Smallest first step.** A `MarkUnread` action and an `email_thread_triage` table with
`done_at`. Add a Done button and `e` on the reader. Show an Undo toast for 5 seconds,
then auto-advance to the next thread, as Superhuman does.

### Risks and mitigations

- *Users expect Gmail to follow.* Say "Done in Relaticle" on the toast, and add a line in settings.
- *Threads split across mailboxes.* `email_threads` is unique per mailbox
  (`database/migrations/2026_03_21_054840_create_email_threads_table.php:30`). Done keys on
  the viewer's own thread, so a teammate's copy never inherits it.
- *Backlog on first open.* A 156,632-email mailbox opens with years of "needs reply". Offer
  "Mark everything older than 7 days Done" once. Superhuman coaches the same move
  ([inbox zero](https://blog.superhuman.com/inbox-zero-in-7-steps)).

---

## 11. Keyboard

### The question

Which keys drive the Conversations list and reader, who owns the map, and how does it stay accessible?

### Today

- The composer binds bare `c` on `window` and skips inputs and contenteditable
  (`packages/EmailIntegration/resources/views/livewire/email-composer.blade.php:21-27`).
- The chat sidebar binds bare `j`, `k` and `n` on `window`
  (`packages/Chat/resources/views/livewire/app/chat/chat-sidebar-nav.blade.php:4-37`). It renders
  on every app page through the `SIDEBAR_NAV_END` hook (`packages/Chat/src/ChatServiceProvider.php:240-243`).
  `j` and `k` open the next or previous chat, and `n` goes to the dashboard.
- Chat also takes Cmd/Ctrl J (`packages/Chat/resources/views/livewire/app/chat/chat-side-panel.blade.php:36-53`).
  It takes Cmd/Ctrl Enter to approve a single proposal
  (`packages/Chat/resources/views/livewire/chat/chat-interface.blade.php:387-399`).
- Cmd/Ctrl K is Filament global search over records (`app/Providers/Filament/AppPanelProvider.php:444`).
  It lists records, not actions. No command palette exists.
- No shortcut can be turned off or remapped. Every handler hardcodes its keys.

### What competitors chose and why

- **Superhuman** sells speed: under 100 ms per action, aiming for 50 ms
  ([speed](https://blog.superhuman.com/superhuman-is-built-for-speed/)). It teaches keys
  through Cmd K, which shows "the letter that appears on the right" for each action
  ([shortcuts](https://help.superhuman.com/hc/en-us/articles/45191759067411-Speed-Up-With-Shortcuts)).
  It mirrored Gmail's keys so power users faced "zero learning curve"
  ([PMF engine](https://blog.superhuman.com/how-superhuman-built-an-engine-to-find-product-market-fit)).
- **Linear** uses its command menu and right-click menus to teach shortcuts. Its stated reason is
  that moving the mouse "may only take a fraction to 1-2 seconds longer" but the seconds add up
  ([invisible details](https://linear.app/blog/invisible-details)). `?` lists every key
  ([navigating](https://linear.app/enablement/guides/navigating-linear)).
- **Gmail** ships most single-key shortcuts off until the user turns them on. It also
  offers custom remapping ([help](https://support.google.com/mail/answer/6594)). Inferred
  reason: a mass-market product cannot surprise people who type into the page by accident.
- **Zero** (open source) keeps one map with scopes: navigation, global, mail list, thread
  and compose. It maps keys for non-QWERTY layouts
  ([shortcuts.ts](https://github.com/Mail-0/Zero/blob/main/apps/mail/config/shortcuts.ts)).
  Its flaw: `r` means mark read in the list and reply in the reader.

### Options

- **A. Ad hoc listeners (today).** Each component hardcodes its keys. Failure: chat's `j`
  would navigate away from the email list, and nobody can turn a key off.
- **B. Gmail set, hardcoded.** `j/k o r a f e u # /` in the email components. Failure: it
  collides with chat's `j/k/n`, and it fails WCAG 2.1.4.
- **C. One scoped map.** An app-level registry lists every binding with a scope, an action
  id and a label. Packages register their entries from their service providers. Handlers,
  the `?` sheet, tooltips and `aria-keyshortcuts` all read the registry. One user setting
  turns off character-key shortcuts. A focused component's scope wins over global.
- **D. Palette only.** Modifier keys only, actions behind a new Cmd K menu. Strength: no
  accidental triggers. Failure: no palette exists, and triage at two keys per action is slow.
- **E. C plus per-user remapping now.** Spark and Gmail do this. Failure: a settings
  screen and conflict detection that few will use.

**Accessibility.** WCAG 2.1.4 (Level A) requires a way to turn off or remap any shortcut
made of printable characters, unless it is active only on focus
([Understanding 2.1.4](https://www.w3.org/WAI/WCAG21/Understanding/character-key-shortcuts)).
The reason is speech input: dictated words arrive as letters. Today `c`, `j`, `k` and `n`
fail this. Screen readers in browse mode take letter keys for their own navigation, so
our keys must never be the only path. Every action keeps a labelled button (2.1.1). Rows
use a roving tabindex with `aria-selected`. A polite live region announces "Done. Press z to undo".

**Layouts.** Match on `event.key` so `j` stays `j` on AZERTY or Dvorak. When `event.key` is
not a Latin letter, as on Cyrillic or Armenian layouts, fall back to `event.code` (`KeyJ`).
Show the key the user must press, as Zero's layout mapper does.

**Ctrl K.** Keep it as Filament's record search. Do not overload it with email actions
until a real action palette exists. The `?` sheet and tooltips teach keys meanwhile.

### Matrix

| Option | User value ×3 | Clarity ×2 | Fit ×3 | Future-proof ×3 | Cost ×1 | Total |
|---|---|---|---|---|---|---|
| A. Ad hoc (today) | 2 | 2 | 3 | 2 | 5 | 30 |
| B. Gmail set, hardcoded | 4 | 3 | 3 | 2 | 4 | 37 |
| **C. One scoped map with off switch** | 5 | 5 | 4 | 5 | 3 | **55** |
| D. Palette only | 3 | 4 | 4 | 4 | 2 | 43 |
| E. C plus remapping now | 5 | 4 | 4 | 5 | 2 | 52 |

- Fit is 4 for C because keyboard maps say nothing about records or tiers. They only must not bypass them.
- E's clarity drops because remapped keys break shared help and screenshots.

### Future-proof check

| Test | C (pick) | E (runner-up) |
|---|---|---|
| Agents | Pass. Keys call the same actions Rela proposes | Pass |
| No AI key | Pass | Pass |
| Scale 10x | Pass | Pass |
| Providers | Pass | Pass |
| Trust | Pass. Meets WCAG 2.1.4 through the off switch | Pass |
| Reversibility | Pass. The registry can grow into E without a migration | Pass |

### Recommendation

Pick **C**. It beats E because the off switch alone satisfies 2.1.4, and remapping can
come later on the same registry. The map:

| Scope | Keys |
|---|---|
| List | `j/k` next and previous, `Enter` open, `x` select, `e` Done, `u` unread, `/` search, `?` help |
| Reader | `j/k` move the reader, `r` reply, `a` reply all, `f` forward, `e` Done, `u` unread, `l` link to record, `Esc` close |
| Toast | `z` undo |
| Global | `c` compose, Cmd/Ctrl K search, Cmd/Ctrl J Rela |

`r` means reply everywhere, so Zero's flaw cannot recur. Chat's `j/k` move into a chat
scope active only when the chat list has focus.

**What would change our mind.** If over 10% of weekly email users turn the shortcuts off,
switch the default to off, as Gmail does. If support hears remapping requests from three
or more workspaces, build E.

**Smallest first step.** The registry and the off switch, adopted by the composer `c` and
the chat `j/k/n`. Store the setting in a `users` jsonb column, as `notification_preferences` does.

### Risks and mitigations

- *Collisions return as packages add keys.* An arch test fails when two entries share a key and scope.
- *Keys fire inside Filament modals.* Keep chat's guard (`[role=dialog]`, `.fi-modal-open`) in the one dispatcher.

---

## 12. Bulk actions on received mail

### The question

How does a seller act on many threads at once without risking teammates' mail?

### Today

- No list of received mail has selection. The record tab renders custom rows
  (`packages/EmailIntegration/resources/views/filament/relation-managers/emails-relation-manager.blade.php:33`).
- Available writes: `MarkEmailAsReadAction` for one email and the owner's
  `UpdateEmailSharingAction::execute(Email, User, tier, shares)`
  (`packages/EmailIntegration/src/Actions/UpdateEmailSharingAction.php:23`).
- `LinkEmailAction` is the automatic linker. It takes only an `Email` and matches by address
  and domain (`packages/EmailIntegration/src/Actions/LinkEmailAction.php:26-63`). No action
  links an email to a record a user picks, and none unlinks.
- `IncrementalEmailSyncJob` writes read rows one query per email (`:119-124`). Bulk paths need set-based inserts.

### What competitors chose and why

- **Gmail** offers checkboxes, a toolbar, and "select all conversations that match this
  search". Inferred: mass-market users need a visible checkbox, and power users need scale.
- **Superhuman** coaches new users to "Select All From Here" and press `E`, with a cutoff of
  "a few days to 1 week" ([inbox zero](https://blog.superhuman.com/inbox-zero-in-7-steps)).
  Bulk Done is its onboarding, because a backlog kills the flow it sells.
- **Close** lets reps mark all inbox items Done or snooze them in bulk
  ([inbox](https://help.close.com/docs/inbox)). Its inbox is a to-do list, so clearing it is the job.
- **Linear and HEY** select with `x` and act through a menu (`;` in HEY)
  ([HEY shortcuts](https://help.hey.com/article/758-keyboard-shortcuts)). Inferred: keyboard
  users want selection without the mouse.

### Options

- **A. None (today).** Failure: the first-run backlog cannot be cleared.
- **B. Checkbox column with a floating bar.** A checkbox shows on hover and focus. Shift-click
  selects a range and `x` toggles. A bar offers Done, Mark read, Mark unread, Link to record,
  Request access and Change sharing. Each verb calls one batch action that checks visibility
  per id. Strength: familiar. Failure: the bar can offer a verb some rows refuse.
- **C. Filament table bulk actions.** Rebuild the list as a Filament table. Strength: reuse.
  Failure: Filament's row layout fights the two-line row and the split reader.
- **D. `x` plus palette.** Failure: invisible to mouse users and no palette exists.
- **E. Select all matching.** Act on every thread a filter returns, in a queued job.
  Failure: a sharing change on thousands of threads is hard to undo.

### Matrix

| Option | User value ×3 | Clarity ×2 | Fit ×3 | Future-proof ×3 | Cost ×1 | Total |
|---|---|---|---|---|---|---|
| A. None | 1 | 3 | 3 | 4 | 5 | 35 |
| **B. Checkbox and floating bar** | 4 | 5 | 5 | 5 | 4 | **56** |
| C. Filament bulk actions | 4 | 4 | 4 | 4 | 3 | 47 |
| D. `x` plus palette | 3 | 2 | 4 | 4 | 3 | 40 |
| E. Select all matching | 3 | 3 | 3 | 4 | 2 | 38 |

- B's fit is 5 because each verb re-checks the tier per row, as `MarkEmailAsReadAction` does.
- B's user value is 4, not 5: bulk work is weekly, not hourly.

### Future-proof check

| Test | B (pick) | C (runner-up) |
|---|---|---|
| Agents | Pass. Batch actions match the chat rule of `ids[]` per tool | Pass |
| No AI key | Pass | Pass |
| Scale 10x | Pass. Page-bounded, set-based writes | Pass |
| Providers | Pass. Local writes only | Pass |
| Trust | Pass. Sharing changes stay owner-only and audited | Pass |
| Reversibility | Pass. Undo restores the prior rows | Partial. Undoing Filament's layout means a second rebuild |

### Recommendation

Pick **B**. It keeps the row and reader designed in decisions 3 and 6. C would trade them
for reuse.

Rules:

- Change sharing appears only when every selected row is the viewer's own.
- Request access appears only for locked rows.
- Done, read and unread offer Undo.
- Sharing asks for confirmation.

E arrives later in a narrow form: "Mark everything older than N days Done" stores a
cutoff, not a row per thread.

**What would change our mind.** If selections often span more than one page, add "select
all matching" for Done and read only. Measure the share of bulk actions that hit the page cap.

**Smallest first step.** `MarkThreadsDone`, `MarkEmailsRead` and `MarkEmailsUnread` batch
actions, plus the bar with those three verbs.

### Risks and mitigations

- *Leaking hidden rows through counts.* The bar counts only rows the viewer can see.
- *Manual link has no action.* Build `LinkEmailsToRecord` with user, ids and record, plus the
  unlink suppression from decision 22, before adding that verb.
- *Mass sharing mistakes.* Sharing changes go to the activity log, with the count shown before confirmation.

---

## 13. Search engine

### The question

What engine finds a thread by words, names or addresses, fast, and only within what the viewer may read?

### Today

- `EmailSearchService::applyToQuery()` matches `%term%` with `ILIKE` across three branches
  (`packages/EmailIntegration/src/Services/EmailSearchService.php:24-58`):
  - participant name or address, skipping other people's BCC rows;
  - `subject`, gated by the SUBJECT or FULL tier (`:46-50`, `:73-79`);
  - `snippet`, gated by FULL (`:52-56`, `:85-90`).
- It never reads `email_bodies`. Body text sits in its own table, one row per email
  (`database/migrations/2026_03_21_050455_create_email_bodies_table.php:15-20`). It is stored
  for every synced message (`packages/EmailIntegration/src/Actions/StoreEmailAction.php:87-90`).
- The service is already the one owner. The record tab calls it
  (`packages/EmailIntegration/src/Filament/RelationManagers/BaseEmailsRelationManager.php:112-113`).
  So does `VisibleEmailsQuery` (`packages/EmailIntegration/src/Queries/VisibleEmailsQuery.php:69-71`),
  which serves Rela and the MCP server.
- Indexes: btree on `lower(email_address)` and on the address host
  (`database/migrations/2026_09_29_222721_index_email_participant_and_attendee_addresses_by_host.php`).
  A leading wildcard cannot use them. No `pg_trgm` or `tsvector` exists.
- Self-hosted runs `postgres:17-alpine` (`compose.yml:229`), which has contrib modules but not pgvector.

### What competitors chose and why

- **Superhuman** built search inside the browser with a local full text table. It wanted
  results "as the user types" and search that works offline
  ([search](https://blog.superhuman.com/delightful-search-more-than-meets-the-eye)). A
  paid single-user client can keep mail on the device. A team CRM with tiers cannot.
- **Shortwave** runs Elasticsearch for keywords and Pinecone for vectors, namespaced per
  user, then reranks with a cross-encoder
  ([deep dive](https://www.shortwave.com/blog/deep-dive-into-worlds-smartest-email-ai),
  [summary](http://www.zenml.io/llmops-database/building-a-production-grade-email-ai-assistant-using-rag-and-multi-stage-retrieval)).
  The CEO says keyword, label and time-range search still does the first pass
  ([interview](https://www.cognitiverevolution.ai/the-ai-email-assistant-ive-been-waiting-for-with-andrew-lee-of-shortwave)).
  Its AI answers questions, and a per-user namespace is the whole privacy model.
- **Gmail** owns an inverted index at Google scale and sorts results by date. Its operators
  became the syntax users know ([operators](https://support.google.com/mail/answer/7190)).
- **Attio** does not search email content (see [../benchmark.md](../benchmark.md)). Inferred:
  read-time matching and tiers made content search hard, and it accepted its most-cited gap.

### Options

- **A. `ILIKE` (today).** Every search scans the viewer's visible mail. Failure: at 1.5 million
  emails in one mailbox it times out, and bodies never match.
- **B. `pg_trgm` GIN** on subject, snippet, participant name and address. Strength: any
  language, partial words, addresses. `pg_trgm` is a trusted extension, so a database owner can
  install it. Failure: trigram indexes on bodies run several times the text size, so bodies stay out.
- **C. Postgres full text.** Use `websearch_to_tsquery('simple', ...)`. The `simple` config
  lowercases without stemming, so Armenian, Russian and English all work. Two indexes keep tiers apart:
  1. A GIN expression index on `to_tsvector('simple', coalesce(subject, ''))`. It is short and cheap to recompute.
  2. A `body_tsv` tsvector column on `emails`, holding snippet plus the first 20,000
     characters of `body_text`. A trigger on `email_bodies` keeps it current, so every writer
     is covered. A GIN index on `(workspace_id, body_tsv)` uses `btree_gin`.

  `body_tsv` lives on `emails` because `email_bodies` has no `workspace_id`.
  Adding a generated column would rewrite a large table under an exclusive lock, and
  Postgres 17 cannot index a virtual one. Plain columns add instantly and backfill in
  chunks. Participants keep `pg_trgm` from B. Results sort newest first, as Gmail does.
- **D. pgvector semantic search.** Embed each body and match by meaning. Failure: it needs
  a model, so it breaks the no-AI-key test. Self-hosters must also swap images. 372,714 ×
  1,536 floats is about 2.3 GB before the index.
- **E. Meilisearch or Typesense.** Fast and typo tolerant. Failure: a new container for
  every self-hosted install, a sync pipeline, and a second copy to erase under GDPR.

**Keeping tiers with each engine.**

- **A, B, C and D** run in Postgres. Each match branch keeps its tier predicate in the same
  `WHERE`. The subject index pairs with `whereViewerCanSeeSubject()`. Body and snippet pair
  with `whereViewerCanSeeBody()`. A viewer's share still overrides the email default, as
  `.ai/rules/email-integration.md` requires.
- **E** must copy the ACL into the index: viewer ids per tier, per-viewer shares,
  cross-message shares, synced copies and the BCC rule. Every share change or new member then
  forces a reindex, and any drift leaks mail. The alternative is to re-filter ids in
  Postgres, which breaks paging.
- **Provider search APIs** (Gmail `q=`, Graph `$search`) score 31 and are not listed. They
  would fan out to 50 mailboxes with each owner's token. They ignore tiers, and they stop
  when a mailbox needs reauthorization.

**Index size, rough.** We have no body length aggregate. Assume 2 to 6 KB of body text per
email. Then 372,714 emails hold 0.75 to 2.2 GB of text. A `simple` tsvector runs near the
text size before TOAST compression: about 0.5 to 1.5 GB. The GIN index adds 0.2 to 0.7 GB.
Subject GIN and participant trigram add about 0.2 GB. At 10x, plan for 5 to 15 GB of
vectors and 2 to 7 GB of index. The rehearsal must measure this.

### Matrix

| Option | User value ×3 | Clarity ×2 | Fit ×3 | Future-proof ×3 | Cost ×1 | Total |
|---|---|---|---|---|---|---|
| A. `ILIKE` (today) | 2 | 3 | 5 | 1 | 5 | 35 |
| B. `pg_trgm` | 3 | 4 | 5 | 3 | 4 | 45 |
| **C. Full text plus trigram** | 5 | 4 | 5 | 5 | 3 | **56** |
| D. pgvector | 4 | 3 | 3 | 2 | 1 | 34 |
| E. External engine | 5 | 4 | 2 | 2 | 1 | 36 |

- C's clarity is 4: without stemming, "meeting" misses "meetings" unless the last term matches as a prefix.
- E's fit is 2 because tier enforcement moves out of the database that owns it.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass. `EmailSearchService` stays the owner behind `VisibleEmailsQuery` | Pass |
| No AI key | Pass | Pass |
| Scale 10x | Pass if candidate ids come from each index first, then visibility and sort apply. Proven only by rehearsal | Partial. Bodies remain unsearchable |
| Providers | Pass. Indexes our stored copy | Pass |
| Trust | Pass. One database, one erase path; vectors cascade with the email | Pass |
| Reversibility | Pass. Drop the column and indexes | Pass |

### Recommendation

Pick **C**. It beats B because body search is the gap users name, and B cannot index bodies
at an acceptable size. Keep pgvector as a later, optional rerank for Rela, behind FULL access.

**What would change our mind.** Two signals would. If the rehearsal shows p95 search over
500 ms on the largest mailbox, reconsider. If the GIN index exceeds the table it serves,
reconsider too. If over 20% of searches return nothing and a semantic rerank fixes them,
add D as an optional layer.

**Smallest first step.** Run one read-only aggregate in production:
`avg(octet_length(body_text))` on `email_bodies`. Then add the subject expression index and
participant trigram with `create index concurrently` and `$withinTransaction = false`. The
precedent is `database/migrations/2026_10_21_000002_index_inbox_columns_on_notifications_table.php`.

### Risks and mitigations

- *Backfill length.* Rehearse on anonymised production data. If it runs past a few minutes,
  queue a chunked command from the migration, as `.ai/guidelines/relaticle/core.md` prescribes.
- *Broad terms.* With `simple` there are no stop words. Cap candidate ids before the visibility join.
- *Extension rights on managed Postgres.* `pg_trgm` and `btree_gin` are trusted. Confirm the production role can create them.

---

## 14. Filters and saved views

### The question

How does a seller narrow the list, and which owner defines the filters every surface uses?

### Today

- No filter UI exists for received mail.
- `VisibleEmailsQuery::filterRules()` accepts flat parameters
  (`packages/EmailIntegration/src/Queries/VisibleEmailsQuery.php:47-58`): `search`,
  `record_type`, `record_id`, `direction`, `thread_id`, `sent_after`, `sent_before`, `status`.
  The MCP `ListEmailsTool` publishes them (`app/Mcp/Tools/Email/ListEmailsTool.php:46-80`).
- The CRM lists use the `app/Queries` filter tree, and `.ai/rules/queries.md` forbids flat
  parameters there. Email is not a `CrmEntity`, so it sits outside that rule.
- `FilterTree::validate()` takes a `CrmEntity` (`app/Queries/FilterTree.php:48`). `app/Queries`
  may not import a package, but a package may import `App\Queries`.
- No saved views exist anywhere in the app.

### What competitors chose and why

- **Gmail** uses operators only (`from:` `has:attachment` `after:`). The search box is the one
  surface on every client ([operators](https://support.google.com/mail/answer/7190)).
- **Superhuman** keeps operators but adds autocomplete and syntax highlighting, because
  "the interface should have auto-completion" for perceived speed
  ([search](https://blog.superhuman.com/delightful-search-more-than-meets-the-eye)).
- **Close** filters its inbox by Lead Status, pipeline and Smart Views
  ([inbox](https://help.close.com/feature-guide/inbox)). Saved searches are its core object,
  so reusing them in the inbox is cheap.
- **Notion Mail and Shortwave** define views and splits as saved queries
  ([../benchmark-modern.md](../benchmark-modern.md)). Notion Mail's dozens of views did not save it.

### Options

- **A. Flat parameters, no UI (today).** Failure: people cannot filter, and agents get a
  second grammar.
- **B. Filament filter panel.** Strength: free. Failure: a side panel hides active filters.
- **C. Chip bar plus typed operators.** Chips cover Mailbox, From, Direction, Linked to,
  Has attachment, Date and Category. Typing `from:airbnb.com` in search becomes a chip.
  An `EmailFilters` class in the package owns the names. Chips, the operator parser, Rela
  and MCP all read it. The tree uses the `app/Queries` grammar: `$eq`, `$in`, `$gte`,
  `$and`, `$not` and `FilterErrors` messages. Agents learn one grammar.
- **D. Operators only.** Gmail's model. Failure: invisible to most sellers.
- **E. Fold email into `app/Queries` now.** Generalise `FilterTree` to take a definitions map
  and register email. Strength: one validator. Failure: an `app/Queries` refactor across five
  CRM lists before any email user benefits.

**Saved views.** Needs reply, Awaiting reply, All and Unlinked are code-defined views over
the same chips. User-saved views wait until chips settle. When they come, they store the
filter tree as JSON per user. Validation runs again on read, so a renamed filter fails clearly.

### Matrix

| Option | User value ×3 | Clarity ×2 | Fit ×3 | Future-proof ×3 | Cost ×1 | Total |
|---|---|---|---|---|---|---|
| A. Flat, no UI (today) | 1 | 3 | 3 | 2 | 5 | 29 |
| B. Filament panel | 3 | 3 | 4 | 3 | 4 | 40 |
| **C. Chips plus operators, one owner** | 5 | 5 | 5 | 5 | 3 | **58** |
| D. Operators only | 3 | 2 | 4 | 4 | 4 | 41 |
| E. Fold into `app/Queries` now | 4 | 4 | 5 | 5 | 1 | 51 |

- C's fit is 5 because every chip compiles inside `VisibleEmailsQuery`, after the visibility bound.
- E's user value is 4 because the same chips ship weeks later.

### Future-proof check

| Test | C (pick) | E (runner-up) |
|---|---|---|
| Agents | Pass. MCP and Rela read the same definitions and grammar | Pass |
| No AI key | Pass | Pass |
| Scale 10x | Pass. Each chip maps to an indexed column | Pass |
| Providers | Pass | Pass |
| Trust | Pass. A "From" chip on a metadata-only row matches only what the row shows | Pass |
| Reversibility | Pass. Moving the definitions into `app/Queries` later is a refactor, not a migration | Pass |

### Recommendation

Pick **C**. It beats E because it gives the same grammar to agents now. The shared
validator can follow when a second non-CRM list needs it.

Replace the flat MCP parameters with the tree in the same change. The Minimal Change
rule in `.ai/guidelines/relaticle/core.md` forbids leaving old and new paths side by side.

**What would change our mind.** If a second package list (meetings) needs filters, do E.
If more than 30% of filtered sessions use the same three chips, ship saved views.

**Smallest first step.** `EmailFilters` with Mailbox, Direction and Date. Wire the chips and
`VisibleEmailsQuery` to it. Parse `from:` and `has:attachment` into chips.

### Risks and mitigations

- *Two grammars drift.* Add email to `tests/Feature/CRM/SurfaceParityTest.php` coverage so
  MCP, chat and chips publish the same names.
- *Tier leaks through filters.* A filter on subject or body text goes through
  `EmailSearchService`, never a raw `where`.
- *View sprawl.* Ship four code-defined views first. Notion Mail shows the cost of dozens.
