# Email benchmark (public sources, read 2026-10-31)

Every claim carries its source. "Inferred" marks a reading of the docs rather
than a statement in them. Videos and screenshots were not reviewed, so layouts
come from help text.

## Side-by-side

| Capability | Relaticle today | Attio | Superhuman | HubSpot | Close | Pipedrive | Twenty |
|---|---|---|---|---|---|---|---|
| Central list of received mail | No | No | Yes (split inbox) | Yes (conversations inbox) | Yes (action inbox) | Yes (Sales Inbox) | No |
| Record email tab | Yes | Yes, configurable per object | n/a | Yes | Yes | Yes | Yes |
| Thread grouping | No, per message | Inferred yes | Yes | Yes | Yes | Yes | Yes |
| Unread shown | No (data exists) | No | Yes | Yes | Yes | Yes | Not documented |
| Search inside email content | Subject and snippet only | No | Yes, offline, as you type | Yes | Yes | Yes | Not documented |
| Keyboard triage | `c` only | `c`, Ctrl K | Full set plus palette | Partial | Partial | Partial | Ctrl K |
| Done / archive | No | No | Yes, returns on reply | Yes | Yes, two-way with Gmail | Yes, optional two-way | No |
| Reminders / follow-up | No | Sequences only | Yes, "if no reply" default | Sequences | Reminders tab, workflows | Not documented | No |
| Send later from composer | No (outbox reschedule only) | No | Not confirmed | Yes | Yes | Yes, Outbox folder | No |
| Undo send | 5 s | Not documented | 10 s | Not documented | Not documented | Not documented | No |
| Templates | Yes (icon) | Yes, shared | Snippets, `;` trigger | Yes | Yes, tags | Yes, insert field | No |
| Variables with fallbacks | Yes, no fallback UI | Yes, `{` with colour state | Snippet variables | Yes | Yes, defaults | Yes, empty warning | No |
| AI drafting in composer | No (Rela only) | Ask Attio, `/ai` in sequences | Cmd J, instant replies, auto drafts | Yes | Not documented | Yes, tone and length | No |
| Sharing tiers | Private, metadata, subject, full, per teammate | Metadata, subject (default), full, individuals | Shared conversations | Inbox-based | Per user view | Shared, private, team only | Metadata, subject, all |
| Access requests | Yes (hidden icon) | Yes, choose the owner | No | No | No | No | No |
| Manual link to record | Action exists, no reader UI | No | n/a | "Associated with" | Automatic by contact | Yes | No |
| Open tracking | No | Microsoft 365 only | Read statuses | Yes, inferred opens, bot filter | Yes, default on | Yes, opens and clicks | No |
| Bulk send | Yes, mass send | 10 or 50 per batch | n/a | Sequences, 3 per min | Yes, with throttles | 100 per group email | No |
| Sync health in the UI | Accounts page only | Progress, "In sync", email on finish | n/a | Not documented | Not documented | Not documented | Not documented |

## Attio

Attio is the closest peer and the product Relaticle's email model most resembles.

**Where email lives**
- "Attio does not have a traditional inbox page or sent folder." Email appears on
  each record's Emails tab
  ([view emails](https://attio.com/help/reference/email-calendar/view-emails-and-meetings)).
- The sidebar Emails page holds drafts, workspace templates, the outbox and
  failed sends, the same shape as Relaticle's page today
  ([navigating](https://attio.com/help/reference/productivity-collaborating/navigating-your-workspace)).
- The Failed list shows the 300 most recent failures with reasons, read-only
  ([outbox](https://attio.com/help/reference/email-calendar/email-outbox)).
- Emails tabs can be configured per object from an email or domain attribute,
  including across relationships, and an object can have several
  ([2026-01-14](https://attio.com/changelog/2026/emails-on-deals-and-custom-objects)).
  - Deals default to the associated company's domains. This is Relaticle decision
    25.
- Emails are matched to records by participant address and domain at read time,
  not stored links. There is no manual "link this email to record" (inferred from
  its absence in the docs).

**Sync**
- Gmail syncs everything not deleted, with no date or folder picker. Microsoft
  syncs Inbox and Sent only
  ([sync](https://attio.com/help/reference/email-calendar/email-and-calendar-syncing)).
- A progress tracker runs, the status reads "In sync", and a completion email
  follows.
- Mailbox limits: Free and Plus 1, Pro 2, Enterprise unlimited.

**Privacy**
- Mailbox tiers are Metadata only, Subject line and metadata (the default), Full
  access with the workspace, and Full access for named individuals
  ([sharing](https://attio.com/help/reference/email-calendar/sharing-emails)).
- Request access lets you pick which teammate on the thread receives it
  ([2025-12-17](https://attio.com/changelog/2025/changelog-december-17-2025)).
- Protected and Blocked lists apply per workspace and per mailbox
  ([private](https://attio.com/help/reference/email-calendar/keep-emails-and-calendar-events-private)).

**Composer**
- Press `c` anywhere, or click any email address in the app
  ([2025-01-28](https://attio.com/changelog/2025/compose-emails-directly-in-attio)).
- Replies open "right beneath the original message"
  ([reply](https://attio.com/changelog/2025/respond-to-emails-directly-in-attio)).
- Mass send uses `{` variables. A token without a fallback shows yellow; one with
  a fallback shows blue. "Review emails" previews every recipient's copy
  ([send](https://attio.com/help/reference/email-calendar/send-emails-in-attio)).
- Limits: 12 per hour and 200 per day per mailbox. Mass and sequence sends are
  spaced 5 minutes apart. No scheduled one-off sends and no HTML.

**AI**
- An auto summary line and a label (Scheduling, Marketing, News, Notifications,
  Receipts, Invoices, Social, Hiring) on each email
  ([2025](https://attio.com/changelog/2025/auto-summaries-and-labels)).
- Ask Attio drafts emails that you then save, deny or send.
- Communication intelligence: connection strength, strongest connection, and
  first, last and next interaction
  ([enriched data](https://attio.com/help/reference/managing-your-data/enriched-data)).

**Criticism**
- G2 lists "email sharing" issues as a top con, with 19 mentions
  ([G2](https://www.g2.com/products/attio/reviews?qs=pros-and-cons)).
- Reddit users dislike all-or-nothing sync with no date cutoff or allowlist
  ([r/CRM](https://www.reddit.com/r/CRM/comments/1oq9jte/)).
- Workspace search "does not search the contents of emails".

**What to take**
- Configurable record tabs and read-time matching for opportunities.
- The `{` variable fallback colours.
- The Subject-line default.
- "Request access" with a chosen owner.

**What to beat**
- No inbox and no content search.
- No send later.
- Sharing that users find hard.

## Superhuman

- **Split inbox.** Up to 7 splits are advised; the count is hidden at Inbox Zero
  ([splits](https://help.superhuman.com/hc/en-us/articles/46005619081101-Default-Split-Inbox)).
- **One-at-a-time flow.** Archiving opens the next email
  ([flow](https://blog.superhuman.com/how-to-design-for-flow/)).
- **Done.** `E` marks Done, and the thread returns when someone replies
  ([inbox zero](https://help.superhuman.com/hc/en-us/articles/45295217605523-Achieve-Inbox-Zero)).
- **Reminders.** `H` sets one, defaulting to "if no reply"; it returns with a
  purple dot
  ([remind](https://help.superhuman.com/hc/en-us/articles/46005666142733-Remind-Me)).
- **Command palette.** Cmd K shows each action's single-key shortcut, so people
  learn the shortcuts by using it
  ([shortcuts](https://help.superhuman.com/hc/en-us/articles/45191759067411-Speed-Up-With-Shortcuts)).
- **Search.** Results update as you type and recent mail is searchable offline.
  Operator tips sit in a side panel
  ([search](https://help.superhuman.com/hc/en-us/articles/46005672652301-Search),
  [engineering](https://blog.superhuman.com/delightful-search-more-than-meets-the-eye)).
- **Snippets.** `;` inline or Cmd `;`, private or team, with variables
  ([snippets](https://help.superhuman.com/hc/en-us/articles/46005686571149-Snippets)).
- **AI writing.**
  - Cmd J writes or rewrites
    ([AI](https://help.superhuman.com/hc/en-us/articles/46005557122957-Write-with-AI)).
  - Three instant replies cycle with Tab
    ([instant reply](https://help.superhuman.com/hc/en-us/articles/46005583725709-Instant-Reply)).
- **Undo send.** `Z` within 10 s
  ([undo](https://help.superhuman.com/hc/en-us/articles/46005666743309-Undo)).
- **Speed.** The target is under 100 ms per action. Mail lives in a local
  database, and likely threads are preloaded and prerendered
  ([speed](https://blog.superhuman.com/superhuman-is-built-for-speed)).

## HubSpot

- **Sending from a record.** You send and reply from the record. An "Associated
  with" picker chooses which records the email logs to
  ([one-to-one email](https://knowledge.hubspot.com/one-to-one-email/send-and-reply-to-one-to-one-emails)).
- **Conversations inbox views.** Unassigned, Assigned to me and All open, with a
  comment mode beside reply
  ([inbox](https://knowledge.hubspot.com/inbox/use-the-conversations-inbox)).
- **Open tracking.** A pixel with bot filtering. When the pixel is blocked but a
  click or reply arrives, HubSpot records an "inferred open"
  ([tracking](https://knowledge.hubspot.com/connected-email/understand-hubspot-sales-email-open-and-click-tracking)).
- **Sequences.** Contacts are unenrolled on reply or booked meeting
  ([unenroll](https://knowledge.hubspot.com/sequences/unenroll-from-sequence)).
  Bulk enrollment sends at most 3 emails per minute
  ([limits](https://knowledge.hubspot.com/connected-email/sales-email-send-limits)).

## Close

- **The inbox is an action queue.** It is not a mailbox mirror, and Done in Close
  archives the email in Gmail and back
  ([inbox](https://help.close.com/docs/inbox),
  [configuration](http://help.close.com/docs/email-configuration)).
- **Send + Done and reply detection** clear an item automatically.
  "Potential Contacts" surfaces senders who are not yet in Close
  ([changelog](https://close.com/changelog?fe6f4718_page=4)).
- **Scheduling.** Ctrl Shift Enter opens the schedule popup.
- **Template tags.** Tags have defaults
  ([emailing](https://help.close.com/docs/emailing)).
- **Sending limits.** Hourly, daily and minimum delay, with a 10% buffer kept for
  manual sends
  ([limits](https://help.close.com/v1/docs/en/email-sending-limits)).

## Pipedrive

- **Visibility.** Choices are Shared, Private and Team only. Only the owner can
  change them, and the composer offers the choice before sending
  ([visibility](https://support.pipedrive.com/en/article/how-can-i-view-or-adjust-email-visibility)).
- **Sync start date.** It ranges from 3 days to 2 years back
  ([settings](https://support.pipedrive.com/en/article/personal-account-settings)).
- **Templates.** "Insert field" warns when a field is empty
  ([templates](https://support.pipedrive.com/en/article/email-templates)).
- **Scheduled sends** wait in an Outbox folder, where you can cancel them, move
  them to drafts or delete them
  ([schedule](https://support.pipedrive.com/en/article/schedule-emails-for-later)).
- **AI drafting.** "Write my email" takes a tone and a length
  ([AI](https://support.pipedrive.com/en/article/ai-email-creation)).
- **Group email.** Up to 100 recipients, with a 30 s delay between sends
  ([group](https://support.pipedrive.com/en/article/group-emailing)).

## Front and Missive

- **Front.**
  - Each conversation has one owner, and assigning it routes it to "Assigned to
    me" ([assign](https://help.front.com/en/articles/2344)).
  - Comments are internal; @mentioning someone on a private conversation shares
    only that conversation, after a confirmation
    ([mentions](https://help.front.com/en/articles/2256)).
  - Collision detection shows when a teammate is replying
    ([collision](https://help.front.com/en/articles/2403)).
- **Missive.**
  - A coloured border marks a shared conversation in the list, and avatars show
    who has access
    ([conversations](https://missiveapp.com/docs/core-features/conversations)).
  - Rules can label mail with AI
    ([rules](https://missiveapp.com/docs/advanced-features/rules/actions)).

## Gmail, Outlook and HEY

- **Gmail.**
  - Undo send can be 5, 10, 20 or 30 s, and Schedule send sits under the Send
    arrow ([undo](https://support.google.com/mail/answer/2819488)).
  - Search operators include `from: to: subject: has:attachment after: before:`
    ([operators](https://support.google.com/mail/answer/7190)).
  - Categories retrain when mail is moved
    ([categories](https://support.google.com/mail/answer/3094499)).
- **Outlook.** Focused and Other learn from what you interact with
  ([focused](https://support.microsoft.com/en-us/outlook/what-is-focused-inbox)).
- **HEY.**
  - The Screener asks about first-time senders, then routes each to Imbox, The
    Feed or Paper Trail ([how it works](https://hey.com/how-it-works)).
  - Changing a sender's route moves their past mail too.
  - Reply Later and Set Aside are stacks.
  - Shortcuts: `j/k`, `x` select, `;` bulk menu
    ([shortcuts](https://help.hey.com/article/758-keyboard-shortcuts)).

## Salesforce and Streak

- **Salesforce Einstein Activity Capture.**
  - Automated mail (no-reply senders, mailing lists) is set to Don't Share until
    the owner reviews it. Others can request access
    ([EAC](https://help.salesforce.com/s/articleView?id=000392958&language=ja&type=1)).
- **Streak.**
  - Adding a thread offers "Just this thread" or "All emails with" a contact or
    domain. Auto-added mail carries a sparkle icon
    ([manual add](https://support.streak.com/en/articles/4300253-manually-adding-an-email-from-the-gmail-inbox)).
  - Teammates' unshared emails show only to, from and date
    ([sharing](https://support.streak.com/en/articles/4300110-overview-automatic-email-sharing)).

## Folk and Twenty

- **Folk.**
  - Email appears as interactions on each contact. Sync skips mail older than 3
    years and threads with more than 20 recipients
    ([folk sync](https://help.folk.app/en/articles/8170484)).
  - Visibility is private by default, with Subject only or Full access as options
    ([folk privacy](https://help.folk.app/en/articles/6416668)).
- **Twenty.**
  - Connecting runs a wizard that sets visibility (Metadata, Subject, All) and
    contact creation before the first sync
    ([overview](https://docs.twenty.com/user-guide/calendar-emails/overview)).
  - Sync runs at about 400 messages per minute, with an incremental pass every 5
    minutes.
  - No sequences and no mass send.

## Patterns that recur among the leaders

1. Done as the main action, with the thread returning on a new reply.
2. Follow-ups that resurface automatically when no reply arrives.
3. A command palette that teaches single-key shortcuts (`j/k e r /`).
4. A few prioritised views instead of one long list.
5. Noise routed away from the main view: newsletters, receipts, automated mail.
6. Privacy as an owner default plus per-thread overrides.
7. Metadata-only visibility with a request path.
8. Automatic linking plus a visible picker to correct it.
9. Composing from the record with merge fields that warn when empty.
10. Undo send and a visible Outbox for scheduled mail.
11. Tracking that admits its limits, toggled per email.
12. Throttled bulk sends that stop on reply.
13. Internal comments and collision detection on shared threads.
14. Instant search, with operators shown as hints.
15. Speed through prefetching and optimistic UI.
