# Modern email benchmark: 9 products built or rebuilt since 2023

Read on 2026-10-31. The earlier benchmark in [benchmark.md](benchmark.md) covers the
established players. This one covers the generation that sets today's bar:

- **Four email clients**: Notion Mail, Shortwave, Spark 3, Zero.
- **Two AI inbox agents**: Inbox Zero, Cora.
- **Three AI-native CRMs**: Day.ai, Clarify, Lightfield.

Zero and Inbox Zero are open source, and their sections cite the actual code at
a pinned commit. Everything else comes from public docs, changelogs and
marketing pages. No product was logged into, so layouts come from help text and
marketing mock-ups. "Inferred" marks a reading of the docs rather than a
statement in them.

Screenshots of each product's own pages are in the orb at
`.amp/in/artifacts/email-audit/benchmark/` (23 files). They are not committed.

## The three findings that matter most

1. **Nobody in the AI-native CRM tier has a thread list.**
   - Day.ai puts email on record timelines and in its assistant.
   - Clarify uses a record `Messages` tab.
   - Lightfield's help center answers "Does Up next show emails?" with no:
     "You'll find emails on the account, contact or opportunity they belong to"
     ([Up next](https://docs.lightfield.app/help/up-next)).
   - A CRM Conversations list with Needs reply and Awaiting reply is open
     ground, not a copy.
2. **Standalone AI inboxes are retreating; CRM-anchored email is not.**
   - Notion Mail shut down on web, desktop and iOS on 2026-09-22, 17 months
     after launch, because users were handing email to agents
     ([Notion](https://www.notion.com/help/notion-mail-inbox-is-going-away-what-to-do-next)).
   - Its top complaint was that email never truly linked to the rest of Notion
     ([review](https://clean.email/blog/email-clients/notion-mail-review)).
   - The lesson: our list wins only if every row is tied to records. A generic
     inbox loses to Gmail.
3. **The bar for AI is approval, not autonomy.**
   - Day.ai, Clarify, Lightfield, Cora and Inbox Zero all draft and never send
     on their own.
   - They present AI output as cards that state why each one exists, what it
     drew on, and what state it is in.
   - This fits Rela's proposal-and-approve pipeline exactly.

## Side-by-side

| Capability | Notion Mail | Shortwave | Spark 3 | Zero | Inbox Zero | Cora | Day.ai | Clarify | Lightfield |
|---|---|---|---|---|---|---|---|---|---|
| Thread list | Yes, saved views | Yes, splits + bundles | Yes, 3 layouts | Yes | Writes Gmail labels | Gmail stays the inbox | No | No | No |
| Needs reply state | No | Auto reminders | Reminders | Auto-draft gate only | Yes, 4 states | `Needs Response` label | Agent finds follow-ups | Reply drafter skips handled threads | No |
| Reader | Side, center or full peek (setting) | Side pane, full screen | Split or full | Split, lazy bodies | n/a | n/a | Record timeline | Record tab, keyboard | Record page |
| AI summary | No | Top of every thread | Short, detailed, action points | Collapsed card | n/a | Brief digests | Briefing email | Meeting briefs | Meeting briefs |
| AI drafts | Quick replies | Voice-trained, Tab autocomplete | Auto-drafts | Style metrics | Rule action | Voice-trained, never sends | Overnight drafts, never sends | Inbox Reply Drafter | Review queue |
| Keyboard | Gmail set + palette | Full + palette + `?` | 6 presets + custom | Scoped map, rebindable | n/a | n/a | No | Inbox-like keys | Shortcuts |
| Team privacy | Single user | Live shares, private comments | Private comments, shared drafts | Single user | Single user | Single user | Share all or none, exclusions | Participants-only default, request access | Full or metadata, per item |

## Notion Mail (2025, shut down 2026-09-22)

**Views are saved queries.**
- Filters: unread, attachment, from, to, domain, date and more.
- Group by: date, sender domain, label, unread.
- Each property can be shown or hidden in the list per view
  ([views](https://www.notion.com/zh-cn/help/views-groups-filters-and-properties)).

**Auto label** turns a plain-language prompt into a label in five steps:

1. Name the label and describe it in plain words.
2. Confirm the sample matches it finds.
3. Optionally back-label past mail.
4. Choose "Show in inbox" or "Filter from inbox".
5. Save, which creates a sidebar view automatically
   ([AI](https://www.notion.com/help/use-notion-ai-with-notion-mail)).

**Row:**
- Sender names in one column, the subject in the next.
- Right-aligned pastel label chips.
- High-contrast mode greys read rows
  ([settings](https://www.notion.com/help/notion-mail-settings)).

**Reader:**
- Thread style is a setting: Side peek (resizable), Center peek or Full page.
- Auto-advance after archive is also a setting: next, previous or close
  ([navigate](https://www.notion.com/help/navigate-your-inbox)).
- Keys: `o` expands one message, `shift+o` expands all, `n` and `p` move
  between messages
  ([shortcuts](https://www.notion.com/en-gb/help/notion-mail-keyboard-shortcuts)).

**Composer:**
- The block editor with a `/` menu.
- Snippets can carry a subject and recipients.
- Schedule send sits in a caret next to Send, with a time zone picker
  ([compose](https://www.notion.com/help/compose-an-email)).

**What to take:** per-view property visibility, right-aligned chips, and the
reader style and auto-advance as user settings.

**What to avoid:** a standalone inbox with shallow links to the rest of the
product, and dozens of views on day one.

## Shortwave

**Splits are inbox tabs defined by a query, label or sender.**
- An "Other" split catches the rest.
- **Bundles** collapse a sender or label into one row you can mark done
  together.
- **Delivery schedules** hold a bundle until set times
  ([settings](https://www.shortwave.com/docs/guides/customize-your-shortwave-settings)).

**Row:**
- Avatar, then the sender (bold when unread), then the time on the right.
- Subject, then a one-line snippet.
- Star and attachment icons.
- Sticky, collapsible section headers: TODOS, STARRED, LAST 7 DAYS.
- On hover: star, todo, snooze, delete, done
  ([changelog](https://www.shortwave.com/changelog)).

**Reader and triage:**
- A side pane opens when the window has room; `Esc` closes it.
- An **instant summary** sits at the top of every thread
  ([summaries](https://www.shortwave.com/blog/instant-ai-summaries-every-email)).
- Done auto-advances to the next thread. `[` and `]` mean done-and-previous
  and done-and-next.
- A natural-language date picker ("EOD", "lunch tomorrow") serves snooze,
  send later and reminders.
- Automatic reminders fire when no reply arrives.

**Search:** Gmail operators plus `is:todo`, `has:comment` and a semantic
`about:"pricing feedback"`. Queries autocomplete and highlight
([search](https://www.shortwave.com/docs/references/search)).

**Composer:**
- Voice-trained drafting with Tab-accepted autocomplete.
- Instant reply chips.
- A warning before sending a draft that still contains `{placeholder}` text.
- Send & done: `ctrl+shift+enter`.

**Team:** `@mention` shares the live thread. Private comments show typing
indicators, and threads can be assigned
([team](https://www.shortwave.com/docs/guides/team)).

**What to take:** bundles for automated senders, the summary at the top of the
thread, done-and-next, one shared date picker, and the placeholder warning.

## Spark 3

**Three inbox layouts:**
- Simple List
- Unread Cards: People, Notifications and Newsletters, with batch actions
- Focused List: Priority, Pins, Notifications, Newsletters, Invitations and
  Assigned to me
  ([layouts](https://sparkmailapp.com/help/manage-your-inbox/customize-your-inbox))

**Gatekeeper** asks Accept or Block for each new sender
([gatekeeper](https://sparkmailapp.com/help/sending-emails/accept-or-block-new-senders)).

**Triage verbs, each with a documented job**
([pin vs snooze](https://sparkmailapp.com/help/manage-your-inbox/set-aside-vs-pin-vs-snooze)):
- Done
- Pin, for reference
- Set Aside, for things to act on later, kept in a "bubble" at the bottom left
- Snooze, which brings mail back as new

**Keyboard and summaries:**
- Keyboard presets imitate Gmail, Superhuman, Apple Mail and others, and every
  shortcut can be rebound
  ([shortcuts](https://sparkmailapp.com/help/tips-tricks/use-keyboard-shortcuts)).
- AI summaries come as Short, Detailed or Action points, and can run
  automatically for chosen senders
  ([summary](https://sparkmailapp.com/blog/ai-summary)).

**Criticism:** the move to Electron brought slowness and partially loaded long
threads ([reddit](https://www.reddit.com/r/SparkMail/comments/1uosj7r)).

**What to take:**
- Clear definitions for each triage verb.
- A summary length the reader can choose.
- Never load a thread partially.

## Zero (open source, `Mail-0/Zero` at `36132735`)

**Row (`apps/mail/components/mail/mail-list.tsx` L247-660):**
- A select dot that turns into a check when the row is selected.
- The sender, `font-semibold` when unread and `font-medium` when read; read
  rows also drop to `opacity-70`.
- A BIMI avatar, or a group icon when there are several recipients.
- The subject with a reply count and a draft pencil, then the snippet.
- Labels and the time.
- A hover pill with Star, Important, Archive and Bin, mounted only while
  hovered.

**The list never loads message bodies.** Each thread stores a summary with the
latest message (body stripped), labels, `hasUnread`, `totalReplies`,
`hasDraft` and `isGroupThread`, so one query paints a page
(`apps/server/src/lib/thread-list-summary.ts`).

**Reader (`mail-display.tsx` L688-698):**
- Only the latest message and the one being replied to are expanded.
- A collapsed message never mounts its body. Once expanded, it stays mounted,
  so expanding again is instant.
- The AI summary is a collapsed card with a "Summary" label.

**Keyboard (`config/shortcuts.ts`):**
- Bindings are grouped by scope: navigation, global, mail list, thread, compose.
- `g` sequences jump between views. `mod+k` opens the palette. `shift+?` shows
  help.
- With nothing selected, list actions apply to the hovered row
  (`mail-list-hotkeys.tsx`).
- Bindings can be changed. One flaw: `r` means "mark read" in the list and
  "reply" in the reader.

**Speed:**
- **Hover prefetch.** Hovering a row for 120 ms prefetches it.
- **Neighbour prefetch.** Opening a thread prefetches the threads above and
  below it.
- **Optimistic actions.** An action applies at once, shows a 5 s Undo toast,
  and only writes when the toast closes (`use-optimistic-actions.ts`).
- **No polling.** Updates arrive over a socket, and on focus or reconnect.

**What to take:** the summary columns on the thread row, lazy message bodies,
hover and neighbour prefetch, one scoped keyboard map, actions on the hovered
row, and an undo toast.

## Inbox Zero (open source, `elie222/inbox-zero` at `35adda1a`)

**Four thread states:** TO_REPLY, AWAITING_REPLY, FYI and ACTIONED. Each is
stored as a `ThreadTracker` row; old trackers are resolved and one new tracker
is inserted, unique per thread and message (`prisma/schema.prisma`).

**Free checks before any AI** (`utils/ai/choose-rule/match-rules.ts`):
- Senders such as `noreply@`, `notifications@` and `newsletter@` never get a
  status.
- If you have never replied to a sender who has sent you 10 or more emails,
  their mail cannot be TO_REPLY.
- When the history check fails, the code assumes you have replied, so the
  sender stays eligible.

**Then an LLM reads the whole thread** (`utils/ai/reply/determine-thread-status.ts`):
- Unanswered questions persist.
- A promise you made means TO_REPLY, even if you sent the last message.
- "I'll handle it" counts as ACTIONED.
- When you sent the last message, FYI is not an option.

**Every outbound message re-runs the check** under a lock
(`utils/reply-tracker/outbound.ts`).

**Follow-up reminders** use separate day thresholds for awaiting-reply and
needs-reply, and weekends do not count.

**Senders are categorized as a whole**, from up to three recent subjects, and
the AI may answer "Other" when unsure. A list of senders with a read rate under
20% backs bulk unsubscribe.

**What to take:** the free checks before any AI, recomputing on every send,
separate thresholds that skip weekends, and a stored reason for each status.

## Cora (Every)

**Mail is sorted rules first, then AI, into one category per email**, and each
category maps to one action
([categories](https://help.cora.computer/en/articles/13225562-customize-email-categories)):
- Important mail stays in the inbox, with or without an AI draft.
- Everything else is archived into a twice-daily **Brief** of summaries.

**Importance is learned from history:** who you reply to quickly and what you
act on. Drafts land in Gmail and are never sent automatically.

**What to take:**
- A digest of tracked-company mail as an alternative to showing every FYI row.
- Rules that run before AI, first match wins.
- A reason stored for every decision.

## Day.ai

**Email lives on the person and company timeline**, with grouped threads,
inline images and attachment previews
([timeline](https://www.day.ai/resources/the-agent-origin-story-and-emails-in-timeline)).
The assistant is the main surface.

**AI work arrives as cards.** Each draft card shows:
- **Pills** for "Promise made", "Pricing concern" and "Next step".
- **Provenance**: "Pulled from: call, email, meeting, CRM".
- **State**: "Held in Gmail Drafts, awaiting your review, never sent
  automatically".

**Commitment tracking:** commitments found in mail become Actions, and Day
closes the Action when it sees you followed up.

**Privacy is Share All or Share None**, with exclusions by domain. Admins
cannot read private mail, and agents inherit the person's access.

**Criticism:** Gmail only, and it "breaks down with multiple users" (quoted in
a competitor's roundup).

## Clarify

**Email lives on a record's `Messages` tab.** Inside it, `↑/↓` moves between
emails and `R`, `A`, `F`, `#` reply, reply all, forward and delete. `C`
composes anywhere ([shortcuts](https://docs.clarify.ai/en/articles/15648304-keyboard-shortcuts)).

**What syncs:**
- Every folder except Trash, Spam and Drafts.
- Mail with an unsubscribe link, invites and auto-replies are filtered out.
- Inbound mail alone never creates records by default.

**Privacy** ([sharing](https://docs.clarify.ai/en/articles/16774485-sharing-and-permissions)):
- The workspace default is **Participants only**, and each member can override it.
- Delegated access exists.
- One email or a batch can be shared, view-only, with no notification.
- **Request access** carries a note. The owner approves or declines, and the
  requester is told either way.
- Delegated sending is separate from delegated reading.

**The Inbox Reply Drafter** drafts replies only to mail addressed to you. It
skips cc-only mail, aliases, and threads a teammate already handled
([template](https://www.clarify.ai/templates/inbox-reply-drafter)).

**Criticism:** drafts "cannot be saved or shared for review before sending"
(its own docs).

## Lightfield

**Email lives on account, contact and opportunity pages. There is no inbox by
design.**

**Records are created only "When you reach out"** (mail you send, meetings you
attend):
- Mail with unsubscribe headers and auto-responders is filtered out.
- A "See excluded activity" view lets you sync an item back in.
- Backsync defaults to 1 month and extends to 24 months, with a notification
  when it finishes
  ([mail and calendar](https://docs.lightfield.app/help/settings/mail-and-calendar/)).

**Privacy:**
- Full access is the default; Metadata only is the alternative.
- Each item can be made private or visible from its menu.
- When several mailboxes share an item, the most permissive setting wins. This
  is a privacy risk to avoid.

**The For review queue** shows each AI draft with:
- its reason
- its linked record
- a link to the run that made it

"Only one reviewer needs to act."

## What changes in our decisions

| Decision | Change |
|---|---|
| 1. Page job | Stronger case for C: the AI-native CRMs left the thread list empty, and Notion Mail shows a generic inbox fails |
| 2. Default view | Needs reply is computed in two stages: free SQL rules always, optional Rela refinement with a stored reason |
| 3. Reader | Lazy message bodies; the summary sits at the top as a labelled, collapsible line |
| 6. Row | Zero's row anatomy; right-aligned record chips as in Notion Mail; read rows dimmed |
| 8. Noise | Filter `List-Unsubscribe` and `Auto-Submitted` mail before labels; bundle automated senders into one row |
| 10. Done | Done auto-advances (a user setting); undo toast |
| 11. Keyboard | One scoped map; act on the hovered row; never reuse a key across scopes |
| 20. AI drafting | Rela output shows as approvable cards with provenance and state; drafts only for mail addressed to you that no teammate has answered |
| 23. Sharing default | Clarify also defaults to participants only; never let the most permissive mailbox win |
| 28. Speed | Denormalized row summary on the conversation; hover and neighbour prefetch |
| 30. Follow-ups | Separate thresholds; weekends skipped |
| New 31 | Rela in the reader: summary, suggested record updates, draft reply |
| New 32 | Digest of FYI mail on tracked companies (Cora's Brief, Day's briefing) |
