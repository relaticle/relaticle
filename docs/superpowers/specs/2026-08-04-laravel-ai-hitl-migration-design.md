# Adopt laravel/ai human-in-the-loop tool approval

**Date:** 2026-08-04
**Branch:** `ManukMinasyan/chore/laravel-ai-hitl-migration`
**Status:** Design approved, pending implementation plan

## Summary

`laravel/ai` v0.10.0 (2026-07-21, laravel/ai#773) shipped first-class
human-in-the-loop tool approval. Relaticle has carried a hand-rolled equivalent
since the chat assistant launched: the `pending_actions` table, the
`PendingActionService` execution layer, `ProposalEditor`, an expiry command, a
supersede mechanism, and two prompt blocks (`<superseded_proposals>`,
`<resolved_actions>`) that exist only to compensate for the fact that our
approvals cannot resume a paused turn.

This design replaces that layer with the framework's. Roughly 1,400 lines are
deleted outright and a table disappears, but the motivating argument is
**capability, not tidiness**: framework approvals resume the generation loop, so
a multi-step request stops being "approve, then type *continue*, then approve
again" and becomes a single conversation where each write is still individually
gated.

We are on v0.6.8. The feature does not exist in our vendor tree.

## Decisions taken

| Decision | Choice | Rationale |
|---|---|---|
| Scope | **Framework-native** — delete our approval plumbing, accept the behavior changes | Everything in the custom layer except display and tenant scoping is now strictly worse than the framework equivalent |
| Approval gate | Every write stays gated, always | `InteractsWithApprovals` is approval-required by default; `needsApproval()` per-call tiering is available but deliberately unused |
| Batch decisions | Keep the dock stepper, submit one `Decision::edit(['records' => $kept])` | Preserves per-item review without exploding one call into 25 |
| Auto-resume depth | Uncapped; bounded by existing `MaxSteps(15)` / `Timeout(120)` | This is the payoff of the change |
| Proposal expiry | Dropped | Guards stale `action_data`, but the tool re-reads the record at execute time under HITL |
| Transcript audit cards | Derived from conversation history | The tool controls its own return payload; no audit table needed |
| Shipping | **Two PRs** — upgrade first, migration second | The upgrade alone carries two High-impact DB migrations and a store rewrite whose failure mode is silent |
| Dependency sweep | Folded into PR 1, risky upgrades as separate commits | Seven of nine outdated packages are patch/minor; bundling costs little and bisectability is preserved commit-wise |

## What the framework provides

```php
class DeleteCompanyTool implements Approvable, Tool
{
    use InteractsWithApprovals;   // approval-required by default
}

// pause
$response->hasPendingApprovals();      // id, tool, arguments, reason

// resume — the SAME turn continues
$agent->continue($conversationId, as: $user)->stream(Decisions::from([
    'call_abc' => Decision::approve(),
    'call_def' => Decision::reject('The invoice must be retained.'),
    'call_ghi' => Decision::edit(['records' => $kept]),
]));
```

- Pause state persists in `agent_conversation_messages.approval_state`, with raw
  provider content blocks in `meta` so the paused turn replays verbatim on the
  same provider.
- Streaming emits a `tool_approval_request` event. Supported by `prompt`,
  `stream`, `queue`, `broadcast`, `broadcastNow`, `broadcastOnQueue`.
- Abandoned pauses auto-settle: dangling tool calls receive a synthetic denied
  result when the conversation moves on, so history stays replayable.
- Requires a `Conversational` agent using `RemembersConversations`. `CrmAssistant`
  already qualifies.

Two mechanics verified against the v0.10.2 source rather than the docs:

- `Decision::edit()` executes with the edited arguments and does **not** re-gate,
  so there is no approval loop (`TextGenerationLoop::resolveApprovalResults`).
- A tool that throws during a resume is caught and returned to the model as
  `The tool call failed: …`; the exception does not escape into the job.

## Mapping

| Ours | Framework | Verdict |
|---|---|---|
| `PendingActionService` — action allowlist, `_record_id`/`_model_class`/`_batch`, execute-on-approve | Tool's own `handle()` runs on resume | Delete |
| `pending_actions` table, status/operation enums, `ExpirePendingActionsCommand` | `approval_state` on the message row | Delete |
| `ProposalEditor` — mutate `action_data` pre-approval | `Decision::edit($arguments)` | Replace |
| Supersede-on-new-message, `PendingActionsSuperseded`, `<superseded_proposals>` | `settleAbandonedToolCalls()` | Delete |
| `<resolved_actions>`, `resolvedSinceLastAssistantMessage()` | Real tool results land in history | Delete |
| Retry-idempotency guard on duplicate proposals | Pause keyed by tool call id | Delete |
| Stored `display_data` | `PendingApproval` carries only id/tool/arguments/reason | Keep, rebuilt at render time |
| Per-item batch decisions | Decision granularity is per tool call | Redesigned (see below) |
| Team/user scoping, tenant context on write | — | Keep |

## Architecture

```
user message → ProcessChatMessage → agent.stream
   → write tool is gated → loop PAUSES before handle()
   → ToolApprovalRequest stream event → slim broadcast
   → ProposalCard renders from the paused message
   → user decides → job dispatched with decisions
   → agent.continue(...)->stream(Decisions::from([...]))
   → tool handle() runs the App\Actions\* call → result → model continues
   → may pause again (next card)
```

The source of truth for a pending write moves from `pending_actions` to
`agent_conversation_messages.approval_state`. No new tables.

### Tool layer, and the validation-ordering trap

`BaseWriteCreateTool`, `BaseWriteUpdateTool`, and `BaseWriteDeleteTool` become
`implements Approvable, Tool` with `use InteractsWithApprovals`. Their `handle()`
stops building proposals and executes the `App\Actions\*` call directly.

Today, validation (custom fields, permissions, batch cap, record-not-found) runs
at *proposal* time, so invalid input never produces a card — the model receives
an error and self-corrects. Under HITL, `handle()` runs only after approval, so
the naive port would show the user a card for an invalid proposal and surface the
error *after* they approved it.

Resolution: validate inside `needsApproval()`.

- Invalid request → return `false` → no pause → `handle()` runs immediately and
  returns the error payload **without writing**. Same self-correction loop as
  today; no card for garbage input.
- Valid request → return `Approval::required(...)` → the card appears.

Validation memoizes on the tool instance keyed by the `Request` tool-call id, so
it runs once per pass rather than twice.

Validation must stay deterministic across the pause. If it flips to invalid
between pause and resume (a custom field was deleted, say), the decision is still
applied, `handle()` re-validates, and the error goes to the model — no write.

### Display

Cards render from the paused call's `arguments` at render time through the
existing `ProposalDisplayBuilder` and `CustomFieldsDisplayFormatter`. Update
diffs read the *current* record, which is fresher than today's stored snapshot
and removes the possibility of `display_data` drifting from `action_data`.

`ToolApprovalRequest::toArray()` carries full arguments; a 25-record batch would
exceed the 10 KB Reverb cap. `StreamEventBroadcaster` therefore gets an explicit
slim payload for it — `{type, invocation_id, approvals: [{id, tool}]}` — and the
client asks the Livewire component to render call `id` server-side from history.
One render path serves both the live stream and a page reload.

### Batch handling

The dock keeps its stepper. Per-item choices live in Livewire component state,
not the database. On submit:

- all kept → `Decision::approve()`
- some kept → `Decision::edit(['records' => $kept])`
- none kept → `Decision::reject('User skipped all N records.')`

Each record commits in its own transaction inside `handle()`, so partial progress
still survives a mid-batch failure — the property `approveItem()` provides today.
The tool's return payload names the created records and states which the user
skipped, so the model does not re-propose them.

Accepted cost: the user no longer gets incremental "created!" feedback per item;
the batch commits when the review pass is submitted.

### Resume job, credits, tenant context

`ProcessChatMessage` takes a `TurnInput` that is either a user message or a set
of approval decisions. One path, all callers migrated, no dual old/new route.

- **Credits.** A resume is a real generation and reserves with its own `turnId`
  and resolution key.
- **Locking.** `WithoutOverlapping($conversationId)` already serializes turns; an
  approve click dispatches and returns immediately.
- **Tenant context.** Moves from `PendingActionService::approve()` into the job:
  `TenantContextService::setTenantId($team)` in try/finally before the resume.
  Without it `saveCustomFields()` iterates every tenant.
- **Retry trap.** An approved tool's result is stored *before* the model
  continues. Our rate-limit `release()`/retry path would re-prompt with the same
  decisions and hit `ApprovalMismatchException`. The job therefore inspects
  `approval_state` first: if the calls are no longer pending, it continues as a
  plain turn instead of resubmitting decisions.

### Error handling

| Failure | Behavior |
|---|---|
| Tool throws during resume | Framework returns `The tool call failed: …` to the model; job survives |
| Stale or double-clicked card | `ApprovalMismatchException` caught by the dock, card refreshed from history, no retry |
| Generation fails after the tool executed | Approval already recorded; next turn is a plain prompt, never a resubmit |

## PR 1 — the upgrade (ships alone, green on main)

### Dependency sweep

PR 1 also refreshes every outdated direct dependency, composer and npm. As of
2026-08-04:

| Package | From → To | Handling |
|---|---|---|
| `laravel/ai` | 0.6.8 → 0.10.2 | the main event, below |
| `asmit/resized-column` | 3.0.2 → 4.0.2 | **major** — own commit, constraint bump, visual check |
| `relaticle/custom-fields` | 3.5.5 → 3.6.0 | own commit — core to the write path this design depends on |
| `filament/filament` | 5.7.4 → 5.7.5 | batched |
| `livewire/livewire` | 4.3.3 → 4.3.5 | batched |
| `pestphp/pest` | 5.0.2 → 5.0.3 | batched |
| `rector/rector` | 2.5.8 → 2.6.1 | batched (dev) |
| `laravel/pint` | 1.30.0 → 1.30.3 | batched (dev) |
| `spatie/laravel-query-builder` | 7.3.0 → 7.3.1 | batched |
| npm `dompurify`, `shiki` | 3.4.12 → 3.4.13, 4.3.1 → 4.4.1 | in-range, `npm update` |

The three risk-bearing upgrades — `laravel/ai`, `asmit/resized-column`, and
`relaticle/custom-fields` — each land as their own commit so a regression stays
bisectable inside the PR. The remaining patch releases batch into one commit.

### laravel/ai 0.6.8 → 0.10.2

1. Migration: rename `user_id` → `participant_id`, add `participant_type`,
   reindex both conversation tables, backfill the `App\Models\User` morph; add
   the `approval_state` text column.
2. `SupersededAwareConversationStore`: adopt the participant signatures,
   implement `storeApprovalResults()`, and **delete our `mapRecordToMessages()`**.
   The parent's `reconstructToolTurn()` — pause detection, provider content
   blocks, cross-row resolved-call tracking — is what makes resume work. We keep
   only the `whereNull('superseded_at')` filter. Upstream has no query hook; if
   the copied method proves annoying to maintain, propose a `messagesQuery()`
   extension point to laravel/ai.
3. 0.9 sweep: `providerOptions()` → `withProviderOptions()`, `TextGateway`
   removal, and changed provider default models checked against `ModelRegistry`.
4. Expect test churn. 0.9 runs faked responses through the real
   `TextGenerationLoop`: one extra assistant message after a faked tool call, one
   extra `ToolCall` stream event per call, and faking an unregistered tool now
   throws `NoSuchToolException`.

## PR 2 — the migration

Everything above: approvable tools, render-time display, batch decisions, the
resume job, prompt cleanup, and the deletions.

## Testing

- Pause assertions via `AgentResponse::fakeWithPendingApprovals()`.
- Feature tests drive the dock end to end: pause → decide → assert the record was
  written **and** the tool result landed in conversation history.
- One browser test for the resumed multi-step flow on the real Redis queue with
  Horizon and Reverb running. A sync queue hides exactly the ordering and
  duplicate-proposal bugs this change can introduce.
- Retained and re-pointed: tenant-scope, custom-fields bridge, batch, and
  duplicate-warning coverage.
- Deleted with their mechanisms: allowlist, expiry, supersede, and
  retry-idempotency tests.

## Deleted

`PendingActionService` (762), `ProposalEditor` (392), `PendingAction` model
(111), `PendingActionStatus` and `PendingActionOperation` (50),
`ExpirePendingActionsCommand` (24), `PendingActionsSuperseded` (45), the
`pending_actions` table and its migration, and
`chat.pending_action_expiry_minutes`. Approximately 1,400 lines.

Out of scope: the `WriteGuard` enum reads like part of this machinery but is not
— it is model metadata with no remaining enforcement consumer, only printed by
`ChatModelsCommand`. Its fate is a separate question.

Substantially trimmed rather than deleted: `ProposalCard` (execution and batch
bookkeeping go, stepper and display stay), the `CrmAssistant` prompt
(`agent_should_stop`, `<superseded_proposals>`, `<resolved_actions>` all go),
`ProcessChatMessage`, and the `pending_action` handling in
`chat-interface.blade.php`.

## Behavior changes users will notice

| | Before | After |
|---|---|---|
| Write without approval | impossible | impossible |
| After approving | agent already gone; user types "continue" | write runs inside the resumed turn, model keeps going |
| Next step of a multi-step request | user must ask | model proposes it, pausing again for its own card |
| Rejecting | card marked rejected, model never learns why | reason goes back to the model, which responds |
| Several writes at once | one per turn, hard stop | may pause together for a single review pass |
| Batch review | each item commits on click | items commit when the review pass is submitted |
