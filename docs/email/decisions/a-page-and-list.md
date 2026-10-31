# Decisions 1 to 9: the Emails page and the list

This file reasons through the first nine decisions in [decisions.md](../decisions.md):
what the `Emails` page is for, how it opens, how a thread is read and linked, and what
each row shows. It follows [method.md](../method.md) exactly. Scores use the weighted
criteria (User value x3, Clarity x2, CRM and privacy fit x3, Future-proof x3, Cost x1),
so every total is out of 60.

Evidence comes from three places:

- The code as of 2026-10-31, cited by file and line. Package paths are relative to
  `packages/EmailIntegration/` unless they start with `app/`, `database/` or `packages/`.
- The production aggregates in [README.md](../README.md).
- Competitor sources, cited by URL. "Inferred" marks a reading of a source, not a
  statement in it.

## Summary of the picks

| # | Decision | Pick | Runner-up | Score | Confidence |
|---|---|---|---|---|---|
| 1 | Job of the Emails page | C. A Conversations list of every thread the viewer may see, with record context; outbound lists become secondary tabs | D. An action queue only | 53 vs 45 | Medium |
| 2 | Default view | C. Needs reply, falling back to All when empty | B. All conversations, newest first | 53 vs 51 | Medium |
| 3 | Reader surface | C. A non-modal right drawer that keeps the list live | B. A split pane | 57 vs 53 | High |
| 4 | Deep links | E. A stable resolver route keyed by email id, plus the reader's id in each host page's URL | B. A query string on the host page only | 58 vs 48 | High |
| 5 | Message rows or thread rows | C. One row per conversation, merged across mailboxes by RFC headers | B. One row per provider thread (`email_threads`) | 54 vs 42 | Medium |
| 6 | Row content and density | C. Two lines with right-aligned record chips | B. One dense line | 57 vs 48 | High |
| 7 | Unread state | B. Bold plus a dot, only on mail the viewer can open and that reached them | C. A dot only | 56 vs 52 | High |
| 8 | Categories and noise | C. Hide promotional mail by default with a counted toggle; badge only actionable categories | E. Bundle automated senders into one row | 54 vs 47 | Medium |
| 9 | Rows the viewer cannot open | B. Open the reader at the privacy gate with a labelled Request button | D. Collapse runs of locked rows into one group row | 56 vs 50 | Medium |

Four facts shape every decision below.

1. **Agents already have the list humans lack.** Rela and the MCP server list every
   visible email in the workspace, linked or not, through `VisibleEmailsQuery`
   (`src/Queries/VisibleEmailsQuery.php:65-90`). People can only reach mail through a
   record tab. That covers 46% of synced mail.
2. **A conversation is stored once per mailbox.** `email_threads` is unique on
   `(connected_account_id, thread_id)`
   (`database/migrations/2026_03_21_054840_create_email_threads_table.php:30`). Gmail
   and Microsoft thread ids are per mailbox. Two teammates on one conversation produce
   two threads. Messages are de-duplicated across mailboxes by RFC Message-ID
   (`src/Services/PreferredEmailCopyService.php:29-49`). Threads are not.
3. **Most workspaces have one mailbox.** 32 mailboxes across 29 workspaces leaves room
   for at most three workspaces with two or more mailboxes (inferred from the README
   counts). The 95.4% `metadata_only` tier, 0 shares and 0 access requests mostly
   describe single-mailbox workspaces where nobody else reads the mail. The privacy
   surfaces must work, but they are not yet where daily value lives.
4. **Noise is already cut at sync.** Since 2026-10-02 Gmail promotions, social, forums
   and updates mail is never stored (`3683eb4d5`, `src/Services/GmailService.php:30,186`).
   Since 2026-10-09 Microsoft mail with `List-Unsubscribe` or bulk `Precedence` is
   skipped too (`1a57d49cf`, `src/Services/MicrosoftGraphMailService.php:172-181`,
   `src/Jobs/StoreEmailJob.php:147`). The 37% Marketing share is mostly older mail
   (inferred: the counts are not split by date).

---

## 1. The job of the Emails page

### The question

Should the `Emails` page hold synced conversations, or only the mail the user writes?

### Today

- The page has four tabs: Drafts, Outbox, Failed and Templates
  (`src/Enums/EmailPageTab.php:17-20`). It opens on Drafts
  (`src/Filament/Pages/EmailInboxPage.php:55-56`).
- Synced mail is reachable only from the `Emails` tab of a company, person or
  opportunity (`src/Filament/RelationManagers/BaseEmailsRelationManager.php:93-123`).
- With no mailbox, the page shows only a connect card and hides Drafts and Templates
  (`resources/views/filament/pages/email-inbox.blade.php:2-23`).
- The import-complete notification's "Open inbox" button leads here
  (`src/Notifications/MailboxHistoryImportCompletedNotification.php:157-163`). The user
  lands on an empty Drafts table.
- Production: 372,714 synced emails, 54% linked to no record, 27 sends in 90 days,
  2 templates, 1 mass send.

**Why the page became outbound-only.** The history matters, because this pick reverses it.

- `c1e284531` (2026-08-13) replaced the two-pane inbox with a full-width list and an
  overlay reader "matching how Companies, People and Opportunities already open their
  records".
- `18159fe9c`, the same day, put that list on record pages.
- `0730dddd7`, the same day again, removed the inbox tab: "The mail board no longer
  lives on this page." A contributor wrote it, not the founder.
- A "Restore inbox emails tab" commit (`acbe7e0a1`, 2026-08-14) was reverted the next
  day (`1c5546af5`). That revert shows the removal was intended.
- `c3f30b7a2` (2026-10-02, the founder) deleted the list code that had been unmounted
  since August. It was cleanup of a settled call, not a new decision.

No commit or pull request states the reason. The likely reasons are inferred:

- the Attio model, where mail lives on records;
- not wanting to build a weaker Gmail;
- the old list being a per-message mirror of the mailbox, which is what a Gmail clone looks like.

Those reasons still hold against a mailbox clone. They do not hold against a list where
every row carries its record context.

### What competitors chose and why

- **Attio** has no inbox: "Attio does not have a traditional inbox page or sent folder"
  ([view emails](https://attio.com/help/reference/email-calendar/view-emails-and-meetings)).
  Its send docs tell users to "Continue to use your Gmail or Microsoft 365 inbox to
  reply to emails and receive notifications"
  ([send](https://attio.com/help/reference/email-calendar/send-emails-in-attio)). The
  business reason is in its Series A post: email is ingested as "one of our most
  powerful data sets" to build the record graph
  ([Series A](https://attio.com/blog/series-a-round)). Email is an input to a data
  platform, not a workspace to live in. The cost is accepted: G2 reviewers cite email
  sharing as a top con, and workspace search "does not search the contents of emails"
  ([G2](https://www.g2.com/products/attio/reviews?qs=pros-and-cons)).
- **Close** sells to high-volume reps who call, email and text all day. Its Inbox is "a
  hub for everything you should react to" and "should be considered a to-do list"
  ([inbox](https://help.close.com/feature-guide/inbox)). Close says reps should "work
  exclusively from their Close inbox", and Done archives the mail in Gmail too. Close
  can make that bet because its buyer replaces Gmail for sales work. The cost is
  two-way provider writes and a narrower audience.
- **HubSpot** built its conversations inbox for shared, customer-facing channels:
  "multiple shared customer-facing channels in one unified inbox"
  ([overview](https://knowledge.hubspot.com/inbox/overview-of-the-conversations-inbox)).
  It serves support and service work with routing and assignment. One-to-one sales mail
  stays on the record. Inferred: HubSpot needs the inbox to sell Service Hub, not to
  serve the individual seller.
- **Superhuman** sells speed to individuals who pay to replace their mail client
  ([speed](https://blog.superhuman.com/superhuman-is-built-for-speed/)). A full client
  is the product, so the comparison is only useful for interaction design.
- **Notion Mail** shut down on 2026-09-22. Notion's stated reason: "more than half of
  Notion Mail users manage emails without ever opening their inbox"
  ([TechCrunch](https://techcrunch.com/2026/06/25/notion-mail-shuts-down-amid-agent-takeover),
  [Notion](https://www.notion.com/help/notion-mail-inbox-is-going-away-what-to-do-next)).
  A general inbox inside a workspace tool lost to Gmail on one side and agents on the other.
- **Day.ai and Lightfield** bet on agents. Day.ai describes CRM data as "low resolution"
  and keeps email "linked to the people, companies" for agents to read
  ([Day.ai](https://day.ai/guides/crm-is-low-resolution)). Lightfield calls itself a
  record "structured for agents to reason from"
  ([Series A](https://lightfield.app/blog/our-series-a)), and its help center sends
  email questions to the record page ([Up next](https://docs.lightfield.app/help/up-next)).
  Neither has a cross-record thread list. Inferred: their human surface is the agent,
  so a list would compete with it.

The pattern: a product builds a central email list when its buyer works email as a
queue (Close, HubSpot service, Superhuman). It skips the list when email is an input to
a record graph (Attio, Day.ai, Lightfield). Relaticle has both users. People still own
the relationship, and Rela already reads email as an agent.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Outbound-only page, mail on record tabs (today) | Drafts, Outbox, Failed, Templates | Attio | Never competes with Gmail; no new query | 54% of mail is visible nowhere; the page is empty for nearly everyone; the import notification lands on nothing |
| B. Mailbox clone | Folders, labels, spam, full inbox | Gmail, Superhuman | Familiar | Competes with Gmail and loses; needs provider write scopes; the Notion Mail outcome |
| C. CRM Conversations list | Every thread the viewer may see, newest activity first, each row with its company, person or opportunity chips; views for Needs reply, Awaiting reply, All, Unlinked; outbound lists as secondary tabs | HubSpot inbox, Close (partly) | Surfaces the 54%; one list for people that matches what agents already get | Becomes a Gmail clone if rows lose record context; heavy query at 1.5M emails without a summary table |
| D. Action queue only | Only threads that need a reply or a follow-up | Close | Small, focused, clear goal | Empty for a quiet user; no place to browse or link unlinked mail |
| E. Agent-first page (replaces "No page") | The page is a Rela briefing: what needs you, with a chat box | Notion agents, Day.ai, Lightfield | Matches where the market is going | Fails with no AI key; low trust with 2 AI summaries ever; hides the underlying list |

Option E in decisions.md was "no page" (Twenty). I replaced it with the agent-first page
because that is the real alternative in 2026. "No page" scores below A on every criterion.

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 3 | 4 | 3 | 5 | 38 |
| B | 3 | 4 | 1 | 1 | 1 | 24 |
| **C** | 5 | 4 | 5 | 4 | 3 | **53** |
| D | 4 | 3 | 4 | 4 | 3 | 45 |
| E | 3 | 2 | 3 | 2 | 3 | 31 |

- A, Future 3: it passes Agents only because agents already bypass it. It fails Reversibility in spirit, since the empty page trains people to ignore it.
- C, Clarity 4: the views need one line of copy each to be obvious.
- C, Future 4: Scale 10x is partial until a conversation summary table exists (decision 5).
- E, Future 2: it fails the No AI key test outright.

### Future-proof check

| Test | C (pick) | D (runner-up) |
|---|---|---|
| Agents | Pass. The list should read through the same query owner as `VisibleEmailsQuery`, so people and agents see the same rows with the same tiers | Pass. Same query, narrower filter |
| No AI key | Pass. Every view is computed from headers and direction (decision 2) | Pass |
| Scale 10x | Partial. `restrictToVisiblePreferredCopies` runs `DISTINCT ON` over the workspace (`PreferredEmailCopyService.php:40-46`). It needs a conversation summary table before 1.5M emails | Partial, same reason |
| Providers | Pass. Built on stored rows, not provider folders | Pass |
| Trust and regulation | Pass. Every row passes the existing policy; the page explains who sees what | Pass |
| Reversibility | Pass. It is a page over existing data. Removing it needs no migration | Pass |

### Recommendation

**Pick C, with D's Needs reply as a view inside it.** C beats D because D has no answer
for the 54% of mail that is unlinked. Linking that mail is the main way the CRM gets
better. C also gives Rela's answers a place to land: a link to a thread a person can open.

This is the one decision that reverses a deliberate design. It needs the founder's call.

**What would change our mind:** 30 days after launch, fewer than 20% of users who open
the page open a thread from it, while record-tab opens stay flat. That would mean people
read mail in Gmail and want the CRM only as the record. We would then fall back to A plus
a single Unlinked view.

**Smallest first step:** an `All` view on the existing page, built on `VisibleEmailsQuery`
with record chips, behind a feature flag for two workspaces. Point the import
notification at it.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| It drifts into a Gmail clone | No folders, spam, labels or provider writes. Every row shows record chips or an explicit "Unlinked" chip with Link and Create person |
| Teammates see a wall of "Subject hidden" rows | Default the list to mail the viewer can open; locked rows follow decision 9 |
| The page and the record tab drift apart | One query owner for "emails a viewer may see". Today the record tab builds its own list (`BaseEmailsRelationManager.php:101-118`) |
| Slow at 156,632 emails | A simple paginator (no count) first; the summary table from decision 5 before 1.5M |

---

## 2. Default view when the page opens

### The question

Which view opens when a person clicks `Emails`?

### Today

- The page opens on Drafts (`EmailInboxPage.php:56`). Production shows 27 sends in 90 days, so nearly every user opens an empty table.
- Nothing in the schema says whether a thread needs a reply. `emails.direction`, `is_internal` and `sent_at` exist (`database/migrations/2026_03_21_050430_create_emails_table.php:33,49,30`). `email_threads` stores counts and dates only (`create_email_threads_table.php:21-26`).
- Messages per thread average 1.12. Most "conversations" are one inbound or one outbound message. "Needs reply" is mostly the question "was this inbound message answered".

### What competitors chose and why

- **Close** opens on the Inbox, which holds "the current items you should handle now"
  ([inbox](https://help.close.com/feature-guide/inbox)). In 2024 Close added a
  customizable Primary tab so reps "focus less on lower value Inbox items (e.g.
  follow-up reminders, OOO messages)" and auto-marked items Done when it saw a reply
  ([changelog](https://close.com/changelog/inbox-improvements)). Close learned that a
  queue only works when the default view excludes noise.
- **Superhuman** opens on split inboxes such as VIP and Team
  ([splits](https://help.superhuman.com/hc/en-us/articles/46005619081101-Default-Split-Inbox)).
  Its design rule is "make the next action obvious"
  ([flow](https://blog.superhuman.com/how-to-design-for-flow/)). A view that shows what
  needs you is the next action.
- **HubSpot** opens its conversations inbox on views such as Unassigned and Assigned to
  me ([inbox](https://knowledge.hubspot.com/inbox/use-the-conversations-inbox)). Those
  views fit service queues with owners, not a seller's own mail.
- **Gmail** opens on everything, newest first. It can, because it is the system of record for mail.
- **Inbox Zero** (open source) computes TO_REPLY with free sender and header checks
  first, then an optional model pass ([benchmark-modern.md](../benchmark-modern.md)).
  It shows that Needs reply can work without a model.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Drafts (today) | Opens on the user's drafts | Attio | Zero cost | Empty for nearly everyone |
| B. All, newest first | Every visible conversation by latest activity | Gmail | Never empty once mail syncs; obvious | Old Marketing mail on top; no answer to "what needs me" |
| C. Needs reply, fall back to All | Latest message inbound, from outside the workspace, from a person (not a bulk or no-reply sender), with no later outbound message. If empty, open All with a "Nothing needs a reply" line | Close, Superhuman splits | Answers the daily question | Wrong positives without good rules; needs a stored status to stay fast |
| D. Last view chosen | Remember the view per user | Most apps | Respects habit | First visit still needs a default; surprises after a shared link |
| E. Rela briefing | A model summary of what needs you | Cora Brief, Day.ai briefing | Rich | Fails with no AI key; 2 summaries ever |

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 1 | 2 | 3 | 4 | 5 | 33 |
| B | 3 | 5 | 4 | 5 | 5 | 51 |
| **C** | 5 | 4 | 5 | 4 | 3 | **53** |
| D | 3 | 3 | 4 | 4 | 4 | 43 |
| E | 3 | 3 | 3 | 1 | 2 | 29 |

- B, User 3: with 1.12 messages per thread, "newest" shows mostly one-off mail.
- C, Fit 5: the rule needs record context ("from outside the workspace") that only a CRM has.
- C, Cost 3: it needs a stored status updated on every stored and sent message.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass. Store the status and its reason on the conversation, so Rela and the MCP list tool can filter on it | Pass |
| No AI key | Pass. The rules are SQL and headers. A Rela refinement is optional | Pass |
| Scale 10x | Partial. A live "no later outbound" check is a correlated subquery. A stored status column fixes it | Pass. It is an index scan on `last_email_at` |
| Providers | Partial. The bulk check reads `List-Unsubscribe` and `Precedence`, which IMAP and JMAP also carry. Gmail's category labels are a bonus, not a dependency | Pass |
| Trust and regulation | Pass. Each status carries a stored reason the row can show ("Inbound from Acme, unanswered 2 days") | Pass |
| Reversibility | Pass. The status is derived data and can be dropped or recomputed | Pass |

### Recommendation

**Pick C, falling back to B when Needs reply is empty.** C beats B by 2 points, and the
margin is the whole point of the page: B shows what arrived, C shows what to do. I drop
"remember the last view" from the pick in decisions.md. It hides the default from anyone
who changes views once, and it makes shared links open in different states. Revisit it
when data shows people switching views on most visits.

**What would change our mind:** in a sample of 200 Needs reply threads reviewed by
workspace owners, more than 25% are marked "not needed". At that point B becomes the
default until the rules improve.

**Smallest first step:** compute Needs reply at read time for the viewer's own mailbox
only, with three rules: inbound, not internal, no later outbound message in the same
conversation. Ship it as a view before making it the default.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Automated mail lands in Needs reply | Bulk mail is already skipped at sync; add the `noreply@` sender rule and the "10 unanswered emails" sender rule from Inbox Zero |
| Replies sent from Gmail are missed | Sent mail syncs from both providers, so a Gmail reply clears the status on the next sync. Show "updated 5 min ago" when sync lags |
| A teammate's reply should clear my thread | The status is per conversation, not per mailbox (decision 5), so any outbound message in it counts |
| An empty default looks broken | The fallback opens All with a positive line, not an empty state |

---

## 3. Reader surface

### The question

Where does a conversation open when a person clicks a row?

### Today

- A centred overlay covers the screen: a fixed, full-viewport backdrop and an 85vh panel
  (`resources/views/components/reader-overlay.blade.php:41-48`). The list behind it is
  also set `invisible` while a message is open
  (`resources/views/filament/relation-managers/emails-relation-manager.blade.php:19-22`).
- Moving to the next email takes close plus click. The reader renders one message, not
  the thread (`resources/views/components/email-view.blade.php`).
- The overlay was a deliberate 2026-08-13 choice (`c1e284531`). It replaced a two-pane
  reader to match how records open. The commit also explains why CSS animation replaced
  `x-show`: Alpine visibility state did not survive Livewire morphing. Any new surface
  must respect that constraint.

### What competitors chose and why

- **Superhuman** deliberately hides the list while reading: "you can't see the inbox at
  the same time as a conversation", so nothing distracts from the next action
  ([flow](https://blog.superhuman.com/how-to-design-for-flow/)). This works for a user
  who processes one email at a time. A seller reading about a company wants the opposite:
  the record beside the mail.
- **Notion Mail** made the reader style a setting: side peek, centre peek or full page
  ([navigate](https://www.notion.com/help/navigate-your-inbox)). Inferred: Notion could
  not choose, and a setting moved the decision to the user.
- **Shortwave** opens a side pane only when the window has room
  ([settings](https://www.shortwave.com/docs/guides/customize-your-shortwave-settings)).
- **Front and Missive** use split panes because their users answer shared queues all day.
- **Attio, Clarify and Day.ai** read mail on the record itself, because the record is the product.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Centred overlay (today) | Modal over the page | | Focus; already built | Hides the record and the list; no `j/k` flow |
| B. Split pane | List left, reader right, both always visible | Outlook, Front, Missive | Fastest triage | Cramped on record tabs, which already sit under a record header; poor below 1280px |
| C. Non-modal right drawer | Slides over the right 55 to 60% of the content; the list and the record header stay visible and clickable; `j/k` swaps the drawer content | Linear, HubSpot preview | Keeps the CRM context; one component on every host | Covers part of a wide table; needs care with focus |
| D. Full page per thread | A route per thread | Gmail, Superhuman | Shareable, roomy | Loses the list and the record; back-button triage |
| E. Responsive host | Drawer on record tabs, split pane on the Conversations page at 1280px and up, full screen under 640px | Shortwave (partly) | Best layout per width | Three layouts to test, each in light, dark and mobile |

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 4 | 3 | 5 | 5 | 43 |
| B | 5 | 4 | 4 | 5 | 3 | 53 |
| **C** | 5 | 4 | 5 | 5 | 4 | **57** |
| D | 3 | 4 | 3 | 5 | 3 | 44 |
| E | 5 | 3 | 5 | 5 | 2 | 53 |

- All options score Future 5 because the reader is presentation only. The tests separate them elsewhere.
- E, Clarity 3: the same click behaves differently by window width.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass. Rela's summary and proposal cards render at the top of the drawer, labelled and collapsible | Pass |
| No AI key | Pass. The summary slot simply does not render | Pass |
| Scale 10x | Pass. Lazy message bodies: load only the latest message and the one being replied to, as Zero does | Pass, same technique |
| Providers | Pass | Pass |
| Trust and regulation | Pass. The drawer shows the privacy gate and the audit facts (who shared, which tier) in one place | Pass |
| Reversibility | Pass. Swapping the host keeps the same `email-view` component | Pass |

### Recommendation

**Pick C on every host first.** C beats B because B cannot fit on a record tab, and
record tabs are where 100% of mail is read today. One host keeps one set of browser tests.
A split pane can come later on the Conversations page when a measured triage session
shows the drawer slowing people down. The drawer also respects the August reasoning:
records and mail still open the same way, just without hiding the context.

**What would change our mind:** median time from first open to the fifth thread read in
a session exceeds 60 seconds on the Conversations page. That would argue for the split
pane there.

**Smallest first step:** move `reader-overlay` to a right-anchored, non-modal panel
without the backdrop. Drop the `invisible` class on the list. Keep `Esc` routed through
`closeReader()` so drafts still save (`reader-overlay.blade.php:22-36`).

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| The Alpine and Livewire morph bug returns | Keep the CSS mount animation and the `wire:key` per email, as in `c1e284531` |
| Focus escapes to the list behind | Trap focus only while the composer is open; otherwise allow list focus so `j/k` works |
| The drawer covers record actions | Width capped at 60% and resizable; `Esc` always closes |
| Long threads slow the drawer | Collapsed messages never mount their bodies; expanded ones stay mounted |

---

## 4. Deep links

### The question

What URL opens a specific conversation, and what happens when the viewer may not open it?

### Today

- A panel-wide deep link already exists. The notification handler is rendered on every
  app panel page (`src/EmailIntegrationServiceProvider.php:155-170`). It binds
  `#[Url(as: 'email')]` and opens the reader on mount
  (`src/Livewire/EmailAccessNotificationHandler.php:45-53`).
- `Email::appUrl()` builds that link on the Dashboard (`src/Models/Email.php:449-456`).
  Share and access notifications use it (`src/Notifications/EmailSharedNotification.php:97,130`).
- It opens only when the viewer passes `viewBody` (`EmailAccessNotificationHandler.php:83`).
  Otherwise `appUrlFor()` drops the parameter and the link opens a bare Dashboard
  (`Email.php:444-447`).
- The record tab's reader is not in the URL: `selectedEmailId` is a plain property
  (`BaseEmailsRelationManager.php:51`). Refresh closes it.
- The link names one message, not a conversation. Agents get `id` and the per-mailbox
  `thread_id` and no URL (`src/Support/EmailForAgent.php:134-149`).

decisions.md and findings.md #15 say "a thread cannot be linked". That is only true for
the record tab. The Dashboard link works today for mail the viewer can read.

### What competitors chose and why

- **Gmail and Superhuman** give every thread a URL, because the thread is their unit of work.
  Gmail's ids are per mailbox, so a copied link opens only for its owner (inferred from how Gmail ids work).
- **Linear** puts the peeked item in the URL of the list behind it. Inferred: it gives
  shareable state without leaving the list.
- **HubSpot** links to the record with the activity selected
  ([one-to-one email](https://knowledge.hubspot.com/one-to-one-email/send-and-reply-to-one-to-one-emails)).
  The record is the address.
- **Attio** sends access-request recipients to the email on the record
  ([2025-12-17](https://attio.com/changelog/2025/changelog-december-17-2025)).
- **Day.ai** draft cards cite their sources ("Pulled from: call, email, meeting"), so
  each claim needs a link back to the mail ([benchmark-modern.md](../benchmark-modern.md)).

### Options

| Option | How it works | Strengths | Failure modes |
|---|---|---|---|
| A. Today | `?email=<id>` on the Dashboard through the panel-wide handler; the record tab is not linkable | Exists, works from notifications | Opens over the Dashboard, out of context; a refresh on a record tab loses the reader; locked mail gives a bare Dashboard |
| B. Host query string | `#[Url]` on each host's reader property: `/companies/{id}?tab=emails&email=...`, `/email?email=...` | Cheap; refresh-safe | Every sender of a link must know which host to use; links break when hosts change |
| C. Dedicated route `/email/threads/{thread}` | A page per thread | Clean | Thread ids are per mailbox, so a teammate's link can name a thread the viewer does not hold; loses the list and record |
| D. URL hash | `#email=...` | No server state | Invisible to Livewire on first load; not sent to the server for authorization |
| E. Resolver route plus host query string | `/email/open/{email}` checks the viewer and redirects to the best host: the record tab with `?email=` when one is linked, else the Conversations page. A locked email lands on the privacy gate (decision 9). Host pages keep B for refresh | One stable URL for notifications, Rela citations and the MCP server; survives layout changes | One more route to keep; must never leak existence of a private email |

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 3 | 3 | 3 | 5 | 35 |
| B | 4 | 5 | 4 | 3 | 5 | 48 |
| C | 4 | 4 | 3 | 2 | 3 | 38 |
| D | 3 | 3 | 3 | 2 | 4 | 34 |
| **E** | 5 | 5 | 5 | 5 | 3 | **58** |

- B, Future 3: links already sent in notifications break when a host changes, which fails Reversibility.
- C, Future 2: it encodes the per-mailbox thread id in a public URL.
- E, Fit 5: the resolver applies the policy once, at open time, in one place.

### Future-proof check

| Test | E (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass. `EmailForAgent` adds a `url`, so Rela and Claude cite a link the person can open with their own permissions | Partial. Agents would need to know the host rules |
| No AI key | Pass | Pass |
| Scale 10x | Pass. One indexed lookup by primary key | Pass |
| Providers | Pass. Keyed by our ULID, never a provider id | Pass |
| Trust and regulation | Pass. The resolver returns the same "not available" page for a missing and a private email, and can log opens | Partial. Each host repeats the check |
| Reversibility | Pass. The route is a permanent redirect; hosts can change behind it | Fail. Sent links break with the host |

### Recommendation

**Pick E.** decisions.md picked B. E wins because links leave the app: they go into
notification emails, Rela answers and MCP tool results that live for months. A
resolver keeps that promise while the page layout changes. Key the link on the email
ULID, not a thread id: a message has one id per mailbox copy, and the resolver can open
the viewer's preferred copy of the same conversation through
`PreferredEmailCopyService`.

**What would change our mind:** if more than 90% of deep-link opens come from inside the
app with the host already loaded, B alone is enough and the route can wait.

**Smallest first step:** make the record tab's `selectedEmailId` a `#[Url(as: 'email')]`
property, which matches the panel-wide handler's name. Then add the resolver and point
`Email::appUrl()` at it.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| The panel-wide handler and a host both react to `?email=` | The handler opens only when the page has no reader host of its own |
| A link reveals that a private email exists | Same response for missing and private; no subject in the page title below `viewSubject` |
| Opening a link marks mail read unexpectedly | Keep today's rule: opening marks read for the viewer only (`src/Filament/Concerns/HasEmailReaderActions.php:61`) |
| Workspace mismatch | The resolver binds the email's own workspace and refuses when the viewer is not a member |

---

## 5. Message rows or thread rows

### The question

Is one row one message, one provider thread, or one conversation across mailboxes?

### Today

- One row per message, newest first (`BaseEmailsRelationManager.php:118`). On the seeded
  company, 5 conversations rendered as 13 rows.
- The same message in two mailboxes shows once. The list keeps the viewer's own copy,
  then the most open tier (`PreferredEmailCopyService.php:29-49`). The row then lists
  every mailbox that holds it ("via 2 mailboxes", `list-row-wide.blade.php:102-132`).
- `email_threads` exists but is per mailbox: unique on `(connected_account_id, thread_id)`
  (`create_email_threads_table.php:30`). `EmailThread::emails()` joins on both columns
  (`src/Models/EmailThread.php:57-61`).
- `email_threads.subject` is the first message's subject
  (`src/Actions/SyncEmailThreadAction.php:51`). It ignores per-message tiers, so a thread
  row cannot use it as is.
- `emails` stores `rfc_message_id` and `in_reply_to`, but not the `References` header
  (`create_emails_table.php:23-26`).
- Production: 1.12 messages per thread on average. Most conversations are single messages.
  The long threads are the ones that matter: active sales conversations (inferred).

### What competitors chose and why

- **Gmail, Superhuman and HubSpot** show thread rows. Gmail invented conversation view in
  2004, and a single-user client has one mailbox, so provider threads are enough.
- **Front and Missive** solve the multi-mailbox case. Front dedupes a conversation that
  reaches several teammates into one shared conversation with one owner
  ([assign](https://help.front.com/en/articles/2344)). Missive shows who has access with
  avatars ([conversations](https://missiveapp.com/docs/core-features/conversations)).
  Their whole business is several people on one conversation, so they pay for merging.
- **Lightfield** lets the most permissive mailbox win when several hold the same item
  ([benchmark-modern.md](../benchmark-modern.md)). It merges, but in a way that leaks.
- **Streak** groups by box (a record), because its unit is the pipeline item.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. One row per message (today) | Each message is a row, de-duplicated across mailboxes | | Simple; exact | Long threads flood the list; 13 rows for 5 conversations |
| B. One row per provider thread | Rows from `email_threads` | Gmail, Superhuman | Table exists | The same conversation shows twice when two teammates hold it; the stored subject can leak a hidden message |
| C. One row per conversation | A `conversation_key` per email, set at store time: inherit the key of the message named by `In-Reply-To` in the same workspace, else the provider thread's key, else its own Message-ID. Two provider threads that share a Message-ID merge. The row is built from the messages the viewer may see | Front (dedupe) | One row per real conversation; provider-independent; the right unit for Needs reply | Needs a column, a backfill and care with missing parents |
| D. One row per record, threads nested | Group by company or person | Streak | Strong CRM framing | Unlinked mail has no group; a thread with two companies appears twice |
| E. Toggle between A and B | User setting | Outlook | Choice | Two layouts to build, test and explain |

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 2 | 4 | 4 | 5 | 39 |
| B | 4 | 4 | 3 | 3 | 4 | 42 |
| **C** | 5 | 5 | 5 | 4 | 2 | **54** |
| D | 3 | 3 | 4 | 3 | 2 | 38 |
| E | 3 | 2 | 4 | 3 | 2 | 36 |

- B, Fit 3: duplicate rows in shared workspaces, and the subject leak risk.
- C, User 5 despite 1.12 messages per thread: the average hides that the threads people reread are the long ones.
- C, Future 4: Reversibility is partial because it adds a stored column (decision below).

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass. `VisibleEmailsQuery` gains a `conversation` filter, replacing the per-mailbox `thread_id` filter (`VisibleEmailsQuery.php:54,79`). Agents stop missing a teammate's half of a thread | Fail. Agents keep a per-mailbox id |
| No AI key | Pass | Pass |
| Scale 10x | Pass. An index on `(workspace_id, conversation_key)` plus a summary row per conversation (latest message, counts, latest direction) lets one query paint a page, as Zero does | Partial. Fast, but the duplicates grow with mailboxes per workspace |
| Providers | Pass. Message-ID and In-Reply-To are RFC 5322 and exist on Gmail, Graph, IMAP and JMAP | Fail. It depends on each provider's thread id semantics |
| Trust and regulation | Pass. The row is built only from messages the viewer may see; it never reads `email_threads.subject` | Partial. Needs the same rule, and the table invites misuse |
| Reversibility | Partial. The column is additive and can be ignored, but it needs a backfill migration rehearsed on production data | Pass. No schema change |

### Recommendation

**Pick C.** decisions.md picked B, assuming `email_threads` was the right unit. It is not:
a thread in Relaticle is one mailbox's view of a conversation. B would show the same
conversation twice in every workspace with two mailboxes on it, which is exactly the case
a CRM exists for. C also makes Needs reply correct: a teammate's reply clears the thread
for everyone. Visibility stays per message: a row shows when any message in it is visible
to the viewer, and its subject and snippet come from the newest message the viewer may
see at that tier.

**What would change our mind:** if a production count shows fewer than 1% of conversations
spanning two or more mailboxes after six months, B's duplicates are rare. B is then the
cheaper choice.

**Smallest first step:** a read-only production count of Message-IDs held by two or more
mailboxes in the same workspace. Then add `conversation_key` at store time for new mail.
Backfill it in a chunked migration, rehearsed per `core.md`.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| A reply arrives before its parent, so the chain splits | Fall back to the provider thread key; merge when the parent arrives later |
| Unrelated mail merges on a shared Message-ID | Merge only within one workspace, and only on exact Message-ID |
| A row leaks a hidden message's subject | Build row fields from visible messages only; one test with a mixed-tier thread |
| Backfill load on the largest mailbox (156,632 emails) | Chunked `eachById` in the migration, as `core.md` describes |

---

## 6. Row content and density

### The question

What does one row show, and on how many lines?

### Today

- Three lines (`list-row-wide.blade.php:95-184`):
  1. Subject, "via" mailbox, attachment icon and time.
  2. The first four from and to names.
  3. Snippet and category badge.
- The subject is always `font-medium` (`list-row-wide.blade.php:97`). Nothing marks unread.
- The "via Name" text shows even when the mailbox is the viewer's own (`list-row-wide.blade.php:133-137`).
- Below subject access the row reads "Subject hidden" (`list-row-wide.blade.php:98`). At 95.4% `metadata_only`, that is what a teammate sees for almost every row.
- No row shows the company, person or opportunity it is linked to. On a record tab that is implied. On a cross-record list it is the missing piece.

### What competitors chose and why

- **Superhuman and Gmail** use one dense line, because their users scan hundreds of rows a day and the sender is the main signal ([TechCrunch](https://techcrunch.com/2017/08/18/rapportive-founders-new-startup-superhuman-is-what-gmail-would-be-if-built-today/)).
- **Zero** (open source) shows a semibold sender when unread, 70% opacity when read, a reply count and a hover action pill ([benchmark-modern.md](../benchmark-modern.md)).
- **Notion Mail** right-aligns label chips so the subject keeps its position ([views](https://www.notion.com/help/views-groups-filters-and-properties)). Its failure is the warning: rows that never linked to the rest of Notion.
- **Front** shows two lines with an assignee, because ownership is its product.
- **Attio** replaces the snippet with an AI summary line ([2025](https://attio.com/changelog/2025/auto-summaries-and-labels)). Attio can afford to: it runs models on every synced email as part of its data platform.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Three lines (today) | Subject, participants, snippet | | Built | Tall; no record context; no unread |
| B. One line | Sender, subject, snippet, time | Gmail, Superhuman | Densest | No room for record chips; "Subject hidden" rows become empty lines |
| C. Two lines with record chips | Line 1: sender names with count (`Brian, Olivia (3)`), the owner's avatar when it is a teammate's mailbox, attachment icon, time. Line 2: subject, muted snippet, up to two right-aligned chips (company, opportunity) or an "Unlinked" chip | Front, HubSpot, Notion chips | Record context on every row; useful even at metadata tier | Chips crowd narrow screens |
| D. AI summary instead of snippet | One model line per thread | Attio | Rich | Fails with no AI key; costs per row; 2 summaries ever |
| E. Density setting | Compact and comfortable | Gmail | Choice | A setting before evidence anyone wants it |

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 3 | 3 | 3 | 5 | 5 | 44 |
| B | 4 | 4 | 3 | 5 | 4 | 48 |
| **C** | 5 | 4 | 5 | 5 | 4 | **57** |
| D | 3 | 3 | 2 | 2 | 2 | 29 |
| E | 3 | 3 | 3 | 4 | 3 | 39 |

- C, Fit 5: a metadata-only row still answers "who talked to Acme, when", which is the point of that tier.
- D, Fit 2: summaries of mail the viewer cannot read would need their own tier rules.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Pass. The row fields map to `EmailForAgent::summary` plus `linked_records`, one owner for both | Pass |
| No AI key | Pass | Pass |
| Scale 10x | Pass. Fields come from the conversation summary row; chips need one eager load per page | Pass |
| Providers | Pass | Pass |
| Trust and regulation | Pass. Each field respects the tier; chips show only records the viewer may see | Pass |
| Reversibility | Pass | Pass |

### Recommendation

**Pick C.** It beats B because B has no room for the record chips that make this list a
CRM list rather than Gmail. The row should also work at the metadata tier. For a locked
row, line 2 shows "Subject hidden" in muted text, but the chips and the owner avatar
still carry meaning. Drop "via Name" when the mailbox is the viewer's own.

**What would change our mind:** if usability sessions show people ignoring the chips and
asking for more rows per screen, test B with chips on hover.

**Smallest first step:** add the chips and the owner avatar to `list-row-wide` on record
tabs, then reuse the component on the Conversations page.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Chips wrap on mobile | At 390px, keep one chip plus "+1" |
| Chip eager load causes N+1 | Load `companies`, `people`, `opportunities` with the page, scoped by `WorkspaceScope` |
| A chip shows a record the viewer cannot open | Chips pass the record policy; hidden records render nothing |
| The colour map drifts again | Chip and badge colours read enums, never a Blade map (decision 8) |

---

## 7. Unread state

### The question

How does a row show mail the viewer has not read, and whose read state is it?

### Today

- `withReadStateFor()` loads `is_read` per viewer (`src/Models/Email.php:186-190`, called at `BaseEmailsRelationManager.php:105`). `list-row-wide.blade.php` never reads it.
- Read state is per user in `email_reads`, unique on `(email_id, user_id)` (`database/migrations/2026_03_21_050530_create_email_reads_table.php:51-59`).
- The owner's read state syncs one way from both providers. It is set at store time (`src/Actions/StoreEmailAction.php:79`) and toggled on each delta (`src/Jobs/IncrementalEmailSyncJob.php:106-140`). Reading in Relaticle does not mark read in Gmail or Microsoft.
- Opening the reader marks read for the viewer only (`HasEmailReaderActions.php:61`).
- A teammate gets a read row only by opening. A shared message stays "unread" for them forever unless opened.
- The old inbox did render unread with a `data-unread-indicator` hook (`c1e284531` message). The row lost it when the list moved to record tabs.

### What competitors chose and why

- **Gmail and Superhuman** bold unread rows. Both are the system of record for read state, so bold means "you have not seen this anywhere".
- **Zero** uses a dot and a semibold sender, and dims read rows to 70% ([benchmark-modern.md](../benchmark-modern.md)).
- **Close** counts inbox items, not unread mail, because Close tracks work, not reading ([inbox](https://help.close.com/feature-guide/inbox)).
- **Attio** shows no unread state on record tabs. Inferred: when Gmail stays the inbox, a second unread state invites mismatch, so Attio avoids it.
- **Slack** uses a "new since your last visit" line, because channels are shared and read state there means "new to you".

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Not shown (today) | | Attio | No mismatch | Unread looks like read |
| B. Bold plus dot, scoped | Bold sender and subject plus a dot, only on messages the viewer can open that reached them: their own mailbox, or shared with them after the share date. A thread is unread when any such message is unread | Gmail, Superhuman | Familiar; mirrors Gmail for the owner | Needs the scoping rule; one-way sync can disagree after a read in Relaticle |
| C. Dot only | A dot, no weight change | Linear | Quiet | Easy to miss in a dense list |
| D. "New since last visit" line | Divider at the last visit time | Slack | Works for shared mail | A second concept; per-user visit tracking |
| E. Counts on views only | Badge counts, rows unchanged | | Cheap | Says how many, not which |

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 1 | 1 | 3 | 5 | 5 | 34 |
| **B** | 4 | 5 | 5 | 5 | 4 | **56** |
| C | 3 | 4 | 5 | 5 | 5 | 52 |
| D | 3 | 3 | 4 | 4 | 3 | 42 |
| E | 2 | 3 | 4 | 4 | 4 | 40 |

- B, User 4: most owners still read in Gmail, so unread in Relaticle mostly echoes Gmail.
- B, Fit 5 only with the scoping rule: unscoped, every teammate's metadata row would be unread forever.

### Future-proof check

| Test | B (pick) | C (runner-up) |
|---|---|---|
| Agents | Pass. Add `is_read` for the acting user to `EmailForAgent`, so "what's new from Acme" has an answer | Pass |
| No AI key | Pass | Pass |
| Scale 10x | Pass. `withExists` on a unique index per row; a thread rollup on the summary row | Pass |
| Providers | Pass. Both providers already sync the owner's read state; IMAP has `\Seen` and JMAP has `$seen` | Pass |
| Trust and regulation | Pass. Read state is the viewer's own; no one learns whether a teammate read something | Pass |
| Reversibility | Pass | Pass |

### Recommendation

**Pick B with the scoping rule.** B beats C because weight is the signal people already
read without thinking. The scoping rule is what makes it safe. Add an unread count on the
Needs reply view only, not on the nav item: a nav count that includes newsletters trains
people to ignore it. Do not write read state back to the provider. That needs a modify
scope on Gmail and moves Relaticle toward a mail client (decision 10).

**What would change our mind:** support reports that "read in Relaticle, still unread in
Gmail" confuses people. That would be a signal to consider write-back behind an opt-in scope.

**Smallest first step:** read `is_read` in `list-row-wide` for rows where the viewer owns
the mailbox. Add bold and a dot with a `data-unread` hook for tests.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Teammates see everything as unread | The scoping rule: only mail that reached the viewer |
| Mismatch with Gmail after reading in Relaticle | Gmail is the source for the owner; a later Gmail read syncs back and agrees |
| Unread only in colour fails accessibility | Weight plus a dot with an `aria-label` |
| Thread rollup is slow | Store an unread flag per viewer only if needed; start with `withExists` |

---

## 8. Categories and noise

### The question

How do newsletters, receipts and automated mail stay out of the way without hiding mail that matters?

### Today

- A rule-based classifier stamps one category at sync (`src/Services/EmailClassifier.php:43-63`). Gmail's `CATEGORY_PERSONAL` maps to Personal (`GmailService.php:161`).
- `Other` is stored but never shown (`src/Enums/EmailCategory.php:63-66`, `Email.php:259-275`). The 52% Other share shows no badge. decisions.md's "badge on every row" is wrong for half the mail.
- The badge keeps its own colour map, which disagrees with the enum and lists `Notifications` and `News`, which are not cases (`resources/views/components/category-badge.blade.php:4-13` vs `EmailCategory.php:35-45`).
- Bulk mail is already dropped at sync, never stored:
  - Gmail: the four bulk categories (`3683eb4d5`, `GmailService.php:30,162,186`).
  - Microsoft: `List-Unsubscribe` or bulk `Precedence` headers (`1a57d49cf`, `MicrosoftGraphMailService.php:165,172-181`).
  - Both: skipped in `StoreEmailJob.php:147`.
- Production: Marketing 37%, Sales 0.1%. 24,007 Marketing emails sit on company and person tabs. They are mostly from before the October filter (inferred).

### What competitors chose and why

- **HEY** screens first-time senders and routes accepted ones to the Imbox, The Feed or the Paper Trail ([how it works](https://www.hey.com/how-it-works)). Jason Fried's reason: "there's no such thing as a private email address" any more, so consent comes first ([TNW](https://thenextweb.com/news/how-jason-fried-wants-to-change-your-perception-of-email-with-hey)). HEY can demand a decision per sender because it owns the address.
- **Gmail** splits into tabs and retrains when mail is moved ([categories](https://support.google.com/mail/answer/3094499)). It has the volume to learn from.
- **Close** lets reps remove "lower value Inbox items" from the Primary tab ([changelog](https://close.com/changelog/inbox-improvements)).
- **Salesforce Einstein Activity Capture** holds automated mail as Don't Share until the owner reviews it ([EAC](https://help.salesforce.com/s/articleView?id=000392958&language=ja&type=1)). That is a privacy reason, not a tidiness one.
- **Clarify and Lightfield** drop mail with unsubscribe headers before it reaches a record. Lightfield keeps a "See excluded activity" view ([mail and calendar](https://docs.lightfield.app/help/settings/mail-and-calendar/)).
- **Shortwave** bundles a sender into one row ([settings](https://www.shortwave.com/docs/guides/customize-your-shortwave-settings)).

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Badge per category except Other (today) | Sync drops bulk; a badge on the rest | | Built | Marketing badges add noise; two colour maps |
| B. Category tabs | Tabs per category | Gmail | Familiar | Sales at 0.1% makes most tabs empty; rule labels are too coarse for tabs |
| C. Hide promotional by default | Lists exclude Marketing and automated senders unless "Show 24 promotional" is clicked. Badges only for categories that change the action: Scheduling, Invoice, Support. The enum owns colour and label | HEY Feed, EAC | One click to recover; quiet by default | A misclassified customer email hides behind the toggle |
| D. Screener | Approve each new sender | HEY | Strong control | One decision per sender for 156,632-email mailboxes; Relaticle does not own the address |
| E. Bundle automated senders (replaces "No categories") | One row per noisy sender, done together | Shortwave, Inbox Zero sender categories | Keeps mail visible but compact | A sender-level model to maintain; the sync filter already removes most of it |

Option E was "no categories at all". The sync-time filter already removes most bulk mail,
so "none" is close to today. Bundling is the stronger alternative.

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 3 | 3 | 3 | 5 | 35 |
| B | 3 | 4 | 3 | 3 | 3 | 38 |
| **C** | 5 | 4 | 5 | 4 | 4 | **54** |
| D | 3 | 3 | 3 | 3 | 2 | 35 |
| E | 4 | 4 | 4 | 4 | 3 | 47 |

- A, Future 3: Gmail's categories drive Gmail classification, so the two providers already behave differently.
- C, Future 4: Providers is partial until both providers use the same header rule.

### Future-proof check

| Test | C (pick) | E (runner-up) |
|---|---|---|
| Agents | Pass. The category is a filter on `VisibleEmailsQuery`, so Rela can say "3 invoices from Acme this month" | Pass |
| No AI key | Pass. Rules and headers. A model can refine labels later | Pass |
| Scale 10x | Pass. An indexed label filter | Partial. Grouping by sender per page costs more |
| Providers | Partial. Gmail relies on Gmail's categories, Microsoft on headers. Store the headers' verdict for both so IMAP and JMAP get the same rule | Partial, same |
| Trust and regulation | Pass. Dropping bulk mail at sync is data minimisation under GDPR. Hidden is not deleted, and the toggle shows the count | Pass |
| Reversibility | Pass for the toggle. Dropped bulk mail cannot be recovered, which is the privacy trade we keep | Pass |

### Recommendation

**Pick C.** C beats E because the sync filter already does most of E's work for new mail.
What remains is mostly the legacy Marketing rows, and a default toggle handles them in one
query. Keep dropping bulk mail at sync rather than storing it for an Excluded view: every
stored email is a GDPR liability, and nothing shows people miss it. Move badge colours
into `EmailCategory::getColor()` and delete the Blade map.

**What would change our mind:** if more than 5% of toggled-in promotional rows are then
opened or linked, the classifier hides real mail. In that case add a per-sender "always
show" rule, then consider E.

**Smallest first step:** on record tabs, exclude the Marketing label by default with a
counted toggle. Delete `category-badge`'s match and read the enum.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| A customer's email from `notifications@` is hidden | The toggle shows the count; a per-sender override follows if the signal fires |
| Gmail changes its categories | The header rule becomes the primary check for both providers |
| Hidden mail counts toward record tab badges | Badges count what the default view shows |
| People want bulk mail back | Explain at connect that newsletters are not synced. That is a promise, so keep it |

---

## 9. Rows the viewer cannot open

### The question

What happens when a viewer meets mail they may not read?

### Today

- Below `viewBody` the row renders as a `div`, not a button, so the row itself does nothing on click (`list-row-wide.blade.php:58-71`).
- The row does carry a labelled button: "Request access from {owner}". After a request it shows "Requested" (`list-row-wide.blade.php:177-183`, `resources/views/components/request-access-list-pill.blade.php:3-18`). decisions.md and findings.md #17 say "no feedback". That is not accurate.
- The reader has a full privacy gate with tier-specific copy and a request hint (`email-view.blade.php:434-470`). It cannot be reached: both reader hosts return no email unless the viewer passes `viewBody` (`BaseEmailsRelationManager.php:189`, `EmailAccessNotificationHandler.php:83`).
- The reader header prints the snippet when the viewer passes `viewSubject` (`email-view.blade.php:68,138-146`). A subject-tier viewer would see body text if the gate became reachable. Any change here must fix that first.
- `requestAccess` is allowed for any non-owner below `full` (`src/Policies/EmailPolicy.php:65-74`).
- The request lifecycle (expiry, decisions, notices) shipped between 2026-10-22 and 2026-10-24 (`e47751e16` to `ad9f72fd1`). It is nine days old.
- Agents see locked rows with `access` and null subject and snippet (`EmailForAgent.php:140-142`). They have no tool to request access.
- Production: 0 access requests ever, 0 shares, 95.4% `metadata_only`. With at most three multi-mailbox workspaces, few people ever see a teammate's locked row (inferred).

### What competitors chose and why

- **Attio** lets the requester choose which teammate on the thread receives the request ([2025-12-17](https://attio.com/changelog/2025/changelog-december-17-2025)). Attio defaults to the Subject tier, so locked rows are common and the request path must be good.
- **Clarify** defaults to "Participants only". Its requests carry a note and notify the requester either way ([sharing](https://docs.clarify.ai/en/articles/16774485-sharing-and-permissions)).
- **Streak** shows only to, from and date for unshared mail ([sharing](https://support.streak.com/en/articles/4300110-overview-automatic-email-sharing)). It accepts thin rows to keep the relationship visible.
- **Day.ai** is "Share All or Share None", and "agents inherit the person's access" ([benchmark-modern.md](../benchmark-modern.md)). It avoids per-email requests entirely.
- **Lightfield** lets the most permissive mailbox win, which avoids locked rows by leaking.

### Options

| Option | How it works | Who does it | Strengths | Failure modes |
|---|---|---|---|---|
| A. Row plus Request pill (today) | Row inert; a labelled pill sends the request | | Built; one click | Clicking the row does nothing; the gate's explanation is never seen |
| B. Open the reader at the gate | The row opens the reader. Below `viewBody` it shows the metadata the tier allows, the gate copy, the owner, and a primary Request access button with an optional note | Attio, Clarify | Every row behaves the same; the gate explains why | Needs the snippet leak fixed first |
| C. Hide locked rows | Show only readable mail | | Clean | Hides that a relationship exists; breaks "who talked to them" |
| D. Collapse runs | Consecutive locked rows from one owner become "12 emails in Ana's mailbox" with Request all | Streak (partly) | Compact on busy records | Hides dates and participants a viewer may see; a bulk request flood |
| E. Relationship row (replaces "request on hover") | Locked mail shows only as one line per owner and record: "Ana emailed Acme 12 times, last Tuesday" | Attio connection strength | Matches the metadata tier's purpose | Loses per-thread detail; a new summary to compute |

Option E in decisions.md was "request on hover". Today's row already has a request button,
so the hover variant adds little. The relationship row is the real alternative.

### Matrix

| Option | User x3 | Clarity x2 | Fit x3 | Future x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 3 | 4 | 5 | 5 | 44 |
| **B** | 4 | 5 | 5 | 5 | 4 | **56** |
| C | 2 | 2 | 2 | 3 | 5 | 30 |
| D | 4 | 4 | 5 | 4 | 3 | 50 |
| E | 3 | 4 | 5 | 4 | 3 | 47 |

- B, User 4, not 5: few viewers meet locked rows in today's single-mailbox workspaces.
- C, Fit 2: it hides metadata the owner chose to share.
- D, Future 4: a "Request all" button can flood an owner, which strains Trust.

### Future-proof check

| Test | B (pick) | D (runner-up) |
|---|---|---|
| Agents | Pass. Add a request-access proposal tool so Rela can offer "Ask Ana for access" as an approval card; the gate and the agent read the same policy | Partial. A bulk request needs its own approval rules |
| No AI key | Pass | Pass |
| Scale 10x | Pass. One row per open | Pass |
| Providers | Pass | Pass |
| Trust and regulation | Pass. The gate says who owns the mail and what the viewer can see. Requests are logged and expire (`e47751e16`) | Partial. Grouping hides what each request covers |
| Reversibility | Pass | Pass |

### Recommendation

**Pick B.** It beats D because every row then behaves the same, and the gate is the only
place that can explain the tier. The request flow is nine days old, so 0 requests is not
yet evidence that people don't want it. Treat it as unmeasured. Keep the row pill as a
shortcut. Add D on record tabs only if a record shows more than three locked rows in a
row and owners do not complain about request volume.

**What would change our mind:** 60 days after B ships in workspaces with two or more
mailboxes, gate opens produce requests under 2% of the time. Then the metadata row is
enough, and E's summary line serves the viewer better.

**Smallest first step:** fix the reader snippet to require `viewBody`
(`email-view.blade.php:138`). Then let the record tab's `selectedEmail()` load rows the
viewer may `view` and render the gate for the rest.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| The gate leaks the snippet or body | Fix line 138 first; one test per tier asserting what the reader renders |
| Opening a locked row marks it read | Skip `MarkEmailAsReadAction` below `viewBody` |
| Owners get request spam | One open request per requester per email already holds (`ad9f72fd1`); requests expire |
| A viewer learns a private email exists | `VisibleEmailScope` already removes private rows entirely, so the gate never sees them |

---

## Corrections to the existing docs found while verifying

| Claim | What the code shows |
|---|---|
| decisions.md 4, findings.md #15: "a thread cannot be linked" | A panel-wide `?email=` link exists (`EmailAccessNotificationHandler.php:45-53`, `Email.php:449-456`). Only the record tab's reader is not URL-bound (`BaseEmailsRelationManager.php:51`) |
| decisions.md 9, findings.md #17: locked rows give "no feedback" | They carry a "Request access from {owner}" button and a "Requested" state (`list-row-wide.blade.php:177-183`). The reader gate is at `email-view.blade.php:434`, not 447, and is unreachable from both hosts |
| decisions.md 8: "Badge on every row" | `Other` (52%) never shows a badge (`EmailCategory.php:63-66`) |
| decisions.md 8, 2023+ evidence: filter on headers first | Already done at sync: Gmail since 2026-10-02 (`3683eb4d5`), Microsoft since 2026-10-09 (`1a57d49cf`) |
| decisions.md 1 and README: the outbound-only page was the founder's call | `0730dddd7` was written by a contributor. A restore was reverted the next day (`1c5546af5`). The founder's `c3f30b7a2` removed code unmounted since August |
| decisions.md 5: `email_threads` is the thread unit | It is per mailbox, and its `subject` ignores tiers (`SyncEmailThreadAction.php:51`) |
| method.md: cites `competitors.md` | The file does not exist; the reasoning lives in each decision file |
| Not in any doc | The reader shows the snippet at subject access (`email-view.blade.php:138`), a leak once the gate is reachable |
