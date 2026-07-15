# Chat self-host polish — graceful turn failure + probe/resolver hardening

**Date:** 2026-07-16
**Branch:** `ManukMinasyan/self-host-ollama-config-v4`
**Scope:** Polish the self-host (Ollama / OpenAI-compatible) chat feature to merge-ready by
resolving the three findings from the pre-merge business review. All fixes land **inside this PR**.

## Background

The PR replaces the `AiModel` enum with a config-driven model registry and adds self-hosted model
support. A Tier-3 business review verified the cloud SaaS path is behavior-preserving and safe, and the
self-host happy path works, but surfaced three issues. Evidence lives in
`.context/reviews/local/REVIEW.md`.

The self-host feature explicitly targets slow "thinking" models (qwen3-class). Those routinely exceed
the pre-existing `#[Timeout(120)]` on `ProcessChatMessage`, exposing a latent failure mode.

## Problem statements

### P1 — A timed-out (or otherwise mid-stream-killed) turn is silently lost (MEDIUM)

Root cause: `ProcessChatMessage::handle()` persists the turn's messages only when the stream **completes**.
laravel/ai's `ConversationStore` flushes the user + assistant rows at turn-end, and the app's
`persistUserDocument()` / `materializeAssistantDocument()` run inside the `->then()` success callback. A
`create_task`-style tool call, however, creates its `PendingAction` **during** streaming.

So when the 120s timeout kills the job mid-stream:
- `->then()` never runs → **no user or assistant message row is persisted** → the conversation reopens as
  a blank "How can I help?" (the user's own message vanishes).
- The tool call's `PendingAction` survives as an orphaned `pending` row with **no visible proposal card**
  (it auto-expires ~15 min later via `ExpirePendingActionsCommand`).
- `failed()` charges the 1-credit reserved minimum.
- `failed()` only *broadcasts* a transient `ChatStreamFailed` (lost on reload); nothing is persisted.

This is not ollama-specific — any mid-stream job death (cloud OOM/crash) loses the turn identically. Slow
local models just make it routine. **Not reachable on the cloud SaaS prod path** (cloud turns finish in
seconds; prod has no self-hosted env), so it is not a production blocker — it is a quality gap in the
newly-shipped self-host feature.

Verified: `ProcessChatMessage … 2m FAIL` in the Horizon log (vs a Sonnet turn `4s DONE`); 0 rows in
`agent_conversation_messages`; orphaned `pending` PendingAction; empty conversation on reload; a `/no_think`
fast ollama turn works perfectly by contrast.

### P2 — `chat:models --probe=<cloud-model>` throws a raw exception (LOW)

`ChatModelsCommand::probe()` derives its HTTP endpoint from the provider's `url`. Cloud providers such as
`anthropic` have no `url`, so the command builds an invalid `/api/chat` request and throws an unhandled
exception / stack trace. The probe is a self-hosted diagnostic; it should decline cloud models cleanly.
`--probe=ollama`, `--probe=selfhosted:*`, and `--probe=<unknown>` already behave correctly.

### P3 — `AiModelResolver::autoPick()` can TypeError on an emptied catalog (LATENT / informational)

The tail `return $this->registry->find('claude-sonnet') ?? $chain[0];` indexes `$chain[0]` on a possibly
empty array, and a `: ModelDescriptor` return type turns a `null` into a fatal TypeError. Only reachable if
the model catalog is deliberately emptied (config tampering). Defensive hardening only.

## Chosen approach

**Approach A — graceful failure, keep the 120s cap.** Fix the symptom (lost/incoherent turn) rather than
raise the ceiling. Rationale: it fixes the actual defect with a tightly-contained change confined to the
**failure path**, hardens *every* mid-stream death (not just ollama), and avoids the Horizon-worker
throughput cost of longer timeouts. The 120s cap and the client watchdog (125s) are unchanged.

Rejected: raising / per-provider-configuring the server timeout (bigger behavioral lever, throughput
tradeoff, and a truly stuck model still needs the graceful path). Rejected: doc-only (leaves the bad UX).

## Design

### D1 — Make a dead turn coherent (`ProcessChatMessage::failed()`)

The happy path is **not touched**. All new behavior is in `failed(?Throwable $exception)`, which already
fires on the timeout (it currently charges the credit). `failed()` gains three responsibilities, in order:

1. **Backfill the user message (idempotent).** Determine whether the `ConversationStore` already persisted
   this turn's user row. If not (the timeout case — zero rows), insert a `role='user'` row into
   `agent_conversation_messages` from `$this->message` (`content`) and `$this->document` (`document`),
   with `conversation_id`, `user_id`, and timestamps — matching the shape the store writes. Idempotency:
   only insert when this turn's user message is not already the latest user row for the conversation.
2. **Persist a visible failure note.** Insert a `role='assistant'` row carrying a user-facing message so it
   survives reload (the existing `ChatStreamFailed` broadcast stays, for the live client). Message text
   branches on failure type, reusing the branching already in `failed()`:
   - timeout → *"This model didn't respond within the time limit (120s). Try a shorter prompt, or switch to
     a faster model."*
   - rate-limited → existing rate-limit copy.
   - other → existing generic-error copy.
   All copy wrapped in `__()`. Build the `document` column from the text via the existing parser
   (`materializeAssistantDocument` uses `getParser()->buildFromText(...)`).
3. **Resolve the orphaned proposal.** Supersede any `pending` PendingActions created during the dead turn,
   reusing `PendingActionService::supersedePendingForConversation($this->conversationId)` (the same call the
   job already makes at turn *start*). No ghost proposal card lingers; the user retries.

**Credit:** unchanged — keep the existing 1-credit `settleReservedMinimum` charge (the model spent compute;
refunding opens a minor "always-timeout = free" vector, and self-hosted compute is the operator's own). The
charge is now paired with an honest, visible failure message.

**Timeout detection.** `failed()` distinguishes a timeout from other failures to pick copy #2. Laravel
surfaces a queue timeout as a recognizable throwable (e.g. `Illuminate\Queue\TimeoutExceededException` /
max-attempts); the plan step will confirm the exact type on this stack and match on it, falling back to the
generic-error copy when unknown.

**Write layer.** `ProcessChatMessage` already performs `DB::table('agent_conversation_messages')`
inserts/updates directly (`persistUserDocument`, `persistMentions`, `materializeAssistantDocument`); the
backfill/note inserts follow that established in-job pattern for consistency. If the implementation prefers,
the two inserts may be extracted into a small `PersistTurnFailure` action — decided at plan time; either way
no new abstraction is introduced beyond what the job already does.

### D2 — Guard `chat:models --probe` to self-hosted models

In `ChatModelsCommand::probe()`, before building the HTTP request, check the descriptor. If
`! $descriptor->selfHosted`, print:
*"Probe is only supported for self-hosted endpoints (ollama / SELF_HOSTED_AI_*). '{id}' runs on the
{provider} SDK and can't be probed this way."* and return `self::FAILURE` (no request built). Self-hosted
models (`ollama`, `selfhosted:*`) keep the current probe behavior.

### D3 — Safe `autoPick()` fallback

Replace `?? $chain[0]` with a fallback chain that cannot return `null` from a `: ModelDescriptor` method:
`find('claude-sonnet') ?? ($chain[0] ?? $this->registry->all()[0] ?? throw new RuntimeException('No chat
model is configured — set at least one provider in config/chat.php.'))`. A clear domain exception replaces a
raw TypeError. No behavior change with shipped config.

## Testing

Per project conventions (Testing Trophy; tests through real entry points; no `tests/Unit`).

- **D1 (Feature, `tests/Feature/Chat/`):** drive a turn that leaves an in-flight `pending` action, invoke
  the job's `failed()` path, and assert: (a) a `role='user'` row exists with the sent content; (b) a
  `role='assistant'` timeout/error note is persisted; (c) the pending action is superseded
  (`superseded_at` set); (d) exactly the 1-credit minimum is charged. Add a timeout-typed case and a
  generic-error case to cover the copy branch.
- **D1 live re-verify:** re-run the original browser repro (slow ollama turn via `OLLAMA_MODEL=qwen3:8b`) →
  reopen the conversation → assert the user message + failure note render and no ghost proposal card
  appears. Evidence screenshot into `.context/reviews/local/`.
- **D2:** extend `tests/Feature/Chat/ChatModelsCommandTest.php` — `--probe=claude-sonnet` exits with the
  "self-hosted only" line and no exception; `--probe=ollama` behavior unchanged.
- **D3:** extend `tests/Feature/Chat/AiModelResolverTest.php` — an emptied catalog yields the clear
  `RuntimeException`, not a TypeError.
- **Gates:** changed-area suite + `phpstan analyse` (0 new errors) + `pint --dirty` + `test:type-coverage`
  (stays 100%) + `rector --dry-run` (0), matching the review's gate run.

## Out of scope

- Raising or per-provider-configuring the `#[Timeout(120)]` server cap (Approach B).
- Any change to the cloud SaaS picker / plan-gating / credit math (verified behavior-preserving).
- Changing the 125s client watchdog.
- Surfacing (rather than superseding) a timed-out turn's proposed action for approval — the retry path is
  simpler and avoids a proposal card with no explanatory context.

## Success criteria

1. A self-host turn that exceeds 120s reopens showing the user's message + a clear "timed out" note, with no
   ghost proposal card and no permanently-orphaned pending action.
2. `chat:models --probe=<cloud-model>` prints a friendly decline and never throws.
3. `AiModelResolver` never TypeErrors on a degenerate catalog.
4. The verified cloud SaaS path is unchanged; all quality gates stay green (type-coverage 100%).
