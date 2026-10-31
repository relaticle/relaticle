# Email decisions: index

32 decisions, each reasoned in full in `decisions/` using [method.md](method.md):

- what the code and production show today, with file and line;
- what each competitor chose and why;
- five options, each with how it fails;
- a weighted matrix out of 60, where future-proofing scores as heavily as user value;
- a six-test future-proof check;
- the pick, the signal that would change our mind, and the first step.

Why each of the 23 competitors built email the way it did is in
[competitors.md](competitors.md).

| File | Decisions |
|---|---|
| [decisions/a-page-and-list.md](decisions/a-page-and-list.md) | 1 to 9: what the page is, the list, the reader, deep links |
| [decisions/b-triage-and-search.md](decisions/b-triage-and-search.md) | 10 to 14: Done, keyboard, bulk actions, search, filters |
| [decisions/c-composer.md](decisions/c-composer.md) | 15 to 21: composer surface, autosave, send later, undo, templates, AI drafting, toolbar |
| [decisions/d-linking-privacy-reach.md](decisions/d-linking-privacy-reach.md) | 22 to 27: linking, sharing default, access requests, opportunities, sync health, connect |
| [decisions/e-speed-ai-and-later.md](decisions/e-speed-ai-and-later.md) | 28 to 32: speed, tracking, follow-ups, Rela in the reader, FYI mail |

## All picks

| # | Decision | Pick | Runner-up | Score | Confidence |
|---|---|---|---|---|---|
| 1 | Job of the Emails page | Conversations list with record context; outbound lists become secondary tabs | Action queue only | 53 / 45 | Medium |
| 2 | Default view | Needs reply, falling back to All when empty | All, newest first | 53 / 51 | Medium |
| 3 | Reader surface | Non-modal right drawer that keeps the list live | Split pane | 57 / 53 | High |
| 4 | Deep links | Stable resolver route keyed by email id, plus the reader id in each host page URL | Query string on the host page only | 58 / 48 | High |
| 5 | Rows | One row per conversation, merged across mailboxes by RFC headers | One row per `email_threads` row | 54 / 42 | Medium |
| 6 | Row content | Two lines, right-aligned record chips | One dense line | 57 / 48 | High |
| 7 | Unread | Bold plus a dot, only on mail the viewer can open and that reached them | Dot only | 56 / 52 | High |
| 8 | Noise | Hide promotional mail by default behind a counted toggle; badge only actionable categories | Bundle automated senders | 54 / 47 | Medium |
| 9 | Locked rows | Open the reader at the privacy gate with a labelled Request button | Collapse runs of locked rows | 56 / 50 | Medium |
| 10 | Done and read | Per-user Done kept in Relaticle, plus read and unread; returns on new inbound mail | Read and unread only | 57 / 54 | High |
| 11 | Keyboard | One scoped shortcut map, `?` sheet, hints, off switch for single keys | Same plus full remapping now | 55 / 52 | High |
| 12 | Bulk actions | Checkbox column with a floating action bar | Filament table bulk actions | 56 / 47 | High |
| 13 | Search | Postgres full text, separate subject and body indexes gated by tier, `pg_trgm` for addresses | `pg_trgm` only | 56 / 45 | Medium |
| 14 | Filters | Chips and typed operators over one email filter owner in the `app/Queries` grammar | Fold email into `app/Queries` now | 58 / 51 | Medium |
| 15 | Composer surface | Corner window for new mail, inline for replies | Side panel | 57 / 47 | High |
| 16 | Draft safety | Debounced server autosave with a stale-write check | Same plus a browser crash copy | 56 / 50 | High |
| 17 | Send later | Split Send with presets in the sender's time zone | Typed time | 56 / 45 | High |
| 18 | Undo send | Per-user setting, default 10 s; Undo reopens the draft | 10 s fixed | 54 / 46 | Medium |
| 19 | Templates and variables | `/` templates, `{` chips from custom fields, fallbacks, per-recipient preview | Insert field with empty warning | 56 / 45 | Medium-high |
| 20 | AI drafting | "Draft with Rela" through the draft pipeline, never sends | Rewrite selection | 55 / 45 | Medium |
| 21 | Toolbar | Short fixed row plus the existing selection bubble | Bubble and Markdown only | 49 / 46 | Medium |
| 22 | Linking | Stored links, link and unlink chips, a suppression table, an Unlinked senders view | Read-time identity links | 54 / 44 | Medium-high |
| 23 | Sharing default | Ask at every connect with Participants only preselected; raising the workspace default never widens past mail | Ask with Subject preselected | 55 / 44 | Medium |
| 24 | Access requests | Labelled request on locked rows and reader, plus a per-record batch request | Per-email request only | 56 / 51 | Medium |
| 25 | Opportunity email | Read-time company and point-of-contact mail in the opportunity's time window, plus pins | Read-time since creation | 53 / 48 | Medium-high |
| 26 | Sync health | Scheduled watchdog stores a health state per mailbox; banner, nav dot, owner then admin notices | Banner computed per page view | 56 / 47 | High |
| 27 | Connect and empty states | Connect card above usable tabs, sharing step after every connect, no-provider state | Sharing step only | 55 / 50 | High |
| 28 | Speed and live updates | Fast server path plus a content-free Reverb signal per user | Refresh on focus | 55 / 53 | Medium-high |
| 29 | Tracking | No pixels; engagement from replies and meetings | Opt-in click tracking later | 56 / 46 | High |
| 30 | Follow-ups | Awaiting reply as a query in business days | Self-closing follow-up task | 57 / 52 | High |
| 31 | Rela in the reader | Summary line plus approvable cards on the proposal pipeline, with a facts card that needs no AI | Summary line only | 52 / 47 | Medium |
| 32 | FYI mail | Store an "automated" reason at sync, hide by default, bundle on record tabs | Bundle per sender | 56 / 48 | Medium |

## What the deep pass changed from the first draft

- **Rows merge across mailboxes (5).** `email_threads` is unique per mailbox, so the
  first draft's thread rows would show a shared conversation twice.
- **Deep links are a resolver route (4).** A panel-wide `?email=` link already exists
  (`Email.php:449-456`). Links leave the app through notifications, Rela and MCP, so
  they need a URL that survives layout changes.
- **The sharing default is Participants only, not Subject (23).** GDPR Art. 25(2),
  the ICO's worker-monitoring guidance and German works councils favour least
  exposure. Rela and MCP see what the viewer sees, so a wide default widens agents too.
- **"Most open copy wins" stays (23).** Each owner controls only their own copy. The
  reader names the source mailbox.
- **No tracking pixels, as a position (29).** Apple Mail Privacy Protection, CNIL's
  2026 consent guidance and deliverability all argue against them.
- **Done never writes back to Gmail (10).** The reason is re-consent for 32 mailboxes,
  Entra admin approval and two writers on one flag. Not Google's assessment, which
  `gmail.readonly` already triggers.
- **Live updates carry ids, not content (28).** One broadcast payload cannot respect
  each viewer's tier.
- **No browser crash copy of drafts (16).** It would escape GDPR erasure.

## Corrections to earlier docs found while verifying code

- A contributor made the outbound-only call (`0730dddd7`, reverted restore
  `1c5546af5`). The founder's `c3f30b7a2` removed code unmounted since August.
- Bulk mail is already dropped at sync (`StoreEmailJob.php:145-149`), Gmail since
  2026-10-02 and Microsoft since 2026-10-09. The 37% Marketing share is mostly older
  rows (inferred). Headers are not stored, and `Auto-Submitted` is not checked.
- Over half of the unlinked mail is likely marketing (about 113,900 of 200,900,
  derived from rounded shares). "100% of mail visible" is the wrong goal.
- No action links or unlinks an email by hand. `LinkEmailAction` writes only `auto`
  links. The 3 manual links come from sending from a record.
- Chat binds bare `j`, `k` and `n` on every page (`chat-sidebar-nav.blade.php`), and
  none can be turned off. That fails WCAG 2.1.4 today.
- Ctrl K is Filament record search, not an action palette.
- New mail arrives by polling every 5 minutes (`bootstrap/app.php`). Only calendar
  uses push.
- An admin raising the workspace default re-tiers past mail of mailboxes with no tier
  of their own, without telling the owners
  (`ApplyDefaultSharingTierToExistingEmailsAction.php`).
- Sync skips workspaces without paid access while mailboxes still show `active`. That
  is a likely cause of the 375-hour stall (inferred).
- Locked rows already show "Request access from {owner}"
  (`list-row-wide.blade.php:177-183`). The reader prints the snippet at subject access
  (`email-view.blade.php:138`), which would leak body text once that gate is reachable.

## Brainstorm log (founder decisions)

| # | Question | Decision | Date |
|---|---|---|---|
| 1 | Job of the Emails page | **C. CRM Conversations list** with record context; Needs reply is a view inside it; Drafts, Outbox, Failed and Templates become secondary tabs. Reverses the outbound-only page of `0730dddd7` | 2026-10-31 |
| 2 | Which mail appears and what teammates see | **C. All of your own mail, plus teammates' threads only where linked to a record, at their tier.** Needs reply and Awaiting reply cover only your own conversations; Unlinked is your own mail only; a shared thread shows once at your access. First step: linked-only condition in the teammate branch of `VisibleEmailScope` | 2026-10-31 |
| 3 | Reader surface | **C. Non-modal right drawer on every host.** Thread renders as header with record chips, optional Summary line, collapsed earlier messages with lazy bodies, latest message expanded, inline reply. Split pane only if measured | 2026-10-31 |
| 4 | Deep links | **E. Resolver route `/email/open/{email}`** keyed by email ULID: binds the email's workspace, picks the viewer's preferred copy, redirects to the linked record tab with `?email=` (else Conversations), same "not available" page for missing and private. Hosts keep `#[Url(as: 'email')]` for refresh; `EmailForAgent` gains `url` | 2026-10-31 |
| 5 | Rows | **C. One row per conversation**, `conversation_key` set at store time from In-Reply-To, then provider thread, then own Message-ID; merge only on exact Message-ID within one workspace; row fields from messages the viewer may see. **Also store the `References` header at sync** so merging survives a missing In-Reply-To or an out-of-order parent. First step: read-only prod count of Message-IDs held by 2+ mailboxes | 2026-10-31 |
| 6 | Row content | **C. Two lines with right-aligned record chips.** Line 1: senders with count, teammate-mailbox owner avatar, attachment icon, time. Line 2: subject, muted snippet, up to two chips (company, opportunity) or an Unlinked chip with Link and Create person. Locked rows keep chips and owner avatar; "via Name" hidden for the viewer's own mailbox. One chip plus "+1" at 390px | 2026-10-31 |
| 7 | Unread state | **B. Bold plus a dot (with `aria-label`), scoped** to messages that reached the viewer (own mailbox, or shared after the share date) and that the viewer can open; a thread is unread when any such message is. One unread count, on Needs reply only. No write-back to the provider. `EmailForAgent` gains `is_read` for the acting user | 2026-10-31 |
| 8 + 32 | Noise and FYI mail (merged) | **D. One owner stores `automated_reason` at sync** from `List-Unsubscribe`/`List-Id`, `Auto-Submitted` (not `no`), `Precedence`, role sender, provider category, on both providers. Hidden behind a counted Automated view on Conversations; bundled per sender on record tabs; "Not automated" suppresses the sender per workspace. Delete the Blade colour map (enum owns it); Gmail's Personal hint stops short-circuiting other rules. Keep today's bulk drop at sync. Backfill from the Marketing label in a rehearsed chunked migration. No digest | 2026-10-31 |
| 9 | Locked rows | **B. Row opens the drawer at the privacy gate** with owner, tier explanation and Request access (optional note). Fix the snippet leak (`email-view.blade.php:138`) first; opening a locked row never marks read; request-access proposal tool for Rela | 2026-10-31 |
| 10 to 31 | Remaining decisions | **Founder accepted every recommended pick as written in `decisions/b` to `decisions/e`** ("go ahead with your recommendations"). Each stays open to revision while the spec is reviewed | 2026-10-31 |
