---
paths:
  - 'packages/Chat/**'
---

# Chat

## Chat always passes a resolved provider, so #[Provider] failover lists never run
AiModelResolver::resolve() returns one concrete provider, ChatController hands it to ProcessChatMessage, and the job calls $agent->stream(provider: ...). laravel/ai reads the #[Provider] attribute ONLY when the prompt's provider argument is null (Promptable::getProvidersAndModels), so a provider LIST on CrmAssistant would never fail over. To actually get provider failover, stream() has to receive the array - a product decision, because the fallback provider runs a different model.
Two related limits: StreamErrorException is not a FailoverableException, and StreamableAgentResponse::hasYielded() suppresses failover once any event reached the consumer, so a mid-stream failure cannot fail over either.
Provider failover therefore lives in the job, not the attribute. When resolution came from `auto` (resolve() tags it `source`), nothing has streamed yet, and transient retries are exhausted, ProcessChatMessage re-dispatches itself once against the next entry in `chat.auto_chain` (failoverDepth bounds it to one hop). An EXPLICIT model pick never fails over: users pay per-model credit multipliers, so a silent swap is worse than an error.

## Anthropic prompt caching rides on providerOptions() overriding the request body
laravel/ai still has no native prompt-cache API (v0.11.0; PR #860 closed, #869 open). CrmAssistant::anthropicCachedSystemBlocks() works only because Gateway\Anthropic\Concerns\BuildsTextRequests ends with array_merge($body, $providerOptions), so the returned 'system' array of content blocks replaces Anthropic's plain-string system prompt and carries the cache_control breakpoint. Tool schemas render before system in Anthropic's cache prefix, so the one breakpoint covers all of them plus the static instructions; per-turn context must stay in the second, uncached block.
If that merge order ever changes upstream nothing throws - turns just silently cost full price. tests/Feature/Chat/AnthropicPromptCachingTest.php asserts the built request body; keep it green on every laravel/ai upgrade. Also: Usage::promptTokens is the UNCACHED remainder only, so any cost figure priced off it understates the real prompt.

## A chained turn is one plan, grouped by turn_id
Proposals created in the same turn share `pending_actions.turn_id` and are presented
as one card (`ProposalPlanService::steps()`), approved by one click. A later step
references an earlier one with `$ref:<pending_action_id>` in place of a record id;
`PlanReferenceValidator` accepts it only backwards, in the same turn, pointing at a
still-pending single-record CREATE of the expected entity type, and
`PlanReferenceResolver` swaps it for the real id inside the approving transaction.
Rejecting a step cascade-cancels its dependents (`result_data.cancelled_by`), and
`approveAll` commits per step and stops at the first failure, a plan is a sequence
of real CRM writes, not a transaction.
The dock re-docks on the WHOLE pending set (`syncActiveProposal` keys on a signature
of all pending ids): keying on the first id alone leaves the card rendering the plan
as it looked when only step one had streamed in.

## ProposalPayload is the only decoder for `pending_actions.action_data`
That column is a four-arm union of marker keys: a single create is the record itself,
a single update adds `_record_id`/`_model_class`, a single delete is `_record_ids`, and
any multi-record proposal is `_batch` + `records`. Read it through
`Support\ProposalPayload` (and `ProposalProgress` for `result_data.items`), never
re-derive `($data['_batch'] ?? false) === true` inline. Fifteen inline copies used to
disagree: the dock counted a malformed batch as one record while approval threw.
Reading is split by consequence on purpose. `recordAtOrEmpty()`/`displayAt()` are
lenient because they feed a render, where a throw blanks the dock; `batchRecords()`/
`batchRecordAt()`/`recordIds()` are strict because they feed a real CRM write, where a
silently emptied record creates a blank one.
The stored shape is frozen and the payload class only ever reads. Do not normalize on
write: replayed proposal tool results embed `action_data` verbatim (rewriting one
invalidates the Anthropic prompt-cache prefix from that turn on), and
`PendingActionService::createProposal` dedupes job retries by strict array equality
against rows already in the database.

## Deciding a proposal starts the next turn; it is not the user typing
`TurnContinuationService::resume()` runs from the dock (`ProposalCard::settleAfterResolution`)
and queues `ProcessChatMessage(isContinuation: true)`. Without it the user had to type
"next" after every card just to hear what happened or to get the rest of a chained
request. Four invariants keep it bounded, and all four are load-bearing:
- It fires only from a human decision, never from a turn ending, so the loop cannot
  drive itself.
- It fires only when the conversation has NOTHING pending, and that is re-checked
  inside the job. `handle()` supersedes every pending proposal at the top, and a
  chained turn is briefly pending-free between two steps streaming in, so a
  dispatch-time check alone would cancel steps the user never saw. `WithoutOverlapping`
  delays the job, it does not un-fire it.
- `Cache::add("chat:continued:{turnId}")` makes it once per decided turn across tabs
  and double clicks. `CreditService::reserveCredit` is idempotent by key and cannot
  stand in for this.
- It costs a credit like any other turn. Out of credits means no resume, not a queued
  one, the user can still type.
The turn runs on a synthetic user message (the provider needs a final user turn). It is
stamped `meta->kind = "continuation"` by `SupersededAwareConversationStore` and excluded
in `TranscriptScope`, so the model sees it and the transcript does not. Compare that
exclusion with `coalesce(...)`, not a bare `meta->>'kind'`: on every other row the
comparison is NULL, the enclosing AND is NULL, and `NOT NULL` drops the row, which hid
half the transcript the first time it was written.
Prompt and UI follow from this: the assistant must never ask the user to say "continue"
or "next", and a decided proposal card collapses to one line (pending stays fully
expanded, you may not approve what you were not shown).

## The sidebar chat list cannot repaint itself
`ChatSidebarNav` is mounted through a Filament render hook, so it is a Livewire
component nested inside `Filament\Livewire\Sidebar`. A nested component's own
re-render never reaches the DOM here: the server renders it and Livewire drops
the html, leaving whatever the page loaded with. Only the parent's render paints
it, which Filament exposes as the `refresh-sidebar` event. Everything that
changes the list therefore dispatches that: `send.js` on conversation creation,
`stream.js applyTitle()` when a generated title arrives, the row's rename, and
`ChatSidebarNav::deleteConversation`. The component itself listens to nothing;
`$listeners => '$refresh'` there is worse than dead, because a self-refresh
batched with the parent's refresh takes the parent's paint down with it.
Dispatch `refresh-sidebar` in its own tick when another Livewire event fires
alongside it (`setTimeout(..., 0)`).
Two traps behind this one. A row's title cannot be patched by hand: it lives in
an `x-if` template Alpine tears down while renaming, so the write lands on a
detached node and the stale title returns with the template. And the all-chats
panel is nested the same way and has the same defect, still unfixed: it shows
the list as of its last full page load.

## The footer decides exactly what is rendered; a paginated batch shows ONE record
Since 2026-08 (user-directed Attio redesign) a standalone batch docks as
pagination: the card renders ONLY the active record, the footer arrows
(`prevItem`/`nextItem`) page through the undecided ones ("2/3"), and the
footer's "Create"/"Discard" resolve the ACTIVE record alone
(`ProposalCard::createCurrent` -> `approveActiveItem`, `discardCurrent` ->
`rejectActiveItem`), advancing to the next undecided record. ⌘⏎ therefore
creates one record per press mid-batch.
The invariant that makes a per-record footer safe: the card must never render
more than the record those buttons decide. In 2026 the footer decided ONE
hidden record under the cursor while the card LISTED every record, so a user
who clicked Discard to dismiss the card silently rejected a contact the card
still reported as created. Re-adding a visible record list beside a per-record
footer recreates that bug; a footer deciding more than what is rendered
recreates its mirror image.
A PLAN's batch step still lists every record row (the plan footer is
"Approve all N", so everything it decides must be on screen) with per-row skip
(`skipItem`, hover-revealed on `sm:`+ but ALWAYS visible below it: touch has no
hover, and an invisible skip control was its own past defect).

## Attribute checkboxes exclude fields at approval time, never from action_data
Each excludable field row of a standalone card carries a checkbox
(`ProposalCard::$excludedFields`, applied by `PendingActionService::approve`/
`approveItem` via `sanitizedExclusions` + `withoutExcludedFields`). Unchecked
codes are stripped from the write IN MEMORY at execution; the stored
action_data stays frozen (replay/prompt-cache contract). The list is
client-writable checkbox state, so the SERVICE guard is the defense: payload
markers and the entity's title key are never excludable, delete excludes
nothing. Exclusions reset on every navigation (setActive, focusItem,
prev/next, each decided item) so one record's exclusions cannot leak onto the
next. Audit truth: excluded codes persist in `result_data.excluded` (single)
or `result_data.items[i].excluded` (batch item) and reach the model via
`excludedFieldEntries()` -> `<resolved_actions>` ("fields unchecked by the
user, NOT written") — without that line the model reports values it never
set, the same defect class `skippedItemLabels()` exists to prevent.

## Duplicates are caught by the REAL field rules, which need the tenant bound
The card-level "Heads up: already proposed a moment ago" warning was removed
(user-directed, 2026-08). Duplicate values (a person email above all) are
rejected by the custom-fields package's own unique rules running inside
`CustomFieldsRequestValidator` at proposal time — never re-implement them as
bespoke lookups in a tool; they drift, and any escape hatch they offer is a
lie the real rule blocks later. Two load-bearing pieces:
- `ProcessChatMessage::bindAuth()` binds `TenantContextService` for the whole
  job (restored in `releaseAuth()`). Without it the unique rules silently
  no-op in the queued job — the exact bug that let chat create duplicate
  emails while the panel form rejected them. Any new chat entry point that
  validates or writes custom fields needs the same binding.
- `BaseWriteCreateTool` SKIPS a record that fails validation instead of
  aborting the call: the valid records still become one proposal, and
  `skipped_records` (+ `skipped_note`) in the tool result tell the model each
  skipped record and reason to relay to the user. All records failing returns
  a plain error listing every reason.

## A partially-skipped batch is stored Approved: never render that status raw
`finalizeBatchIfComplete` marks the row Approved when ANY item was created, so
`pending_actions.status` cannot describe a mixed outcome. Two readers must derive
from `result_data.items` instead:
- the transcript header chip, via `batchOutcome()` in `transcript.js` ("2 created"
  + "1 skipped"), never `action.status`;
- the model's `<resolved_actions>` block, via `PendingActionService::
  skippedItemLabels()` -> `CrmAssistant::resolvedBlock()`, which names each skipped
  record as "skipped by the user, NOT created".
Without the second one the assistant reads "approved" plus the proposal's full
record list and tells the user every record was created, including the ones they
declined. Both are asserted (`ProposalCardComponentTest`, `BatchResolvedActionsTest`).

## The table's paint budget is client-only: the model can never state a row count
`BaseReadListTool` stopped slicing server-side, so `display_block.rows` carries the
WHOLE page the model read. The trim moved to `RECORDS_TABLE_COLLAPSE_ROWS` in
`transcript.js`, which paints the first 10 until the user clicks "Show all N rows".
That constant is never sent to the model and has no payload field, so the only
numbers the model can state truthfully are `total` and `has_more`; ANY row count it
writes is unverifiable and routinely wrong (a 25-row page paints 10).
CrmAssistant rule 10 therefore bans row counts outright. The ban leaked once because
it did not cover the case where the USER names the number first: "show me 25
companies" produced "here are the first 25 of your 56" above a table reading
"Showing 10 of 56". Rule 10 now spells that case out and gives the sentence to write
instead ("the first page of 56"), asserted in `CrmAssistantInstructionsTest`.
Two consequences for future work: never add a prompt rule that asks the model to
state how many rows the user can see, and if a payload field is ever wanted for it,
it must be the SERVER telling the model the paint budget, not the model guessing.
Prompt constraints are probabilistic: this narrows the tail, it does not close it.
