# AI Chat: world-class program (Telegram-feel, blocks, capabilities, voice)

## Summary

One program to take the assistant from "works" to best-in-class. Six phases, each an
independently shippable PR track. Feel-first sequencing: the transcript front-end is
restructured once in Phase 1 and every later phase plugs into that structure instead
of re-opening it. Retrieval (RAG) is deliberately excluded: interface pinned here,
build tracked in issue #495 (milestone v3.5).

The bar: sending a message, watching the answer arrive, and acting on a proposal
should feel as fast, fluid, and lossless as Telegram. Record references and results
should look like the product, not like markdown.

## Success criteria

- Send to visible bubble: under 50ms (optimistic).
- Conversation switch: under 200ms perceived (cache-first render).
- Zero message loss across reload, disconnect, 429, or stream failure.
- No scroll jank during streaming; autoscroll only when pinned to bottom.
- Records in prose render as chips that navigate; read results render as cards
  and tables, not markdown.
- Every phase merges with the chat suite green and the production-shaped stack
  walked (Horizon, `QUEUE_CONNECTION=redis`, Reverb, real browser).

## Program map

| Phase | Ships | Depends on |
|---|---|---|
| 0 | Assistant name + brand touches | nothing |
| 1 | Telegram-feel: restructure, feel mechanics, visual polish, stability fixes | nothing |
| 2 | Rendering content: `display_block` cards + inline record chips | 1 |
| 3 | Capability completeness: comments, activity, email drafts, search | 2 |
| 4 | Voice input (transcription into composer) | 1 |
| 5 | Retrieval interface pin (display templates folded into Phase 2) | 2 |

## Phase 0: naming

The assistant has no name. UI says "Chat"; the system prompt says "Relaticle CRM
Assistant". Decision from 2026-07-20 stands: not "Brain", distinct proper name
preferred. Cost: UI labels, one system prompt line, a config key.

Candidates (pick one at spec review):

| Name | For | Against |
|---|---|---|
| **Rela** (recommended) | Derived from the brand, short, ownable, no known collisions | Invented word, needs a beat to land |
| Orbit | "Everything in your CRM's orbit", warm | orbit.love was a community CRM |
| Navi | Navigator connotation | Zelda association |
| Relaticle AI (fallback) | Zero explanation needed | Generic, no personality |

## Phase 1: Telegram-feel

### 1a. Front-end restructure (behavior-preserving, first commit)

`chat-interface.blade.php` is ~2,100 lines of Blade plus one Alpine mega-object.
Livewire 4 islands cannot be used inside loops (verified), so the architecture
stays: one Livewire component, Alpine keyed `x-for` renders the transcript. The
restructure is about files, not framework:

- Blade partials under `livewire/chat/partials/`: `_transcript`, `_composer`,
  `_banners` (proposal partials already exist).
- ES modules under `packages/Chat/resources/js/chat/`: `transcript.js` (message
  list state, grouping, windowing), `stream.js` (Reverb event routing,
  invocation-bound bubbles move here), `send.js` (send states, drafts, queue),
  `blocks.js` (block registry). `chat-editor.js` stays.
- `blocks.js` ships in this phase as a registry with one registered type: the
  existing proposal card. That empty slot is the Phase 2 contract.

Known traps carried into the module split (from the 2026-06 overhaul):
documents stored in Alpine reactive state come back as Proxies, so every
`setDocument`-from-state site must JSON round-trip (`plainDocument()`); exceptions
in the Alpine flush queue drop queued `$nextTick` callbacks, so critical dispatches
use `setTimeout`.

### 1b. Feel mechanics

1. **Send states.** Per-message `queued -> sending -> sent -> failed`, Telegram
   clock-then-check. Optimistic bubble and `clientKey` exist; add the state
   machine and inline resend on `failed`. The 429 path pops the optimistic
   bubble instead of duplicating it (deferred finding F2, fixed here).
2. **Drafts.** Per-conversation TipTap document persisted to localStorage
   (`chat.draft.{conversationId}`), debounced, restored on switch and reload,
   cleared on successful send.
3. **Instant switching.** In-memory cache of rendered messages per conversation
   (keep last 5). Switch renders from cache immediately, then reconciles from
   the server; server truth wins (reconcile pattern already exists).
4. **Transcript shape.** Group consecutive same-role messages under a 3-minute
   gap (tighter spacing, one timestamp per group). Day separators and a sticky
   date header, computed in the user's timezone. Entrance transitions respect
   `prefers-reduced-motion`.
5. **Windowed history.** Initial render is the last 50 messages; scroll-up loads
   50 more via the existing `beforeMessageId` pagination in
   `ListConversationMessages`. No DOM trimming in v1; revisit if profiling
   demands it.
6. **Keyboard.** Cmd+K conversation switcher (search over conversation titles),
   Esc closes the panel, ArrowUp in an empty composer edits your last message
   (reuses the existing edit flow).

### 1c. Visual polish

Every chat surface brought to one standard: side panel, all-chats panel,
full-page conversation, dashboard tasks partial. Composer redesigned as an input
bar (TipTap field, mention chips, model picker, mic slot for Phase 4, send).
Assistant bubble typography loosened; proposal card visually refreshed; follow-up
chips and status pills aligned to the design system. Empty, first-run, loading,
error, and offline states all designed, with real CRM-sounding demo content.

Rules: design tokens from `resources/css/theme.css` only; light + dark parity;
mobile viewport treated as a first-class layout for the side panel. Done-gate per
project UI rules: agent-browser screenshots in light, dark, and mobile, clicked
through with empty-state data. The standard per surface is defined by reference
screenshots taken before work starts, so "polished" is reviewable.

### 1d. Stability fixes riding along

- **F1**: cards resolved in another tab stay actionable. Broadcast
  `pending_action.resolved` on approve/reject; reconcile marks the card resolved
  everywhere.
- **F3**: thumbs toggle-off silently deletes typed category and comment. Confirm
  before delete.

## Phase 2: rendering content

Research basis: sanitizer behavior measured on both pipelines, precedents from
Slack, Anthropic Citations, OpenAI Apps SDK, Vercel remend (2026-08-18).

### Inline record chips

- `RecordReferenceResolver` starts returning **relative reference URLs**
  `/r/{type}/{id}` as the `url` field in read tool results. The model's behavior
  does not change: it already renders `[Name](url)` per the Citations prompt
  section. Relative paths survive both sanitizers unchanged (League CommonMark
  keeps them; DOMPurify's default `ALLOWED_URI_REGEXP` strips custom schemes but
  passes relative paths, measured).
- New authenticated route `GET /r/{type}/{id}`: resolves via
  `RecordReferenceResolver::urlFor()` for the viewer's current team and
  redirects; 404 when not a member. This makes every emitted reference a working
  link even when chip rendering fails, and makes copied markdown pasteable.
- Chip rendering: server side, a Link `NodeRenderer` in `MarkdownRenderer` for
  hrefs matching `^/r/` renders the chip markup (icon by type, label, data
  attributes). Client side, a post-sanitize sweep in `renderMarkdown` swaps
  matching anchors to the same markup. Labels are untrusted; escape at the
  boundary.
- Copy rewrites `/r/...` hrefs to absolute URLs in the copied markdown.
- Hover card (record preview on chip hover) is optional and deferred to Phase 5
  where the template config gives it fields to show.

### Display blocks

The proposal card already proves the pattern: tool result carries a typed
display payload, the client renders it from a registry, reload re-derives it
server-side. Generalize to read results:

- Envelope inside the tool-result JSON:
  `{type: "display_block", block: "record_card" | "records_table" | "aggregate",
  display: {...}, ...data for the model...}`. The tool declares the block; the
  model never emits presentation markup and needs zero prompt compliance.
- `display` is slim (column spec, row ids, formatted values), sized so the
  persisted tool result does not balloon the replayed transcript.
- `StreamEventBroadcaster` continues to NOT broadcast read results live (Reverb
  10 KB cap, learned the hard way). Blocks render at `stream_end` reconcile and
  on reload, exactly like the `prerendered` pattern.
- `ListConversationMessages` derives blocks from persisted `tool_results` the
  same way it derives pending actions today.
- `blocks.js` registry maps block type to an Alpine template; `record_card` and
  `records_table` reuse the typed field rows from `_proposal-field`
  (`badges`, `boolean`, `link`, text).
- `BaseReadListTool` and `BaseReadShowTool` opt in; the prompt's "present lists
  as tables" rule relaxes to "the block shows the data; summarize in one line".

## Phase 3: capability completeness

Every new tool follows the house rules: batch input like the delete path, blocks
output from Phase 2, custom-fields bridge where the entity has them, writes
proposal-gated. Candidates, each its own PR:

1. **Comments**: read comments on a record; propose creating one
   (relaticle/comments is integrated; mentions enabled).
2. **Activity**: "what changed on Acme this week" over spatie/activitylog.
   Reader must merge `properties` AND `attribute_changes` (known v5 split).
3. **Email drafts**: propose an email via EmailIntegration; send is the
   approval. Attachments-dropped bug (`SendEmailAction`) must be fixed first or
   scoped out.
4. **In-conversation search**: search within a conversation's messages
   (feature deferred from Phase 1 by design).
5. **Escorts**: exports and anything else un-toolable routes through
   `GuideToPageTool` destinations, never "not supported".

## Phase 4: voice input

Push-to-talk in the composer: MediaRecorder (webm/opus), `POST
/chat/transcribe` (auth, throttled 10/min, max 2 minutes audio), server calls
laravel/ai `Transcription` (OpenAI; Anthropic has no STT), text inserts at the
cursor in TipTap. **Never auto-sends**: the user reviews, then sends through the
normal flow, so the approval philosophy is untouched. Free at launch behind the
throttle (whisper-class cost is pennies); revisit billing with the credits epic.
Config flag `chat.voice_enabled` for self-hosters without an OpenAI key.

## Phase 5: team display templates + retrieval pin

**Templates** (the Discord "pretty printing" request, Jav 2026-08-18) are
answered WITHOUT a new config surface, decided 2026-08-20. Which fields
represent an entity in chat is DERIVED from what the team already configured
on its custom fields: `visible_in_list` minus `list_toggleable_hidden` for the
`records_table` block (what the Filament table shows), `visible_in_view` for
the `record_card` block (what the record view page shows), both ordered by
`sort_order`. A sampled tenant already has `{amount, close_date, stage}`
marked visible on `opportunity`, which is Jav's ask, already stored.

On top of that, fields the tool call filtered or sorted on are promoted to the
front and included even when hidden, so relevance follows the question rather
than a static per-team template.

No `chat_display_templates` table, no settings UI: a chat-only template would
be a second source of truth for a fact the custom-field settings already own.
The selector lives in `DisplayFieldSelector` and is consumed by the block
builders (and later the chip hover card). Accepted trade: no chat-only
override; if that demand appears, extend the existing custom-field form with a
"show in chat" toggle rather than adding a second config surface.

**Retrieval pin**: `CrmAssistant` reserves a `RetrieveContextTool` slot: input
`{query: string, entity_types?: string[], record_id?: string}`, output chunks
with source record references that render as Phase 2 chips. pgvector in our own
Postgres, never provider-hosted stores. Build is issue #495, triggered by
unstructured volume (EmailIntegration at scale).

## Cross-cutting

- **Ops**: `HORIZON_CHAT_MAX=3` means three concurrent streams platform-wide;
  raising it is a deploy note in Phase 1's PR (ops decision, flagged as a launch
  blocker for "world class"). Chat workers run `max-jobs=0`, so every release
  needs `horizon:terminate`.
- **Verification**: each phase is verified against the production-shaped stack
  and walked in a real browser per the chat package rules. Phase 1 adds browser
  tests for send states, drafts, switching, and grouping.
- **Testing**: chat suite baseline (450+ tests) green at every merge; new
  features tested through real entry points per the Testing Trophy; visual work
  screenshot-verified light/dark/mobile.
- **Prompt changes** (Phase 2 relaxation, Phase 5 tool) each get a
  `CrmAssistantInstructionsTest` guard.

## Out of scope

Reactions, file attachments in chat, offline send queue beyond draft
persistence, desktop app, autonomous or triggered agents, the RAG build (#495),
renaming anything beyond assistant surfaces.

## Delivery

Sequential PRs: 0 (naming) → 1a (restructure) → 1b+1d (feel + fixes) → 1c
(polish, sibling PR if large) → 2 (chips) → 2 (blocks) → 3 (one PR per tool) →
4 (voice) → 5 (templates) → 5 (retrieval pin, doc-only). Each merges
independently with main green.
