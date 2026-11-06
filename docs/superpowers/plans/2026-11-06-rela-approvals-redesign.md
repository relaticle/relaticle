# Rela Approvals Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use sdd-lean to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rela's docked proposal becomes a change list with one "Approve N changes" decision, leave-out on the card, a locked message box, and check-line receipts with no status dots, in the app and in every marketing mock.

**Architecture:** The pending-action model, `ProposalPlanService` semantics and `action_data` stay as they are. Leave-out becomes Livewire component state on `ProposalCard`, applied inside `approveAll` (reject left-out steps with their cascade, reject left-out rows, approve the rest). The dock and receipt Blade partials are rewritten; outcome words and icons move to one enum. Marketing mocks are hand-built Blade twins and are redrawn to match.

**Tech Stack:** Laravel 12, Livewire 4, Alpine, Filament 5, Tailwind 4, Pest 4 (feature + browser), PHP 8.5.

**Spec:** `docs/superpowers/specs/2026-11-06-rela-approvals-redesign-design.md` (read it first; its "Builder-check resolutions" are binding). Target behaviour: the "Assembled" tab of `~/.claude/research/assets/rela-chat-approvals-ui-2026-11/mockup/index.html`.

## Global Constraints

- The message box stays locked while a proposal is pending; the dock sits where it sits today.
- No colored status dots anywhere in a receipt or a mock. No Undo.
- Every value of a pending write is visible; only text longer than 48 characters or rich text clamps to two lines with "more".
- n counts records, not steps, in the header, the primary label, the recount and the receipt.
- Cmd/Ctrl+Enter runs only a primary that shows the ⌘↵ hint; never an email, an invitation, a delete, or a plan containing a delete.
- Own-approval steps (`PendingAction::needsOwnApproval()`) are never counted or run by the plan primary.
- Copy, verbatim: "Approve {n} changes" (singular "Approve 1 change" in a plan), "{n} of {total} changes", "Approve", "Approve {entity}", "Discard all", "Discard", "Delete {n} {entity plural}", "Send email", "Don't send", "Approve or discard the proposal to keep chatting", "Applied {n} changes · just now", "Propose again", "· discarded", "· expired", "· replaced by a newer proposal", "{Name} is left out, so this {entity} is left out too. It links to {Name}.", "Keep {Name}", "linked from {step name}", "Then email {subject}", "you approve it on its own, next".
- Every user-facing string through `__()`; no em-dash (U+2014) in any file; business words per `architecture.md` (person, company, opportunity, workspace).
- Write UI must keep the "one fact, one owner" rule: outcome words and icons live on enums, never in a Blade `match`.

## Review Focus

1. A failed approval mid-plan after leave-out: left-out steps must already be rejected, and no left-out step may stay pending. Test in Task 1.
2. A left-out step that another step links to, then "Keep": both return to pending state on the card and nothing was written in between. Test in Task 1.
3. A plan whose only approvable steps are all left out: the primary hides and only "Discard all" remains; Cmd+Enter does nothing. Test in Task 2.
4. Cmd+Enter with a draft in the composer still does nothing (the draft wins), including on a multi-step plan. Test in Task 3.
5. Two decided proposals in one conversation: "Propose again" shows only on the most recent one, and never while anything is pending. Test in Task 4.

---

### Task 1: Leave-out state and approval semantics

**Files:**
- Modify: `packages/Chat/src/Livewire/Chat/ProposalCard.php` (state, `approveAll`, `createCurrentFromShortcut`, view data at `:1800-1827`, remove `prevItem`/`nextItem` and the standalone pagination cursor use)
- Modify: `packages/Chat/src/Services/ProposalPlanService.php:146` (`approveAll`)
- Test: `tests/Feature/Chat/ProposalPlanCardTest.php`, `tests/Feature/Chat/BatchCreateApprovalTest.php`

**Interfaces:**
- Produces on `ProposalCard`:
  - `public array $leftOutSteps = [];` (`list<string>` pending action ids)
  - `public array $leftOutRows = [];` (`array<string, list<int>>` step id to row indexes)
  - `public function toggleLeftOut(string $stepId): void` (toggles; also clears that step from `$leftOutRows`)
  - `public function toggleLeftOutRow(string $stepId, int $index): void` (no-op for a row named in `linkedRowIndexes()`)
  - `public function leftOutDependents(): array` (`array<string, string>` dependent step id to the left-out step id that takes it, transitive, computed from `ProposalPlanService::dependencyIds()`)
  - `public function linkedRowIndexes(): array` (`array<string, array<int, string>>` step id to row index to the name of the step that links to that row via `$ref:<id>#n`)
  - `public function approvableRecordCount(): int` (records in pending, approvable, not-left-out steps and rows; replaces `approveAllCount` in the view)
  - `public function primaryIsRisky(): bool` (true when any counted step is a delete, or the only counted step needs its own approval and is not `approvesFromKeyboard()`; a WebAccess-only card is not risky)
  - `public mixed $leftOutSteps`, `public mixed $leftOutRows`, read only through `ProposalPlanService::cleanLeftOutSteps()` / `cleanLeftOutRows()`; `ProposalPlanService::rejectRows()` for a standalone own-approval batch (invitations) with rows left out
- Produces on `ProposalPlanService`:
  - `public function approveAll(PendingAction $action, User $user, array $leftOutSteps = [], array $leftOutRows = []): array` with the same return shape. Order: reject each left-out step via `reject()` (cascade), then `PendingActionService::rejectItem()` for each left-out row, then approve the remaining pending, non-own-approval steps as today.
- `approveAll` becomes the primary for standalone batches too (a single batch step is a plan of one); `createCurrent` stays the primary for a single record.

- [ ] **Step 1: Write failing tests** in `ProposalPlanCardTest`:
  - `it('rejects a left-out step and its dependents and approves the rest')`: three-step plan (person, task with `$ref` to the person, note); `toggleLeftOut(person)`; `approveAll`; assert person and task `status === Rejected`, task `result_data.cancelled_by === person id`, note Approved, one Company count unchanged, one Note created.
  - `it('writes nothing until approve when a step is left out')`: `toggleLeftOut(person)`; assert all three still Pending; `toggleLeftOut(person)` again; `approveAll`; all three Approved.
  - `it('reports the dependents a left-out step takes')`: `toggleLeftOut(person)`; `leftOutDependents() === [taskId => personId]`.
  - `it('counts records not steps')`: plan of a 6-record batch + one task; `approvableRecordCount() === 7`; `toggleLeftOutRow(batch, 2)`; `=== 6`.
  - `it('never leaves a left-out step pending after a failed approval')`: plan where step 3 fails validation; left out step 1; `approveAll`; assert step 1 Rejected, step 2 Approved, step 3 Pending with a resolve error.
  - `it('has no leave-out for a row a later step links to')`: batch create of 2 people + task with `$ref:<batch>#1`; `toggleLeftOutRow(batch, 1)` is a no-op; `linkedRowIndexes()[batch][1]` is the task's summary.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Chat/ProposalPlanCardTest.php`. Expected: the new tests FAIL (undefined method).
- [ ] **Step 3: Write failing tests** in `BatchCreateApprovalTest`: `it('approves every row of a standalone batch except left-out rows')` (3 people, leave out row 1, primary approves rows 0 and 2, row 1 `skipped`, status Approved); `it('rejects a standalone batch when every row is left out')`.
- [ ] **Step 4: Implement** the `ProposalCard` members and the `approveAll` signature above. Reset `$leftOutSteps`/`$leftOutRows` in `setActive()` like `$excludedFields`. `approveAll` on the card passes both arrays and clears them after a successful call. Remove `prevItem`, `nextItem` and the `activeItemPosition` paging from the standalone batch path (`createCurrent` for a batch now delegates to `approveAll`).
- [ ] **Step 5: Run** both test files. Expected: PASS. Then `composer test:affected`.
- [ ] **Step 6: Commit** `feat(chat): leave out proposal steps and rows until approve`

### Task 2: The dock card as a change list

**Files:**
- Modify: `packages/Chat/resources/views/livewire/chat/proposal-card.blade.php` (header, footer labels table, remove pagination block)
- Rewrite: `packages/Chat/resources/views/livewire/chat/partials/_dock-step.blade.php` (line layout; remove per-step Approve/Reject at `:143-171`)
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_dock-field.blade.php` (checkbox becomes per-field x calling `toggleField`; single-record cards only)
- Create: `packages/Chat/resources/views/livewire/chat/partials/_dock-line-values.blade.php` (the compact `label value` pairs, diff per spec decision 3, clamp rule)
- Create: `packages/Chat/resources/views/livewire/chat/partials/_dock-own-line.blade.php` ("Then email {subject}" line and its read-only full message)
- Test: `tests/Feature/Chat/ProposalPlanCardTest.php`, `ProposalCardLifecycleTest.php`, `EmailToolsTest.php`, `InviteWorkspaceMemberToolTest.php`

**Interfaces:**
- Consumes: Task 1 members (`leftOutSteps`, `leftOutRows`, `leftOutDependents()`, `linkedRowIndexes()`, `approvableRecordCount()`, `primaryIsRisky()`).
- Produces: the primary button carries `data-proposal-shortcut` only when it shows the ⌘↵ hint (Task 3 reads it).

- [ ] **Step 1: Write failing render tests** (Livewire `assertSeeHtml`/`assertSeeText`):
  - plan of three: sees "3 changes", "Approve 3 changes", "Discard all", every value of every step (job title, email, due date, assignee, note body), the ref chip text "Priya Nair" with "new", and no "Approved together, in order", no "(step 1)".
  - after `toggleLeftOut(person)`: sees "1 of 3 changes", "Priya Nair is left out, so this task is left out too. It links to Priya Nair.", "Keep Priya Nair", and "Approve 1 change" (`trans_choice('Approve :count change|Approve :count changes')`).
  - standalone batch of 6: sees all six names (assert each one, the card must render every record the footer approves), "Approve 6 changes", no paging controls.
  - standalone invitation card of 2: both addresses visible, each with its x; leaving one out changes the button to "Send 1 invitation".
  - delete of 2 tasks: sees "Delete 2 tasks", the primary has no `data-proposal-shortcut`.
  - plan task + email: sees "Approve task", "Then email Renewal options for Figma", "you approve it on its own, next"; primary has `data-proposal-shortcut`.
  - email alone (after `approveAll` on that plan): sees "Send email", "Don't send", no `data-proposal-shortcut`.
  - all approvable steps left out: no primary button, "Discard all" present.
  - value longer than 48 characters renders inside a `line-clamp-2` element with a "more" control.
- [ ] **Step 2: Run** the four files. Expected: new assertions FAIL; update the old assertions that pin "Approve all", "Approved together, in order", "Create"/"Save changes" labels to the spec copy (do not delete those tests).
- [ ] **Step 3: Implement** the partials. Lines use the neutral 24px tile (red tint on delete), verb in `text-gray-500`, name `font-semibold`, values `text-xs` labels and `text-sm` values. The x is `sm:invisible sm:group-hover/line:visible` and visible below `sm`, `min-h-8 min-w-8`, `aria-label="{{ __('Leave out :name', ['name' => $name]) }}"`. Left-out line: `line-through text-gray-400`, x becomes "Keep". Dependency note: amber tint (`bg-amber-50 text-amber-800 ring-amber-200`, dark `bg-amber-400/10 text-amber-300`). Ref chip: primary tint plus "new". Header meta "Hover a line to leave it out" on `sm:` only.
- [ ] **Step 4: Run** the four files and `composer test:affected`. Expected: PASS.
- [ ] **Step 5: Walk it** with agent-browser on the production-shaped stack (Horizon, `QUEUE_CONNECTION=redis`, Reverb) for the five scenarios in the spec, light and dark, side panel and full page; save shots under `.context/approvals/task-2/`.
- [ ] **Step 6: Commit** `feat(chat): dock proposals as a change list with one decision`

### Task 3: Locked box and the Cmd+Enter gate

**Files:**
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_composer.blade.php:14-40,69` (locked line under the dock; remove the sr-only "Review before continuing" at `:31`)
- Modify: `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php:392-411` (gate)
- Modify: `packages/Chat/src/Livewire/Chat/ProposalCard.php:884` (`createCurrentFromShortcut` returns early when `primaryIsRisky()`)
- Create: `tests/Browser/Chat/ProposalDockLockTest.php`

**Interfaces:**
- Consumes: `data-proposal-shortcut` on the primary (Task 2), `primaryIsRisky()` (Task 1).

- [ ] **Step 1: Write the browser test** `ProposalDockLockTest` with cases: `it('shows the locked line while a proposal waits and removes it after a decision')` (assert text "Approve or discard the proposal to keep chatting", the input form hidden; approve; the line gone, the input visible); `it('approves a three-step plan with Cmd+Enter')`; `it('ignores Cmd+Enter on an email, a delete and a plan with a draft in the box')`.
- [ ] **Step 2: Run** `php artisan test --compact tests/Browser/Chat/ProposalDockLockTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the locked line (`role="status"`, lock heroicon, `text-xs`, dashed border, `bg-gray-50 dark:bg-white/5`, `h-10`), shown with the same `x-show="hasPendingProposal"` as the dock. Gate: replace `pending.length !== 1` with "no element `[data-proposal-shortcut]` in the docked card, or streaming, or a draft"; keep the `proposal:create-current` dispatch.
- [ ] **Step 4: Run** the browser test, then `tests/Browser/Chat/WebAccessDockTest.php` (the web-access Allow keeps Ctrl+Enter: add `data-proposal-shortcut` and the ⌘↵ hint to its Allow button in `_dock-web-access.blade.php`). Expected: PASS.
- [ ] **Step 5: Commit** `feat(chat): lock the message box while a proposal waits`

### Task 4: Receipts without dots

**Files:**
- Create: `packages/Chat/src/Enums/ProposalOutcome.php` (cases `Applied`, `LeftOut`, `Discarded`, `Expired`, `Replaced`, `NotSent`; methods `icon(): string` heroicon names `check`, `no-symbol`, `no-symbol`, `clock`, `arrow-path`, `no-symbol`; `label(): string` with the spec words)
- Modify: `packages/Chat/src/Enums/ProposalEntity.php` and `PendingActionOperation.php` (receipt verb pieces: "Created", "Created task", "Added note", "Updated", "Moved", "Deleted", "Queued email to", "Sent :count invitations"), keeping `done()`
- Rewrite: `packages/Chat/resources/views/livewire/chat/partials/_proposal-card-body.blade.php` (receipt lines; delete the dot map at `:186-200`), `_proposal-item-chips.blade.php`
- Modify: `packages/Chat/resources/js/chat/transcript.js:1406-1425` (`batchOutcome()` returns outcome keys, words from templates), `chat-interface.blade.php:84-91` (templates from `ProposalOutcome`)
- Modify: `_transcript.blade.php` (a "Propose again" text button on the latest decided proposal, calling `sendNextStep({ prompt })`)
- Test: `tests/Feature/Chat/ProposalCardLifecycleTest.php`, `PendingActionSupersedeTest.php`, `tests/Browser/Chat/ProposalRowDisclosureTest.php`, `PlanCardTest.php`

**Interfaces:**
- Produces: `ProposalOutcome::forStep(PendingAction $step): self` and `ProposalOutcome::forItem(array $item): self` (reads `ProposalProgress` for rows). `ProposalOutcome::label()` returns the word without the leading middot; the partial adds it.

- [ ] **Step 1: Write failing tests**: applied plan renders "Applied 3 changes · just now", one check line per write with the record chip link, and no `rounded-full` element carrying `bg-green-`, `bg-red-` or `bg-gray-400`; mixed batch renders "Moved 5 opportunities to Negotiation" and "Kovra add-on seats · left out"; discarded renders the struck summary, "· discarded" and "Propose again"; superseded renders "· replaced by a newer proposal" with no button; two decided proposals render "Propose again" once (the latest), and none while a third is pending; a check line still opens to its written values (`ProposalRowDisclosureTest`).
- [ ] **Step 2: Run** the four files. Expected: FAIL.
- [ ] **Step 3: Implement** the enum and partials. Check icon `text-gray-500` (not green). "Propose again" sends `__('Propose again: :summary', ['summary' => $summary])`.
- [ ] **Step 4: Run** the four files and `composer test:affected`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(chat): receipts as check lines without status dots`

### Task 5: Marketing mocks mirror the app

**Files (all under `packages/Cloud/resources/`):**
- `views/marketing/home/partials/hero-agent-composer.blade.php`, `hero-agent-conversation.blade.php`, `hero-agent-preview.blade.php` (inline script `swapFooter` and label swap at `:359-405`)
- `views/marketing/home/partials/rela-mock.blade.php`, `story.blade.php:40-47`
- `views/marketing/partials/ai-control-mock.blade.php` (rewrite the three panels: check and ban lines, no dots), `ai-playground.blade.php`, `ai-plan-mock.blade.php`, `ai-email-mock.blade.php`
- `views/marketing/ai-native-crm.blade.php:230-250`
- `views/components/home/hero-dock-checkbox.blade.php` (becomes the per-field x twin; rename to `hero-dock-field.blade.php` and update both includes)
- `js/motion.js:113-148` (`playApproval`), `:253-277` (`storyRecord`)
- Tests: `packages/Cloud/tests/Feature/Marketing/ProductPagesTest.php`, `PublicPagesTest.php`, `AiNativeCrmPageTest.php`; `tests/Browser/Marketing/ShowcaseDemoTest.php`, `AiPlaygroundTest.php`, `AssistantPlaygroundTest.php`, `HeroDemoTest.php`

- [ ] **Step 1: Update the copy assertions first** to the spec copy ("Approve", "Approve 3 changes", "Approve or discard the proposal to keep chatting", "Applied", no "Approved together, in order", no "Save changes"); keep "Approve all never sends an email". Run them; expected FAIL.
- [ ] **Step 2: Redraw** each mock with the Task 2 and Task 4 markup and classes (copy the app partials' structure; mocks stay static Blade), including the locked line under every docked mock. Light and dark.
- [ ] **Step 3: Run** the Cloud feature tests and the four marketing browser tests. Expected: PASS.
- [ ] **Step 4: Screenshot** home hero, `/ai` (playground, plan, email, control sections) and `/ai-native-crm` beside the app at the same state, light and dark, Chromium; then the marketing browser tests with `--browser safari`. Save under `.context/approvals/task-5/`.
- [ ] **Step 5: Commit** `feat(marketing): mirror the redesigned rela approvals`

### Task 6: Rules and help docs

**Files:**
- Modify: `.ai/rules/chat.md` (rewrite "The footer decides exactly what is rendered; a paginated batch shows ONE record" for spec decision D; add the locked-box rule, the no-dots receipt rule, leave-out semantics from decision C, and "n counts records")
- Modify: `packages/Documentation/resources/content/help/ai-assistant/approve-what-the-assistant-changes.md` and `help/email-and-calendar/use-your-email-with-an-ai-assistant.md` (labels, leave-out, receipts, locked box); check `docs/guides/mcp.md` and `rest-api.md` only for the words "Approve all" in an assistant context
- Test: `composer test:arch` (conventions: em-dash, business words, YAML colon trap in docs front matter)

- [ ] **Step 1: Edit** the files. No front-matter value gains a bare `: ` (ParseException 500s every help page).
- [ ] **Step 2: Run** `composer test:arch` and `php artisan test --compact packages/Documentation` (or the docs test path the repo uses). Expected: PASS.
- [ ] **Step 3: Commit** `docs(chat): describe the redesigned approval dock`

### Task 7: Gates and the end-to-end walk

- [ ] **Step 1:** `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:lint`, `composer test:arch`, `composer test:affected`. Expected: all clean.
- [ ] **Step 2:** Browser tests for every changed surface once in WebKit: `php artisan test --compact tests/Browser/Chat/ProposalDockLockTest.php tests/Browser/Chat/PlanCardTest.php tests/Browser/Chat/ProposalRowDisclosureTest.php --browser safari` and the marketing browser tests the same way.
- [ ] **Step 3:** The production-shaped chat loop (Horizon, Redis queue, Reverb), real browser: each board scenario through send, stream, dock, leave-out, approve and discard; light, dark, side panel, full page; then WebKit. Screenshots into the PR.
- [ ] **Step 4:** Push, watch the `Tests` workflow as a background task, update the PR body (what changed, why, behaviour to know, tests, checked, not checked).
