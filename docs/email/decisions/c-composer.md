# Composer decisions (15 to 21)

This file reasons through the seven composer decisions with the method in
[../method.md](../method.md). Each section answers one question and checks the code as
of 2026-10-31. It explains why each competitor chose what it did, then scores five
options out of 60.

Paths are relative to `packages/EmailIntegration/` unless they start with `app/`,
`packages/` or `bootstrap/`.

Usage sets the stakes. Production shows 27 sends from the app in 90 days, 2 templates,
1 signature, 1 mass send and 2 AI summaries ever. The composer is not failing at scale.
It is barely used. So each pick must lower the cost of the first send, and it must not
add upkeep that 27 sends cannot justify.

"Inferred" marks a reading of a competitor's motive that its own pages do not state.

## Summary

| Decision | Pick | Runner-up | Score (pick / runner-up) | Confidence |
|---|---|---|---|---|
| 15. Composer surface | B. Corner window for new mail, inline dock for replies, mass send stays fitted | D. Side panel | 57 / 47 | High |
| 16. Draft safety | B. Debounced server autosave with a stale-write check | D. B plus a browser crash buffer | 56 / 50 | High |
| 17. Send later | B. Split Send with presets in the sender's time zone | C. Natural-language time | 56 / 45 | High |
| 18. Undo send window | C. Per-user setting, default 10 s, Undo reopens the draft | B. 10 s fixed | 54 / 46 | Medium |
| 19. Templates and variables | B. `/` for templates, `{` chips built from custom fields, fallbacks, per-recipient preview | D. Insert field with empty warning | 56 / 45 | Medium-high |
| 20. AI drafting in the composer | B. "Draft with Rela" through the existing draft pipeline, never sends | D. Rewrite selection | 55 / 45 | Medium |
| 21. Editor toolbar | B. Minimal fixed row plus the existing selection bubble | C. Bubble and Markdown only | 49 / 46 | Medium |

Two picks differ from [../decisions.md](../decisions.md). Decision 21 picks B, not C. In
decision 17, "show the recipient's local time" is dropped because people have no time
zone field. The recommendations below explain both.

---

## 15. Composer surface

### The question

Where should a new email and a reply open, so the user can still read the record they
are writing about?

### Today

- One Livewire component serves two docks: `floating` for compose and `inline` for
  replies (`src/Livewire/EmailComposer.php:89-90`, `getListeners()` at `:170-179`).
- `open()` always sets `isExpanded = true` (`EmailComposer.php:280`). New mail opens as
  a centred modal at 85vh with a backdrop (`resources/views/livewire/email-composer.blade.php:157-160`).
- The corner window already exists: `w-[38rem] h-[36rem]` at the bottom right
  (`email-composer.blade.php:154`). Users reach it only by shrinking the modal.
- A backdrop click minimises instead of closing (`email-composer.blade.php:142`).
- Replies dock inline under the message (`openReply()`, `EmailComposer.php:288`).
- The expand button hides while a source message is previewed (`email-composer.blade.php:191-207`).
- Mass send needs the wide layout for its recipient panel (`email-composer.blade.php:37,159`).

### What competitors chose and why

- **Gmail** opens new mail in a corner window and puts replies inline. Inferred: Gmail
  users write while reading other mail, so a blocking surface costs them context.
- **Attio** opens compose with `c` from anywhere and puts replies "right beneath the
  original message"
  ([reply](https://attio.com/changelog/2025/respond-to-emails-directly-in-attio)).
  Inferred: Attio's value is the record page, so the composer must not cover it.
- **Superhuman** gives the draft the whole screen. Its design centres on one thing at a
  time ([flow](https://blog.superhuman.com/how-to-design-for-flow/)). Speed of a single
  task beats context there, because the inbox is the product.
- **Front** uses a side composer. Inferred: in a shared inbox the conversation and
  teammate comments must stay visible next to the draft.
- **Notion Mail** let the user choose side, centre or full for the reader
  ([navigate](https://www.notion.com/help/navigate-your-inbox)). That choice served
  people with different screens, at the cost of three layouts to maintain.

A CRM is closer to Attio than to Superhuman. The record is why the user is writing.

### Options

| Option | How it works | Strengths | Failure modes |
|---|---|---|---|
| A. Fitted modal for new mail, inline replies (today) | `open()` expands; the backdrop hides the page | Room to write; one layout | Hides the record being written about; feels like a separate app |
| B. Corner window by default, expand on demand, inline replies; mass send and a resumed reply open fitted | Flip the default in `open()`; keep the fitted state for wide cases | Record stays readable; layout already built; Gmail users know it | Small on 1280px laptops; must go full screen below 640px |
| C. Inline everywhere, new mail too | New mail docks in the record's Emails tab | Strongest record tie | No home for compose from the Emails page or from `c` on a list |
| D. Side panel | Right-hand panel, page reflows | Wide and non-blocking | Collides with the reader drawer (decision 3); a third layout |
| E. Full page route | `/email/compose` | Simple; linkable | Leaves the record; loses unsaved state on navigation |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 3 | 4 | 3 | 4 | 5 | 43 |
| B | 5 | 4 | 5 | 5 | 4 | **57** |
| C | 4 | 4 | 4 | 3 | 3 | 44 |
| D | 4 | 4 | 4 | 4 | 3 | 47 |
| E | 2 | 4 | 2 | 4 | 3 | 35 |

- B's Clarity is 4, not 5: two compose sizes plus an inline dock take a session to learn.
- B's Cost is 4: the corner layout exists, but the mass-send and source-preview cases
  need explicit rules.
- C's Future-proof is 3: a compose that only exists inside a record fails when agents
  or the MCP server open a draft with no record.

### Future-proof check

| Test | B. Corner window | D. Side panel |
|---|---|---|
| Agents | Passes. A Rela draft opens in the same window through `composer:open` with `draftId` | Passes, same event |
| No AI key | Passes | Passes |
| Scale 10x | Passes. Layout only | Passes |
| Providers | Passes | Passes |
| Trust | Passes. Nothing new is stored | Passes |
| Reversibility | Passes. One default flag in `open()` | Weak. A reflowing page layout is hard to take back |

### Recommendation

Pick B. New mail opens in the corner window. Mass send and a draft with a source message
open fitted, because they need the width. Replies stay inline. Below 640px the window
fills the screen. B beats D because the corner window already exists, and D fights the
drawer reader from decision 3 for the same edge of the screen.

**What would change our mind:** more than 30% of new compose sessions get expanded within
5 seconds, over 30 days of at least 100 sessions. Then the corner window is too small,
and the default should go back to fitted.

**Smallest first step:** set `isExpanded` from the payload in `open()`, true only for
mass send or a loaded draft with a source email. Record expand clicks in the existing
activity log.

### Risks and mitigations

- **The corner window covers the page's bottom-right actions.** Minimise collapses it to
  a 20rem title bar (`email-composer.blade.php:155`). Check Filament's table pagination
  sits clear of it.
- **Users lose the window behind a navigation.** Livewire `wire:navigate` keeps the
  floating component. Autosave from decision 16 covers a full reload.
- **Mass send opened small would hide recipients.** The rule above keeps it fitted.

---

## 16. Draft safety and autosave

### The question

How do we make sure typed text is never lost, without flooding the server or leaking
personal data?

### Today

- A draft is saved only by `close()` and inline dismiss (`EmailComposer.php:739-746`,
  `dismissInline()` at `:346-353`). `minimize()` saves nothing (`:720-723`).
- The `persistDraft()` docblock says it runs on minimize and close
  (`EmailComposer.php:1899`). The code no longer does, so the comment is stale.
- A crash, a closed tab or an expired session loses everything.
- `persistDraft()` stores pending uploads, copies forwarded attachments, then calls
  `SaveEmailDraftAction` (`EmailComposer.php:1904-1962`). It also dispatches
  `drafts:changed` (`:1959`), which re-renders the Drafts table and its tab badge.
- `SaveEmailDraftAction` updates by id with no version check, so the last write wins.
  A missing draft aborts with 403 (`src/Actions/SaveEmailDraftAction.php:77`).
- Two tabs are already a known case: `send()` deletes the draft "best-effort"
  (`EmailComposer.php:489-495`).
- Drafts are `privacy_tier` PRIVATE (`SaveEmailDraftAction.php:97`). They are never shown
  to teammates.
- Drafts never touch the provider. Gmail sync excludes `in:drafts`
  (`src/Services/GmailService.php:181-185`). Graph sync drops the Drafts folder
  (`src/Services/MicrosoftGraphMailService.php:661`). Nothing writes drafts back.
- No job expires old drafts. A search of `bootstrap/app.php` and `src/Console` finds no
  draft pruning.

### What competitors chose and why

- **Gmail** saves drafts to the server as you type. Inferred: a Gmail draft is a mailbox
  object that every device and IMAP client must see, so the server owns it.
- **Superhuman** keeps mail in a local database for speed
  ([speed](https://blog.superhuman.com/superhuman-is-built-for-speed)). It pays for that
  with a sync engine, which it can afford as a dedicated client.
- **Front and Missive** share drafts so teammates can co-write. That needs real-time
  conflict handling, which their shared-inbox model justifies.
- **Day.ai** dropped Gmail auto-drafts in 2025. Users of "Superhuman or another email
  client that doesn't sync drafts" never saw them, and others found they "were
  cluttering up your workflow"
  ([Day.ai](https://www.day.ai/resources/were-sunsetting-gmail-auto-drafts-and-replacing-them-with-something-way-better)).
- **Notion Mail** kept drafts and scheduled mail only in its own store. At shutdown users
  had to migrate them by hand before they were "permanently deleted"
  ([Notion](https://www.notion.com/help/notion-mail-inbox-is-going-away-what-to-do-next)).

The lesson: a draft lives where it is written. Writing it into the provider helps only
a product that is the mail client.

### Options

| Option | How it works | Strengths | Failure modes |
|---|---|---|---|
| A. Save on close (today) | `close()` calls `persistDraft()` | No extra requests | Crash or closed tab loses text |
| B. Debounced server autosave with a stale-write check | Alpine calls `$wire.autosave()` 2 s after the last keystroke, on blur and on `visibilitychange`. The client sends the `updated_at` it last saw; a newer row returns "changed in another tab" | One source of truth; works on any device; no new storage | One request per pause; needs a light save path |
| C. `localStorage` recovery only | The browser keeps a copy | No server load | Personal data stays on shared machines after logout; one device only |
| D. B plus a `sessionStorage` crash buffer | B, plus a per-tab copy cleared on save and logout | Survives a dropped connection | Two copies to reconcile; browser storage is still personal data |
| E. Write drafts to Gmail and Graph | Draft API on each save | Draft shows in the user's mail client | Needs new scopes; Day.ai's clutter problem; two providers to keep in step |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 2 | 3 | 3 | 5 | 33 |
| B | 5 | 5 | 5 | 4 | 4 | **56** |
| C | 3 | 3 | 2 | 3 | 4 | 34 |
| D | 5 | 4 | 4 | 4 | 3 | 50 |
| E | 3 | 3 | 2 | 1 | 1 | 25 |

- B's Future-proof is 4, not 5. A request per pause is fine at 50 mailboxes. Typing
  load grows with users, not mailboxes, so test 3 passes. The round trip is the
  watch item.
- C's Fit is 2: browser storage outlives logout, and GDPR erasure cannot reach it.
- E's Future-proof is 1: it depends on each provider's draft API and needs
  `gmail.compose`, while connect asks only `gmail.readonly` and `gmail.send`
  (`src/Controllers/RedirectController.php:72-73`). That scope list is from our code.
  The scope needed is from Google's API docs, read from memory, not re-checked.

### The Livewire round-trip cost

- The body is the Tiptap document array (`EmailComposer.php:138-154`). Every request
  ships it with all public state: recipients, mass recipients and attachment metadata.
- A 3-minute email with a 2 s debounce makes about 10 to 30 saves. Each save is one
  small request.
- The real cost is the side effects, not the payload. Each `persistDraft()` stores
  uploads, copies forwarded files and fires `drafts:changed`. On the Emails page that
  re-runs four tab-count queries (`src/Filament/Pages/EmailInboxPage.php:106-135`).
- So autosave needs its own light path. Write subject, recipients and body only. Fire
  `drafts:changed` only when the row is first created. Never show the "Draft saved" toast
  (`EmailComposer.php:742`).
- Livewire handles one request per component at a time. An autosave in flight can delay
  Send by one round trip. Cancel the pending debounce when Send is pressed.

### Future-proof check

| Test | B. Server autosave | D. Server plus browser buffer |
|---|---|---|
| Agents | Passes. Rela and MCP drafts are the same rows (`SaveAgentEmailDraft`) | Passes for the server copy; agents cannot see the buffer |
| No AI key | Passes | Passes |
| Scale 10x | Passes with the light save path | Passes |
| Providers | Passes. Provider-independent | Passes |
| Trust | Passes. One PRIVATE row, erasable | Weak. A copy in the browser escapes erasure |
| Reversibility | Passes. No migration; `updated_at` serves as the version | Passes |

### Recommendation

Pick B. Show a quiet "Saved" or "Saving" line in the composer header. Show a banner if
the row changed elsewhere: "This draft changed in another tab. Reload it?" If a send
elsewhere deleted the draft, show "This draft was sent or discarded in another tab".
B beats D because the browser copy adds a second store of personal data for a failure
the server copy already covers.

**GDPR.** A draft is the author's and the recipients' personal data. Autosave keeps text
people would have abandoned, so it grows what we hold. Drafts untouched for 90 days
should move to a "Clean up old drafts" prompt, then be deleted. Disconnect already purges
mailbox data (`src/Actions/PurgeStoredMailboxData.php`). Check that it covers DRAFT rows
before shipping.

**What would change our mind:** the composer's Livewire p95 latency rises by more than
150 ms after release. Or support reports text loss on flaky connections. The first moves
the debounce to 5 s. The second adds D's buffer.

**Smallest first step:** add an `autosave()` method that writes text fields only, and
call it on `visibilitychange` and blur. That alone covers closed tabs.

### Risks and mitigations

- **Autosave creates a draft for every open-and-abandon.** `isDraftEmpty()` already
  skips blank sessions (`EmailComposer.php:1988-1998`). Keep that guard.
- **Two tabs overwrite each other.** The `updated_at` check turns a silent overwrite
  into a visible choice.
- **A race between autosave and Send queues a stale body.** Send reads component state,
  not the row, and Send cancels the debounce first.

---

## 17. Send later

### The question

How should a user schedule an email from the composer, in the right time zone, so it
still goes out after a deploy?

### Today

- The composer has no schedule control. Only the Outbox can reschedule a queued email
  (`src/Livewire/OutboxTable.php:89-104`). Users must send first, then catch it within
  the undo window.
- `SendEmailAction` already accepts `scheduled_for` (`src/Actions/SendEmailAction.php:52,
  256-269`). Chat and MCP already use it: `SendEmailTool` reads `scheduled_for`
  (`packages/Chat/src/Tools/Email/SendEmailTool.php:76,89`).
- Agent times parse in the user's zone: `Date::parse($value, $user->effectiveTimezone())`
  (`packages/Chat/src/Tools/Concerns/ReadsSendTime.php:20`).
- Users have a nullable `timezone` with a fallback to `app.timezone`, which is UTC
  (`app/Models/User.php:62,193-196`, `config/app.php:168`).
- People have no time zone. `app/Enums/CustomFields/PeopleField.php` has no such field,
  and `app/Models/People.php` has none.
- Scheduling is a column plus a scheduler, not a queue delay. `email:dispatch-outbox`
  runs every minute, `withoutOverlapping()->onOneServer()` (`bootstrap/app.php:402-406`).
  It claims due rows whose `scheduled_for <= now()`
  (`src/Console/Commands/DispatchOutboxCommand.php:136-158`).
- Limits apply at release, not at scheduling. Each mailbox gets 12 per hour and 200 per
  day (`config/email-integration.php:89-90`). Capacity counts SENT plus SENDING
  (`DispatchOutboxCommand.php:97-133`).
- Queueing is capped at 100 QUEUED emails per user. `OutboxFull` is thrown at queue time
  (`config/email-integration.php:95`, `SendEmailAction.php:241-251`). Scheduled mail
  counts against it.
- A worker that dies mid-send is reclaimed (`DispatchOutboxCommand.php:83-95`).

### What competitors chose and why

- **Gmail** puts Schedule send under the Send arrow
  ([help](https://support.google.com/mail/answer/2819488)). Inferred: presets fit the
  common cases (tomorrow morning, Monday), and Gmail has the scale to know them.
- **Superhuman** uses a natural-language box. Its users live on the keyboard, and typing
  "tue 9am" beats a date picker for them.
- **Notion Mail** added a time zone picker next to Send
  ([compose](https://www.notion.com/help/compose-an-email)). Its users span time zones,
  and a scheduled send that lands at 3 am undoes the point.
- **Pipedrive** keeps scheduled mail in an Outbox folder where it can be cancelled or
  moved back to drafts
  ([schedule](https://support.pipedrive.com/en/article/schedule-emails-for-later)).
- **Attio** has "no scheduled one-off sends" (see [../benchmark.md](../benchmark.md)).
  Inferred: it steers timed outreach into sequences, its paid automation.
- **Close** binds `Ctrl Shift Enter` to the schedule popup, for keyboard sellers.

### Options

| Option | How it works | Strengths | Failure modes |
|---|---|---|---|
| A. Outbox reschedule only (today) | Send, then reschedule | No new UI | Undiscoverable; a race with the 5 s window |
| B. Split Send with presets | Caret beside Send: Tomorrow 8:00, Monday 8:00, Pick a date and time. Times read in `effectiveTimezone()` and show "your time" | One click; reuses `scheduled_for`; matches chat | Wrong if the user's zone is unset |
| C. Natural-language time field | "tue 9am" parsed to a time with a preview | Fast for keyboard users | Locale parsing; ambiguous input |
| D. Send in the recipient's time zone | Resolve the person's zone, send at their 8:00 | Lands at a good hour | No person time zone exists; needs a new field or inference |
| E. AI best-time send | A model picks the hour | No choice to make | Needs data we lack (27 sends) and an AI key |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 2 | 4 | 4 | 5 | 39 |
| B | 4 | 5 | 5 | 5 | 4 | **56** |
| C | 4 | 3 | 4 | 4 | 3 | 45 |
| D | 3 | 3 | 3 | 3 | 2 | 35 |
| E | 2 | 2 | 2 | 1 | 1 | 20 |

- B's User value is 4: with 27 sends in 90 days, scheduling serves few people today.
  It is still the most asked-for composer gap in the benchmark.
- E's Future-proof is 1: it fails the no-AI-key test outright.

### Future-proof check

| Test | B. Presets | C. Natural language |
|---|---|---|
| Agents | Passes. Same `scheduled_for` the chat and MCP tools already write | Passes |
| No AI key | Passes | Passes if parsed by rules, not a model |
| Scale 10x | Passes. The dispatcher query is per mailbox and indexed by status | Passes |
| Providers | Passes. We hold the mail; no provider schedule API | Passes |
| Trust | Passes. Scheduled mail is visible and cancellable in the Outbox | Passes |
| Reversibility | Passes. No migration | Passes |

### Why a column beats a queue delay

A `dispatch()->delay()` lives in Redis. A Redis flush, a Horizon restart with a full
queue, or a failed deploy can drop it. Nobody can list it, edit it or cancel it. The
`scheduled_for` column survives deploys and shows in the Outbox. Agents can reschedule
it (`RescheduleQueuedEmailAction`). The cost is precision: mail goes out up to a minute
late. That is fine for email. A self-hosted install must run the scheduler, which it
already needs for every send today.

### Recommendation

Pick B. The caret menu shows "Tomorrow, 8:00", "Monday, 8:00" and "Pick a date and
time", each with a resolved date. If the user has no time zone, the menu says "Times are
in UTC. Set your time zone" and links to the profile. A scheduled email keeps `PRIORITY`,
so it goes before bulk mail when it is due. The confirmation says it may wait if the
mailbox is at its hourly limit. Bind `Ctrl Shift Enter` to open the menu. B beats C
because presets are visible to mouse users. C can join later as the custom-time input.

Drop the recipient-time hint from [../decisions.md](../decisions.md). There is no person
time zone to show. Add one later only as a custom field, so it stays settable from chat
and the API.

**What would change our mind:** over 60 days, more than 40% of scheduled sends use the
custom picker, not a preset. Then the presets are wrong. Or users schedule 13 or more
emails for one hour and they slip behind the hourly limit. Then the picker should warn
before it accepts the time.

**Smallest first step:** add the caret menu, and pass `scheduled_for` through
`queueEmail()` (`EmailComposer.php:512-548`). The action, dispatcher and Outbox already
handle it.

### Risks and mitigations

- **Unset user time zone.** The UTC notice above, plus a one-time prompt in the picker.
- **`OutboxFull` with many scheduled emails.** The composer already shows its message
  (`EmailComposer.php:540-546`). Add the count of scheduled emails to that message.
- **Filament's `DateTimePicker` time zone.** The Outbox picker sets none
  (`OutboxTable.php:93-98`), so it may show UTC. Not verified in the browser. Give both
  pickers the user's zone.

---

## 18. Undo send window

### The question

How long should an email wait after Send, and what should Undo give back?

### Today

- A composer send is `PRIORITY` with `scheduled_for = now() + undo_send_window_seconds`
  (`EmailComposer.php:532`, `SendEmailAction.php:262-266`). The default is 5 s
  (`config/email-integration.php:92`).
- The toast lasts the same 5 s and carries Undo (`src/Support/QueuedSendNotifier.php:24-37`).
- Undo cancels while the row is QUEUED and reports "Too late" after that
  (`src/Livewire/EmailAccessNotificationHandler.php:149-177`,
  `src/Actions/CancelQueuedEmailAction.php:24-36`).
- The dispatcher runs once a minute (`bootstrap/app.php:402-406`). So mail really goes
  out 5 to about 65 seconds after Send. The Undo button vanishes after 5 s, though the
  email stays cancellable from the Outbox until it is claimed.
- Undo marks the email CANCELLED and shows "Send cancelled". It does not reopen the
  text for editing, which is the reason most people press Undo.
- Chat sends follow the same 5 s path after the user approves the card
  (`src/Actions/PrepareAgentEmail.php:98`, `src/Actions/SendAssistantEmail.php:33-44`).
- MCP sends are held much longer. `QueueAgentEmailAction` calls `HoldAgentEmail`
  (`src/Actions/HoldAgentEmail.php:42-55`), which holds for 300 s by default with a 60 s
  floor (`config/email-integration.php:93`, `src/Actions/PickAgentSendTime.php:13-21`).
  The cap is 10 held per user (`src/Actions/ReserveAgentHold.php`), and the user is told
  at once by `notifyNow` (`QueuedSendNotifier.php:43-47`).
- The orphan helper text "Leave blank to send with a 5-second undo window" still sits in
  `lang/en/filament/concerns/email-compose.php:57`. No view uses it, and it hardcodes 5.

### Interactions with send limits and agent holds

- An email in its undo window is QUEUED. It counts toward the 100-queued cap
  (`SendEmailAction.php:245-250`), but not toward 12 per hour, which counts SENT and
  SENDING only. An undone email never spends hourly capacity.
- A longer window keeps more rows QUEUED at once. At 30 s and normal pace that is
  a handful of rows. It cannot reach 100.
- The MCP hold must stay separate. An outside agent's email has no human at the
  keyboard, so the user's undo setting must never shorten it. Chat is different: the
  user approved the card, so the human window applies.

### What competitors chose and why

- **Gmail** ran Undo Send as a Labs experiment for six years. It became standard in June
  2015 ([Ars Technica](https://arstechnica.com/information-technology/2015/06/gmail-enables-unsend-option-for-all-users/)),
  with a "cancellation period" of 5, 10, 20 or 30 s
  ([Guardian](https://www.theguardian.com/technology/2015/jun/24/gmail-undo-send-email-google)).
  The default is 5 s ([Google](https://blog.google/products/gmail/how-to-unsend-email-gmail)).
  Inferred: a short default keeps sending feeling instant, and the setting serves
  careful senders.
- **Superhuman** fixes the window at 10 s with `Z`, and does not let users extend it
  ([undo](https://help.superhuman.com/hc/en-us/articles/46005666743309-Undo)). It offers
  "Instant Send" to skip the delay
  ([blog](https://blog.superhuman.com/gmail-hacks)). Its own article argues that "fast
  access to Ctrl+Z beats long delays"
  ([blog](https://blog.superhuman.com/how-to-unsend-an-email)). Its bet is reflexes, not
  settings.
- **Apple Mail** chose a 10 s default (same Superhuman article).
- **Every** undo in these products reopens the draft. Gmail says "your original message
  will reappear so you can make any tweaks"
  ([Google](https://blog.google/products/gmail/how-to-unsend-email-gmail)).

### Options

| Option | How it works | Strengths | Failure modes |
|---|---|---|---|
| A. 5 s fixed (today) | Config value | Feels instant | Too short to notice a wrong recipient; Undo throws the text away |
| B. 10 s fixed | Config default to 10 | Matches Superhuman and Apple | No choice for careful senders |
| C. Per-user setting 5, 10, 20, 30 s, default 10; `z` undoes; Undo reopens the draft; "Send now" in the toast | Read at queue time; Undo turns the row back into a DRAFT and opens the composer | Fits both reflex and careful users; Undo gives the text back | One more setting; a cancel-to-draft path to build |
| D. Toast stays until the dispatcher claims the mail | Countdown to the next minute tick | Honest about real timing | Length varies from 5 to 65 s; ties the UI to scheduler cadence |
| E. No undo | Send at once | Simplest | Removes the safety net people expect |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 3 | 3 | 4 | 4 | 5 | 44 |
| B | 3 | 4 | 4 | 4 | 5 | 46 |
| C | 4 | 4 | 5 | 5 | 4 | **54** |
| D | 4 | 3 | 4 | 3 | 3 | 42 |
| E | 2 | 4 | 2 | 4 | 4 | 36 |

- C's User value is 4, not 5. Most users never change the setting. The reopened draft is
  the part everyone gets.
- D's Future-proof is 3. If the dispatcher moves to events, the window collapses, and
  the promise changes under users.

### Future-proof check

| Test | C. Setting plus reopen | B. 10 s fixed |
|---|---|---|
| Agents | Passes. Chat honours the user's window; MCP keeps its own floor | Passes |
| No AI key | Passes | Passes |
| Scale 10x | Passes | Passes |
| Providers | Passes. The hold is ours, before any provider call | Passes |
| Trust | Passes. Cancelled mail never reached the provider | Passes |
| Reversibility | Passes. A nullable user preference; the config stays the fallback | Passes |

### Recommendation

Pick C. Default 10 s. Undo returns the email to DRAFT and reopens it in the composer.
`z` triggers Undo while the toast shows. "Send now" sets `scheduled_for` to now. C beats
B because B fixes the length and still discards the text. The reopen is most of the value.

**What would change our mind:** over 60 days, under 1% of users change the setting.
Then drop the setting and keep 10 s fixed with reopen. If Undo is pressed on more than 5%
of sends, the composer is missing a pre-send check, such as the empty-variable warning
in decision 19.

**Smallest first step:** set the config default to 10 and make Undo reopen the draft.
Ship the setting second.

### Risks and mitigations

- **The minute tick sends mail up to 60 s after the toast ends.** That is a delay, not a
  broken promise, because Undo only ever claims "until the toast ends". "Send now" covers
  impatient users.
- **Undo after the dispatcher has claimed the row.** "Too late" stays, as today.
- **Someone later reads one setting for all agents.** Name the user's preference for
  human and approved-chat sends only, and keep `PickAgentSendTime` as the MCP owner.

---

## 19. Templates and variables

### The question

How should a user insert a saved message and personal fields, so that no recipient ever
gets a blank or a raw tag?

### Today

- Templates and signatures are icon menus in the composer footer with a tooltip and an
  `aria-label` (`email-composer.blade.php:306-326`,
  `resources/views/components/composer-picker-menu.blade.php:26-27`).
- `applyTemplate()` replaces subject and body and keeps the signature
  (`EmailComposer.php:1426-1445`).
- Variables come from a fixed list of six: name, company, phone number, job title,
  LinkedIn, today (`src/Services/EmailTemplateRenderService.php:29-36`). The editor's
  merge-tag button uses the same list (`EmailComposer.php:1067`).
- Three people fields are hardcoded by code (`EmailTemplateRenderService.php:206-241`).
  Any other custom field cannot be inserted, though custom fields are the core of
  Relaticle's data model.
- A known tag with no value becomes empty text without a warning ("Hi ,").
- An unknown tag ships to the recipient unchanged as `{{ tag }}`
  (`EmailTemplateRenderService.php:303`).
- Tags resolve by label as well as key (`mergeTagKeyAliases()`, `:335-359`). Renaming a
  label would break old templates.
- Mass send passes the raw body to `SendEmailBatchAction` (`EmailComposer.php:623-633`).
  There is no per-recipient preview.

### What competitors chose and why

- **Attio** uses `{` for variables from record attributes. "A variable without a
  fallback value set will be highlighted in yellow, and when a fallback value is set it
  will be blue." "Review emails" previews each recipient's copy
  ([Attio](https://attio.com/help/reference/email-calendar/send-emails-in-attio)).
  Inferred: CRM attributes are often empty, so Attio shows the risk before send rather
  than after a bounce of embarrassment.
- **Pipedrive** inserts fields from deals, people and organisations. Any empty merge field
  gives a warning, and fields from contact sync cannot be used
  ([Pipedrive](https://support.pipedrive.com/en/article/email-templates)). Its choice
  favours safety over speed.
- **Superhuman** uses `;` snippets with built-in variables and free `{placeholder}` text.
  It "will warn you to fill those in before sending"
  ([snippets](https://help.superhuman.com/hc/en-us/articles/46005686571149-Snippets)).
  Its model is personal speed: "your greatest hits, on demand".
- **Notion Mail** made snippets slash commands. A snippet could carry a subject and
  recipients ([compose](https://www.notion.com/help/compose-an-email)). It reused
  Notion's `/` habit.
- **Shortwave** warns before sending a draft that still contains `{placeholder}` text
  (see [../benchmark-modern.md](../benchmark-modern.md)).

All five agree on one rule: the sender sees a missing value before the recipient does.

### Options

| Option | How it works | Strengths | Failure modes |
|---|---|---|---|
| A. Footer menus and six fixed tags (today) | As above | Simple | Custom fields unreachable; blanks and raw tags reach recipients |
| B. `/` for templates and signatures; `{` chips built from active custom fields of person, company and opportunity; fallback per chip; a pre-send check; per-recipient preview for mass send | One catalogue derived from the custom-field schema; tokens store the field code | Every field insertable; the risk is visible; agents can use the same catalogue | Most build work; chip rendering in Tiptap |
| C. `;` snippets with free placeholders | Superhuman model | Fast for experts | Placeholders are not data; no CRM tie |
| D. "Insert field" button with an empty-value warning | Pipedrive model | Clear; safe | No fallback, so mass send blocks on any empty field |
| E. Template language with conditionals | Twig or Liquid | Powerful | Hard to learn; template injection risk |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 2 | 2 | 2 | 5 | 27 |
| B | 5 | 4 | 5 | 5 | 3 | **56** |
| C | 4 | 3 | 3 | 4 | 4 | 43 |
| D | 3 | 4 | 4 | 4 | 4 | 45 |
| E | 3 | 1 | 3 | 2 | 2 | 28 |

- A's Fit is 2: it fights the record model, because custom fields are invisible.
- B's User value is 5 despite 2 templates in production. The current tool cannot say
  "Hi Ana" safely to 50 people, which is the job mass send exists for.

### One owner for variables

The architecture rules say a fact more than one surface publishes gets an owner class.
Today `MERGE_TAGS` is a second, smaller copy of the field list. The custom-field schema
already owns it (`App\Mcp\Schema\CustomFieldSchema`, `CustomFieldType::inputFormat()`).
B builds one variable catalogue from active custom fields per entity, plus a few computed
values such as today and the sender's name. The composer, the template editor, mass send,
Rela and the MCP tools all read it. Tokens store the field code, never the label, so a
renamed field keeps working. A field marked inactive leaves the catalogue. A template
that still uses it shows the chip in red.

### Future-proof check

| Test | B. Catalogue, chips, fallbacks | D. Insert field |
|---|---|---|
| Agents | Passes. Rela writes `{{ person.job_title }}` from the same catalogue | Passes, with no fallbacks |
| No AI key | Passes | Passes |
| Scale 10x | Passes. Resolution is per recipient at queue time | Passes |
| Providers | Passes | Passes |
| Trust | Passes. The preview shows exactly what each person gets | Passes |
| Reversibility | Passes if the legacy `{name}` and label forms keep resolving | Passes |

### Recommendation

Pick B. Type `/` for templates and signatures. Type `{` for variables, which insert as
chips. A chip is yellow without a fallback and blue with one. Before send, the composer
blocks raw unknown tags and lists recipients whose fields are empty with no fallback.
Mass send gets "Review emails", stepping through each recipient's rendered copy. Keep
labelled footer buttons for mouse users. B beats D because fallbacks let a mass send go
out when some people have no job title. D would block it.

**What would change our mind:** fewer than 10 workspaces use a variable within 90 days of
release. Then the catalogue is overbuilt, and the next step is better templates, not more
fields.

**Smallest first step:** add the pre-send check against today's renderer. Block any
unresolved `{{ }}` and warn on empty values. It ships alone and stops the worst outcome.

### Risks and mitigations

- **Old templates use labels or legacy `{name}`.** Keep `mergeTagKeyAliases()` as a read
  shim. Write codes only.
- **Field values carry markup.** Keep HTML escaping in `substitute()`
  (`EmailTemplateRenderService.php:293-295`).
- **Multi-value fields.** `customFieldMergeValue()` already joins lists with commas
  (`:243-262`). The catalogue reuses it.

---

## 20. AI drafting in the composer

### The question

How should Rela help write an email inside the composer, so that a human always sends
and every AI-written line is traceable?

### Today

- The composer has no AI. Rela drafts through chat (`CreateEmailDraftTool`,
  `SendEmailTool`) and the MCP server (`app/Mcp/Tools/Email/CreateEmailDraftTool.php`).
- `PrepareAgentEmailDraft` turns a Markdown body into draft fields and checks the
  mailbox and reply target (`src/Actions/PrepareAgentEmailDraft.php:15-78`).
  `SaveAgentEmailDraft` stores the row.
- `SendEmailTool` says "nothing is sent until they do"
  (`packages/Chat/src/Tools/Email/SendEmailTool.php:28-30`). Its proposal needs its own
  approval (`ProposalEntity::needsOwnApproval()`, per `AGENTS.md`).
- Provenance exists on the row as `creation_source`: `chat` or `mcp`, labelled with the
  assistant's name (`src/Enums/EmailCreationSource.php:18-19,31-32`). Nothing marks which
  text in a draft came from AI once a human edits it.
- Credit types are Chat, Summary, Embedding and the ledger kinds. There is no draft type
  (`packages/Chat/src/Enums/AiCreditType.php:12-19`).
- Two AI summaries ever. AI in email is not discovered today.

### What competitors chose and why

- **Day.ai** stages every draft and says "Drafts only, never sent". "The rep reviews, the
  rep decides" ([Day.ai](https://day.ai/go/agent-workforce)). Inferred: a CRM that sends
  a wrong email to a buyer loses the account, so autonomy is a liability, not a feature.
- **Clarify's** Inbox Reply Drafter answers "Does it send replies automatically? No"
  ([Clarify](https://www.clarify.ai/templates/inbox-reply-drafter)). It acts only on mail
  addressed to you, skips cc-only mail and threads a teammate handled, and mirrors tone
  "from your recent sent emails".
- **Shortwave Ghostwriter** learns "from your past writing". It loads "a pre-computed
  textual description of their style and relevant example emails for few-shot prompting"
  ([deep dive](https://www.shortwave.com/blog/deep-dive-into-worlds-smartest-email-ai),
  [Ghostwriter](https://www.shortwave.com/blog/introducing-ghostwriter-ai-writing-that-learns-from-you)).
  It also offers Tab autocomplete
  ([autocomplete](https://www.shortwave.com/blog/ghostwriter-ai-email-autocomplete)).
  As a single-user client it can read all of your mail, which a team CRM with sharing
  tiers cannot.
- **Day.ai** has the sender write down how they handle a situation, so the draft "follows
  the sender's judgment as well as their phrasing"
  ([video](https://day.ai/videos/ai-emails-that-sound-like-you)). That is voice from
  instructions, not from mining mail.
- **Superhuman** binds Write with AI to `Cmd J`
  ([AI](https://help.superhuman.com/hc/en-us/articles/46005557122957-Write-with-AI)).

### EU AI Act and provenance

Article 50 applies from 2 August 2026
([Commission](https://digital-strategy.ec.europa.eu/en/policies/guidelines-ai-transparency-obligations)).
This is a reading of the rules, not legal advice:

- Deployers must label AI text "published to inform the public on matters of public
  interest". The duty lapses when the text "has undergone a process of human review or
  editorial control" and someone holds editorial responsibility
  ([Article 50](https://ai-act-service-desk.ec.europa.eu/en/ai-act/article-50)). A sales
  email to one person, reviewed and sent by a human, seems to fall outside it.
- Providers of systems that generate text must mark outputs in a machine-readable way.
  The duty does not apply where the system "performs an assistive function for standard
  editing" (same source). Relaticle may count as a provider for Rela. Whether a full
  drafted email is "standard editing" needs counsel. One source reports a grace period
  to 2 December 2026 for systems already on the market
  ([Commission quick facts](https://digital-strategy.ec.europa.eu/en/factpages/quick-facts-transparency-rules-ai-systems)).

So record provenance now, whatever the label decision. Store on the draft which parts
came from Rela, the model and the credit spend. That keeps every later choice open:
a visible label, a header, or none.

### Options

| Option | How it works | Strengths | Failure modes |
|---|---|---|---|
| A. None in the composer (today) | Chat only | No new cost | Undiscovered; context switch to chat |
| B. "Draft with Rela" button and `Ctrl J` | A prompt box; Rela uses the thread and linked records the viewer may see; text goes into the editor through `PrepareAgentEmailDraft`; a "Drafted by Rela" chip stays until sent; hidden without an AI key | Reuses the pipeline, credits and permissions; human sends | Credit cost per draft; bad drafts erode trust |
| C. Instant replies under every thread | Pre-generated suggestions | Zero effort | Spends credits on mail nobody answers; reads threads at scale |
| D. Rewrite selection | Shorter, friendlier, fix grammar | Small, clear, "standard editing" | Helps polish, not the blank page |
| E. Voice-trained Tab autocomplete | Model on sent mail | Feels magic | Reads all sent mail; an embedding store to erase; fails with no AI key |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 3 | 4 | 4 | 5 | 41 |
| B | 4 | 5 | 5 | 5 | 3 | **55** |
| C | 3 | 4 | 3 | 2 | 2 | 34 |
| D | 3 | 4 | 4 | 4 | 4 | 45 |
| E | 4 | 3 | 2 | 2 | 1 | 31 |

- B's User value is 4: two summaries ever says discovery, not demand, is unproven.
- E's Fit is 2: voice from all sent mail ignores sharing tiers for shared mailboxes and
  creates a store GDPR erasure must reach.

### Voice learning versus privacy

Use the sender's own words at request time, never a trained model. Rela may read the
last few emails this user sent to the same person or company. That mail is the user's
own, at full access. Add a short "How I write" note the user edits, as Day.ai does. Never
read a teammate's mailbox for voice. Store no embeddings. This passes erasure with
nothing extra to delete.

### Future-proof check

| Test | B. Draft with Rela | D. Rewrite selection |
|---|---|---|
| Agents | Passes. The same pipeline Rela and MCP already use, with the viewer's permissions | Passes |
| No AI key | Passes. The button hides; the composer works | Passes |
| Scale 10x | Passes. On demand only | Passes |
| Providers | Passes | Passes |
| Trust | Passes with stored provenance and a never-send rule | Passes |
| Reversibility | Passes. A button and a nullable provenance field | Passes |

### Recommendation

Pick B, then D. B opens a prompt above the body ("Reply accepting Tuesday, mention the
pilot price"). It writes into the editor and never sends. The chip "Drafted by Rela,
from this thread and the Airbnb opportunity" stays visible until send. Spend Chat
credits first, and measure by `creation_source`. If a separate credit type is added
later, sweep `packages/SystemAdmin` for `match` over `AiCreditType`, as the architecture
rules require. Automatic drafts, if ever built, follow Clarify's rules: only mail to
you, never cc-only, never a thread a teammate answered.

B beats D because the blank page is the larger cost, and the pipeline already exists.

**What would change our mind:** fewer than 20% of Rela drafts get sent, measured as drafts
sent within 24 hours, over 60 days. Then the quality or the context is wrong, and D
should lead while drafting improves.

**Smallest first step:** a composer button that sends the prompt, thread id and record to
the existing chat draft tool and loads the result into the open composer. Add one
nullable provenance field on the draft.

### Risks and mitigations

- **Rela cites mail the viewer cannot read.** Draft context goes through
  `VisibleEmailsQuery` (`PrepareAgentEmailDraft.php:63`) and record policies, as chat does.
- **Users send AI text unread.** The chip and a no-send rule keep a human step. Do not
  add "Draft and send".
- **Credit surprise.** Show the cost in the prompt box when credits run low.

---

## 21. Editor toolbar

### The question

Which formatting controls should the composer show, so writing feels light without
hiding what people need?

### Today

- The fixed toolbar holds seven groups: bold, italic, underline, strike, inline image;
  paragraph and H1 to H3; four alignments; quote and code block; two lists; undo and
  redo; merge tags (`EmailComposer.php:1070-1078`).
- A selection bubble already exists. On a paragraph it shows bold, italic, underline,
  strike, link, lists and quote (`EmailComposer.php:1079-1081`).
- Link is only in the bubble, not in the fixed toolbar. Users must select text to add a
  link. [../decisions.md](../decisions.md) lists it in the fixed toolbar, which is wrong.
- The toolbar's `attachFiles` adds an inline image. The footer paper clip adds an
  attachment (`email-composer.blade.php:302-303`). Two clip-like controls do different
  things.
- The signature editor uses bold, italic, underline and link only (`EmailComposer.php:1521`).

### What competitors chose and why

- **Gmail** hides formatting behind a "Formatting options" toggle. Inferred: most mail is
  plain prose, so formatting is the exception, and the corner window is small.
- **Superhuman** shows no toolbar. It formats on selection and with shortcuts, which suits
  keyboard users.
- **Notion Mail** used Notion blocks behind `/`
  ([compose](https://www.notion.com/help/compose-an-email)). Inferred: it reused a habit
  Notion users already had. That habit did not save the product, which closed for
  other reasons.
- **Attio** sends no HTML in mass and sequence mail (see [../benchmark.md](../benchmark.md)).
  Inferred: plain mail lands better and looks personal.

### Options

| Option | How it works | Strengths | Failure modes |
|---|---|---|---|
| A. Full fixed toolbar (today) | Seven groups | Everything visible | Busy; headings, alignment and code rarely suit email |
| B. Minimal fixed row plus the bubble | Bold, italic, link, bulleted list, numbered list, More (headings, quote, code, strike, alignment); variables move to `{` | Discoverable for mouse and touch users; calm | One menu to open for rare formats |
| C. Bubble and Markdown shortcuts only | Remove the fixed row | Cleanest | Formatting undiscoverable; touch users struggle with selection |
| D. Toolbar behind an "Aa" toggle | Gmail model | Compact | One extra click for every format |
| E. Markdown only | Plain text editor | Simple output | Loses rich mail; surprises users |

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A | 2 | 2 | 3 | 3 | 5 | 33 |
| B | 4 | 4 | 4 | 4 | 5 | **49** |
| C | 4 | 3 | 4 | 4 | 4 | 46 |
| D | 3 | 3 | 4 | 4 | 5 | 44 |
| E | 2 | 2 | 3 | 3 | 3 | 31 |

- B's Cost is 5: it is a change to the `toolbarButtons` array.
- C's Clarity is 3: the bubble shows nothing until the user selects, and touch selection
  is slow.
- Fit is 4 at best for all: formatting has little to do with records or privacy.

### Future-proof check

| Test | B. Minimal row | C. Bubble only |
|---|---|---|
| Agents | Passes. Rela writes Markdown that maps to the same marks | Passes |
| No AI key | Passes | Passes |
| Scale 10x | Passes | Passes |
| Providers | Passes. Fewer heading and alignment styles render the same in Gmail and Outlook (inferred) | Passes |
| Trust | Passes | Passes |
| Reversibility | Passes. A config array | Passes |

### Recommendation

Pick B. This differs from [../decisions.md](../decisions.md), which picked C. C's speed
gain is the selection bubble, and the bubble already exists. So B keeps that gain and
adds a visible row for mouse and touch users. Drop undo and redo; the keyboard covers
them. Move alignment, headings, code and strike into More. Move variables to `{`
(decision 19). Rename the inline-image control "Insert image", so it is not confused
with Attach.

**What would change our mind:** the More menu is opened in under 2% of sessions and the
fixed row in under 10%. Then C is enough, and the row can go.

**Smallest first step:** edit the `toolbarButtons` array at `EmailComposer.php:1070-1078`
and add `link` to it.

### Risks and mitigations

- **Users who relied on alignment or headings.** They stay under More.
- **The corner window from decision 15 is narrow.** Five buttons and More fit in 38rem.
  Check that in the browser at 1280px.
- **Tiptap Markdown input rules surprise users.** They are Tiptap defaults today, so
  this change adds no new behaviour.
