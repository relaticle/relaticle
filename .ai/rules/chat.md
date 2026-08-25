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

## A batch card decides the whole remainder; only a record's own row skips it
The dock footer of a batch proposal is one decision over everything still
undecided: "Create all N" (`ProposalCard::createCurrent` -> `approveRemainingItems`)
and "Discard all" (`discardCurrent` -> `rejectRemainingItems`), both looping the
per-item service calls and stopping at the first failure. Per-record skip lives
ONLY on the record's own row (`skipItem`, the ✕ in `_dock-step.blade.php`).
It was the other way round once: the footer read "Create"/"Discard" and resolved
ONE record per click, so a user who clicked Discard to dismiss the card silently
rejected the record under the cursor. That is how a proposed contact was skipped
while the card still reported success. Never put a per-record decision back in
the footer, and never let the footer label omit the count.
The skip ✕ is hover-revealed on `sm:` and up but ALWAYS visible below it: touch
has no hover, and an invisible skip control is the same defect again.

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
