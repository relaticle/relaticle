# Server-owned first message handoff (PRG pattern)

**Status:** Draft
**Date:** 2026-05-07
**Branch (target):** `feat/dashboard-chat`
**Sequenced after:** `2026-05-07-tiptap-mention-editor-and-storage-design.md` (depends on `document` being a known server-side concept)

## Goal

First send (from any page, with no existing `conversation_id`) creates the conversation server-side, queues the user message, and the URL contains only the new conversation ID. Reload, back-button, and bookmark are all idempotent — they re-load the existing conversation rather than re-firing the message.

This replaces two brittle patterns:

1. **Dashboard:** `location.href = '/chats?message=hi&model=auto'`. URL is sticky — refresh re-fires the message, back-button re-fires it, copy-paste leaks the prompt.
2. **Conversation page first send:** frontend mints UUID7 client-side, calls `POST /chat/conversations/init` to claim the row, then `POST /chat/send` with the message. Two requests, three race surfaces (init/send/stream-start), draft-phantom rows possible if the user navigates between init and send.

…with one server-owned pattern that matches what every mainstream AI chat does (ChatGPT, Claude.ai, Gemini, Perplexity all use POST + redirect to `/{conversation_id}`):

1. Frontend POSTs `{document, model?}` to `POST /chat/conversations`
2. Server creates the conversation + the user message + dispatches `ProcessChatMessage` in one transaction
3. Server returns `{conversation_id}`
4. Frontend either `location.href = '/chats/' + id` (dashboard) or `history.replaceState(...)` (conversation page)

## Non-goals

- Server-side idempotency key (request hash → de-duplication). Only relevant if double-submit becomes a real problem; existing client-side `submitting`/`isStreaming` locks are sufficient.
- Pre-creating a conversation on dashboard mount so the URL changes optimistically before the server roundtrip. Premature optimization.
- Combining `POST /chat/conversations` and `POST /chat/send` into a single REST endpoint at `/chat/conversations/{id?}/messages`. Possible cleanup later; doesn't affect external consumers.

## Architecture

**One new endpoint.** `POST /chat/conversations` accepts `{document, model?}`, performs everything currently split between `/chat/conversations/init` and the "no conversation_id" branch of `/chat/send` in one server-side transaction:

1. Validate document, parse via `TipTapDocumentParser` (from spec #2)
2. Resolve model via `AiModelResolver`
3. Reserve credit via `CreditService`
4. Insert conversation row (title from first message)
5. Insert user message row (with `document` jsonb, `content` plain text, mention join rows)
6. Commit
7. Dispatch `ProcessChatMessage` job (post-commit, so the queue worker sees the rows)
8. Return `{conversation_id}` JSON

**Both inputs use it.** Dashboard `submit()` and conversation-page `sendMessage()` (when `conversationId === null`) both POST to this endpoint. Dashboard does a full redirect; conversation page does `history.replaceState` to avoid a page nav (already mounted, just rebinds to the new ID).

**Existing `/chat/send` keeps handling subsequent sends** in an established conversation. Its "no conversation_id" branch becomes dead code; the validator now requires `conversation_id` and returns 422 if absent.

**`/chat/conversations/init` endpoint is deleted** along with its route, controller method, and the F-002 auto-title backfill block in `ChatController::send`. All three become unreachable after the migration. Alpha — no other consumers to migrate.

## Components

| Unit | Status | Responsibility |
|---|---|---|
| `ChatController::createConversation` | New method | POST `/chat/conversations` — single transactional flow described above |
| `ChatController::send` | Modified | Validator now requires `conversation_id`; the "no conversation_id" branch is removed |
| `ChatController::init` | Deleted | Method and route removed |
| `ChatController::send` auto-title backfill block | Deleted | Title is set at conversation creation time by the new endpoint; backfill is no longer reachable |
| `routes/chat.php` | Modified | Add `POST /chat/conversations`. Remove `POST /chat/conversations/init`. |
| `dashboardChatInput()` Alpine factory | Modified | `submit()` POSTs to `/chat/conversations`, then `location.href = '/chats/' + id` |
| `chatInterface()` Alpine factory | Modified | When `conversationId === null` at submit time, POSTs to `/chat/conversations`, then `history.replaceState`, then continues normal Reverb subscription. When non-null, current `/chat/send` flow unchanged |
| `App\Filament\Pages\ChatConversation::mount()` | Modified | Drops the `?message=` and `?model=` query-param parsing — they are no longer sent |
| `tests/Feature/Chat/AutoTitleAfterInitTest.php` | Modified or deleted | Either rewritten to test title-at-creation via the new endpoint, or deleted because the test now covers two endpoints — keep one path |

## Wire contract

**Request** — `POST /chat/conversations`:

```json
{
  "document": { ... TipTap JSON document (per spec #2) ... },
  "model": "claude-sonnet"
}
```

`model` is optional and falls back to the user's `ai_preferences['default_model']` per `AiModelResolver`.

**Response** — `200 OK`:

```json
{
  "conversation_id": "01k..."
}
```

**Error responses** (parity with `/chat/send`):

- `422` — invalid `document`, malformed JSON, no text content (empty editor)
- `402` — insufficient credit; response shape identical to `/chat/send`'s 402 (used by the credit-upgrade UI today)
- `403` — user has no `currentTeam`
- `500` — unexpected server failure (transaction rolls back; nothing partial committed)

## Server flow

`App\Http\Controllers\Chat\ChatController::createConversation` (sketch — exact namespacing verified at write time):

```php
public function createConversation(Request $request, TipTapDocumentParser $parser): JsonResponse
{
    $validated = $request->validate([
        'document' => ['required', 'array'],
        'model' => ['nullable', 'string'],
    ]);

    /** @var User $user */
    $user = $request->user();
    $team = $user->currentTeam;
    abort_if($team === null, 403);

    $resolved = $this->modelResolver->resolve($user, $validated['model'] ?? null);

    $balance = $this->creditService->reserve($team, $resolved['model'] ?? '');
    if ($balance['status'] === 'insufficient') {
        return response()->json([
            'error' => 'insufficient_credits',
            'reset_at' => $balance['reset_at'] ?? null,
            'upgrade_url' => url('/app/billing'),
        ], 402);
    }

    $parsed = $parser->parse($validated['document'], team: $team);

    if (trim($parsed['text']) === '') {
        throw ValidationException::withMessages(['document' => 'Message is empty.']);
    }

    $conversationId = (string) Str::uuid7();

    DB::transaction(function () use ($conversationId, $user, $team, $parsed, $validated): void {
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => (string) $user->getKey(),
            'team_id' => $team->getKey(),
            'title' => TitleSanitizer::clean($parsed['text']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createUserMessage(
            conversationId: $conversationId,
            text: $parsed['text'],
            document: $validated['document'],
            mentions: $parsed['mentions'],
        );
    });

    dispatch(new ProcessChatMessage(
        user: $user,
        team: $team,
        message: $parsed['text'],
        conversationId: $conversationId,
        resolved: $resolved,
        mentions: $parsed['mentions'],
    ));

    return response()->json(['conversation_id' => $conversationId]);
}
```

**Atomicity guarantees:**

- Conversation row + user message row + mention join rows commit atomically. If any insert fails, the transaction rolls back and the request returns 500 with no partial state.
- `ProcessChatMessage` dispatch happens **after** the transaction commits — the queue worker can never pick up a job before its rows are visible.
- `CreditService::reserve` runs before the transaction. If credit is insufficient, no DB writes happen; user gets 402.

**Title-at-creation** replaces the F-002 backfill flow. `TitleSanitizer::clean($parsed['text'])` produces the same title that the existing send endpoint would set, just at insert time instead of via post-insert UPDATE.

## Client flow

### Dashboard (`dashboardChatInput()` factory)

```js
async submit() {
    if (this.editor.isEmpty() || this.submitting) return;
    this.submitting = true;
    this.error = null;

    try {
        const res = await fetch('/chat/conversations', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({
                document: this.editor.getDocument(),
                model: this.selectedModel !== 'auto' ? this.selectedModel : undefined,
            }),
        });

        if (!res.ok) {
            this.submitting = false;
            this.handleError(res); // 422 → inline; 402 → credit upgrade
            return;
        }

        const { conversation_id } = await res.json();
        window.location.href = '/chats/' + conversation_id;
    } catch (_) {
        this.submitting = false;
        this.error = 'Network error. Try again.';
    }
},
```

The dashboard always does a full page redirect — destination is a different Filament page (`ChatConversation`).

### Conversation page (`chatInterface()` factory)

`sendMessage()` branches on `conversationId`:

```js
async sendMessage() {
    const text = this.editor.getText().trim();
    if (text === '' || this.isStreaming) return;
    if (this.editor.charCount() > 5000) return;

    this.isStreaming = true;

    if (this.conversationId === null) {
        return this.sendFirstMessage();
    }
    return this.sendSubsequentMessage();
},

async sendFirstMessage() {
    try {
        const res = await fetch('/chat/conversations', {
            method: 'POST',
            headers: { ... same as dashboard ... },
            body: JSON.stringify({
                document: this.editor.getDocument(),
                model: this.selectedModel !== 'auto' ? this.selectedModel : undefined,
            }),
        });

        if (!res.ok) {
            this.isStreaming = false;
            this.handleError(res);
            return;
        }

        const { conversation_id } = await res.json();
        this.conversationId = conversation_id;
        history.replaceState({}, '', '/chats/' + conversation_id);
        this.subscribeToConversation(conversation_id);

        // ProcessChatMessage was already dispatched server-side.
        // The Reverb subscription picks up streaming events as they
        // arrive — exactly the same handlers we already use.
    } catch (_) {
        this.isStreaming = false;
        this.error = 'Network error. Try again.';
    }
},
```

**Why `replaceState` on the conversation page**: the chat-interface component is already mounted at `/chats`. A full `location.href` redirect would unmount it, force a page reload, and re-fetch all assets. `replaceState` keeps the editor mounted, just rebinds `conversationId` and updates the URL. Optimistic streaming start is preserved — user sees their message and the assistant response without a page flash.

**Race condition note**: between `replaceState` and `subscribeToConversation`, the streaming job has already been dispatched. If Reverb's event arrives before the subscription is in place, that event is lost. This race exists today in the same form (between `initConversation` returning and the subscription being set up) and is mitigated by `ProcessChatMessage` running through Horizon with a small queue-pickup delay — events arrive after subscription. If we ever see lost events, the fix is to subscribe **before** the POST and ignore events for unknown conversation IDs server-side.

## Migration strategy (sequenced after spec #2)

Spec #2's commits 1–4 land first. Then this design's commits:

5. **`feat(chat): add POST /chat/conversations endpoint`** — server only. New controller method. Route registered. Tests cover success, 422, 402, 403. No client uses it yet. (This is the "5" of this design but landed *after* spec #2's 1–4 in the overall sequence.)

6. **`refactor(chat): dashboard POSTs to /chat/conversations + redirects`** — `dashboardChatInput()` factory replaces `location.href = '/chats?message=...'` with the fetch-then-redirect flow. Removes the `?message=` and `?model=` query-param read from `ChatConversation::mount()`. Removes the legacy `chat:mentions` sessionStorage handoff (replaced by `document` in the POST body). Tests cover success and 4xx error rendering.

7. **`refactor(chat): conversation-page first send uses /chat/conversations + replaceState`** — `chatInterface()` factory branches on `conversationId === null`. New branch hits `/chat/conversations`, then `history.replaceState`, then continues the normal Reverb subscription flow.

8. **`refactor(chat): remove /chat/conversations/init endpoint and dead code`** — delete `ChatController::init`, the route, and the auto-title backfill block in `ChatController::send` (commit `53ae1e3`'s F-002 fix). Update `AutoTitleAfterInitTest` to either assert the title is set by the new endpoint or remove if redundant.

**Risk surface.** Step 7 changes the in-conversation first-send path — currently the optimistic-frontend pattern. The replaceState approach preserves that feel but the Reverb subscription timing is sensitive. Test plan covers the race; if it surfaces in real use, the fix is the documented "subscribe-before-POST" mitigation.

**Roll-forward only.** Step 8 deletes endpoints. If we need to roll back step 6 or 7, the dashboard would have to keep working against the new endpoint anyway (the old `?message=` consumer is gone). In practice, fix forward.

## Testing

### Feature (PHP)

- `CreateConversationTest` (new):
  - Success path: 200, conversation row + message row + mention rows inserted, ProcessChatMessage dispatched
  - Empty document → 422
  - Insufficient credit → 402 with the expected response shape
  - Missing currentTeam → 403
  - Cross-tenant mention IDs in document → filtered (per spec #2 parser behavior)
  - Title set from first message
- `ChatControllerSendTest` (modified): existing tests still pass; add an assertion that POST without conversation_id now returns 422
- `AutoTitleAfterInitTest`: rewritten to test title-at-creation via `/chat/conversations`, OR deleted if `CreateConversationTest::title_set_from_first_message` covers it

### Browser (Pest 4)

- `DashboardFirstSendTest` (new): visit dashboard, type message, send, assert URL becomes `/chats/{ulid}`, assert message appears in chat history, assert assistant streaming starts
- `ConversationFirstSendTest` (new): visit `/chats` (no ID), type message, send, assert URL becomes `/chats/{ulid}` via replaceState (no full page reload — verifiable by checking that an Alpine-data attribute set on mount is still present after the URL changes), assert streaming continues without flicker
- `RefreshIdempotencyTest` (new): perform a first send, refresh the destination page, assert exactly one user message + one assistant message exist (no duplication, no re-fire)
- `BackButtonIdempotencyTest` (new): perform a first send from dashboard, click browser back, assert dashboard renders empty (no message re-fires), forward button returns to the conversation in its current state

## Open questions

- Whether `ChatController::createConversation` should set `team_id` on the message row directly or rely on the model's `BelongsToTeamCreator` boot-time hook (verified at write time)
- Whether to keep `App\Filament\Pages\ChatConversation::mount()`'s `?message=` parsing as a deprecated path for one release (alpha — leaning toward delete-immediately, but documenting here for pushback)

## Related work

- Spec #2 — `2026-05-07-tiptap-mention-editor-and-storage-design.md`. The `document` field this design accepts is defined there. This work is not deployable without spec #2's commits 1–4 having shipped.
