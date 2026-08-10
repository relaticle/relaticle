# Universal chat record-context: pill, binding, and grounding trace

**Date:** 2026-08-03
**Branch:** `ManukMinasyan/consolidate-ask-relaticle` (lands inside PR #429)
**Status:** Design approved (option C), pending implementation plan
**Predecessor:** `2026-07-30-consolidate-ai-into-chat-design.md` (built the binding this spec hardens)

## Summary

PR #429 gave the chat assistant page-context binding, then a follow-up added a
dismissible pill making it visible. Live verification while brainstorming this
spec found the binding **works by accident**: the side panel's own context state
is nulled by an XHR immediately after every page load, and the feature survives
only because the child `ChatInterface` snapshots correct values as mount props
one render earlier.

This spec (a) fixes that architecture so context resolution is deliberate and
live, (b) extends binding to Task/Note records opened as modals — all five
entity types, universally, (c) persists the bound record per message and shows
it in history, and (d) re-injects the ids of previously referenced records so
follow-up turns can act without re-searching. The pill stays; converting it to
an auto-inserted @mention was researched and rejected.

## Research: how the leaders do it

| Product | Ambient context | Shown as | Removable | Explicit tier |
|---|---|---|---|---|
| Ask Attio | "The context where you open Ask Attio takes precedence" | "The related item appears in the bottom left of the chat" | not documented | separate `@mention` |
| Notion Agent | "takes in the context of the page you're currently in" | scope indicator at top of input | scope selector | separate `@` + attachments |
| VS Code Copilot Chat | current file auto-attached | implicit-context chip | click strikes it through (not sent) | separate `#file` / Add Context |
| HubSpot Breeze | page-aware; "Summarize this" works on record pages | page-level | — | prompt-level |
| Salesforce Agentforce | `recordId` grounding on record pages | action-level | — | — |

Three conclusions bind this design:

1. **The two-tier model is universal.** Ambient context (weak, visible,
   removable) is kept separate from explicit mentions (strong, user-typed)
   in every product surveyed. None auto-inserts a mention for the current
   record. The pill is the industry answer; auto-mention has no precedent.
2. **Our pill is literally Attio's pattern** — a chip near the composer showing
   the related item — and the dismissal semantics (stop sending, not just hide)
   match VS Code's strikethrough.
3. **Removing ambient context is the known failure mode.** VS Code made current-file
   context manual and drew sustained backlash demanding it back
   (github.com/orgs/community/discussions/170378). The fix for invisibility is
   visibility, not removal.

Pattern we lack that Copilot has: per-message grounding disclosure. Section 4
adds the CRM equivalent.

Sources: attio.com/help/reference/attio-ai/ask-attio/chat-with-ask-attio;
notion.com/help/notion-agent; code.visualstudio.com/docs/copilot/chat/copilot-chat-context;
github.com/microsoft/vscode/issues/251453; knowledge.hubspot.com/ai/use-breeze-assistant;
developer.salesforce.com/docs/platform/lwc/guide/use-record-context.html.

## Verified defect inventory

**D1 — Context state is nulled right after every page load (works-by-accident).**
`chat-side-panel.blade.php:45` listens for `livewire:navigated` — which fires on
the *initial* load too — and calls `$wire.refreshContext()`. During that XHR,
`request()->route()` is Livewire's update endpoint, never the record route, so
`ChatContextService::getContext()` returns nulls and wipes the panel's
`recordType`/`recordId`/`recordName`/`starterPrompts`. Browser-proven on a fresh
People record load: panel state `{recordType: null, recordId: null}` while the
child `ChatInterface` held `{people, 01kxn0qrs…}` from its mount-time snapshot.
Consequences: the panel's state is untrustworthy after load; the bottom
suggested-prompts strip never renders (cleared immediately); no XHR-driven
re-resolution can ever work, which blocks D2.

**D2 — Task and Note records never bind.** They open as modals via query params
(`?tableAction=edit&tableActionRecord=<id>`) on index routes, not record routes,
so route-parameter resolution finds nothing. The agent degrades to asking.

**D3 — No history trace, and ids are lost after turn 1 — for mentions too.**
Page context leaves no visible trace on sent messages. Server-side,
`TipTapDocumentParser` appends only the mention *label* into stored text;
`agent_conversation_message_mentions` (ids) is read solely by the UI
(`ListConversationMessages:46`), never by the agent. On turn 2+ the agent knows
*who* was discussed but not the id — it must re-search by name, ambiguous when
names collide. This defect applies equally to explicit mentions and page
context; the earlier pill-vs-mention debate misattributed it to one side.

**D4 — The bottom suggested-prompts strip is dead code.** Fed by panel state
that D1 clears on load; also now duplicates the empty-state chips, which read
their own mount-time copy.

**D5 — Pill visual identity.** The pill borrows mention vocabulary (@ glyph,
primary tint) but does not match the mention chip rendering
(`chat-editor.js:81`, class `mention`), which prompted the "pill or mention?"
question in the first place.

**D6 — `ChatContextService::getContext()` is not team-scoped.**
`$modelClass::query()->find($recordId)` with no `whereBelongsTo` and no policy
check. Safe today only because Filament route middleware authorized the record
before the service ran. The moment resolution accepts a client-supplied URL
(Section 1), the service must guard itself.

## Decisions

| Decision | Choice | Grounds |
|---|---|---|
| Pill vs auto-mention | **Pill stays** (ambient tier) | Industry-universal two-tier model; auto-mention collapses precedence and re-imports the deleted button's draft-wiping bugs |
| Precedence | Explicit `@mention` > page context, unchanged | Matches every surveyed product; keeps "on Acme's page, ask about Globex" unambiguous |
| Pill look | Restyled to the mention chip's visual language + record-type icon | Answers D5 without changing semantics |
| Dismissal | Per-record-visit (resets on navigation), gates sending AND persistence | Matches VS Code per-message strikethrough spirit |
| History trace | Persist page context per user message; render as a small chip on sent messages | D3, Copilot-style disclosure |
| Id re-injection | Conversation context ledger in the system prompt | Fixes D3's agent-side half for mentions and page context alike |

---

# Section 1 — URL-grounded context resolution (fixes D1, D6)

`ChatContextService` gains one entry point that resolves context **from a URL**
instead of the ambient request:

```php
/** @return array{page: string|null, record_type: string|null, record_id: string|null, record_name: string|null} */
public function getContextForUrl(string $url): array
```

Implementation: `Route::getRoutes()->match(Request::create($url))` inside a
try/catch (`NotFoundHttpException` → empty context), then the existing
`ENTITY_MAP` route-name matching. `refreshContext()` becomes
`refreshContext(?string $url = null)`; the Blade listener passes
`window.location.href`. The mount path uses `request()->fullUrl()` — one
resolution code path, no more dependence on `request()->route()` surviving into
XHRs.

**Security (D6):** the URL is now client-supplied, so the service guards itself:
`whereBelongsTo($user->currentTeam)` + `$user->can('view', $model)`, mirroring
`ChatController::resolvePageContext()`. A forged URL for another team's record
resolves to empty context. The send path keeps its own independent re-validation
— defense in depth, not replacement.

**State propagation:** the panel can no longer rely on the child's mount-time
snapshot (that was the accident). After resolving, `refreshContext()` dispatches
a Livewire browser event `chat:context-updated` with
`{type, id, label, prompts}`; `chat-interface`'s Alpine listens
(`x-on:chat:context-updated.window`) and updates `pageContext`,
`pageContextLabel`, `starterPrompts`, and resets `pageContextDismissed` to
`false` (a new record is a new binding). The child's mount props remain only as
initial values. The child is **not** re-keyed by record id — re-mounting would
destroy the TipTap editor and any draft.

# Section 2 — Live URL watching (enables interactive binding)

A small Alpine helper in the side panel patches `history.pushState`/
`history.replaceState` and listens for `popstate`, debounced (~150ms), calling
`$wire.refreshContext(location.href)` when the URL actually changed. This
catches SPA navigations (which use `pushState`) and — critically — query-string
changes that never fire `livewire:navigated`, which is how Filament modals
update the URL.

The existing `livewire:navigated → $wire.refreshContext()` listener
(`chat-side-panel.blade.php:45`) — the D1 trigger — is **deleted**, replaced by
this watcher. A wire:navigate may produce both a watcher-driven resolution and
a fresh mount on the swapped page; both resolve the same URL, so the double
call is idempotent and harmless.

# Section 3 — Task and Note modal binding (fixes D2)

`ENTITY_MAP` matching already covers `.tasks.` / `.notes.` route names
(`filament.app.resources.tasks.index` matches). What's missing is the record
id: when the matched route has no `record` parameter, fall back to the
`tableActionRecord` query parameter from the URL being resolved. Same guards as
Section 1.

- **Deep-link loads** (`?tableAction=edit&tableActionRecord=<id>`) bind
  guaranteed — the URL carries everything at resolution time.
- **Interactive modal opens** bind **iff** Filament v5 syncs the mounted table
  action into the query string at open time (Section 2 then picks it up). The
  implementation plan starts with a browser probe of that behaviour; if v5 does
  not sync interactively, deep-links still bind, interactive opens keep today's
  ask-first behaviour, and the gap is documented in the plan rather than
  silently absorbed. Closing the modal (param removed) unbinds via the same
  watcher.
- The tasks **board** route (`tasks/board`) matches `.tasks.` and carries no
  record — resolves to type-only context (no id, no pill), same as any index.

# Section 4 — Grounding trace in history (fixes D3, UI half)

`agent_conversation_message_mentions` gains a `source` column
(`varchar` default `'mention'`; forward-only migration). When a user message is
processed with page context attached (not dismissed),
`ProcessChatMessage::persistMentions()` also writes one row
`{type, id, label, source: 'page_context'}`.

`ListConversationMessages` returns page-context rows separately from mentions
(`page_context: {type, id, label, url}|null` per message, url via the existing
`RecordReferenceResolver`). The UI renders a small mention-styled chip beneath
the user's message bubble — record-type icon + name, linking to the record.
History now answers "what was this message about?" — the one genuine advantage
auto-mention had.

Dismissed pill ⇒ `page_context: null` ⇒ no row ⇒ no chip. Consistent.

# Section 5 — Conversation context ledger (fixes D3, agent half)

`CrmAssistant::dynamicInstructions()` gains a `contextLedgerBlock()`: the
distinct records referenced **earlier in this conversation** — mentions and
page contexts, from the mentions table — capped at 10, most recent first:

```
<context type="user_data">
Records referenced earlier in this conversation (use these ids directly):
- people "Manch Minasyan" (id: 01kxn0qrs…)
- company "Acme" (id: 01kxn0tsd…)
</context>
```

Labels pass through `PromptText::sanitize()` like every other injected label.
`ProcessChatMessage` queries the table for the conversation (one indexed query)
and calls `withContextLedger()`. Cost ≈ a few dozen tokens per turn; benefit:
turn-2 follow-ups act on ids instead of re-searching by name. The current
turn's mention/page blocks stay as-is and take precedence; the prompt says so.

# Section 6 — Pill restyle + strip removal (fixes D4, D5)

- Pill adopts the mention chip's visual tokens and gains a record-type icon
  (building/user/banknotes/check/document per type), keeping the ×, the
  "stop sending, don't hide" semantics, and per-record dismissal reset.
- The bottom suggested-prompts strip and `ChatSidePanel::$suggestedPrompts` are
  deleted. Single prompt source: `getSuggestedPrompts()` → `starterPrompts` →
  empty-state chips, updated live via `chat:context-updated`.
- The full-page `/chats` and dashboard remain contextless by design (no record
  in view); explicit @mentions are the mechanism there — matching Attio.

# Error handling

- Unmatchable/foreign URL, unknown route, malformed id → empty context, never
  an exception surfaced to the panel.
- Cross-team or unviewable record in a URL → empty context (D6 guard); the
  send path re-validates independently.
- `source` rows with a type no longer resolvable render label-only chips
  (no link), same as mentions today.

# Testing

- **`ChatSidePanelContextTest` reworked and strengthened:** URL-based
  resolution makes the container fake unnecessary — tests pass real URLs
  (`/app/{tenant}/people/{id}`, `/app/{tenant}/tasks?tableActionRecord={id}`,
  cross-team URL, garbage URL) through the real service. Asserts the
  `chat:context-updated` dispatch.
- **`PageContextBindingTest` extended:** persistence of the `page_context`
  source row; no row when dismissed; ledger block renders prior-turn ids;
  ledger absent on turn 1.
- **Existing suites:** mention persistence tests must still pass with the
  `source` column defaulting to `'mention'`.
- **Browser verification (production-shaped, per project chat rules):** pill
  updates live across SPA navigation without re-mount (draft survives);
  interactive Task modal open binds (or the documented fallback engages);
  history chips render after send and rehydrate on reload; dismissal produces
  no chip and no ledger entry; the "on Acme's page, @mention Globex" precedence
  walk; light + dark + mobile screenshots.

# Sequencing (single PR, ordered commits)

1. Section 1 (+D6 guard) with reworked tests — the architectural fix everything
   else stands on.
2. Section 2 + Section 3 (probe Filament URL-sync behaviour first).
3. Section 4 migration + persistence + history chips.
4. Section 5 ledger.
5. Section 6 restyle + strip deletion.
6. Full browser verification matrix; shard rebalance if test timings moved.

# Out of scope

- Notion-style scope selector; per-response "references used" disclosure
  (future candidates, noted from research).
- Naming the assistant (separate decision, see ClickUp Brain research).
- Making mentions themselves re-inject ids into *stored text* — the ledger
  covers the need without rewriting the parser.
