# AI Chat World-Class Program Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take the assistant from "works" to best-in-class: Telegram-feel transcript, record chips and display-block cards instead of markdown, broader tool coverage, voice input.

**Architecture:** One Livewire component + Alpine keyed x-for stays (Livewire 4 islands cannot loop). Phase 1 restructures the front-end once into Blade partials + ES modules and seeds a block-renderer registry; every later phase plugs into that registry or the tool-result envelope instead of re-opening the transcript.

**Tech Stack:** Laravel 13 / PHP 8.5, Livewire 4.4, Alpine, TipTap 3, laravel/ai 0.10.x, League CommonMark (server render), marked 18 + DOMPurify 3 (client render), Reverb, Horizon, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-18-ai-chat-world-class-design.md`

## Global Constraints

- Assistant name: **Rela** (assumed from the spec recommendation, NOT yet user-confirmed; swappable via `CHAT_ASSISTANT_NAME`, so PR 1 is safe to build while the name is pending).
- Pre-commit gates, in order: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run` (apply if it suggests), `vendor/bin/phpstan analyse`, `composer test:type-coverage` (must stay 100%), `php artisan test --compact` (targeted filters fine).
- Never the em-dash character (U+2014) in any file, commit, or PR text.
- User-facing strings wrapped in `__()` (PHPStan i18n rules enforce on guarded methods/properties).
- Eloquent writes only inside `app/Actions/` or existing grandfathered files (`EloquentWriteOutsideActionRule`).
- Migrations: `up()` only. PostgreSQL only.
- Tests live only in declared suites (`tests/{Arch,PHPStan,Smoke,Feature,Browser}`); extend existing files covering the same scope before creating new ones; declare `mutates(Class::class)`.
- Chat changes are verified against the production-shaped stack before "done": Horizon running, `QUEUE_CONNECTION=redis`, Reverb up, real browser walk.
- Visual changes are screenshot-verified with agent-browser in light, dark, and mobile, including empty states. Design tokens from `resources/css/theme.css` only.
- Every prompt change to `CrmAssistant` gets a guard in `tests/Feature/Chat/CrmAssistantInstructionsTest.php` (create if absent; check for an existing instructions test first).
- Known Alpine traps: JSON round-trip documents pulled from Alpine state before `setDocument` (Proxy poison); use `setTimeout`, not `$nextTick`, for critical dispatches.
- Deploy note for every chat PR: chat workers run `max-jobs=0`; releases require `horizon:terminate`.
- JS bundle: `packages/Chat/resources/js/chat.js` is a Vite entry; run `npm run build` after JS changes.

## PR map

| PR | Tasks | Ships |
|---|---|---|
| 1 | 1 | Phase 0: name the assistant |
| 2 | 2-4 | Phase 1a: restructure (behavior-preserving) |
| 3 | 5-10 | Phase 1b+1d: feel mechanics + stability fixes |
| 4 | 11 | Phase 1c: visual polish |
| 5 | 12-14 | Phase 2: inline record chips |
| 6 | 15-16 | Phase 2: display blocks |
| 7 | 17-18 | Phase 3: activity tool + in-conversation search |
| 8 | 19 | Phase 3: escort audit (+ gated comments/email tools) |
| 9 | 20 | Phase 4: voice input |

PR 10 (Task 21, team display templates) is REMOVED. Display field selection is
derived inside PR 6; see the Task 21 ruling.

Sequential merges; each PR leaves main green.

---

### Task 1: Name the assistant (Rela)

**Files:**
- Modify: `packages/Chat/config/chat.php` (add key)
- Modify: `packages/Chat/src/Agents/CrmAssistant.php` (identity line in `staticInstructions()`)
- Modify: `packages/Chat/resources/views/livewire/app/chat/chat-side-panel.blade.php:239` (heading "Chat")
- Modify: every other user-visible "Chat"/assistant label found by the greps in Step 1
- Test: `tests/Feature/Chat/AssistantNameTest.php`

**Interfaces:**
- Produces: `config('chat.assistant_name')` returning `'Rela'`; lang key `__('chat::chat.assistant_name')` if a chat lang namespace exists, otherwise inline `config()` reads in Blade.

- [ ] **Step 1: Find every user-facing label**

Run and record results:
```bash
grep -rn ">Chat<\|'Chat'\|\"Chat\"" packages/Chat/resources/views --include="*.blade.php"
grep -rn "Relaticle CRM Assistant\|CRM Assistant" packages/Chat/src packages/Chat/resources
grep -rn "assistant" packages/Chat/resources/views --include="*.blade.php" -i | grep -vi "data-\|class=\|x-"
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\Chat\Agents\CrmAssistant;

mutates(CrmAssistant::class);

it('exposes the assistant name from config', function (): void {
    expect(config('chat.assistant_name'))->toBe('Rela');
});

it('introduces itself by its configured name in the system prompt', function (): void {
    $instructions = (new CrmAssistant)->staticInstructions();

    expect($instructions)->toContain('You are Rela');
    expect($instructions)->not->toContain('Relaticle CRM Assistant,');
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test --compact --filter=AssistantNameTest`
Expected: FAIL (config key missing, prompt says "Relaticle CRM Assistant").

- [ ] **Step 4: Implement**

`packages/Chat/config/chat.php`, near the top-level keys:
```php
'assistant_name' => env('CHAT_ASSISTANT_NAME', 'Rela'),
```

`CrmAssistant::staticInstructions()` first line becomes (keep the rest verbatim; note the heredoc is static, so interpolate via a small concat):
```php
$name = (string) config('chat.assistant_name', 'Rela');

return "You are {$name}, the Relaticle CRM assistant." . <<<'PROMPT'
 ...existing prompt body from the second sentence on...
PROMPT;
```
Careful: the existing heredoc starts with the identity sentence; move ONLY that sentence out. Do not touch any other prompt section (prompt caching depends on the prefix being stable per deploy, which a config read preserves).

Blade labels: replace hardcoded "Chat" headings with `{{ config('chat.assistant_name') }}` where the string names the assistant (panel heading), NOT where it labels the feature generically (e.g. "All chats" stays). Judgment call per hit from Step 1; list the choices in the PR body.

- [ ] **Step 5: Run tests, gates, commit**

Run: `php artisan test --compact --filter="AssistantNameTest|CrmAssistantInstructions"` then the pre-commit gates.
```bash
git add -A && git commit -m "feat(chat): name the assistant Rela via config"
```

- [ ] **Step 6: Browser-verify + screenshot**

agent-browser: open the app panel, confirm the side panel heading reads "Rela", screenshot light+dark. Attach to PR 1.

---

### Task 2: Extract Blade partials (behavior-preserving)

**Files:**
- Modify: `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php`
- Create: `packages/Chat/resources/views/livewire/chat/partials/_transcript.blade.php`
- Create: `packages/Chat/resources/views/livewire/chat/partials/_composer.blade.php`
- Create: `packages/Chat/resources/views/livewire/chat/partials/_banners.blade.php`

**Interfaces:**
- Produces: three `@include('chat::livewire.chat.partials.*')` blocks. Alpine scope is unchanged (includes render inside the same `x-data` root), so every `x-` reference keeps resolving.

- [ ] **Step 1: Baseline the suite**

Run: `php artisan test --compact --filter=Chat` and `php artisan test --compact tests/Browser/Chat` (Browser suite runs non-parallel). Record counts; this is the behavior-preservation contract.

- [ ] **Step 2: Move markup**

Cut the transcript loop (the `<template x-for>` over `messages` and everything message-related) into `_transcript.blade.php`; the composer (TipTap mount, model picker, send button, docked proposal region) into `_composer.blade.php`; error/rate-limit/retry banners into `_banners.blade.php`. Replace each region in `chat-interface.blade.php` with the matching `@include`. Move NOTHING else: no class changes, no reordering, no renamed Alpine props. The TipTap mount keeps `wire:ignore` and `x-ref="editor"` exactly as-is (morphdom wipes it otherwise).

- [ ] **Step 3: Verify identical behavior**

Run the same two suites; counts must match Step 1. agent-browser: send a message on the side panel, watch it stream, confirm proposal card renders (use the seeded ChatQaSeeder fixture flow).

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "refactor(chat): extract transcript, composer, and banner partials"
```

---

### Task 3: Split the Alpine mega-object into ES modules

**Files:**
- Modify: `packages/Chat/resources/js/chat.js`
- Create: `packages/Chat/resources/js/chat/stream.js`
- Create: `packages/Chat/resources/js/chat/send.js`
- Create: `packages/Chat/resources/js/chat/transcript.js`
- Modify: `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php` (the inline `x-data` script block shrinks to composition)

**Interfaces:**
- Produces: `window.Alpine.data('chatInterface', ...)` composed as `{...transcriptModule(), ...sendModule(), ...streamModule(), ...remaining inline state}`. Function names and state keys are IDENTICAL to today's inline ones (`handleTextDelta`, `handleToolResult`, `sendMessage`, `reconcileLatestAssistant`, `messages`, `isStreaming`, ...): this task moves code, it does not rename.

- [ ] **Step 1: Inventory the inline component**

List every function and state key in the blade's Alpine object (there are roughly 60). Assign each to stream (Reverb event routing, invocation-bound bubble logic, watchdog, reconcile), send (sendMessage, retry/resume, rate-limit handling, optimistic bubble), transcript (message array manipulation, scroll pinning, jump pill, copy, edit), or "stays inline" (wiring, refs, panel state).

- [ ] **Step 2: Move one module at a time, building between each**

For each module: create the file exporting a plain-object factory, e.g.
```js
export const streamModule = (ctx) => ({
    handleTextDelta(event) { /* moved body, unchanged */ },
    // ...
});
```
Import and spread in the component definition. After EACH module move: `npm run build`, then re-run `php artisan test --compact tests/Browser/Chat`.

- [ ] **Step 3: Full verification**

Both suites at Task 2 baseline counts. agent-browser full loop: send, stream, approve a proposal, regenerate a message, hit the 429 path (12 rapid sends on Free plan seeds).

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "refactor(chat): split alpine component into stream, send, transcript modules"
```

---

### Task 4: Seed the block registry

**Files:**
- Create: `packages/Chat/resources/js/chat/blocks.js`
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_transcript.blade.php` (proposal card include renders via registry lookup)

**Interfaces:**
- Produces: `registerBlock(type, templateRefId)` and `blockTemplate(type)` in `blocks.js`; type `'pending_action'` registered to the existing proposal-card template. Phase 2 registers `'record_card'` and `'records_table'` here without touching the transcript again.

- [ ] **Step 1: Implement the registry**

```js
const registry = new Map();

export const registerBlock = (type, templateRefId) => registry.set(type, templateRefId);
export const blockTemplate = (type) => registry.get(type) ?? null;

registerBlock('pending_action', 'chat-block-pending-action');
```

Wrap the existing proposal-card markup in `<template id="chat-block-pending-action">`-style x-template usage only if the current x-for structure permits a zero-behavior-change wrapper; otherwise keep the proposal card render inline and have the registry map `'pending_action'` to the existing partial include marker. The deliverable is the registry existing and the transcript consulting it for which block types are renderable (unknown types render nothing, silently).

- [ ] **Step 2: Verify + commit**

Browser suite at baseline. `git commit -m "feat(chat): block renderer registry seeded with proposal card"`. PR 2 done: run the production-shaped walk before opening it.

---

### Task 5: Send-state machine (queued/sending/sent/failed) + F2 fix

**Files:**
- Modify: `packages/Chat/resources/js/chat/send.js`
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_transcript.blade.php` (state glyph on user bubbles)
- Test: `tests/Browser/Chat/SendStateTest.php`

**Interfaces:**
- Consumes: optimistic user bubble + `clientKey` (already exist).
- Produces: `msg.sendState` in `'sending'|'sent'|'failed'` on user messages; `resendMessage(msg)` in send.js.

- [ ] **Step 1: Write the failing browser test**

Copy the login/team setup idiom from `tests/Browser/Chat/SidePanelTest.php`. Assert: after sending, the user bubble carries `data-send-state="sending"` then `data-send-state="sent"`; with the send route made to fail (hit the 429 path by exhausting the per-team limiter in the test seed), the bubble carries `data-send-state="failed"` and a visible resend control, and there is exactly ONE user bubble (F2: no duplicate).

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Livewire\Chat\ChatInterface;

mutates(ChatInterface::class);

it('marks an optimistic bubble failed on rate limit without duplicating it', function (): void {
    config()->set('chat.sends_per_minute', 0);

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}");

    // open the side panel, type, send: reuse the exact selectors from SidePanelTest/DuplicateSendTest
    // then:
    $page->assertSourceHas('data-send-state="failed"');
    expect(substr_count($page->content(), 'data-user-bubble'))->toBe(1);
});
```
(Adapt selectors from the existing chat browser tests; the config key for the limiter is whatever `ChatSendRateLimitTest` uses: read it first.)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Browser/Chat/SendStateTest.php`
Expected: FAIL (no `data-send-state` attribute exists).

- [ ] **Step 3: Implement**

In send.js: stamp `sendState: 'sending'` on the optimistic message; on the send fetch resolving 2xx set `'sent'`; on 429/5xx set `'failed'` and KEEP the bubble (delete the current pop-or-duplicate handling: the F2 bug is the duplicate on retry-after-429, fixed by reusing the same clientKey bubble on resend). `resendMessage(msg)` re-runs the send with the same clientKey and flips state back to `'sending'`. Transcript partial: glyph per state (clock / check / warning + resend button), `data-send-state` attribute for tests.

- [ ] **Step 4: Verify + commit**

Test passes; `DuplicateSendTest` and `ChatSendRateLimitTest` still green. `git commit -m "feat(chat): per-message send states with safe resend"`.

---

### Task 6: Per-conversation drafts

**Files:**
- Modify: `packages/Chat/resources/js/chat/send.js` (persist), `packages/Chat/resources/js/chat-editor.js` (restore hook)
- Test: `tests/Browser/Chat/ComposerDraftTest.php`

**Interfaces:**
- Produces: localStorage key `chat.draft.{conversationId}` holding the TipTap JSON document; `saveDraft()`, `restoreDraft(conversationId)`, `clearDraft(conversationId)` in send.js.

- [ ] **Step 1: Failing browser test**

Type into the composer, navigate away (switch to another conversation or reload the page), return, assert the composer contains the typed text. Second case: send successfully, reload, assert composer empty.

- [ ] **Step 2: Implement**

Debounced (400ms) `saveDraft()` on TipTap update; restore on editor mount and on conversation switch; clear on send success. CRITICAL: the restored document goes through a JSON round-trip (`JSON.parse(JSON.stringify(doc))` or the existing `plainDocument()` helper) before `setDocument`: Alpine-Proxy documents throw inside TipTap's structuredClone and silently kill subsequent lines.

- [ ] **Step 3: Verify + commit**

`git commit -m "feat(chat): persist per-conversation composer drafts"`

---

### Task 7: Instant conversation switching (cache-first render)

**Files:**
- Modify: `packages/Chat/resources/js/chat/transcript.js`
- Test: `tests/Browser/Chat/ConversationSwitchTest.php`

**Interfaces:**
- Consumes: `fetchMessages()` Livewire method (server truth), `reconcileLatestAssistant()`.
- Produces: in-memory `conversationCache` Map (last 5 conversations, LRU), `switchConversation(id)` rendering cache first, reconciling after.

- [ ] **Step 1: Failing test**

Open conversation A (seeded with messages), switch to B, switch back to A: transcript content is present immediately (assert without waiting for network idle), and after reconcile the content matches server state (send a message to A from a second session first, assert it appears after reconcile).

- [ ] **Step 2: Implement**

On leaving a conversation, stash `messages` array in the Map (cap 5, evict oldest). On entering: if cached, render from cache and fire `fetchMessages` in the background, replacing wholesale on response (server wins). No cache across reloads (memory only): drafts cover input, `prerendered` covers content.

- [ ] **Step 3: Verify + commit**

`git commit -m "feat(chat): cache-first conversation switching"`

---

### Task 8: Message grouping, day separators, sticky date

**Files:**
- Modify: `packages/Chat/resources/js/chat/transcript.js` (computed decorations)
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_transcript.blade.php`
- Test: `tests/Browser/Chat/TranscriptShapeTest.php`

**Interfaces:**
- Produces: `decorations(index)` returning `{grouped: bool, daySeparator: ?string}` per message; used only by the partial.

- [ ] **Step 1: Failing test**

Seed a conversation (factory or ChatQaSeeder pattern) with: two user messages 1 minute apart, one 4 minutes later, and one dated yesterday. Assert: exactly one day separator rendered (`data-day-separator`), the 1-minute pair renders grouped (second bubble has `data-grouped`), the 4-minute one does not.

- [ ] **Step 2: Implement**

`grouped` = same role as previous AND created_at gap under 3 minutes AND no pending_actions on the previous message. `daySeparator` = formatted date when the calendar day (user timezone, from the existing page context) differs from the previous message. Sticky date header: a positioned element updated via IntersectionObserver over the separators; respect `prefers-reduced-motion` for its show/hide transition.

- [ ] **Step 3: Verify + commit**

`git commit -m "feat(chat): message grouping and day separators"`

---

### Task 9: Auto load-earlier on scroll-up

**Files:**
- Modify: `packages/Chat/resources/js/chat/transcript.js`
- Test: extend `tests/Browser/Chat/TranscriptShapeTest.php`

**Interfaces:**
- Consumes: `loadEarlierMessages()` Livewire method + `chat:messages-prepended` event (both exist; PAGE_SIZE 50).

- [ ] **Step 1: Failing test**

Seed 120 messages. Open the conversation: 50 render. Scroll to top: 50 more load without a click, scroll position is preserved (the previously-top message stays in view; assert its element is still in viewport).

- [ ] **Step 2: Implement**

IntersectionObserver on a top sentinel calls `$wire.loadEarlierMessages()` (guarded: not already loading, `hasMoreMessages`). On `chat:messages-prepended`, restore scroll by anchoring to the pre-prepend first message's offset.

- [ ] **Step 3: Verify + commit**

`git commit -m "feat(chat): infinite scroll-up history loading"`

---

### Task 10: Keyboard layer (Cmd+K switcher, Esc, ArrowUp edit)

**Files:**
- Modify: `packages/Chat/resources/js/chat/transcript.js` (bindings), `packages/Chat/resources/views/livewire/chat/partials/_composer.blade.php`
- Create: `packages/Chat/resources/views/livewire/chat/partials/_switcher.blade.php`
- Test: `tests/Browser/Chat/KeyboardTest.php`

**Interfaces:**
- Consumes: `GET /chat/conversations` (route `chat.conversations`, exists) for the switcher list; the existing edit flow (`startEdit`).

- [ ] **Step 1: Failing test**

Cmd+K opens an overlay listing conversations filtered as you type; Enter opens the highlighted one. Esc closes the panel/overlay. ArrowUp in an empty composer enters edit mode on your last user message.

- [ ] **Step 2: Implement**

Switcher overlay: Alpine component fetching `route('chat.conversations')`, fuzzy filter on title client-side, arrow-key navigation. Bindings scoped to the chat root element (not document-wide grabs; Filament has its own Cmd+K? Check first: if the app panel binds Cmd+K to global search, use Cmd+J and note it in the PR). ArrowUp only when composer empty and no stream active.

- [ ] **Step 3: F1 + F3 stability fixes ride here**

F1: broadcast on resolution. In `PendingActionService` approve/reject paths add a broadcast on the conversation's private channel as event `pending_action.resolved` with `{pending_action_id, status}`; client reconcile marks matching cards. Feature test with `Broadcast::fake()` asserting the event on approve AND reject.
F3: in the feedback thumbs handler, toggling off with a saved category/comment asks `confirm()` (native is fine) before DELETE `chat.messages.feedback.destroy`.

- [ ] **Step 4: Verify + commit + PR 3**

All Phase 1b tests green, chat Feature suite green, production-shaped walk, `git commit -m "feat(chat): keyboard layer, resolved-card broadcast, feedback delete confirm"`. Open PR 3.

---

### Task 11: Visual polish pass (Phase 1c)

**Files:**
- Modify: all chat Blade surfaces (side panel, all-chats panel, conversation page, dashboard partial, composer, bubbles, cards, chips, banners, empty states)
- No PHP behavior changes.

This task is design work with a hard verification gate, not TDD.

- [ ] **Step 1: Baseline references**

agent-browser: screenshot every surface as-is: side panel (empty + active + proposal docked), all-chats panel, full conversation page, dashboard partial. Light, dark, mobile (390px). Store under the PR branch for before/after.

- [ ] **Step 2: Apply the standard, surface by surface**

Use the frontend-design skill. Constraints: tokens from `resources/css/theme.css` only; composer becomes an input bar (rounded container, inline model picker + mic slot + send, mention chips restyled); bubble typography loosened (prose overrides trimmed); proposal card lightened (header tile smaller, field rows airier); follow-up chips + status pills to one pill spec; empty states get real CRM-persona copy (no lorem, no "No messages yet" alone). One commit per surface.

- [ ] **Step 3: Gate**

Every surface re-screenshotted light/dark/mobile including empty states; browser suite at baseline; PR 4 body shows before/after pairs.

---

### Task 12: `/r/{type}/{id}` reference redirect route

**Files:**
- Create: `packages/Chat/src/Http/Controllers/RecordRedirectController.php`
- Modify: `packages/Chat/routes/chat.php`
- Test: `tests/Feature/Chat/RecordRedirectTest.php`

**Interfaces:**
- Consumes: `RecordReferenceResolver::urlFor(string $entityType, string $recordId): ?string` (exists).
- Produces: route name `chat.record-redirect`, path `/r/{type}/{id}`, auth-gated, 302 to the panel URL or 404.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Relaticle\Chat\Http\Controllers\RecordRedirectController;

mutates(RecordRedirectController::class);

it('redirects a member to the record view', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $company = Company::factory()->for($team)->create();

    $this->actingAs($user)
        ->get("/r/company/{$company->getKey()}")
        ->assertRedirect(); // Location contains the CompanyResource view URL
});

it('404s for a record outside the current team', function (): void {
    $user = User::factory()->withTeam()->create();
    $other = Company::factory()->create(); // other team

    $this->actingAs($user)->get("/r/company/{$other->getKey()}")->assertNotFound();
});

it('404s for an unknown type', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->get('/r/wormhole/123')->assertNotFound();
});

it('requires auth', function (): void {
    $this->get('/r/company/123')->assertRedirect(); // to login
});
```

- [ ] **Step 2: Run to verify it fails** (`--filter=RecordRedirectTest`, expect 404s/route-missing)

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Relaticle\Chat\Support\RecordReferenceResolver;

final readonly class RecordRedirectController
{
    public function __invoke(RecordReferenceResolver $resolver, string $type, string $id): RedirectResponse
    {
        $url = $resolver->urlFor($type, $id);

        abort_if($url === null, 404);

        return redirect($url);
    }
}
```
Route (inside the existing `auth:web` group in `packages/Chat/routes/chat.php`):
```php
Route::get('/r/{type}/{id}', RecordRedirectController::class)->name('chat.record-redirect');
```
Permalink contract (this route is frozen public surface once shipped): `/r/{type}/{id}` URLs persist in transcripts, copies, and external docs forever, so the path shape never changes and the route never moves hosts. The controller is the adapter that absorbs ALL future change: panel URL restructures resolve at request time, and renamed type slugs get permanent aliases in the controller's type map (e.g. a future `contacts` maps alongside `people`). New reference kinds extend the same namespace with new type segments instead of new schemes.

The ownership check in the controller is REQUIRED, not optional: `urlFor()` builds panel URLs blindly via `Resource::getUrl()` with no DB query for every CRM type (verified in `RecordReferenceResolver`), so it happily returns URLs for foreign and nonexistent ids. Before redirecting, map the type to its model class (same map as the resolver: company/people/opportunity/task/note), fetch the record, and `abort_unless` its `team_id` matches the user's current team (404 both for missing and foreign records so the response does not leak existence).

- [ ] **Step 4: Verify + gates + commit**

`git commit -m "feat(chat): authenticated record reference redirect route"`

---

### Task 13: Read tools emit `/r/` reference URLs

**Files:**
- Modify: `packages/Chat/src/Support/RecordReferenceResolver.php` (add `referenceUrl()`)
- Modify: `packages/Chat/src/Tools/BaseReadShowTool.php` (the `['url' => ...]` merge) and `packages/Chat/src/Tools/BaseReadListTool.php` (wherever it stamps per-row `url`)
- Test: extend the existing Get/List tool tests (e.g. `tests/Feature/Chat/` GetCompanyTool coverage; find with `grep -rl "GetCompanyTool" tests/Feature`)

**Interfaces:**
- Produces: `RecordReferenceResolver::referenceUrl(string $entityType, string $recordId): string` returning `"/r/{$entityType}/{$recordId}"` for chip-able types (company, people, opportunity, task, note) and falling back to `urlFor()` for the rest (custom_field keeps its settings deep-link).
- NOT changed: `urlFor()`, mention URLs, proposal-card "View" links (absolute panel URLs stay).

- [ ] **Step 1: Failing test**

Assert a Get tool payload's `url` equals `/r/company/{id}` and a List tool row url likewise.

- [ ] **Step 2: Implement**

```php
private const array CHIP_TYPES = ['company', 'people', 'opportunity', 'task', 'note'];

public function referenceUrl(string $entityType, string $recordId): ?string
{
    if (in_array($entityType, self::CHIP_TYPES, true)) {
        return "/r/{$entityType}/{$recordId}";
    }

    return $this->urlFor($entityType, $recordId);
}
```
Read tool bases swap their `url` assembly to `referenceUrl()`. The model keeps rendering `[Name](url)` per the existing Citations prompt: zero prompt change in this task.

- [ ] **Step 3: Verify + commit**

Existing tool tests updated (url assertions), suite green. `git commit -m "feat(chat): read tools cite records via /r/ reference urls"`

---

### Task 14: Chip rendering on both pipelines + copy rewrite

**Files:**
- Modify: `packages/Chat/src/Support/MarkdownRenderer.php` (chip renderer for `/r/` links)
- Modify: `packages/Chat/resources/js/chat.js` (`renderMarkdown` post-sanitize sweep)
- Modify: `packages/Chat/resources/js/chat/transcript.js` (`copyMessage` href absolutization)
- Test: `tests/Feature/Chat/ChipRenderingTest.php` + browser assertion in an existing chat browser test

**Interfaces:**
- Produces: anchors with `href^="/r/"` render as `<a class="chat-chip" data-record-type="{type}" href="/r/...">` with a type icon; identical markup server and client. Copy output rewrites `](/r/` to `]({appUrl}/r/`.

- [ ] **Step 1: Failing server test**

```php
<?php

declare(strict_types=1);

use Relaticle\Chat\Support\MarkdownRenderer;

mutates(MarkdownRenderer::class);

it('renders record reference links as chips', function (): void {
    $html = (new MarkdownRenderer)->render('See [Acme](/r/company/01ABC) today.');

    expect($html)->toContain('class="chat-chip"')
        ->toContain('data-record-type="company"')
        ->toContain('href="/r/company/01ABC"')
        ->toContain('>Acme<');
});

it('leaves ordinary links alone', function (): void {
    $html = (new MarkdownRenderer)->render('[site](https://example.com)');

    expect($html)->not->toContain('chat-chip');
});

it('escapes hostile labels', function (): void {
    $html = (new MarkdownRenderer)->render('[<img src=x onerror=1>](/r/company/01ABC)');

    expect($html)->not->toContain('<img');
});
```

- [ ] **Step 2: Implement server side**

League CommonMark: register a custom renderer for `Link` nodes in the `Environment` (docs: commonmark.thephpleague.com/2.x rendering). When `getUrl()` starts with `/r/`, emit the chip markup (label from child text, already escaped by the renderer chain since `html_input: strip` ran); otherwise delegate to the default `LinkRenderer`. Icon: inline SVG per type keyed off the path segment (reuse the heroicon names from `record-card.blade.php`'s `$icons` map).

- [ ] **Step 3: Implement client side**

In `renderMarkdown` after `DOMPurify.sanitize`: parse into a detached element, `querySelectorAll('a[href^="/r/"]')`, add the class/data attributes/icon span, serialize back. (Sweep AFTER sanitize so the injected icon markup is ours, not the model's.) `wire:navigate` must NOT be added: the `/r/` route is a server redirect.

- [ ] **Step 4: Copy rewrite**

In `copyMessage`: `text.replaceAll('](/r/', '](' + window.location.origin + '/r/')`.

- [ ] **Step 5: Chip styling**

`chat-chip`: inline-flex pill, subtle border, type icon, truncation at ~24ch, light+dark. Screenshot both modes. Browser test asserts a streamed reply citing a record shows `.chat-chip`.

- [ ] **Step 6: Verify + gates + commit + PR 5**

`git commit -m "feat(chat): record chips on both render pipelines"`. Production-shaped walk (stream a real reply with citations), screenshots, open PR 5.

---

### Task 15: `display_block` envelope + reload derivation

**Files:**
- Modify: `packages/Chat/src/Tools/BaseReadListTool.php`, `packages/Chat/src/Tools/BaseReadShowTool.php`
- Modify: `packages/Chat/src/Actions/ListConversationMessages.php`
- Modify: `packages/Chat/src/Livewire/Chat/ChatInterface.php` (`latestAssistantMessage` returns blocks too)
- Test: `tests/Feature/Chat/DisplayBlockTest.php`

**Interfaces:**
- Produces: read tool result JSON gains two top-level keys: `display_block` (`{block: 'records_table'|'record_card', title: string, columns?: list<{key,label}>, rows?: list<{id,url,cells:map}>, fields?: list<{label,value,type?}>}`) alongside the existing model-facing payload (untouched: the model still reasons over `attributes`/`included`).
- `ListConversationMessages` message arrays gain `display_blocks: list<block>` derived from persisted `tool_results` (same mechanism as `collectPendingActionIds`).
- `latestAssistantMessage()` return gains the same key for stream_end reconcile.

- [ ] **Step 1: Failing tests**

Five: (a) `ListCompaniesTool` result JSON contains a `records_table` block whose rows carry `/r/` urls and formatted cells; (b) `GetCompanyTool` result contains a `record_card` block; (c) after a turn persists, `ListConversationMessages` returns the assistant message with `display_blocks` non-empty. For (c) insert a fake `tool_results` row shaped like the real column content (copy the shape from an existing pending-action test: find with `grep -rl "tool_results" tests/Feature/Chat`).

Plus two for the derived selection: (d) a custom field with `list_toggleable_hidden` true is ABSENT from the `records_table` columns but PRESENT in the `record_card` (it is still `visible_in_view`), and flipping `visible_in_list` to false removes it from both; (e) filtering `ListOpportunitiesTool` on a hidden field puts that field's column FIRST in the block, proving query-aware promotion overrides the team's list setting. Both (d) and (e) assert against a second team's fields too: selection is per-tenant and must never leak.

- [ ] **Step 2: Implement tool side**

Field selection is DERIVED from the team's existing custom-field visibility settings, never from a first-N-fields heuristic and never from a chat-only config table (see the Task 21 ruling below). Add `packages/Chat/src/Services/Tools/DisplayFieldSelector.php`, a `final readonly` sibling of `CustomFieldsSchemaDescriber`, with two methods:

- `listFields(Team $team, string $entityType): list<CustomField>`: mirrors what the Filament TABLE shows: `App\Models\CustomField::query()->where('tenant_id', $team->getKey())->where('entity_type', $entityType)->active()->visibleInList()`, then drop rows whose `settings->list_toggleable_hidden` is true, `orderBy('sort_order')`.
- `cardFields(Team $team, string $entityType): list<CustomField>`: mirrors what the Filament VIEW page shows: same query with `visibleInView()` instead, `orderBy('sort_order')`, no toggleable filter.

Query through `App\Models\CustomField` (the arch rules forbid the package model directly); the `active()`/`visibleInList()`/`visibleInView()` scopes come from the package's `CustomFieldQueryBuilder`. Take `Team` explicitly and never rely on ambient tenant scoping: these tools run inside a queued Horizon job with no Filament tenant bound.

In the list base: columns are the entity's core name/title column plus `listFields()`, rows capped at 10 with `total`. In the show base: `record_card` rows are the core fields plus `cardFields()`, formatted by `CustomFieldsDisplayFormatter` (label/value/type), capped at 8 fields.

Query-aware promotion (this is what makes selection smart rather than merely derived): any field code the tool call itself filtered or sorted on is moved to the front of the derived list, and is INCLUDED even when the team hid it from the list. Ask for "opportunities closing this month over $50k" and close_date/amount lead the table regardless of settings. Implement as one reorder step over the selector's output inside the block builder, using the filter/sort codes the tool already parses; it holds no state and gets no service of its own. Cap still applies after promotion.

Keep `display_block.display` small: formatted strings only, no raw HTML, no full custom-field dumps.

- [ ] **Step 3: Implement derivation + reconcile**

`ListConversationMessages`: a `collectDisplayBlocks(?string $toolResults): array` sibling of the pending-action extraction, attached per message. `latestAssistantMessage()` mirrors it. `StreamEventBroadcaster` is NOT modified: read results stay un-broadcast (Reverb 10 KB cap), blocks appear at stream_end reconcile and on reload.

Token cost containment (required, not optional): `tool_results` are replayed to the model on every later turn, and `display_block` is presentation-only, so replaying it is pure waste (1-2 KB per list call, forever, against a prompt-cached prefix we fought to shrink). Strip the `display_block` key from tool results when building the replayed agent history: `SupersededAwareConversationStore` already filters history on replay and is the precedent hook; add the strip there with a Feature test asserting the replayed history for a turn with a block does not contain `"display_block"` while the persisted row still does.

- [ ] **Step 4: Verify + commit**

`git commit -m "feat(chat): display_block envelope on read tools with reload derivation"`

---

### Task 16: Render blocks client-side + prompt relaxation

**Files:**
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_transcript.blade.php` (block templates)
- Modify: `packages/Chat/resources/js/chat/blocks.js` (register `records_table`, `record_card`)
- Modify: `packages/Chat/src/Agents/CrmAssistant.php` (Formatting rule 3 + Rules section)
- Test: browser block-render test + `CrmAssistantInstructionsTest` guard

**Interfaces:**
- Consumes: `msg.display_blocks` from Task 15; registry from Task 4; typed field row markup patterns from `_proposal-field.blade.php`.

- [ ] **Step 1: Templates**

`records_table`: header from `columns`, rows link the first cell via the row `url` (chip-styled), "Showing X of Y" footer: port the dead `data-table.blade.php` markup (then DELETE `record-card.blade.php` and `data-table.blade.php`: they are referenced by nothing; the marketing partial that hand-copies the markup stays untouched). `record_card`: port the field-row treatment from `_proposal-field.blade.php`.

- [ ] **Step 2: Prompt change**

Rules section, rule 3 becomes: lists and single records are rendered by the block under your reply; reply with ONE short lead-in sentence and do not repeat the data as a markdown table. Guard test: instructions no longer instruct "present results in a compact table format", and do contain the block instruction.

- [ ] **Step 3: Verify live + commit + PR 6**

Production-shaped walk: ask for a company list, confirm the block renders at stream end and after reload, confirm the model stops emitting duplicate markdown tables. Browser test asserts `data-block="records_table"` renders. Screenshots light/dark. `git commit -m "feat(chat): render read results as blocks, relax table prompt"`. Open PR 6.

---

### Task 17: Activity tool

**Files:**
- Create: `packages/Chat/src/Tools/Activity/ListActivityTool.php`
- Modify: `packages/Chat/src/Agents/CrmAssistant.php` (register tool + one Capabilities line)
- Test: `tests/Feature/Chat/ListActivityToolTest.php`

**Interfaces:**
- Consumes: spatie/activitylog tables; models use `LogsActivity`.
- Produces: tool accepting `{record_type?, record_id?, days?: int<=30}` returning changes as a `records_table` display_block (columns: when, who, what changed) + model payload rows.

- [ ] **Step 1: Failing test**

Create a company, update its name, call the tool for that record: result rows include the name change with old/new. CRITICAL: read from BOTH `properties` and `attribute_changes` (spatie v5 split: trait-logged native diffs live in `attribute_changes`; merging both is the known fix for empty "No field changes" output).

- [ ] **Step 2: Implement**

Query `Activity` scoped to the team's records (subject_type map from entity types; `whereMorphedTo` per subject, team ownership via the subject's `team_id`: join or whereIn on ids the team owns, cap 50 rows, latest first). Tenant safety is the test's second case: activity from another team's record never appears.

- [ ] **Step 3: Verify + commit**

`git commit -m "feat(chat): activity history tool"`

---

### Task 18: In-conversation search

**Files:**
- Modify: `packages/Chat/src/Http/Controllers/ChatController.php` (add `searchMessages`)
- Modify: `packages/Chat/routes/chat.php`
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_transcript.blade.php` + `transcript.js` (search overlay + jump)
- Test: `tests/Feature/Chat/ConversationSearchTest.php` + browser case

**Interfaces:**
- Produces: `GET /chat/conversations/{conversationId}/search?q=` returning `{matches: list<{message_id, snippet}>}` (ILIKE over the participant's own non-superseded messages, capped 20); client overlay jumps to a match (loads earlier pages until the id is present, then scrolls + highlights).

- [ ] **Step 1: Failing feature test** (finds a seeded message by substring; excludes superseded rows; 404 on foreign conversation)

- [ ] **Step 2: Implement + browser-verify + commit + PR 7** (Tasks 17+18 together)

`git commit -m "feat(chat): in-conversation message search"`

---

### Task 19: Escort audit + gated tools checkpoint

**Files:**
- Modify: `packages/Chat/src/Support/DestinationResolver.php` (add missing destinations)
- Test: extend its existing test coverage (`grep -rl "DestinationResolver" tests/Feature`)

- [ ] **Step 1: Audit**

List current destinations. Required by spec: custom_fields, import_* (existing), team_members (existing), exports. Add any missing with URL + label + test case.

- [ ] **Step 2: Gated tools decision point**

Comments tools and email-draft tool are specced but their packages (`relaticle/comments` integration, EmailIntegration) are NOT in composer.json on main at plan time. Verify with `grep -n "relaticle/comments\|EmailIntegration" composer.json`. If present now: implement each following the Task 17 shape (read tool + proposal-gated write via a new action in `app/Actions/`, batch input, display_block output, tenant tests). If absent: add escort destinations for them instead and file follow-up issues referencing this plan. Report which branch was taken.

- [ ] **Step 3: Commit + PR 8**

`git commit -m "feat(chat): escort coverage for exports and gated surfaces"`

---

### Task 20: Voice input

**Files:**
- Create: `packages/Chat/src/Http/Controllers/TranscribeController.php`
- Modify: `packages/Chat/routes/chat.php`, `packages/Chat/config/chat.php` (`'voice_enabled' => (bool) env('CHAT_VOICE_ENABLED', true)`)
- Modify: `packages/Chat/resources/views/livewire/chat/partials/_composer.blade.php` + `packages/Chat/resources/js/chat/send.js` (mic button, MediaRecorder)
- Test: `tests/Feature/Chat/TranscribeTest.php`

**Interfaces:**
- Produces: `POST /chat/transcribe` (multipart `audio`, max 25 MB, throttle `10,1` per user) returning `{text: string}`; composer inserts at cursor. Never auto-sends.

- [ ] **Step 1: Failing feature test**

Cases: rejects when `chat.voice_enabled` false (404); rejects missing/oversized file (422); happy path returns text with the AI layer faked. Check the SDK fake surface first: `grep -rn "fake" vendor/laravel/ai/src/Transcription.php vendor/laravel/ai/src/AiManager.php | head`. Use the SDK's fake (`Ai::fake()` family) if it covers transcription; if it does not, bind a fake gateway for the test rather than skipping the assertion.

- [ ] **Step 2: Implement server**

```php
public function __invoke(Request $request): JsonResponse
{
    abort_unless((bool) config('chat.voice_enabled'), 404);

    $request->validate(['audio' => ['required', 'file', 'max:25600', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/wav']]);

    $response = Transcription::fromFile($request->file('audio')->getRealPath())->generate();

    return response()->json(['text' => $response->text]);
}
```
(Exact laravel/ai transcription call: confirm against `vendor/laravel/ai/src/Transcription.php` at implementation time; the class exists in 0.10.3. OpenAI provider only: gate the mic button on an OpenAI key being configured, mirroring how the model picker handles provider availability.)

- [ ] **Step 3: Implement client**

Mic button in the composer (slot reserved by Task 11): MediaRecorder `audio/webm;codecs=opus`, max 120s hard stop, recording state (pulsing), on stop POST and insert returned text at the TipTap cursor. Errors surface in the existing banner area.

- [ ] **Step 4: Verify live + commit + PR 9**

Real browser with a real mic is manual: verify endpoint + insertion with a fixture file upload via agent-browser eval fetch. `git commit -m "feat(chat): voice input via transcription"`.

---

### Task 21: REMOVED. Display field selection is derived, not configured

**Ruling (2026-08-20, user-approved):** Task 21 shipped no code. The
`chat_display_templates` migration, `DisplayTemplate` model,
`SaveDisplayTemplate` action, the team settings UI, `DisplayTemplateTest`,
and PR 10 are all deleted from this plan. Task 15 absorbs the selection logic.

The task's premise was that "nobody can change" which fields represent an
entity in chat. That is false. Every custom field already stores, per tenant,
`visible_in_list`, `list_toggleable_hidden`, `visible_in_view`, and
`sort_order`, editable in the Custom Fields admin UI. A sampled tenant's
`visible_in_list` set for `opportunity` is literally `{amount, close_date,
stage}`, the exact fields Jav's scenario asks for, already configured.

So a chat-only template table would be a SECOND place to store the same fact,
which the project's core rules forbid ("never store the same fact in two
places; pick one source of truth"). Deriving from the existing settings is
strictly better than configuring separately: chat matches what the CRM list
and view pages show, by construction, with no new surface to keep in sync.

Accepted trade: chat display is coupled to list/view visibility. A team that
wants a field in chat but NOT in its table cannot express that. If that
demand ever appears, revisit with a per-field "show in chat" toggle on the
existing custom-field form, extending the one config surface, never adding a
second one.

Hover cards remain deferred (spec marks them optional).

---

## Self-review notes

- Spec coverage: Phase 0 (Task 1), 1a (2-4), 1b (5-10), 1c (11), 1d (10 step 3), 2 chips (12-14), 2 blocks (15-16), 3 (17-19, comments/email explicitly gated: not on main at plan time), 4 (20). Phase 5's template half is REMOVED (see Task 21) and its intent is served inside Task 15 by deriving from custom-field visibility settings. Retrieval pin is spec-only by design; issue #495 tracks the build. Hover cards deferred (spec marks them optional, Phase 5 "later").
- The `HORIZON_CHAT_MAX` raise is an ops decision: flag in PR 3's description, not a code task.
- Type consistency: `referenceUrl()` (Task 13) is what Task 14's sweep and Task 15's rows consume; `display_blocks` key name is shared by Tasks 15 and 16; registry API (`registerBlock`/`blockTemplate`) shared by Tasks 4 and 16.
