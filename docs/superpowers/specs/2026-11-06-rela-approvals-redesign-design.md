# Rela approvals redesign: docked change list, check-line receipts, no status dots

**Date:** 2026-11-06
**Status:** Design approved (founder: "all your recommendations are okay", no Undo), pending implementation plan
**Base:** stacked on PR #1104 (`feat/marketing-self-hosted-frame`), because the marketing mocks it rebuilds are redrawn here
**Research:** `~/.claude/research/rela-chat-approvals-ui-2026-11.md` (20 products, 10 decisions x 5 options). The interactive board is `assets/rela-chat-approvals-ui-2026-11/mockup/index.html` beside it: the "Assembled" tab is the target behaviour.

## Summary

A Rela proposal keeps docking at the bottom of the chat, and the message box stays locked until the
user decides. What changes is how the dock and the transcript read:

- The dock lists each write as one line (verb, record, every value), with one "Approve 3 changes" button.
- Status dots go. A decided proposal becomes check lines with record chips; a discard becomes one grey
  line with "Propose again".
- Leaving one write out of a plan happens on the card before approval, and the card shows what a
  left-out record takes with it.
- An email in a plan is announced on the plan card, then takes the dock alone after the plan is approved.
- The box under the dock says why it is locked.
- Every marketing mock of Rela moves to the same states.

## Founder rules (2026-11-06)

1. While a proposal waits it docks where it sits today, and the message box is not free. The user
   approves or discards before typing again.
2. No colored status dots on decided proposals. This reverses the 2026-09 dot + word rule.
3. No Undo button and no undo feature.

## Decisions

| # | Decision | Choice |
|---|---|---|
| 1 | Where the proposal waits | Card docked at the bottom, over a locked line: "Approve or discard the proposal to keep chatting" |
| 2 | How a plan lists its writes | One line per write: verb, record name, then every value as compact pairs. A record created earlier in the same plan reads as a tinted name with "new" |
| 3 | How an update shows old and new | Old struck, arrow, new on one line when both fit; old above new when the pair is longer than 28 characters |
| 4 | Controls | "Discard all" + "Approve 3 changes ⌘↵" (verb, noun, count). A single record: "Discard" + "Approve ⌘↵" |
| 5 | After approval | "Applied 3 changes · just now" then one check line per write, each with its record chip. No Undo |
| 6 | After a discard | One grey line: the struck summary, "discarded", "Propose again" |
| 7 | Leaving one write out | Hover x per line (always visible below `sm`); a line that loses its link says so with "Keep Priya Nair" |
| 8 | An email in a plan | The plan card lists "Then email ... you approve it on its own, next". After the plan is approved the email card takes the dock alone, with Send email and no shortcut |
| 9 | While Rela works | Unchanged: one quiet line with the result ("Searched companies, found Figma ›") |
| 10 | Record changed after the proposal | Follow-up PR. Needs a new staleness check (see Out of scope) |

Four calls made in brainstorming (founder approved "go ahead with your recommendations"):

- **A. Every value is visible.** The chat rule "a pending card stays fully expanded, you may not
  approve what you were not shown" holds. A line shows all of its values; only long text (a note body,
  an email body) clamps to two lines with "more". No field hides behind a click.
- **B. Leaving a record out takes its dependents with it.** `ProposalPlanService::reject()` already
  cancels every step that links to a rejected one ("approving a task whose contact was just rejected
  would write a record the user never agreed to"). The card now shows that before approval: the
  dependent line is struck with "links to Priya Nair", and "Keep Priya Nair" restores both.
- **C. Nothing is rejected until Approve.** A left-out step or batch row is component state, like
  `$excludedFields` today. Approve applies it: left-out rows are skipped, left-out steps are rejected
  (with their cascade), the rest is approved. Discard all rejects everything, as today.
- **D. A standalone batch lists every record.** This reverses the 2026-08 pagination ("a paginated
  batch shows ONE record"). The invariant that pagination protected still holds in its other form:
  the footer decides exactly what is rendered, because every record is on screen with its own x and
  the button counts what it will approve.

## Current mechanics this builds on (verified)

- Dock and composer: `partials/_composer.blade.php:14` shows the dock with `x-show="hasPendingProposal"`
  and `:69` hides the input form with `x-show="!hasPendingProposal"`. The getter is in
  `chat-interface.blade.php:484-486`, backed by `resources/js/chat/transcript.js:902-907`. `send.js:245,335`
  refuse to submit while a proposal is pending.
- Cmd/Ctrl+Enter: `chat-interface.blade.php:392-411` dispatches `proposal:create-current` only with
  exactly one visible pending action (`:398`), no stream, empty draft. Every plan step is its own pending
  action, so today the shortcut never fires on a plan until one step is left. Feature tests dispatch the
  event directly (`ProposalCardLifecycleTest:289-312`, `EmailToolsTest:1327,1339,1389`,
  `InviteWorkspaceMemberToolTest:271`); `WebAccessDockTest:71` presses Control+Enter through the handler.
- Card shell `livewire/chat/proposal-card.blade.php` (labels, footer, pagination, send arming delay);
  steps `partials/_dock-step.blade.php`; field rows `partials/_dock-field.blade.php` with the checkbox
  exclusion and inline edit (`ProposalCard::editField/saveField`).
- Component `src/Livewire/Chat/ProposalCard.php`: `approveAll`, `approveStep`, `rejectStep`,
  `discardAll`, `createCurrent`, `discardCurrent`, `skipItem`, `prevItem/nextItem`, `toggleField`,
  `$excludedFields`, `stepViews()`.
- Plan semantics `src/Services/ProposalPlanService.php`: `approveAll` skips `needsOwnApproval()` steps,
  commits per step, stops at the first failure; `reject` cascades through `cancelDependentsOf`.
- Decided rendering: `partials/_proposal-card-body.blade.php:165-200` (dot colors and words),
  `partials/_proposal-item-chips.blade.php`, `transcript.js:1406-1425` (`batchOutcome()` words) with
  templates at `chat-interface.blade.php:84-91`. Words come from `PendingActionOperation::done()` and
  `ProposalEntity` receipts (`ProposalEntity.php:142-150, 232-258`).
- Web access dock `partials/_dock-web-access.blade.php` (Deny / Always allow / Allow) is a separate
  surface and keeps its own layout. It only inherits the shell and the locked line.

## Builder-check resolutions (my calls, 2026-11-06)

1. **Field exclusion stays on single-record cards only.** Plans and batch rows get line and row
   leave-out, not per-field x. Today plans have no field exclusion (`_dock-step.blade.php:27`) and the
   plan service passes none; extending it is out of scope.
2. **A batch row that a later step links to (`$ref:<id>#n`) has no x.** It shows "linked from
   {step name}" in its place. The whole step can still be left out, which cascades as today.
   `dependencyIds()` drops the index, so a per-row cascade would need new resolver work.
3. **A plan that contains a delete:** the primary turns danger and loses the shortcut
   ("Approve 3 changes", red, no ⌘↵). Any step that is a delete makes the whole primary risky.
4. **n counts records, everywhere:** header, primary, recount and receipt. A plan with a 6-record
   batch and one task reads "7 changes" and "Approve 7 changes". `approveAllCount` (today a step count,
   `ProposalCard.php:1815`) becomes a record count of approvable, not-left-out records.
5. **"Propose again" shows only on the most recent decided proposal of the conversation, and only when
   nothing is pending.** Its message names the proposal: "Propose again: {summary}". Expired lines get
   it; replaced lines do not. A discard already resumes the turn (`settleAfterResolution`), so the
   button is for a user who wants the same writes back after Rela's reply.
6. **Other own-approval entities keep their current dock layout and buttons.** EmailDraftSend,
   EmailCancel, EmailReschedule and EmailRetry read as "Then {action label}" lines inside a plan and get
   no shortcut. WebAccess keeps Ctrl/Cmd+Enter on Allow and gains the ⌘↵ hint on that button, so the
   rule "the shortcut runs only a primary showing the hint" holds for it too.
7. **The per-step Approve and Reject buttons go** (`_dock-step.blade.php:143-171`). One decision per
   dock: leave lines out, then Approve or Discard all. `approveStep` stays for own-approval steps and
   the email card. Approving one step while keeping the rest pending is no longer offered.
8. **Receipt lines keep their disclosure.** Each check line opens to the values it wrote (the existing
   disclosure that `ProposalRowDisclosureTest` pins); the chip itself still links to the record.
9. **Cmd+Enter on a plan needs a new client gate:** the handler counts the pending actions of the
   docked card (its `turn_id` group) instead of requiring exactly one pending action.
10. **The locked line replaces the sr-only "Review before continuing" status** (`_composer.blade.php:31`),
    so the event is announced once.

## Design

### 1. The dock (pending)

Shell: the existing halo card (`border-primary-200`, `ring-primary-100`) stays. The plan header
changes from "3 steps · Approved together, in order" to "3 changes", with "Hover a line to leave it
out" as right-aligned meta on `sm:` and up.

**Line** (one per step; a single-record card has one line with no header):

- Leading entity tile (24px, neutral; red tint for delete).
- First row: verb in secondary text ("Create person", "Update", "Move", "Delete", "Then email") and the
  record name in semibold.
- Value rows: every field the write sets, as `label value` pairs in 12px secondary text for the label
  and primary text for the value. Short values flow on one wrapped row separated by middots; a value
  longer than 48 characters, or rich text, gets its own row clamped to two lines with "more".
- A field that links to a record proposed earlier in the plan shows the tinted ref chip
  (`chat-chip` variant, primary tint, "new" suffix). This replaces "Priya Nair (step 1)".
- Updates show diffs per decision 3. A field the user excluded renders struck with "Keep".
- Inline edit stays: clicking a value opens the existing field editor (`editField`), unchanged.
- Trailing x (leave the line out). Hover-revealed on `sm:`+, always visible below `sm`, 32px target,
  `aria-label="Leave out {name}"`.
- Field exclusion moves from the checkbox to a per-field x with the same `toggleField` call.

**Batch step** (several records of one type, standalone or inside a plan): the line's value area is a
record list, one row per record: name, the struck old value (stage, due date), and its own x. The
header line states the shared change once ("Move 6 opportunities · Stage to Negotiation"), so rows do
not repeat the target.

**Left-out state:** a left-out line or row turns struck and muted, and its x becomes "Keep". A line
whose `$ref` points at a left-out step shows an amber note under it: "{Name} is left out, so this
{entity} is left out too. It links to {Name}." with the action "Keep {Name}". Header count becomes
"2 of 3 changes"; the primary recounts.

**Own-approval steps** (email send, workspace invitation): listed in the plan as a line
"Then email {subject}" with "to {recipient} · {lock icon} you approve it on its own, next". The line
opens to the full message read-only (all recipients, CC, BCC, subject, body). The plan's primary never
counts or runs it.

**Footer:**

| Card | Left | Primary |
|---|---|---|
| Plan or batch | Discard all | Approve {n} changes ⌘↵ |
| Plan whose only approvable step is one record, beside an email | Discard all | Approve {entity} ⌘↵ ("Approve task") |
| Single create or update | Discard | Approve ⌘↵ |
| Delete (any count) | Discard | Delete {n} {entity plural}, danger, no shortcut |
| Email send (alone in the dock) | Don't send | Send email, no shortcut, keeps the 700ms arming delay |
| Invitations | Don't send | Send {n} invitations, no shortcut (unchanged copy) |
| Every change of an update excluded | Discard | Approve disabled with the existing hint |

Cmd/Ctrl+Enter runs the primary only when it carries the ⌘↵ hint. It never sends an email, an
invitation, or a delete. `chat-interface.blade.php:392-411` reads that from the card, not from a
separate list.

**Email after the plan:** when approval leaves only own-approval steps pending, the dock re-renders with
the email card alone (today's email dock, restyled: header "{subject}" with a lock "Own approval" meta,
the full message, "Don't send" / "Send email"). The plan's approved steps are already in the transcript
as check lines above it.

### 2. The locked box

While a proposal is pending, the slot where the input form was shows one line under the dock:
lock icon + "Approve or discard the proposal to keep chatting" (12px, secondary text, dashed border,
`surface-2` background, 40px). It is not focusable and carries `role="status"` so screen readers hear
why the box is gone. It disappears the moment `hasPendingProposal` turns false.

### 3. The transcript after a decision

The decided card collapses to its receipt (the chat rule "a decided proposal card collapses to one
line" becomes "to its receipt lines").

**Applied** (any approved step):
- Head: "Applied {n} changes · just now" (12px secondary). n counts records written.
- One line per write: check icon (secondary color, not green) + past-tense verb + record chip
  (existing `chat-chip`, a link) + secondary meta. Verbs: "Created", "Created task", "Added note",
  "Updated {chip} · Name, Amount", "Moved 6 opportunities to Negotiation · $110,200" (opens to the
  record chips), "Deleted 2 tasks · {names}" (no chip, the records are gone), "Queued email to {chip}",
  "Sent 3 invitations".
- Mixed outcome in the same block: a left-out step or row as a ban icon + struck muted text
  ("Priya Nair · left out"), an unsent email as "Not sent: {subject}". Excluded fields are named in the
  update's meta ("Updated {chip} · Amount (Name left out)").

**Discarded** (whole plan or single): ban icon + struck summary ("Create 3 changes", "Delete 2 tasks")
+ "· discarded" + text button "Propose again". Expired: same line with "· expired". Superseded: same
line with "· replaced by a newer proposal" and no button.

**Propose again** sends a normal user message, "Propose that again", through the usual send path. It
costs a turn like any message. The model has the decided proposal in `<resolved_actions>`, so it can
re-propose.

No color carries meaning anywhere in a receipt: icon shape and words do. Green and red leave the
receipt entirely.

### 4. Where the words and icons live

One owner per fact (architecture rule "one fact, one owner"):
- Past-tense verb per entity and operation: `ProposalEntity` and `PendingActionOperation` (already own
  `done()` and receipts). Add the receipt line pieces there, never a `match` in a Blade partial.
- Outcome kind (applied, left out, discarded, expired, replaced, not sent): one enum in
  `Relaticle\Chat\Enums`, with its icon name and its word. `_proposal-card-body.blade.php`,
  `_proposal-item-chips.blade.php` and `transcript.js` read it; the JS gets the words through the
  existing templates in `chat-interface.blade.php:84-91`.
- Delete the dot color map in `_proposal-card-body.blade.php:186-200`.

### 5. Marketing

Every hand-built mock moves to the new states with the same copy as the app. Files (from the map in
the research note): `home/partials/hero-agent-composer.blade.php`, `hero-agent-conversation.blade.php`,
`hero-agent-preview.blade.php` (inline script label swap), `home/partials/rela-mock.blade.php`,
`partials/ai-control-mock.blade.php` (the "You stay in control" section: no dots, check and ban lines),
`partials/ai-playground.blade.php`, `partials/ai-plan-mock.blade.php`, `partials/ai-email-mock.blade.php`,
`ai-native-crm.blade.php:230-250`, `home/partials/story.blade.php:40-47`,
`components/home/hero-dock-checkbox.blade.php` (becomes the per-field x twin) and
`resources/js/motion.js` (`playApproval`, `storyRecord`). Mocks keep mirroring the app 1:1 (UI rule),
in light and dark.

Copy that changes on marketing pages: "Save changes" becomes "Approve", "Approved together, in order"
goes, "Approved by you" becomes the check-line receipt. "Approve all never sends an email" stays true
and stays.

### 6. Guidelines and docs

- `.ai/guidelines/relaticle/chat.md` (the source of `.ai/rules/chat.md`): rewrite "The footer decides
  exactly what is rendered; a paginated batch shows ONE record" for decision D, add the locked-box rule,
  the no-dots receipt rule, and the leave-out semantics (decision C). Then `php artisan boost:update`
  and copy `AGENTS.md` to `GEMINI.md`, keeping only this hunk (memory: boost:update drifts unrelated
  skills).
- Help docs under `packages/Documentation/resources/content`: update any page that describes "Approve
  all", status dots, or paging a batch. The plan greps for them.

## Out of scope

- Undo (founder: not needed).
- Decision 10, the "changed after Rela proposed" warning. Today a proposal only expires on a timer
  (`PendingActionService::expireStale`) or is superseded by a new message
  (`supersedePendingForConversation`); nothing compares the record with the proposal. A follow-up PR adds
  the check (store the read values or `updated_at` at proposal time, compare at approval) and the
  amber notice with "Overwrite" and no shortcut.
- Apply-first writes. The "Rela never writes without your approval" promise stays.
- The web-access dock layout.

## Testing

Feature (Livewire, `tests/Feature/Chat`):
- Plan card renders every value of every step, the ref chip with "new", and "Approve 3 changes".
- Leaving out a step marks its dependents left out with the "Keep" note; Approve rejects the left-out
  step and its dependents and approves the rest; "Keep" restores both; nothing is written before
  Approve.
- Leaving out batch rows: Approve skips them, approves the rest, the receipt lists both.
- Standalone batch lists all records; the primary counts them; no pagination controls render.
- Email in a plan: the plan primary approves the task only; the dock then holds the email alone; the
  email's button has no shortcut hint.
- Delete and own-approval buttons carry no shortcut hint.
- Receipt: applied lines, mixed outcome, discarded line with "Propose again", expired, replaced. No
  dot markup (`rounded-full` with a status color) anywhere in a receipt.
- Existing tests that pin old copy are updated, not deleted: `ProposalPlanCardTest`,
  `ProposalCardLifecycleTest`, `BatchCreateApprovalTest`, `InviteWorkspaceMemberToolTest`,
  `EmailToolsTest`, `PendingActionSupersedeTest`, and the Cloud marketing page tests.

Browser (`tests/Browser/Chat`, plus marketing):
- The locked line shows while a proposal waits and goes after a decision; typing is impossible meanwhile.
- Cmd+Enter approves a plan, and does nothing on an email, an invitation or a delete.
- Leave out and Keep on a three-step plan, then approve.
- `PlanCardTest`, `ProposalRowDisclosureTest`, `ProposalFieldEditTest`, and the marketing browser tests
  (`ShowcaseDemoTest`, `AiPlaygroundTest`, `AssistantPlaygroundTest`, `HeroDemoTest`) updated.
- The changed files' browser tests run once with `--browser safari`.

Manual: the chat loop on the production-shaped stack (Horizon, Redis queue, Reverb), walked in a real
browser for each scenario of the board (single update, three linked creates, batch of 6, plan with an
email, delete), light and dark, side panel and full page, then WebKit.

## Risks

- **The fully expanded card is taller than the board's folded lines.** Six values per line for three
  creates is about 300px at 420px wide. The dock already scrolls its steps and keeps the footer
  visible; this keeps that.
- **Deferred leave-out moves logic into `approveAll`.** A partial failure must leave the left-out state
  meaningful: left-out steps are rejected before any approval runs, so a failed approval mid-plan never
  leaves a left-out step pending.
- **Marketing drift.** Nine hand-built mocks copy the app. The marketing tests pin the copy; the UI
  rule pins 1:1 parity by screenshot.
