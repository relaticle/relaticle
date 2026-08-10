# Chat Self-Host Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a timed-out/failed self-host chat turn coherent (never lose the user's message, always show a clear failure note, never leave a ghost proposal), and harden two smaller review findings — all inside the current PR.

**Architecture:** All finding-#1 behavior lives in `ProcessChatMessage::failed()` (the failure path only); the verified happy path is untouched. Findings #2/#3 are one-guard changes in `ChatModelsCommand` and `AiModelResolver`.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4, laravel/ai, Horizon/Redis queue, Pest 4, PostgreSQL.

## Global Constraints

- PostgreSQL only — no SQLite/MySQL branches; migrations `up()` only (no schema changes in this plan).
- All user-facing strings wrapped in `__()` (enforced by custom PHPStan rules).
- Writes in this job use the existing in-job `DB::table('agent_conversation_messages')` query-builder pattern (not Eloquent) — consistent with `persistUserDocument()`/`materializeAssistantDocument()` already in the file; this does not trip `EloquentWriteOutsideActionRule`.
- Tests live under `tests/Feature/Chat/` (Testing Trophy; no `tests/Unit`). Use `it(...)`, `User::factory()->withPersonalTeam()`, `resolve(...)`, `$this->artisan(...)`. Add `mutates(...)` for covered classes.
- Pre-commit gates (run in order): `vendor/bin/pint --dirty --format agent` → `vendor/bin/rector --dry-run` → `vendor/bin/phpstan analyse` → `composer test:type-coverage` (must stay 100%) → `php artisan test --compact --filter=...`.
- Do NOT include AI attribution in commits.

Message-row shape written by the ConversationStore (match it when inserting):
`id` (ULID string), `conversation_id`, `user_id` (string), `agent` = `Relaticle\Chat\Agents\CrmAssistant::class`, `role` (`user`|`assistant`), `content` (plain text), `attachments`/`tool_calls`/`tool_results`/`meta` (JSON; `[]` default), `document` (TipTap JSON), `created_at`/`updated_at`. UI orders by `created_at` then `id` (ULIDs are monotonic).

---

### Task 1: Safe `autoPick()` fallback (finding #3)

**Files:**
- Modify: `packages/Chat/src/Services/AiModelResolver.php` (the `autoPick()` return tail)
- Test: `tests/Feature/Chat/AiModelResolverTest.php`

**Interfaces:**
- Consumes: `ModelRegistry::find(string): ?ModelDescriptor`, `ModelRegistry::all(): list<ModelDescriptor>`, `ModelRegistry::autoChain(): list<ModelDescriptor>`.
- Produces: no signature change; `AiModelResolver::resolve()` now throws `RuntimeException` instead of a TypeError when the catalog is degenerate.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/AiModelResolverTest.php`:

```php
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\ModelRegistry;

it('throws a clear error when no chat model is configured', function (): void {
    config([
        'chat.models' => [],
        'chat.auto_chain' => [],
        'chat.self_hosted' => ['url' => null, 'key' => '', 'models' => null],
    ]);

    $resolver = new AiModelResolver(new ModelRegistry());
    $user = User::factory()->withPersonalTeam()->create();

    expect(fn (): array => $resolver->resolve($user))
        ->toThrow(RuntimeException::class, 'No chat model is configured');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter='throws a clear error when no chat model is configured'`
Expected: FAIL — currently `$chain[0]` on an empty array yields `null`, causing a `TypeError` ("must be of type ModelDescriptor, null returned"), not the expected `RuntimeException` message.

- [ ] **Step 3: Implement the safe fallback**

In `packages/Chat/src/Services/AiModelResolver.php`, replace the final return of `autoPick()`:

```php
        return $this->registry->find('claude-sonnet') ?? $chain[0];
```

with:

```php
        return $this->registry->find('claude-sonnet')
            ?? $chain[0]
            ?? $this->registry->all()[0]
            ?? throw new \RuntimeException('No chat model is configured; set at least one provider in config/chat.php.');
```

(The `??` operator reads undefined array offsets without warnings, so `$chain[0]` / `all()[0]` are safe here.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter='throws a clear error when no chat model is configured'`
Expected: PASS. Also run the full resolver file to prove no regression: `php artisan test --compact tests/Feature/Chat/AiModelResolverTest.php` — all green.

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/src/Services/AiModelResolver.php tests/Feature/Chat/AiModelResolverTest.php
git commit -m "fix(chat): fail loudly instead of TypeError on empty model catalog"
```

---

### Task 2: Guard `chat:models --probe` to self-hosted models (finding #2)

**Files:**
- Modify: `packages/Chat/src/Commands/ChatModelsCommand.php` (`probe()` method)
- Test: `tests/Feature/Chat/ChatModelsCommandTest.php`

**Interfaces:**
- Consumes: `ModelDescriptor::$selfHosted` (bool), `ModelDescriptor::$provider` (?string).
- Produces: no signature change; `probe()` returns `self::FAILURE` with a friendly line for non-self-hosted ids and never builds an HTTP request for them.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/ChatModelsCommandTest.php`:

```php
it('declines to probe a cloud model without throwing', function (): void {
    $this->artisan('chat:models', ['--probe' => 'claude-sonnet'])
        ->expectsOutputToContain('only supported for self-hosted')
        ->assertExitCode(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter='declines to probe a cloud model without throwing'`
Expected: FAIL — the command currently builds a request against an empty base URL for `anthropic` and throws an unhandled HTTP/connection exception.

- [ ] **Step 3: Add the self-hosted guard**

In `packages/Chat/src/Commands/ChatModelsCommand.php`, inside `probe()`, immediately after the existing unknown/unconfigured check (the block that returns `self::FAILURE` for `! $descriptor instanceof ModelDescriptor || $descriptor->model === null`), add:

```php
        if (! $descriptor->selfHosted) {
            $this->error("Probe is only supported for self-hosted endpoints (ollama / SELF_HOSTED_AI_*). '{$id}' runs on the {$descriptor->provider} SDK and can't be probed this way.");

            return self::FAILURE;
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Chat/ChatModelsCommandTest.php`
Expected: PASS (new test green; existing `lists the model registry` test still green).

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/src/Commands/ChatModelsCommand.php tests/Feature/Chat/ChatModelsCommandTest.php
git commit -m "fix(chat): decline chat:models --probe for cloud models instead of crashing"
```

---

### Task 3: Persist a coherent failed turn (finding #1 core)

**Files:**
- Modify: `packages/Chat/src/Jobs/ProcessChatMessage.php` (`failed()`, add `persistFailedTurn()` + `failureMessage()`)
- Test: `tests/Feature/Chat/ProcessChatMessageFailureTest.php` (new)

**Interfaces:**
- Consumes: `$this->message` (string), `$this->document` (array), `$this->conversationId` (string), `$this->user`, `$this->team`, `$this->getParser(): TipTapDocumentParser`, `$this->isRateLimited(?Throwable): bool`, `$this->broadcastSafely(object)`, `$this->resolutionKey(): string`, `PendingActionService::supersedePendingForConversation(string): array`, `CrmAssistant::class`.
- Produces: `failureMessage(?Throwable): string` (Task 4 extends it), `persistFailedTurn(?Throwable): void`. `failed()` now persists the user message + an assistant failure note and supersedes orphaned pending actions.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Chat/ProcessChatMessageFailureTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\PendingAction;

mutates(ProcessChatMessage::class);

function makeFailedTurnJob(User $user, string $conversationId): ProcessChatMessage
{
    return new ProcessChatMessage(
        user: $user,
        team: $user->currentTeam,
        message: 'Create a task titled BR-Foo',
        conversationId: $conversationId,
        resolved: ['provider' => 'ollama', 'model' => 'qwen3:8b'],
        mentions: [],
        document: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Create a task titled BR-Foo']]]]],
        turnId: (string) Str::ulid(),
    );
}

it('makes a failed turn coherent: user message, failure note, superseded proposal, one credit', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'BR failure',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A tool call created this mid-stream, then the turn died.
    DB::table('pending_actions')->insert([
        'id' => (string) Str::ulid(),
        'team_id' => $team->getKey(),
        'user_id' => (string) $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => 'create',
        'entity_type' => 'task',
        'action_data' => json_encode(['title' => 'BR-Foo']),
        'display_data' => json_encode(['title' => 'Create Task', 'summary' => 'Create task "BR-Foo"']),
        'status' => PendingActionStatus::Pending->value,
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    makeFailedTurnJob($user, $conversationId)->failed(new RuntimeException('boom'));

    $messages = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId);

    expect($messages->clone()->where('role', 'user')->where('content', 'Create a task titled BR-Foo')->exists())->toBeTrue()
        ->and($messages->clone()->where('role', 'assistant')->exists())->toBeTrue()
        ->and(PendingAction::query()->where('conversation_id', $conversationId)->value('status'))
        ->toBe(PendingActionStatus::Superseded)
        ->and(AiCreditTransaction::query()->where('team_id', $team->getKey())->sum('credits_charged'))
        ->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Chat/ProcessChatMessageFailureTest.php`
Expected: FAIL — no user/assistant rows are persisted and the pending action stays `pending` (current `failed()` only charges the credit and broadcasts).

- [ ] **Step 3: Extract `failureMessage()` and add `persistFailedTurn()`; rewrite `failed()`**

In `packages/Chat/src/Jobs/ProcessChatMessage.php`, replace the body of `failed()` (keep the `settleReservedMinimum` and `breadcrumb` calls) so it reads:

```php
    public function failed(?Throwable $exception): void
    {
        resolve(CreditService::class)->settleReservedMinimum(
            team: $this->team,
            user: $this->user,
            conversationId: $this->conversationId,
            resolutionKey: $this->resolutionKey(),
            reason: 'job_failed',
        );

        ChatTelemetry::breadcrumb('job.failed', [
            'exception' => $exception?->getMessage(),
            'class' => $exception instanceof Throwable ? $exception::class : null,
        ]);

        $this->persistFailedTurn($exception);

        resolve(PendingActionService::class)->supersedePendingForConversation($this->conversationId);

        $this->broadcastSafely(new ChatStreamFailed(
            conversationId: $this->conversationId,
            message: $this->failureMessage($exception),
        ));
    }

    private function failureMessage(?Throwable $exception): string
    {
        if ($this->isRateLimited($exception)) {
            return __('The assistant is being rate-limited. Please try again in a moment — anything you already approved was saved.');
        }

        return __('The assistant encountered an error. Please try again.');
    }

    /**
     * Make a dead turn coherent: ensure the user's message is persisted (the
     * ConversationStore flushes rows only on stream success, so a mid-stream
     * death loses them) and append a visible assistant failure note that
     * survives reload.
     */
    private function persistFailedTurn(?Throwable $exception): void
    {
        $now = now();
        $table = DB::table('agent_conversation_messages');

        $latest = $table->clone()
            ->where('conversation_id', $this->conversationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['role', 'content']);

        $storePersistedUser = $latest !== null
            && $latest->role === 'user'
            && $latest->content === $this->message;

        if (! $storePersistedUser) {
            $table->insert([
                'id' => (string) Str::ulid(),
                'conversation_id' => $this->conversationId,
                'user_id' => (string) $this->user->getKey(),
                'agent' => CrmAssistant::class,
                'role' => 'user',
                'content' => $this->message,
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'meta' => '[]',
                'document' => json_encode($this->document, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $text = $this->failureMessage($exception);
        $document = $this->getParser()->buildFromText($text, [], $this->team);

        $table->insert([
            'id' => (string) Str::ulid(),
            'conversation_id' => $this->conversationId,
            'user_id' => (string) $this->user->getKey(),
            'agent' => CrmAssistant::class,
            'role' => 'assistant',
            'content' => $text,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'meta' => json_encode(['error' => true], JSON_THROW_ON_ERROR),
            'document' => json_encode($document, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
```

Note: the two inserts share `$now`; correct ordering (user before note) is guaranteed by the monotonic ULID `id` tiebreak the UI already applies.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Chat/ProcessChatMessageFailureTest.php`
Expected: PASS (all four expectations green).

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/src/Jobs/ProcessChatMessage.php tests/Feature/Chat/ProcessChatMessageFailureTest.php
git commit -m "fix(chat): persist a coherent failure turn so a dead stream is never lost"
```

---

### Task 4: Timeout-specific failure copy (finding #1 polish)

**Files:**
- Modify: `packages/Chat/src/Jobs/ProcessChatMessage.php` (add import + `failureMessage()` timeout branch)
- Test: `tests/Feature/Chat/ProcessChatMessageFailureTest.php`

**Interfaces:**
- Consumes: `Illuminate\Queue\TimeoutExceededException`.
- Produces: `failureMessage()` returns timeout-specific copy for a `TimeoutExceededException`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Chat/ProcessChatMessageFailureTest.php`:

```php
use Illuminate\Queue\TimeoutExceededException;

it('shows timeout-specific copy when the turn times out', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'BR timeout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    makeFailedTurnJob($user, $conversationId)->failed(new TimeoutExceededException('timed out'));

    $note = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('content');

    expect($note)->toContain('respond within the time limit');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter='shows timeout-specific copy when the turn times out'`
Expected: FAIL — a `TimeoutExceededException` currently yields the generic "encountered an error" copy.

- [ ] **Step 3: Add the timeout branch**

In `packages/Chat/src/Jobs/ProcessChatMessage.php`, add the import near the other `Illuminate\Queue\Attributes` imports:

```php
use Illuminate\Queue\TimeoutExceededException;
```

Then update `failureMessage()` to branch on timeout first:

```php
    private function failureMessage(?Throwable $exception): string
    {
        if ($exception instanceof TimeoutExceededException) {
            return __('This model didn’t respond within the time limit (120s). Try a shorter prompt, or switch to a faster model.');
        }

        if ($this->isRateLimited($exception)) {
            return __('The assistant is being rate-limited. Please try again in a moment — anything you already approved was saved.');
        }

        return __('The assistant encountered an error. Please try again.');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Chat/ProcessChatMessageFailureTest.php`
Expected: PASS (both failure tests green).

- [ ] **Step 5: Commit**

```bash
git add packages/Chat/src/Jobs/ProcessChatMessage.php tests/Feature/Chat/ProcessChatMessageFailureTest.php
git commit -m "feat(chat): timeout-specific failure copy for slow self-hosted turns"
```

---

### Task 5: Full-stack verification (gates + live repro)

**Files:** none (verification only). No commit unless a gate forces a fix.

- [ ] **Step 1: Run the full changed-area suite**

Run: `php artisan test --compact tests/Feature/Chat/`
Expected: all green (includes the new failure tests + resolver + command + picker-visibility suites).

- [ ] **Step 2: Run the static + style + type gates**

Run, in order:
```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```
Expected: Pint clean, Rector 0 changes, PHPStan 0 errors, type coverage `Total: 100.0 %`. Fix inline if any fail, then re-run.

- [ ] **Step 3: Live-verify the original repro (which exception arrives, and the UI)**

Set up local Ollama and drive a slow turn (mirrors the review repro):
```bash
# .env: OLLAMA_MODEL=qwen3:8b   (revert after)
php artisan horizon   # in a background shell for this checkout
```
In the app (`https://<checkout>.test/app/<team-slug>/chats`), pick the Ollama model and send: "Create a task titled BR-timeout-check, just call the tool". Let it exceed 120s.

Confirm the Horizon log records the failing class (`grep ProcessChatMessage` + the `job.failed` breadcrumb's `class`). If it is NOT `Illuminate\Queue\TimeoutExceededException`, update the `instanceof` in `failureMessage()` to match the actual class (e.g. `MaxAttemptsExceededException`), keeping the generic branch as fallback, then re-run Task 4's test.

- [ ] **Step 4: Confirm the coherent UI on reload**

Reopen the conversation. Assert (screenshot into `.context/reviews/local/`): the user's message is present, a clear timeout note is shown, and NO proposal card lingers. Query to corroborate:
```bash
php artisan tinker --execute '$c="<conversationId>"; echo DB::table("agent_conversation_messages")->where("conversation_id",$c)->count()." msgs; pending=".Relaticle\Chat\Models\PendingAction::where("conversation_id",$c)->value("status")?->value;'
```
Expected: 2 messages (user + note); pending action `superseded`.

- [ ] **Step 5: Revert the local env change**

Remove the `OLLAMA_MODEL` line from `.env` (leave namespaced `br-*` test data). Confirm the picker reverts to the cloud-only set.

---

## Self-Review

- **Spec coverage:** P1 → Tasks 3+4 (+ live re-verify Task 5 steps 3-4); P2 → Task 2; P3 → Task 1; test plan → Tasks 1-4 tests + Task 5 gates/repro; credit-unchanged constraint honored (Task 3 keeps `settleReservedMinimum`); out-of-scope items (timeout cap, watchdog, cloud path) untouched. All covered.
- **Placeholder scan:** none — every code step shows full code and exact commands.
- **Type consistency:** `failureMessage(?Throwable): string` and `persistFailedTurn(?Throwable): void` defined in Task 3, extended in Task 4; `supersedePendingForConversation(string): array`, `getParser(): TipTapDocumentParser`, `isRateLimited(?Throwable): bool` used as they exist in the job; `PendingActionStatus::Pending`/`Superseded` and `CrmAssistant::class` used consistently.
