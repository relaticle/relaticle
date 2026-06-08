# TipTap-backed mention editor + structured message storage

**Status:** Draft
**Date:** 2026-05-07
**Branch (target):** `feat/dashboard-chat`

## Goal

One source of truth for mentions across the chat surface: input editor, storage, and message rendering all use TipTap document JSON. Mention chips are atomic everywhere — atomic to delete in the input, atomic in stored history, atomic in rendered bubbles.

This replaces three brittle, parallel rendering paths:

1. Input: a `<textarea>` with raw `@Token` text plus a parallel pill row above
2. Storage: plain text in `agent_conversation_messages.content` plus a join table for mention IDs
3. Render: post-hoc string substitution by `App\Support\MentionRenderer` to turn `@Token` into HTML chips

…with one structured pipeline:

1. Input: TipTap editor; mentions are atomic Prosemirror nodes
2. Storage: TipTap document JSON on `agent_conversation_messages.document`
3. Render: TipTap's own JS `generateHTML()` reads the same JSON

## Non-goals

- LLM emitting structured outputs natively (mentions in assistant prose still come from tool-call results, post-processed at stream end — no LLM contract change)
- Rich text features: bold, italic, code blocks, slash commands, auto-link
- GIN index on `document` column (no query-into-JSON workload yet)

## Architecture

**Client.** Both inputs (dashboard `dashboard.blade.php` + conversation page `chat-interface.blade.php`) replace their `<textarea>` with a TipTap-backed `<div contenteditable>` editor configured with `Document`, `Paragraph`, `Text`, `Placeholder`, `HardBreak`, `@tiptap/extension-mention`, and `@tiptap/suggestion`. The editor's `getJSON()` is the canonical input state. Message bubbles render via TipTap's JS `generateHTML()` (same bundle, no extra weight) using the same extension list as the editor.

**Server.** `agent_conversation_messages` gains a `document jsonb NOT NULL` column. `App\Services\Chat\TipTapDocumentParser` (server-side, wraps `ueberdosis/tiptap-php` already installed transitively via Filament Forms) converts JSON → `{plain_text, mentions[]}` for inbound writes and converts text + mention rows → JSON for assistant-message materialization at stream end.

**Migration is single-pass on alpha data.** The first migration truncates chat tables (no users on alpha to migrate, decision documented below) so `document` can be NOT NULL from the start. No legacy fallback path in the renderer.

## Components

| Unit | Responsibility | Key inputs / outputs |
|---|---|---|
| `chat-input.blade.php` (new shared partial) | Render the TipTap container, mention dropdown markup, controls row | Slot for submit handler; Alpine factory passed in |
| `Alpine.data('chatEditor', ...)` factory (new, in `packages/Chat/resources/js/chat-editor.js`) | Boot TipTap; expose `getDocument()`, `getText()`, `setText()`, `clear()`, `focus()`, `selectedMentionIds()` | Composed by both `chatInterface()` and `dashboardChatInput()` factories |
| `chatInterface()` Alpine factory (existing, slimmed) | Streaming, cancel, message list, send | Composes `chatEditor()`, no longer owns mention state |
| `dashboardChatInput()` Alpine factory (existing, slimmed) | First-message handoff to conversation page | Composes `chatEditor()`, no longer owns mention state |
| `App\Services\Chat\TipTapDocumentParser` (new) | Parse incoming editor JSON ↔ `{text, mention_ids[]}` | Used by `ChatController::send` and `ProcessChatMessage` |
| `ChatController::send` validation | Require `document` (json), strip legacy `message`/`mentions` | Inbound contract change |

**Why a shared Blade partial:** today the mention markup is duplicated in two views. After this migration the duplication grows (TipTap container styling, suggestion popup positioning, chip CSS, editor-as-Alpine wiring). Extracting now is cheaper than three months from now.

## Wire contract

**Request body** (`POST /chat/send`):

```json
{
  "conversation_id": "01k...",
  "model": "claude-sonnet",
  "document": {
    "type": "doc",
    "content": [{
      "type": "paragraph",
      "content": [
        {"type": "text", "text": "Tell me about "},
        {"type": "mention", "attrs": {"id": "01k...", "type": "company", "label": "Acme Corp"}},
        {"type": "text", "text": " please"}
      ]
    }]
  }
}
```

The legacy `message` and `mentions` fields are no longer accepted. The server's `TipTapDocumentParser` produces them server-side from `document` for the existing downstream code paths (`ProcessChatMessage`, prompt construction, mention join-table writes). No DB/storage contract change downstream of the controller.

**Mention node attrs schema:** `{id: string (ULID), type: 'company'|'people'|'opportunity'|'task'|'note', label: string}`. The `type` attr is the only addition vs Filament's default mention node — required because `id` alone is ambiguous across entity types in this app.

**Cross-page handoff** (dashboard → conversation page) — today a mix of `?message=...` query param and `sessionStorage chat:mentions`. After this migration: `sessionStorage chat:draft = {document}` carrying the editor JSON. `chat-interface.init()` reads it, seeds the editor, dispatches send. (Detailed handoff design lives in a separate brainstorm — sequenced after this work.)

## Storage schema

New migration, up-only per repo convention:

```php
Schema::table('agent_conversation_messages', function (Blueprint $table): void {
    $table->jsonb('document')->after('content');
});
```

NOT NULL by Postgres default for new columns when added without `->nullable()`. To satisfy NOT NULL on the existing rows, the migration first truncates chat tables. Children first to satisfy FK constraints — exact list determined at write time, but covers at minimum:

- `agent_conversation_message_mentions`
- `pending_actions` (FK to conversation_id)
- `agent_credit_transactions` (FK to message_id, if present)
- `agent_conversation_messages`
- `agent_conversations`

`content` (plain text) stays the canonical search column. Existing `ConversationSearch` ILIKE on `content` continues to work unchanged.

No GIN index — we don't query into the JSON.

### Decision: wipe alpha data instead of backfill

Backfill was considered and rejected. Justification:

- This feature ships to an alpha branch with no production users
- A nullable column + permanent fallback renderer adds two long-lived rendering paths to maintain
- A backfill job adds operational concerns (re-run, idempotency, partial-failure metrics) for zero alpha-user benefit
- Wiping the dev/QA chat history is cheap and reversible (re-seed via existing seeders if needed)

If this work later needs to ship to a non-alpha branch with real chat history, swap the truncate for a queueable backfill job — a 30-minute follow-up, not a re-architecture.

## Write paths (where `document` gets populated)

### User message — `ChatController::send`

```php
$validated = $request->validate([
    'document' => ['required', 'array'],
    'conversation_id' => ['required', 'string', 'uuid'],
    'model' => ['nullable', 'string'],
]);

$parsed = $this->documentParser->parse(
    document: $validated['document'],
    team: $user->currentTeam,
);
// $parsed = ['text' => 'Tell me about @Acme_Corp please', 'mentions' => [['type' => 'company', 'id' => '01k...']]]

DB::transaction(function () use ($validated, $parsed, $user, $team): void {
    $message = AgentConversationMessage::create([
        'role' => 'user',
        'content' => $parsed['text'],
        'document' => $validated['document'],
        // ... other existing fields
    ]);
    // Existing mention-row persistence logic, unchanged
});
```

`TipTapDocumentParser::parse()` walks the document tree once, producing both the plain-text representation (for `content`, search, LLM prompt) and the structured mention list (for the join table). Tenant-scoped: any mention node whose `attrs.id` is not authorized for `$team` is dropped before the parse returns.

### Assistant message — `ProcessChatMessage::handle` at stream end

```php
$finalContent = $streamedResponse->text;
$mentionRows = $this->extractMentionsFromTools($streamedResponse); // existing logic

$document = $this->documentParser->buildFromText(
    text: $finalContent,
    mentionRows: $mentionRows,
    team: $user->currentTeam,
);

$message->update([
    'content' => $finalContent,
    'document' => $document,
]);
```

`TipTapDocumentParser::buildFromText()` scans `text` for known `@token` substrings (where token = sanitized mention label or stable token already stored on the mention row) and replaces each with a mention node. Materialization happens once per assistant message at stream end — not per delta. Streaming UI keeps using the live text; the structured re-render kicks in on the broadcast event after the message row is updated.

## Read path / rendering

`ListConversationMessages` action returns `document` alongside the existing fields. The frontend's existing `renderMentions(content, mentions)` is replaced by `renderMessageContent(message)`:

```js
renderMessageContent(message) {
    return generateHTML(message.document, this.tipTapExtensions);
}
```

The `tipTapExtensions` array is shared between editor mounts and render calls — single source of truth for what nodes/marks are valid. The mention chip renders via the same JS component used inside the editor; no second styling path.

**During streaming.** Before stream end, the assistant message row does not exist in the DB yet — it is held in the streaming UI's transient state with `document` absent from the payload. The streaming UI shows the live text via the existing markdown renderer for that transient case only. At stream end, `ProcessChatMessage` inserts the row with both `content` and `document` populated; the broadcast event refreshes the message in the UI, and `renderMessageContent` switches to the TipTap-rendered HTML for that message.

**`MentionRenderer.php` is deleted** as part of the conversation-page swap. Its tests, if any, are deleted with it.

## Migration strategy

Seven commits, each independently shippable to alpha:

1. **`feat(chat): wipe alpha chat data + add document jsonb NOT NULL column`** — migration only. Truncate chat tables; add `document` column. No code reads from it yet.

2. **`feat(chat): add TipTapDocumentParser service`** — pure PHP, fully unit-tested. `parse()` and `buildFromText()` methods. No callers yet. Imports `Tiptap\Editor` from `ueberdosis/tiptap-php`.

3. **`feat(chat): /chat/send accepts document field`** — `ChatController::send` validation now requires `document`. Legacy `message` + `mentions` fields rejected. `DocumentParser::parse()` produces the `content` and `mentions[]` for the existing downstream insert. Also covers conversion in init/edit-message endpoints if they share the same shape.

4. **`feat(chat): assistant messages materialize document at stream end`** — `ProcessChatMessage` change only. Calls `DocumentParser::buildFromText()` once per assistant message at stream completion. Persisted alongside `content`.

5. **`feat(chat): introduce shared chatEditor Alpine factory`** — new JS file `packages/Chat/resources/js/chat-editor.js`. TipTap import, suggestion plugin wired to `/chat/mentions`. Registers `Alpine.data('chatEditor', ...)`. Not yet used by any view. Vite bundle grows by ~55 KB gzipped.

6. **`refactor(chat): swap conversation page input + bubble render to TipTap`** — `chat-interface.blade.php` replaces its `<textarea>` block, mention dropdown markup, and `selectedMentions` pill row with `chatEditor`. `chatInterface()` Alpine factory composes `chatEditor()` instead of owning mention state. Submit body sends `{document, conversation_id, model}` (no `message` or `mentions` fields). `MentionRenderer.php` and its tests deleted. `renderMentions` JS helper replaced with `renderMessageContent`.

7. **`refactor(chat): swap dashboard input to TipTap + JSON handoff`** — same swap on `dashboard.blade.php`. SessionStorage handoff key becomes `chat:draft = {document}`. Old `chat:mentions` sessionStorage key removed. `dashboardChatInput()` Alpine factory composes `chatEditor()`.

**Risk surface.** Steps 6 + 7 are the heavy ones (view rewrites). Steps 1–5 are individually small and shippable. The order is deliberate: server is JSON-aware before the client starts sending JSON; document column exists before parser writes to it; parser exists before stream-end materializer uses it; editor exists before views adopt it.

**Roll-forward, not back.** Step 1 wipes data. Don't deploy to non-alpha branches without first replacing the truncate with a backfill job. This is documented in step 1's commit message and the migration's PHP comment.

## Testing

### Unit
- `TipTapDocumentParserTest` (new)
  - `parse()`: empty doc; text-only; mention-only; mixed text+mentions; malformed JSON throws; cross-tenant mention IDs filtered; unknown mention IDs filtered
  - `buildFromText()`: empty text; text without mentions; single mention; multiple mentions; overlapping token names (`@A` vs `@AB`, longest first); case-sensitive matching; whitespace edge cases

### Feature
- Existing `MentionsPersistenceTest`, `MentionsMessagePersistenceTest`, `MentionsMultiWordSearchTest`, `MentionsRelevanceTest`, `ConversationSearchTest` keep passing — search still hits `content`, persistence assertions adapted to the JSON path
- `ChatControllerSendDocumentTest` (new): POST with `document`, assert `content` derived correctly, assert mentions inserted into join table, assert legacy `message`+`mentions` shape returns 422
- `ProcessChatMessageDocumentMaterializationTest` (new): assert assistant message gets `document` populated at stream end matching its content + mention rows

### Browser
- `MentionDeletionTest` (new): type `Hi @Acme`, select Acme from dropdown, single Backspace, assert chip is removed in one keystroke
- `MessageRenderingTest` (new): create messages with mentions; assert rendered HTML contains atomic chip span with the expected `data-mention-id` and `data-mention-type` attrs
- Existing `MentionPickerTest` keeps testing the picker UI; expectations updated to match TipTap-rendered chips inline (vs today's text + pill split)

## Open questions

- Exact list of FK-cascading tables to truncate in step 1 — verified at migration-write time
- Whether to share the editor partial across `chat-interface.blade.php` and `dashboard.blade.php` from day one (preferred), or duplicate in step 6 and extract in step 7 (lower-risk, slightly more churn)

## Related work

- This spec assumes the `feat/dashboard-chat` branch state at `b61f51fa` (or later); does not depend on but is sequenced before the GET-handoff redesign for dashboard → conversation navigation
