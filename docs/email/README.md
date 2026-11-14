# Email experience audit (2026-10-31)

This folder is a full audit of Relaticle's email feature: the `Emails` page
(`/app/{workspace}/email`), the record `Emails` tabs, the reader, the composer,
sharing, linking and sync health. It compares every surface with Attio and the
email leaders, then picks one best-in-class direction per decision.

| File | What it holds |
|---|---|
| [README.md](README.md) | Summary, evidence, roadmap, success metrics |
| [decisions.md](decisions.md) | Index of 32 decisions and all picks; full reasoning in `decisions/` |
| [method.md](method.md) | Weighted scoring and the six future-proof tests |
| [competitors.md](competitors.md) | Why each of 23 competitors built email the way it did, and the lesson for us |
| [findings.md](findings.md) | Bugs and inconsistencies found, with file and line |
| [benchmark.md](benchmark.md) | Attio, Superhuman, HubSpot, Close, Pipedrive, Front, Missive, Gmail, HEY, Salesforce, Streak, Folk, Twenty, with sources |
| [benchmark-modern.md](benchmark-modern.md) | Products built or rebuilt since 2023: Notion Mail, Shortwave, Spark 3, Zero and Inbox Zero (read from source), Cora, Day.ai, Clarify, Lightfield |

## What the 2023+ research changed

- **The thread list is open ground.** None of the AI-native CRMs (Day.ai,
  Clarify, Lightfield) has a thread list across records. Lightfield's docs send
  email questions to the record page or to chat.
- **A standalone inbox loses.** Notion Mail shut down on 2026-09-22, and its main
  criticism was email that never linked to the rest of the product. Every row we
  show must carry its record context.
- **AI output is a card you approve.** Every product drafts and none sends on its
  own. Rela's proposal pipeline already works this way (decision 31).
- **Needs reply is computed in two stages.** Free header and sender rules run
  always; Rela refinement is optional, from Inbox Zero's open source
  (decision 2).
- **The speed techniques are concrete.** From Zero's source: a list query that
  never loads bodies, hover and neighbour prefetch, lazy message bodies, and
  Undo toasts (decision 28).

## How this was produced

- Walked the running app in a browser with a seeded mailbox (28 conversations,
  two mailboxes, mixed sharing tiers, linked companies and people): Emails page,
  every tab, record Emails tab, reader, inline reply, composer, outbox, the
  no-mailbox state, dark mode and a 390px viewport.
- Read the package code: `packages/EmailIntegration` (pages, Livewire tables,
  Blade components, services, enums) and the git history of the page.
- Pulled read-only, aggregate-only usage counts from production. No record
  content was read.
- Researched Attio and 12 other products from their public docs, changelogs and
  reviews. Every claim in `benchmark.md` carries its source URL.

## The short version

Relaticle syncs mail well and hides it badly. Production holds 372,714 synced
emails across 29 workspaces, yet the `Emails` page shows none of them. It opens
on an empty Drafts table. Mail is reachable only through a record's `Emails`
tab, and 54% of synced mail is linked to no record, so it is reachable nowhere.

The outbound surfaces the page leads with are barely used: 27 emails sent from
the app in 90 days, 2 templates, 1 mass send, 0 access requests, 0 shares.

The fix is not a Gmail clone. It is a CRM conversation list: threads with the
people and companies you track, sorted by what needs you, with the record
context beside every thread and a reader you can drive from the keyboard.

## Evidence from production (aggregates, 2026-10-31)

| Measure | Value | What it tells us |
|---|---|---|
| Workspaces with a mailbox | 29 | The feature has real adoption |
| Mailboxes (Gmail / Microsoft) | 27 / 5 | Microsoft matters (16%) |
| Mailboxes in `error` or `reauth_required` | 3 of 32 (9%) | Broken sync is common and silent |
| Longest gap since last sync on an `active` mailbox | 375 hours | A mailbox can stall for 15 days while marked active |
| Synced emails | 372,714 | Median 797 per mailbox, max 156,632 |
| Emails linked to at least one record | 46.1% | 54% of mail has no surface at all |
| Links made by hand | 3 of 474,007 | Manual linking is effectively absent |
| Links to opportunities | 135 (vs 268,357 company, 205,515 person) | Opportunity email tabs are empty |
| Emails at `metadata_only` | 95.4% | Teammates see almost nothing |
| Access requests ever | 0 | The request flow is not discovered |
| Per-email shares ever | 0 | Sharing is not discovered |
| Emails sent from the app, last 90 days | 27 | Composer, templates and outbox are rarely used |
| Templates / signatures / mass sends | 2 / 1 / 1 | Outbound tooling is unused |
| AI thread summaries ever | 2 | The summary button is not discovered |
| Labels: Other / Marketing / Sales | 52% / 37% / 0.1% | The category badge is mostly noise |
| Messages per thread (avg) | 1.12 | Threads are short; grouping matters less than triage |

## Top 10 moves, in order

1. **Fix the quick bugs** in `findings.md` (P0 and P1): the `Re: Re:` subject,
   the reader's serif body font, the duplicated snippet, the `Status tab`
   filter label, the clipped mobile tab, the hidden drafts and templates when no
   mailbox is connected.
2. **Make the Emails page a Conversations list** (decision 1). Threads with
   tracked people and companies, newest first, with smart views: Needs reply,
   Awaiting reply, All, Unlinked. Drafts, Outbox, Failed and Templates stay as
   secondary tabs. This reverses the 2026-08-13 call to make the page
   outbound-only (`0730dddd7`, written by a contributor), so it needs the
   founder's sign-off.
3. **Group by thread and render unread** (decisions 5 and 7). The query already
   loads `is_read`; the row never shows it.
4. **Replace the centered modal reader with a side drawer** that shows the whole
   thread, the linked records and a deep link (decisions 3 and 4).
5. **Linked-record chips with add and remove in the reader**, plus an Unlinked
   view with one-click "Create person" (decision 22). This is the path to the
   54% of invisible mail.
6. **Sync health you can see**: a banner and a nav dot when a mailbox is broken
   or stale, and a watchdog that marks a silent mailbox degraded after 24 hours
   (decision 26).
7. **Real search**: Postgres full-text search with privacy-aware fields and
   filter chips (decisions 13 and 14).
8. **Keyboard-first triage**: `j/k`, `Enter`, `Esc`, `r`, `a`, `f`, `e`, `u`,
   `/`, `?` plus command palette entries (decision 11).
9. **A composer that saves itself**: autosave, send later presets, `/`
   templates, `{` variables with fallbacks, "Draft with Rela" (decisions
   15 to 20).
10. **Privacy defaults people can live with**: ask at connect with "Subject
    line" preselected, and turn access requests into a one-click row action and
    a per-record bulk request (decisions 23 and 24).

## Roadmap

| Phase | Scope | Decisions | Size |
|---|---|---|---|
| 0. Polish | Every P0 and P1 finding | `findings.md` | 2 to 3 days |
| 1. Conversations | Thread list page, drawer reader with full thread, unread, deep links, linked-record chips, mobile layout | 1 to 9, 22 | 2 weeks |
| 2. Triage and search | Needs reply and Awaiting reply views, Done, shortcuts, bulk actions, full-text search, filter chips, live updates | 10 to 14, 28 | 2 weeks |
| 3. Composer | Corner window default, autosave, send later, slash templates, variables, Draft with Rela, toolbar | 15 to 21 | 1.5 weeks |
| 4. Trust and reach | Sharing default at connect, access requests, unlinked senders, opportunity email, sync health, noise filtering | 8, 23 to 27 | 1.5 weeks |
| 5. Later | Open and click tracking, reminders, sequences | 29, 30 | Scope after phase 4 metrics |

## Success metrics

Measure from production, aggregate only, before and 30 days after each phase.

| Metric | Baseline (2026-10-31) | Target |
|---|---|---|
| Emails sent from the app per 90 days | 27 | 10x |
| Unlinked mail from people (not marketing) that is linked or excluded within 30 days | Unknown; measure first | Most of it, through the Unlinked senders view |
| Manual links per month | ~0 | 50+ |
| Access requests per month | 0 | Any, growing |
| Distinct users who opened an email in the app | 30 lifetime | Weekly active growing |
| Mailboxes broken longer than 24 hours without a visible alert | Unknown, at least 3 | 0 |
| AI thread summaries per month | 2 lifetime | 100+ |
