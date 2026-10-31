# Decisions 28 to 32: speed, tracking, follow-ups, Rela and FYI mail

This file covers the later work on the Conversations list. That means how fast it
feels, whether we track opens, how follow-ups surface, what Rela does in the
reader, and what happens to automated mail. Every section follows
[method.md](../method.md). Code is cited as of 2026-10-31 by path and line.
Production numbers come from [README.md](../README.md). "Inference" marks a reading
of a source, not a claim the source makes.

Two code facts change the starting point for these decisions:

- **New mail arrives every five minutes, by polling.** `email:incremental-sync`
  runs `everyFiveMinutes()` (`bootstrap/app.php:384-388`). Calendar has push
  webhooks (`packages/EmailIntegration/routes/web.php:12`); mail does not.
  Whatever the list does, it can only be as fresh as the last sync.
- **Bulk mail is already dropped at sync.** Since 2026-10-02 (Gmail, `3683eb4d5`)
  and 2026-10-09 (Microsoft, `1a57d49cf`), `StoreEmailJob` returns early for bulk
  mail (`packages/EmailIntegration/src/Jobs/StoreEmailJob.php:145-149`). The 37%
  Marketing share is mostly older rows.

## Summary

| # | Decision | Pick | Runner-up | Score (pick / runner-up, of 60) | Confidence |
|---|---|---|---|---|---|
| 28 | Speed and live updates | Fast server path plus a content-free Reverb signal per user | Fast server path with refresh on focus | 55 / 53 | Medium-high |
| 29 | Open and click tracking | No pixels. Engagement from replies and meetings | Reply signals now, opt-in click tracking later | 56 / 46 | High |
| 30 | Follow-up reminders and sequences | Awaiting reply as a query, in business days | Follow-up task that closes itself on reply | 57 / 52 | High |
| 31 | Rela in the reader | Summary line plus approvable cards on the proposal pipeline, with a no-AI facts card | Summary line only | 52 / 47 | Medium |
| 32 | FYI mail on tracked companies | Store an "automated" reason at sync, hide by default, bundle on record tabs | Bundle each automated sender into one row | 56 / 48 | Medium |

---

## 28. Speed and live updates

### The question

How do we make the Conversations list feel instant and stay current, at 10x
today's volume, without leaking mail to teammates who may not see it?

### Today

- **Broadcasting exists, but only for chat.** Reverb is wired through
  `bootstrap/app.php:91`. Every `ShouldBroadcast` event lives in
  `packages/Chat/src/Events/`, for example `PendingActionResolved.php:24`. They use
  `ShouldBroadcastNow` on `chat.conversation.{id}` (`PendingActionResolved.php:42`).
  That channel checks the participant and workspace (`packages/Chat/routes/channels.php:10-23`).
  A per-user channel `App.Models.User.{id}` exists and nothing uses it for email
  (`routes/channels.php:8-10`).
- **Polling covers sync status, not lists.** `wire:poll` runs on sync status
  (`packages/EmailIntegration/resources/views/livewire/partials/mailbox-sync-status.blade.php:12`,
  15 s) and on the accounts pages (`email-accounts.blade.php:20`,
  `email-account-settings.blade.php:10`, 10 s). No email list polls or listens.
- **Tab counts run on every request.** `tabCounts()` is a `#[Computed]` property
  (`packages/EmailIntegration/src/Filament/Pages/EmailInboxPage.php:106-135`). It
  memoises within one request only, so four `count()` queries run per render.
- **Record tabs use offset pagination.** `emails()` returns a `LengthAwarePaginator`
  of 20 (`BaseEmailsRelationManager.php:93-123`, page call at line 118). That adds a
  `count(*)` over the visible set on every page.
- **A thread summary row exists per mailbox.** `SyncEmailThreadAction` upserts
  `email_threads` with subject, counts and first and last dates
  (`packages/EmailIntegration/src/Actions/SyncEmailThreadAction.php:45-61`). It has
  no direction, reply state or unread facts.
- **Indexes favour time order per workspace and mailbox.**
  `database/migrations/2026_03_21_050430_create_emails_table.php:61-63`.

### What competitors chose and why

- **Superhuman** applies the "100ms rule" and aims under 50 ms. It keeps a local
  database and "preloads and prerenders the email threads that you're most likely to
  view soon" ([blog](https://blog.superhuman.com/superhuman-is-built-for-speed)). Why:
  speed is its whole paid product, so it accepts the cost of a local store and a
  custom renderer.
- **Linear** wrote the sync engine first. Clients keep a local database, and the
  server publishes changes over a socket that clients replay
  ([Linear](https://linear.app/now/rebuilding-delta-sync-read-path),
  [talk notes](https://www.fujimon.com/blog/linear-sync-engine)). Why: Tuomas Artman
  had built three before, and it removes network code from product work. The cost
  is a permission-aware delta log Linear rebuilt as it grew past 20 TB.
- **Zero** (open source) stores a body-free summary per thread. It prefetches on
  120 ms of hover and the neighbours of the open thread. Actions apply at once with a
  5 s Undo, and updates come over a socket ([benchmark-modern.md](../benchmark-modern.md)).
  Why (inference): a small team gets most of Superhuman's feel without a local
  database.

The lesson: perceived speed comes from three things. The data is ready before the
click, actions do not wait for the server, and the list never loads bodies.

### Options

1. **A. Today.** Server render, offset pages, manual refresh. Fails at 1.5M emails:
   the `count(*)` and `OFFSET` grow with the mailbox.
2. **B. Fast server path.** Add viewer-independent summary columns to the
   conversation row, kept by the ingest action: `last_message_at`,
   `last_direction`, `last_inbound_external_at`, `message_count`,
   `has_attachments`. Use cursor pagination on
   `(workspace_id, last_message_at desc, id)`. Alpine handles `j/k` and optimistic
   Done and read with Undo. Prefetch the reader on hover and for neighbours, and
   cache tab counts. Freshness comes from refetching on window focus. Fails if a user
   keeps the tab focused for hours.
3. **C. B plus a content-free Reverb signal.** After `StoreEmailAction` commits,
   send `{workspace_id, conversation_id}` on `App.Models.User.{id}`. It goes only to
   users who can see at least metadata of that email. The client then refetches
   through the visibility query. Fails if Reverb is down, so focus refetch stays as
   the fallback.
4. **D. Push rendered rows.** Broadcast subject, sender and snippet on a workspace
   channel, and prepend them on the client. This was `decisions.md`'s literal pick.
   Fails on privacy: one payload cannot respect per-viewer tiers.
5. **E. Local-first store.** Mirror conversations into IndexedDB, as Superhuman and
   Linear do. Fails on erasure, because we cannot purge a browser cache on a GDPR
   request. It also fails on shared devices and on downloading 1.5M rows.

**Latency budgets** (p95, measured on the largest mailbox):

| Interaction | Budget |
|---|---|
| List first paint, server time for 50 rows | < 300 ms |
| `j/k` move, from prefetched reader HTML | < 100 ms, no round trip |
| Open a prefetched thread / a cold thread | < 150 ms / < 400 ms |
| Done, read or unread shown on screen | < 50 ms, optimistic |
| Tab counts | < 20 ms, cached |
| New row visible after `StoreEmailAction` commits | < 5 s |

A Livewire action is a full POST with a snapshot and a re-render. It cannot meet
100 ms on a remote link. So `j/k` must never wait on it.

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A. Today | 2 | 3 | 4 | 2 | 5 | 35 |
| B. Fast server path | 4 | 5 | 5 | 4 | 4 | 53 |
| C. B plus content-free signal | 5 | 5 | 5 | 4 | 3 | **55** |
| D. Push rendered rows | 5 | 4 | 2 | 2 | 3 | 38 |
| E. Local-first store | 5 | 4 | 2 | 2 | 1 | 36 |

- C beats B on value: new mail appears without a click. B is cheaper by one
  moving part.
- D and E score on speed and lose on fit, because both put content where the
  server cannot control it.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Summary columns serve `VisibleEmailsQuery` too | Same |
| No AI key | No AI involved | Same |
| Scale 10x | Keyset index, no count. Fan-out is one event per user with access | Same, with no fan-out |
| Providers | Indifferent to provider. Push sync later only shortens the 5 min bound | Same |
| Trust | The payload holds IDs only, and the refetch is checked | No broadcast at all |
| Reversibility | Remove the listener, keep B | Columns can be dropped |

### Recommendation

Pick C, built as B first. B delivers most of the speed. C adds liveness for the
cost of one event class.

- **What would change our mind:** after B ships, measure how often a list is open
  when a sync lands. If fewer than 10% of new rows arrive while a list is visible,
  skip C. If users complain that mail is late, the fix is provider push sync,
  not Reverb.
- **Smallest first step:** add the summary columns to the conversation row, fill
  them in `SyncEmailThreadAction`, and move one list to cursor pagination.

### Risks and mitigations

- **A leak through the signal.** Compute recipients from mailbox owner, sharing
  tier and shares. Write a test that a private-tier mailbox sends no signal to a
  teammate.
- **The summary columns drift from the messages.** Recompute in the same transaction
  as the insert, and run a nightly check that compares a sample.
- **Workspace switching.** The user channel spans workspaces, so the client drops
  events whose `workspace_id` is not the current one.

---

## 29. Open and click tracking

### The question

Should emails sent from Relaticle carry open pixels or rewritten links, and if
not, what tells a seller that a message landed?

### Today

- There is no tracking code. No pixel, no link rewrite and no open columns.
- Sends go out through the user's own Gmail or Graph account
  (`packages/EmailIntegration/src/Services/GmailService.php:287-354` builds raw MIME).
  A pixel would need a host, which on a self-hosted install is the `APP_URL`
  domain.
- Production: 27 emails sent from the app in 90 days.

### What competitors chose and why

- **HubSpot** tracks opens and clicks, filters bots, and records an "inferred open"
  when a click or reply arrives without a pixel load
  ([HubSpot](https://knowledge.hubspot.com/connected-email/understand-hubspot-sales-email-open-and-click-tracking)).
  Its own community answer on Apple Mail says bot filtering will "unlikely be able
  to tell the difference" and recommends "against using it for critical processes"
  ([community](https://community.hubspot.com/t/are-email-opens-from-apple-mail-counted-as-unique-or-bot-opens/129599)).
  Why: marketing analytics is a core paid line, so it keeps the metric and adds
  repairs.
- **Attio** tracks opens only on Microsoft 365, only for single-recipient sends,
  not for sequences. "Gmail accounts are not yet supported"
  ([Attio](https://attio.com/help/reference/email-calendar/send-emails-in-attio)).
  Why (inference): it needs SMTP AUTH to send a tracked MIME body. It shipped what
  one provider allowed rather than build a tracking domain.
- **Close** tracks opens by default and warns that "your open rates are likely
  higher than you'll see" ([Close](https://help.close.com/docs/emailing)). Why: its
  buyers are outbound teams who expect the feature.
- **Apple Mail Privacy Protection** (iOS 15, 2021-09-20) preloads remote images
  through a proxy, "even if readers don't actually open those emails"
  ([Litmus](https://www.litmus.com/blog/apple-mail-privacy-protection-for-marketers)).
  Apple Mail held about half of opens then.

### Options

1. **A. None, no stance.** The cheapest option, but sellers get no signal at all.
2. **B. Open pixel per send, off by default.** Fails on MPP: an Apple reader
   "opens" everything. The pixel also adds a second domain to every email, and the
   CNIL consent rule below applies.
3. **C. Opens and clicks with bot filter and inferred opens.** HubSpot's approach.
   It rewrites every link through a redirect. Cold-email vendors now advise turning
   both off, because the shared tracking domain becomes part of your reputation
   ([Coldops](https://coldops.io/blog/should-you-turn-off-open-tracking),
   [Resend](https://resend.com/docs/dashboard/domains/tracking)).
4. **D. No pixels. Use reply and meeting signals.** Show "Replied in 2 days",
   "No reply for 4 business days" (decision 30) and "Meeting booked" from calendar
   sync. These are facts we already store, and no one can fake them.
5. **E. D now, plus opt-in click tracking later.** Only links the sender marks,
   such as a proposal, behind a workspace policy with consent text and a
   custom tracking subdomain.

**Regulation.** The CNIL adopted its tracking-pixel recommendation on 2026-03-12
(délibération 2026-042). Pixels need prior consent, except to measure the
deliverability of an email the recipient asked for. It recommends collecting consent
when the address is collected
([CNIL](https://www.cnil.fr/sites/default/files/2026-05/recommandation_tracking_pixels_emails.pdf)).
A seller writing to a prospect has no such moment. Article 5(3) of the ePrivacy
Directive sits behind this rule in every EU state.

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A. None, no stance | 1 | 4 | 5 | 4 | 5 | 43 |
| B. Opens per send | 2 | 3 | 3 | 2 | 3 | 30 |
| C. Opens, clicks, inferred | 3 | 2 | 2 | 2 | 1 | 26 |
| D. Reply and meeting signals | 4 | 5 | 5 | 5 | 4 | **56** |
| E. D plus opt-in clicks | 4 | 4 | 4 | 4 | 2 | 46 |

- B and C score low on clarity, because an "opened" tick that is wrong half the
  time teaches users to distrust it.
- D answers the real question, "did they respond?", and needs no consent.

### Future-proof check

| Test | D (pick) | E (runner-up) |
|---|---|---|
| Agents | Rela reads reply facts from the same columns | Click events add a second source |
| No AI key | Pure SQL | Same |
| Scale 10x | Uses the decision 28 columns | Click log grows per send |
| Providers | Needs no MIME control | Needs body rewrite on both providers |
| Trust | Nothing to consent to | Consent text, retention, erasure of click logs |
| Reversibility | Nothing to undo | Turning off breaks a promised metric |

### Recommendation

Pick D. With 27 sends in 90 days, tracking would serve almost no one. MPP makes
opens noise, CNIL makes pixels a consent question, and Relaticle's sends ride the
user's own reputation. E is the path if demand appears.

- **What would change our mind:** app sends pass 1,000 per 90 days, and at least
  five paying workspaces ask for click data on proposal links.
- **Smallest first step:** show "Replied after N days" and "No reply yet" on sent
  threads, using the decision 28 columns.

### Risks and mitigations

- **Competitive checkbox loss.** Buyers compare feature grids. Publish the reason
  plainly in the docs: no pixels, by design.
- **Meeting signal privacy.** Show "Meeting booked" only when the viewer may see that
  calendar event.

---

## 30. Follow-up reminders and sequences

### The question

How does a seller learn that someone has not replied, and do we automate the
follow-up?

### Today

- No reminder, snooze or sequence exists in the email package.
- Send limits default to 12 per hour and 200 per day per mailbox
  (`packages/EmailIntegration/config/email-integration.php:89-90`). One user may
  queue at most 100 emails (`email-integration.php:95`).
- `User::timezone` exists and falls back to the app timezone
  (`app/Models/User.php:195`), so business days can use the viewer's zone.
- Tasks exist with an hourly digest (`bootstrap/app.php:380`).

### What competitors chose and why

- **Superhuman** sets a reminder with `H` that defaults to "if no reply"
  ([help](https://help.superhuman.com/hc/en-us/articles/46005666142733-Remind-Me)).
  Why: a client with no CRM has to store the intent per message.
- **Close** pauses a sequence on "any incoming email, SMS, or answered call"
  ([changelog](https://close.com/changelog/sequence-improvements.md)). Workflow goals
  stop a run when a reply arrives ([workflows](https://help.close.com/docs/workflows)).
  **HubSpot** unenrolls on reply or a booked meeting
  ([unenroll](https://knowledge.hubspot.com/sequences/unenroll-from-sequence)).
  Why: a sequence that keeps sending after a reply is the fastest way to lose a
  deal and a domain's reputation.
- **Inbox Zero** keeps separate day thresholds for awaiting reply and needs reply,
  and weekends do not count ([benchmark-modern.md](../benchmark-modern.md)). Why
  (inference): Friday mail should not turn red on Monday morning.
- **Day.ai** turns a promise in mail into an Action and closes it when you follow
  up. Why: commitments matter more than generic timers.

### Options

1. **A. None.**
2. **B. Awaiting reply as a query.** The last message is outbound to an external
   address, no inbound message follows, and it is older than N business days
   (default 3) in the viewer's timezone. It is a view and a row reason, not a job.
3. **C. "Remind me if no reply" at send.** A new reminder table and a scheduled
   job. It duplicates tasks.
4. **D. Follow-up task that closes itself.** From the composer or the reader,
   "Follow up in 3 days" creates a normal Task linked to the thread. The ingest
   action completes it when a reply arrives.
5. **E. Multi-step sequences.** Collides with the limits. One 3-step sequence to
   100 people is 100 sends per step. At 12 per hour, each step takes over 8 hours
   and starves one-off replies. Two overlapping steps pass the 200 a day cap. Over
   100 recipients cannot even queue under `max_queued_per_user`.

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A. None | 1 | 3 | 3 | 3 | 5 | 32 |
| B. Awaiting reply query | 5 | 4 | 5 | 5 | 4 | **57** |
| C. Remind at send | 4 | 4 | 4 | 3 | 3 | 44 |
| D. Self-closing follow-up task | 4 | 5 | 5 | 4 | 3 | 52 |
| E. Sequences | 3 | 3 | 2 | 2 | 1 | 28 |

- B covers every thread without the user setting anything. Clarity is 4 because
  "business days" needs one line of help text.
- D wins on clarity: a task is an object everyone already knows.

### Future-proof check

| Test | B (pick) | D (runner-up) |
|---|---|---|
| Agents | A filter on `ListEmailsTool` and the MCP tool | Rela can propose a task through the existing pipeline |
| No AI key | SQL only | Same |
| Scale 10x | Index on reply state and `last_message_at` | One task per intent |
| Providers | Uses stored direction only | Same |
| Trust | No new personal data | Same |
| Reversibility | A view; delete it | Tasks stay as plain tasks |

### Recommendation

Pick B in phase 2, then D right after. Reject C, because tasks already are
reminders in a CRM. Keep E out of scope until sends grow.

- **What would change our mind:** if Awaiting reply views are opened weekly but
  fewer than 20% of their threads get a reply within a week, users need D's explicit
  nudge sooner.
- **Smallest first step:** a scope on the conversation row for "awaiting reply" and
  one view tab.

### Risks and mitigations

- **Holidays and time zones.** Skip weekends only at first. Show the threshold on
  the view ("No reply for 3+ business days").
- **Internal-only threads.** Exclude threads whose participants are all members
  (`is_internal`, `StoreEmailAction.php:128-132`).

---

## 31. Rela in the reader

### The question

What should Rela offer inside the thread reader, and how do we keep it honest,
private and optional?

### Today

- **One modal summary.** A sparkle icon calls `buildThreadSummaryView()`
  (`packages/EmailIntegration/src/Filament/Concerns/HasEmailReaderActions.php:400-420`).
  Production shows 2 summaries ever.
- **The summary respects the viewer.** It reads each message through
  `PrivacyService::effectiveTier()` (`EmailThreadSummaryService.php:57`). It sends
  body text only at the full tier (lines 136-143), and hashes the prompt with the
  viewer's key (line 32).
- **Three gaps in that service.**
  - It never reserves a credit; only `ChatController.php:194` and
    `TurnContinuationService.php:86` call `CreditService::reserveCredit()`.
  - It defaults to OpenAI `gpt-4o-mini` (`config/services.php:65-68`), so an
    install with only an Anthropic key fails.
  - It needs `Filament::getTenant()` (lines 166-167), so no agent or job can call it.
- **The cache thrashes.** `ai_summaries` is unique per thread and workspace
  (`2026_09_09_015446_recreate_ai_summaries_table.php`). The service deletes and
  recreates the row (lines 169-180), so two viewers overwrite each other.
- **The proposal pipeline is ready to reuse.** `PendingAction` has a nullable
  `conversation_id` and a `turn_id` (`packages/Chat/src/Models/PendingAction.php:39-54`).
  `ProposalPlanService` groups steps by `turn_id` (lines 39-49). Email sends need
  their own approval (`ProposalEntity.php:92-98`).

### What competitors chose and why

- **Day.ai** shows draft cards with pills, "Pulled from: call, email, meeting,
  CRM" and "never sent automatically"
  ([Day.ai](https://www.day.ai/resources/the-agent-origin-story-and-emails-in-timeline)).
  Why: a CRM that writes to records must show its sources, or sellers stop trusting
  every field.
- **Lightfield** keeps a For review queue with a reason, the linked record and the
  run behind each draft ([docs](https://docs.lightfield.app/help/settings/mail-and-calendar/)).
  Why: AI output is its main input, so it needs a queue.
- **Shortwave** puts an instant summary at the top of every thread
  ([blog](https://www.shortwave.com/blog/instant-ai-summaries-every-email)). Why: it
  is an AI mail client, and every account pays for model use.
- **Cora** drafts and never sends ([Every](https://every.to/p/introducing-cora-manage-your-inbox-with-ai)).

### Options

1. **A. Today.** Hidden, unmetered, OpenAI-only.
2. **B. Summary line at the top.** It is labelled "Summary by Rela" and collapsible.
   It generates on first open for threads with three or more messages, and is
   metered.
3. **C. B plus approvable cards.** Rela proposes record updates, a follow-up task
   and a draft reply as `PendingAction` rows on a conversation anchored to the
   thread. Each card names its sources ("from 3 emails you can read and the
   Airbnb opportunity") and its state ("Draft, not sent"). Without an AI key the
   same slot shows a facts card instead: last reply, awaiting status, linked
   records and their stage, plus Create task and Link.
4. **D. A separate review queue for all AI output.**
5. **E. Chat only.** "Ask Rela about this thread" opens chat with the thread
   referenced.

**EU AI Act Article 50** has applied since 2026-08-02. People must be told they are
dealing with AI unless it is obvious. Generated text must be marked machine-readably
by the provider ([Commission FAQ](https://digital-strategy.ec.europa.eu/en/faqs/transparency-obligations-under-article-50-ai-act)).
The label "by Rela" and the approval step meet the human-facing duty (inference,
not legal advice). Marking falls mainly on the model provider. A cheap extra step is
an `X-Relaticle-AI-Draft` header on sends that started as a Rela draft.

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A. Today | 1 | 1 | 3 | 1 | 5 | 22 |
| B. Summary line | 4 | 5 | 4 | 3 | 4 | 47 |
| C. Summary plus cards plus facts card | 5 | 4 | 5 | 4 | 2 | **52** |
| D. Review queue | 3 | 3 | 5 | 4 | 2 | 44 |
| E. Chat only | 3 | 3 | 4 | 4 | 5 | 44 |

- B fails the no-key test: the slot is empty without a model.
- C costs more, but it reuses the approval, plan and broadcast code that chat
  already runs.

### Future-proof check

| Test | C (pick) | B (runner-up) |
|---|---|---|
| Agents | Same `PendingAction` rows the MCP and chat use | Summary stays UI-only |
| No AI key | Facts card works | Empty slot |
| Scale 10x | Generation on open, cached per viewer and input hash | Same |
| Providers | Model chosen by `AiModelResolver`, not pinned | Pinned to config |
| Trust | Sources listed, approval logged, Article 50 label | Label only |
| Reversibility | Hide the cards; the rows expire like chat proposals | Hide the line |

### Recommendation

Pick C, shipped in three steps. C beats B because it works with no key and turns
reading into a recorded action.

- **What would change our mind:** if fewer than 5% of shown cards are approved
  after 60 days, cut the cards back to the summary and the facts card.
- **Smallest first step:** move the summary into the reader top and meter it with
  `reserveCredit`. Resolve the workspace from the thread instead of Filament. Key
  the cache by viewer so viewers stop evicting each other.

### Risks and mitigations

- **Rela sees more than the viewer.** Build every prompt through
  `EmailThreadSummaryService::buildPrompt` or `VisibleEmailsQuery`, never raw
  models. Test that a subject-tier viewer's prompt holds no body.
- **Credit surprise.** Show the cost on the first use, and never auto-generate on
  record tabs.
- **Stale cards.** Supersede a card when a new message arrives, the way
  `PendingActionsSuperseded` already does in chat.

---

## 32. FYI mail on tracked companies

### The question

What do we do with automated, no-reply mail from companies we track, so it neither
buries real conversations nor disappears?

### Today

- **Production labels:** Other 52%, Marketing 37%, Personal 7%, Support 3%,
  Invoice 1.4%, Scheduling 0.6%, Sales 0.1%. 24,007 Marketing emails are linked to
  records.
- **Bulk mail is dropped, not stored.** Gmail lists skip promotions, social, forums
  and updates (`GmailService.php:30,186`). Gmail bulk detection uses category labels
  only (line 162). Microsoft checks `List-Unsubscribe` and `Precedence: bulk|junk`
  (`MicrosoftGraphMailService.php:29,172-181`). `StoreEmailJob.php:147` then
  discards the message.
- **Headers are not stored.** `FetchedEmailData` has no header field
  (`src/Data/FetchedEmailData.php:18-43`). `Auto-Submitted` is checked nowhere.
- **"Marketing" today means a role sender.** The classifier matches `noreply`,
  `notifications` and similar local parts (`EmailClassifier.php:41,59`).
- **"Personal" means Gmail's Primary hint or internal-only mail.** A Gmail
  `CATEGORY_PERSONAL` label short-circuits every rule
  (`GmailService.php:161`, `EmailClassifier.php:46-48`). A pricing email in Primary
  can therefore never be labelled Sales. That is a likely cause of the 0.1% (inference).

### What competitors chose and why

- **Shortwave** bundles a sender or label into one row you mark done together
  ([settings](https://www.shortwave.com/docs/guides/customize-your-shortwave-settings)).
  Why: the mail stays findable while the list stays short.
- **Cora** archives the "90% of the emails we get [that] don't require a response"
  into a twice-daily Brief ([Every](https://every.to/p/introducing-cora-manage-your-inbox-with-ai)).
  Why: Cora replaces the inbox, so the digest is the product. Day.ai's users asked
  for fewer digest emails ([benchmark-modern.md](../benchmark-modern.md)).
- **Lightfield and Clarify** filter unsubscribe-header mail before records.
  Lightfield keeps "See excluded activity" to sync an item back
  ([Lightfield](https://docs.lightfield.app/help/settings/mail-and-calendar/)). Why:
  a CRM must stay clean, but a wrong exclusion must be recoverable.

### Options

1. **A. Every stored message is a row with a badge (today).**
2. **B. A daily digest per company.** A new delivery channel, with its own
   notification fatigue.
3. **C. Bundle each automated sender into one row** in lists and on record tabs.
4. **D. Store the reason at sync.** One owner class decides `automated_reason` from
   `List-Unsubscribe`, `List-Id`, `Auto-Submitted` (not `no`), `Precedence`, a role
   sender, or the provider category. It runs on both providers: Gmail already
   fetches `format=full` (`GmailService.php:133`) and Graph already selects headers
   (`MicrosoftGraphMailService.php:125`). Conversations hide automated rows by default
   behind an "Automated" view. Record tabs bundle them per sender ("6 automated
   emails from Stripe"), with "Not automated" as a correction.
5. **E. Drop more at sync.** Extend today's drop to `Auto-Submitted` and role
   senders. It fails reversibility, because dropped mail comes back only with a
   resync.

### Matrix

| Option | User value x3 | Clarity x2 | Fit x3 | Future-proof x3 | Cost x1 | Total |
|---|---|---|---|---|---|---|
| A. Every row | 2 | 3 | 3 | 3 | 5 | 35 |
| B. Daily digest | 3 | 3 | 4 | 2 | 2 | 35 |
| C. Bundle per sender | 4 | 4 | 4 | 4 | 4 | 48 |
| D. Stored reason, hidden, bundled | 5 | 4 | 5 | 5 | 3 | **56** |
| E. Drop more | 3 | 4 | 3 | 2 | 5 | 37 |

- C alone still relies on the classifier's labels, and those labels are wrong in
  both directions.
- D gives every surface one fact with one owner (`architecture.md`, "One fact, one
  owner").

### Future-proof check

| Test | D (pick) | C (runner-up) |
|---|---|---|
| Agents | `automated_reason` is a filter on the list tools | Bundles are UI-only |
| No AI key | Headers and rules only | Same |
| Scale 10x | One indexed column | Group-by per sender at read time |
| Providers | Standard RFC headers. IMAP and JMAP carry them too | Depends on labels |
| Trust | The reason is shown on the row and can be corrected | Opaque grouping |
| Reversibility | Hidden, never deleted | Same |

### Recommendation

Pick D, with C's bundles as the rendering on record tabs. Drop B: a digest is a
channel, not a fix, and nobody has asked for it. Keep today's bulk drop as is.
Revisit it once the Automated view shows how often users pull mail back.

- **What would change our mind:** if users mark more than 10% of automated rows as
  "Not automated", the header rules are too broad.
- **Smallest first step:** run one aggregate query that splits labels before and
  after 2026-10-02. It tells us how much FYI mail still arrives. Then add the
  detector and the column.

### Risks and mitigations

- **History has no headers.** Backfill `automated_reason` from the Marketing label
  in a chunked migration, and rehearse it on anonymised production data
  (`core.md`).
- **A real customer uses a no-reply address.** The correction suppresses that
  sender for the workspace.
- **Gmail workspaces with tabs off get no category labels,** so bulk mail passes
  today (inference). The header rules close that gap.
