---
paths:
  - 'packages/Chat/**'
---

# Chat

## Chat always passes a resolved provider, so #[Provider] failover lists never run
AiModelResolver::resolve() returns one concrete provider, ChatController hands it to ProcessChatMessage, and the job calls $agent->stream(provider: ...). laravel/ai reads the #[Provider] attribute ONLY when the prompt's provider argument is null (Promptable::getProvidersAndModels), so a provider LIST on CrmAssistant would never fail over. To actually get provider failover, stream() has to receive the array - a product decision, because the fallback provider runs a different model.
Two related limits: StreamErrorException is not a FailoverableException, and StreamableAgentResponse::hasYielded() suppresses failover once any event reached the consumer, so a mid-stream failure cannot fail over either.
Provider failover therefore lives in the job, not the attribute. When resolution came from `auto` (resolve() tags it `source`), nothing has streamed yet, and transient retries are exhausted, ProcessChatMessage re-dispatches itself once against the next entry in `ModelRegistry::autoChain()` (failoverDepth bounds it to one hop). An EXPLICIT model pick never fails over: users pay per-model credit multipliers, so a silent swap is worse than an error.

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
user, NOT written"). Without that line the model reports values it never
set, the same defect class `skippedItemLabels()` exists to prevent.

## Duplicates are caught by the REAL field rules, which need the tenant bound
The card-level "Heads up: already proposed a moment ago" warning was removed
(user-directed, 2026-08). Duplicate values (a person email above all) are
rejected by the custom-fields package's own unique rules running inside
`CustomFieldsRequestValidator` at proposal time. Never re-implement them as
bespoke lookups in a tool; they drift, and any escape hatch they offer is a
lie the real rule blocks later. Two load-bearing pieces:
- `ProcessChatMessage::bindAuth()` binds `TenantContextService` for the whole
  job (restored in `releaseAuth()`). Without it the unique rules silently
  no-op in the queued job. That is the exact bug that let chat create
  duplicate emails while the panel form rejected them. Any new chat entry point that
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

## Never put a sampling attribute on an Anthropic agent
Anthropic removed `temperature`, `top_p` and `top_k` on Opus 4.7 and every model
released after it (Opus 4.8/5, Sonnet 5, Fable 5). A request carrying one is rejected
with a flat 400 and the body `` `temperature` is deprecated for this model ``. Sonnet
4.6 and earlier still accept them, which is what makes this bite so unevenly:
`#[Temperature(0.3)]` on `CrmAssistant` broke 100% of Opus 4.7 turns in production
(Sentry RELATICLE-CRM-6D) while Sonnet, the auto-chain default, kept working, so it
surfaced only when a Pro user picked Opus by hand. An explicit model pick never fails
over, so those users just saw "The assistant encountered an error" on every retry.
The replacement dial is `output_config.effort` (low/medium/high/xhigh/max), sent from
`CrmAssistant::anthropicEffort()` via `providerOptions()` and configured by
`chat.anthropic_effort`. It matters more than temperature did: from Opus 5 onward
thinking is on by default, so a turn spends output tokens before writing a word.
An unrecognised value sends nothing rather than forwarding the typo to the provider.
`AnthropicPromptCachingTest` asserts the built body carries no sampling key; keep that
green, and never reintroduce `#[Temperature]`/`#[TopP]` on an agent that can run on a
current Anthropic model. `ConversationTitler` and `NextStepSuggester` still carry one
only because `#[UseCheapestModel]` pins them to Haiku 4.5, which still accepts it.

## Retiring a model id silently re-prices it at 1x
`ModelRegistry::multiplierFor()` matches on the `model` string and returns 1.0 when
nothing matches, so deleting an entry outright re-prices every settlement that still
names it. Disable the entry instead of deleting it: a disabled entry keeps its
multiplier and its price, stays out of the picker and out of Auto, and keeps
`AiSpendStatsWidget` able to price historical `ai_credit_transactions`. The catalog is
runtime-editable now, so treat the fail-open default as a live hazard: a typo'd `model`
would undercharge every turn on that entry, silently.

## One catalog list: cloud in settings, self-hosted in env, capabilities probed
`chat.models` is the whole catalog. Each entry carries its own Auto membership (`auto`
plus list order), its own price (`input_per_mtok`/`output_per_mtok`) and its own
`capabilities`. There is no `chat.auto_chain` and no `chat.model_costs`; folding them in
is what makes a dangling Auto id and an unpriced model impossible rather than unlikely.
Edited from the sysadmin panel at /sysadmin/ai-models, seeded from `config/chat.php`.

- **Self-hosted models are never stored.** `ModelRegistry::customFromConfig()` builds
  them from `OLLAMA_MODEL` and `SELF_HOSTED_AI_*` on every read, so `.env` stays their
  only source and an operator does not need the panel. They are also appended to
  `autoChain()`, because that tail is what makes Auto work on an install with no cloud
  keys at all.
- **`supports_tools` and `write_guard` are measured, never typed.** `ModelProbe` sends
  one real step carrying the full CrmAssistant surface and reports what the provider
  accepted. An unprobed or rejected entry gets `prompt`, never `api`: claiming the API
  refuses parallel tool calls when it may not is the unsafe direction.
- **`models[].model` is the entry's id**, not just the tag sent to the provider: it is what
  the picker stores in `localStorage['chat:model']`, what `?model=` carries, and what
  `ai_credit_transactions.model` already records. So it is unique across the catalog
  (`distinct()` on the panel's Select, and `ratesFor()` would otherwise let a retired row
  price an active one), and retagging a row makes it a different model. Every user who
  had picked the old tag silently lands back on Auto, which the client does by sanitizing
  its stored pick against the live options. That is the accepted cost of having no
  operator-typed id to mistype or rename; add a succession pointer if it ever stops being
  acceptable. `users.ai_preferences['default_model']` is read by `AiModelResolver` but has
  never been written by any code path. Check before assuming a preference is stored.
- A disabled entry stays only so `AiSpendStatsWidget` can price historical
  `ai_credit_transactions` rows that still name its model. `ModelRegistry::ratesFor()`
  therefore reads disabled entries too, while `all()` skips them.
- The settings push must stay the first line of `ChatServiceProvider::boot()`
  (`ModelRegistry` is a singleton that reads config once), wrapped in `rescue()` (the
  table does not exist on a fresh install's first migrate), and a save must dispatch
  `queue:restart` or a worker keeps serving the catalog it booted with.

## A field missing from a repeater schema is dropped on save
The catalog's credits and prices are edited through a per-row gear modal
(`extraItemActions`) rather than as table columns, but they must still be declared in
the repeater's schema as `Hidden::make()`. Filament builds state from the schema, so a
key that is only in the stored array and not in the schema silently disappears the next
time the form is saved, taking every price on every model with it in one click. Filament renders
`Hidden` components inside a table repeater without giving them a column, which is
exactly what makes this work. `ManageAiSettingsTest` pins it; do not "tidy away" those
three Hidden fields.

## A Filament Select bound to live provider options must merge the current value
`ManageAiSettings::modelOptions()` adds the entry's stored model to the option list. A
Select silently drops a value that is not among its options, so without the merge every
entry goes invalid the moment a provider is unreachable or its key is absent on this
install. That is exactly what happened the first time this page was built. The same
page must also coerce form state to primitives before writing (`normalizeModels()`): a
Select bound to an enum hands back a `Plan` instance, and pint's `strict_comparison`
fixer will rewrite any `==` you reach for, so cast instead of compare.

## spatie/laravel-settings cannot parse the docblocks PHPStan wants
It resolves each property's `@var` through phpdocumentor/type-resolver, which throws
`Unexpected token "", expected '>'` on an `array{...}` shape and
`CouldNotResolveDocblockType: mixed` on an `array<string, mixed>` leaf. PHPStan level 7
meanwhile demands an iterable value type. Use `@phpstan-var` on settings properties:
PHPStan reads it, phpdocumentor does not see a `@var` tag at all, and both are happy.

## A Filament Select bound to an enum returns the enum, not its value
`Select::make('min_plan')->options(Plan::class)` puts a `Plan` instance in form state.
Written straight into the settings row it JSON-encodes as an object and then throws
`Plan::from(): Argument #1 must be of type string, App\Enums\Plan given` inside
`ModelDescriptor::fromConfig()`, which type-hints the primitives a PHP config file used
to supply. `ManageAiSettings::normalizeModels()` coerces at that one write boundary;
a test that fills the form with plain strings does NOT cover this path, so exercise it
through the panel.

## ModelProbe must run the assistant's own generation options
The probe (`ModelProbe` + `ModelProbeAgent`) sends one real step carrying CrmAssistant's
full surface so the provider can reject it before a bad model reaches the catalog. The
trap: `TextGenerationOptions::forAgent()` reflects the agent that RUNS the prompt, so the
first version delegated instructions, tools and providerOptions but not sampling
parameters, so it passed with `#[Temperature(0.3)]` reintroduced. It did not catch the
bug it was built for. `ModelProbeAgent::temperature()`/`topP()` now delegate. Verified by
reintroducing the attribute and watching the probe go red; keep that property.
`tool_choice: none` rides on the `#[ToolChoice]` attribute rather than `providerOptions()`
because the gateway array_merges providerOptions last and would overwrite the guard, and
because the attribute lets each gateway map "none" to its own spelling.
Only NEW `(provider, model)` pairings are probed. Verifying the whole catalog on every
save would let one provider with a stale key block edits to unrelated rows.

## Register every package settings class in config/settings.php
spatie/laravel-settings auto-discovers `app_path('Settings')` only, and every settings
class here lives in `packages/<Name>/src/Settings`. An unregistered one still WORKS,
because the container autowires the concrete class and `Settings::__get()` loads it
lazily, so nothing fails loudly and the omission survives review. What it costs: no `scoped`
binding, so every `resolve()` is a fresh instance and its own query (three per
`ManageAiSettings::save()`), and `SETTINGS_CACHE_ENABLED` can never reach the class
while `ChatServiceProvider::boot()` reads it on every request, job and command.
`config/settings.php` overrides only the `settings` key; `mergeConfigFrom` keeps the
package's `migrations_paths`, `default_repository` and the rest as the base, so do not
copy the whole vendor file in. `ChatCatalogShapeTest` pins the registration.

## available() is "servable here", offered() is "what the product sells"
`ModelRegistry::available()` requires `filled(ai.providers.<p>.key)`, so it answers a
question about THIS INSTALL. The public `/pricing` and `/ai` pages must never route
their model lists through it: the derived strings interpolate into sentences, so a key
that is absent or rotated renders "Every plan can use  and any self-hosted model you
connect yourself" and "1x for ; 1.5x for ; 3x for )" on a public page. The whole test
suite is blind to it because `phpunit.xml` sets a fake key for every provider. Use
`offered()` there (enabled, tool-capable, non-self-hosted, key-independent), which
still cannot name a model `AiModelResolver::pick()` would refuse. `PricingPageTest`
pins it with the keys nulled.

## Never cache what you failed to learn
`ProviderModelCatalog` caches only a non-empty listing; `ModelProbe` caches only a pass.
An empty list or a rejection is what we could not learn, not what the provider offers,
and a 24h `Cache::remember` over a vendor's bad minute blanks the model picker for a day.

## composer test scripts need disableProcessTimeout
Composer kills a script at 300s by default. `test:pest` had the guard and its siblings
did not, so `composer test:pest:full`, the merge gate CLAUDE.md tells you to run,
started aborting on the clock rather than on a test once the suite crossed five minutes.
Every script that can run the whole suite or a slow subset now carries it. Add it to any
new one.
