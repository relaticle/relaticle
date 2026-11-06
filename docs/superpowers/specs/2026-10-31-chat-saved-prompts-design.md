# Chat saved prompts: library, `/` menu, starter chips

**Date:** 2026-10-31
**Status:** Design approved (founder decisions 2026-10-31), pending implementation plan
**Related:** `2026-10-31-chat-web-research-design.md`. The two ship as independent PRs in either order. The research prompts here search the web only once that work is live.

## Summary

Rela gets saved prompts: six built-in prompts that ship in code, plus prompts a user writes,
kept private or shared with the workspace. A prompt is inserted into the composer as a chip
through a `/` menu or a starter chip on the empty chat. The user can add text and several
chips to one message. The server expands each chip into its body inside the user message,
so the instructions survive every later turn, including a turn resumed after an approval.

## Research

Attio ships the same feature as "Ask Attio prompts":

- 15 library prompts, among them "Run account research" ("Run deep web research on a company
  to capture key information"), "Deal brief", "Follow-up email", "Meeting prep".
- `/` in the composer, "Browse prompts", "Add to my prompts" or "Customize", "+ New prompt"
  with Title, Icon, Prompt. Several prompts per message, extra text allowed.
- Sharing with users, teams or the workspace; admins see all prompts.
- Prompts appear as chat suggestions "tailored to what you're currently viewing".
- No `{variables}`: context comes from the open page. Attio's own prompt guide ends prompts
  with "If there is no relevant ... in context, ask the user to confirm ... before proceeding".

Sources: attio.com/help/reference/attio-ai/ask-attio/create-and-use-ask-attio-prompts,
attio.com/platform/ask, attio.com/changelog/2026/updates-to-ask-attio.
Full research: the 2026-10-31 Attio report in this thread's artifacts.

## Decisions

| # | Decision | Choice |
|---|---|---|
| 1 | Sharing scope | Private or workspace. No teams (a workspace has none) |
| 2 | Built-ins at launch | 6: Account research, Person research, Opportunity brief, Pipeline review, Follow-up email, Weekly summary |
| 3 | Where the body goes | Expanded into the stored user message, not a per-turn system block |
| 4 | Starter chip click | Inserts the chip and focuses the composer; Enter sends |

Why decision 3: a per-turn `<prompts>` block in `CrmAssistant::dynamicInstructions()` exists
for one turn only. A research prompt that asks for web access ends its turn, and the resumed
turn (`TurnContinuationService::resume()`) would lose the instructions. Content in the user
message is replayed on every turn, sits in the cached history prefix, and is a snapshot, so
editing or deleting the prompt later never changes an old conversation.

## Design

### 1. Built-in library: one owner

`Relaticle\Chat\Enums\BuiltInPrompt`, a string-backed enum. Every surface (the `/` menu, the
starter chips, the settings page, the parser) reads it. Each `match` lists every case with
no `default`.

| Case | `title()` | `icon()` (Remix, line) | `entity()` |
|---|---|---|---|
| `AccountResearch` | Account research | `ri-building-line` | `CrmEntity::Company` |
| `PersonResearch` | Person research | `ri-user-search-line` | `CrmEntity::People` |
| `OpportunityBrief` | Opportunity brief | `ri-briefcase-4-line` | `CrmEntity::Opportunity` |
| `PipelineReview` | Pipeline review | `ri-bar-chart-box-line` | none |
| `FollowUpEmail` | Follow-up email | `ri-mail-send-line` | `CrmEntity::People` or `Company` |
| `WeeklySummary` | Weekly summary | `ri-calendar-check-line` | none |

- `body()` returns the prompt text through `__()`. Bodies live in a new `lang/en/chat-prompts.php`
  group, one key per case, so translators see them.
- A prompt that needs a record carries the rule: "If no {entity} is open or mentioned, ask me
  for its name (or domain, for a company) and do nothing else until I answer."
- `forContext(?CrmEntity $entity): list<self>` returns up to three starter prompts:
  company page: Account research, Follow-up email, Pipeline review; person page: Person
  research, Follow-up email, Weekly summary; opportunity page: Opportunity brief, Follow-up
  email, Pipeline review; no record: Pipeline review, Weekly summary, Account research.
- Research prompts work before web research ships: their bodies say "search the web when web
  access is available". Draft of Account research is in Appendix A.

### 2. User prompts: model, table, policy, actions

Mirrors `EmailTemplate` (`packages/EmailIntegration/src/Models/EmailTemplate.php`), the
existing user-authored, private-or-shared record.

- Migration in root `database/migrations` (all chat tables live there):
  `create_chat_prompts_table` with `ulid id`, the tenant FK via `TenantMigration::foreignKeyColumn()`
  cascading on delete, `foreignUlid created_by` constrained to `users` (null on delete),
  `string title` (80), `string icon` (64), `text body`, `boolean is_shared` default false,
  `string source_key` nullable (the `BuiltInPrompt` it was customized from), timestamps.
  Index `(workspace_id, is_shared)`.
- `Relaticle\Chat\Models\ChatPrompt`: `final`, `#[Table]`, `#[Fillable]`, `#[UsePolicy(ChatPromptPolicy::class)]`,
  `HasUlids`, `HasWorkspace`, `HasFactory`; `creator()` relation; `#[Scope] visibleTo(User $user)`:
  shared, or created by the user. Every reader goes through that scope.
- `Relaticle\Chat\Policies\ChatPromptPolicy`: `before()` denies other workspaces; `view` when
  shared or own; `update`/`delete` for the creator, or for an orphaned shared prompt a holder of
  `WorkspaceCapability::WorkspaceManage` (the `EmailTemplatePolicy` precedent).
- `icon` is validated against `PromptIcon::cases()`, a small enum of about 16 Remix line icons,
  so no free-form icon name reaches a Blade `svg()` call.
- Actions in `packages/Chat/src/Actions/Prompts/`: `CreatePrompt`, `UpdatePrompt`, `DeletePrompt`.
  `final readonly`, one `execute()`, `abort_unless(can(...))`. Body max 4,000 characters, title max 80.

### 3. Composer: `/` menu and prompt chips

- `packages/Chat/resources/js/chat-prompt-suggestion.js`, a sibling of `chat-mention-suggestion.js`:
  `char: '/'`, starts at line start or after a space. It fetches `GET /chat/prompts?q=`
  (new `chat.prompts` route, throttled like `chat.mentions`) and lists three groups: Built-in,
  My prompts, Shared. The last row links to the settings page ("Manage prompts").
- `ChatPrompt` TipTap node in `chat-editor.js`, an inline atom with attrs `source`
  (`builtin` | `custom`), `id` (enum value or ULID), `title`, `icon`. It renders as a chip
  styled like the mention chip with the icon. Enter-to-send already yields while a suggestion
  is active (`chat-editor.js` L87-L104).
- `TipTapDocumentParser::parse()` gains a `prompts` list next to `mentions`. A prompt node adds
  nothing to `text`. A message may hold at most three prompts.
- `ChatController::send()` and `createConversation()`:
  - accept a message whose text is empty when it holds at least one prompt;
  - resolve each custom id through `ChatPrompt::query()->visibleTo($user)`; an unknown or
    hidden id fails with 422, like a foreign mention is dropped;
  - build the stored message as the user's text followed by one block per prompt:
    `<saved_prompt title="Account research">...body...</saved_prompt>`. Titles and bodies pass
    through `PromptText::sanitize()`. The 5,000-character cap applies to the typed text;
    the expanded message is capped at 20,000.
- The static prompt (`CrmAssistant::staticInstructions()`, L342 block list) gains one line:
  `<saved_prompt>` holds instructions the user chose to run; follow them as the user's own request.
- The bubble renders from `document`, so it shows the chip, not the body. `walkDocumentToHtml`
  in `transcript.js` (L771-L833) gains a `chatPrompt` branch; today it drops unknown nodes.
- Regenerate and retry (`send.js` L200-L205, L681-L688) re-send `userMsg.content` as text.
  With expanded bodies that would show XML in the composer, so both switch to
  `setDocument(userMsg.document)` when a document exists. This also stops dropping mentions.
- The dashboard composer (`dashboard.blade.php` L24-L52) mounts the same editor and gets the
  menu for free; `sessionStorage['chat:bootstrap']` already carries the document.

### 4. Starter chips on the empty chat

- `_transcript.blade.php` L19-L42 keeps its greeting and adds up to three chips under it from
  `BuiltInPrompt::forContext()` for the side panel's page context (`ChatSidePanel` already
  holds `recordType`), and the no-record set on the dashboard and full page.
- A click inserts the chip into the composer and focuses it. The user can type a name after it
  ("Account research" + "Filaforms") or press Enter.
- `ChatInterfacePromptForwardingTest` pins the greeting; it keeps passing.

### 5. Settings page: Prompts

- `Relaticle\Chat\Filament\Pages\PromptSettings` in the `App\Filament\Clusters\Settings`
  cluster (slug `prompts`, label "Prompts", after Notifications). It is its own page, so this
  work does not share a file with the web research PR.
- A table of prompts the user can see: title with icon, Shared badge, author, actions Edit and
  Delete (policy-gated). Header actions: "New prompt" and "Add from library", which opens the
  built-ins; choosing one opens the create form prefilled with its body and `source_key`.
- Filament writes go through the three actions (`EloquentWriteOutsideActionRule`).

### 6. Out of scope

Teams-level sharing, `{variables}`, prompt usage analytics, prompts in the MCP server, and
suggestions from custom prompts by page (built-ins only in v1).

## Tests

Feature (extend existing files first):

- `ChatSendDocumentTest`: a prompt-only message is accepted; the stored content holds the
  expanded body; a teammate's private prompt id fails with 422; a shared one passes; a prompt
  from another workspace fails; more than three prompts fail.
- `TipTapDocumentParserTest`: prompt nodes are collected and add no text.
- New `tests/Feature/Chat/SavedPromptsTest.php`: settings page create, edit, delete through
  Livewire; policy matrix (owner, teammate, workspace owner on an orphan); "Add from library"
  stores `source_key`.
- `ChatInterfacePromptForwardingTest`: starter chips for a company page and for no record.

Browser:

- New `tests/Browser/Chat/PromptPickerTest.php`: `/` opens the menu, a pick inserts a chip,
  Enter sends, the bubble shows the chip title, reload shows it again, regenerate keeps it.
- Run `MentionPickerTest`, `NextStepStripTest`, `DashboardTest` and the new file under
  `--browser safari` once.

Manual verification on the production-shaped stack (Horizon, redis queue, Reverb):
send each built-in on its record page and with no record; confirm the ask-for-the-entity
reply when nothing is in context.

## Appendix A: Account research body (draft)

```text
Research the company in context.

If no company record is open and none is named in my message, ask me for the company name or, better, its domain. Do nothing else until I answer.

1. Find the company in this workspace and read its record, its people, open opportunities, recent notes, tasks and emails.
2. When web access is available, search the web for what the company does, its size, funding, leadership, and news from the last 90 days. Use the domain to avoid similarly named companies. Skip sites that need a login.
3. Write a brief with these sections: Snapshot, What's new, People to talk to, Relationship with us, Suggested angle, Sources. Cite every web fact.
4. Then propose, as one plan, a note on the company holding the brief, and values for the company's empty fields only. Never overwrite a field that already has a value.
```
